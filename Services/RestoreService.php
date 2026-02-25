<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Services;

use App\Actions\CronJob\CreateCronJob;
use App\Actions\Database\CreateDatabase;
use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Site\UpdateDeploymentScript;
use App\Actions\Site\UpdateEnv;
use App\Enums\DatabaseStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkerStatus;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\Site;
use App\Models\Worker;
use App\Services\ProcessManager\ProcessManager;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models\SiteMoverMigration;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Support\EnvParser;
use RuntimeException;

class RestoreService
{
    public function __construct(
        private readonly EnvParser $envParser,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function restore(SiteMoverMigration $migration, Site $sourceSite, array $manifest): Site
    {
        $options = $migration->options_json ?? [];

        $targetServerId = (int) ($options['target_server_id'] ?? 0);
        if ($targetServerId <= 0) {
            throw new RuntimeException('Target server is required for migration.');
        }

        $targetServer = Server::query()->findOrFail($targetServerId);

        $this->assertTargetPrerequisites($targetServer, (string) data_get($manifest, 'site.php_version', ''));

        $targetSite = $this->provisionTargetSite($sourceSite, $targetServer, $manifest, $options);

        $dbContext = $this->setupDatabase($sourceSite, $targetServer, $manifest, $options);

        $this->restoreDatabaseDump($migration, $targetServer, $dbContext);

        $this->copyEnv($sourceSite, $targetSite, $dbContext, (bool) ($options['include_env'] ?? true));
        $this->restoreStorageArchives($migration, $targetSite);
        $this->recreateCronJobs($sourceSite, $targetSite, $manifest);
        $this->recreateWorkers($sourceSite, $targetSite, $manifest, (string) ($options['horizon_mode'] ?? 'auto'));
        $this->runPostMigrationCommands($targetSite, (bool) ($options['run_database_migrations'] ?? false));

        $migration->target_server_id = $targetServer->id;
        $migration->target_site_id = $targetSite->id;
        $migration->save();

        return $targetSite;
    }

    private function assertTargetPrerequisites(Server $targetServer, string $phpVersion): void
    {
        if ($targetServer->webserver() === null) {
            throw new RuntimeException('Target server does not have a webserver service.');
        }

        if ($targetServer->database() === null) {
            throw new RuntimeException('Target server does not have a database service.');
        }

        if ($targetServer->processManager() === null) {
            throw new RuntimeException('Target server does not have a process manager service.');
        }

        if ($phpVersion !== '' && $targetServer->php($phpVersion) === null) {
            throw new RuntimeException('Target server does not have PHP '.$phpVersion.' installed.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $options
     */
    private function provisionTargetSite(Site $sourceSite, Server $targetServer, array $manifest, array $options): Site
    {
        $targetDomain = trim((string) ($options['target_domain'] ?? ''));
        if ($targetDomain === '') {
            throw new RuntimeException('Target domain is required.');
        }

        $targetUserInput = trim((string) ($options['target_user'] ?? ''));
        $targetUser = $targetUserInput !== ''
            ? $targetUserInput
            : $this->uniqueSiteUser($targetServer, $sourceSite->user);

        if (Site::query()->where('server_id', $targetServer->id)->where('domain', $targetDomain)->exists()) {
            throw new RuntimeException('Target domain already exists on target server.');
        }

        $siteInput = [
            'type' => 'laravel',
            'domain' => $targetDomain,
            'aliases' => [],
            'user' => $targetUser,
            'php_version' => (string) data_get($manifest, 'site.php_version', $sourceSite->php_version),
            'source_control' => (int) data_get($manifest, 'site.source_control_id', $sourceSite->source_control_id),
            'repository' => (string) data_get($manifest, 'site.repository', $sourceSite->repository),
            'branch' => (string) data_get($manifest, 'site.branch', $sourceSite->branch),
            'web_directory' => (string) data_get($manifest, 'site.web_directory', $sourceSite->web_directory),
            'composer' => (bool) data_get($manifest, 'site.composer', true),
        ];

        $site = new Site([
            'server_id' => $targetServer->id,
            'type' => 'laravel',
            'domain' => $targetDomain,
            'aliases' => [],
            'user' => $targetUser,
            'path' => '/home/'.$targetUser.'/'.$targetDomain,
            'status' => SiteStatus::INSTALLING,
        ]);

        foreach ($site->type()->requiredServices() as $requiredService) {
            if (! $targetServer->service($requiredService)) {
                throw new RuntimeException("Target server is missing required service: {$requiredService}");
            }
        }

        $site->fill($site->type()->createFields($siteInput));
        $site->type_data = $site->type()->data($siteInput);
        $site->save();
        $site->commands()->createMany($site->type()->baseCommands());

        try {
            $site->type()->install();
            $site->status = SiteStatus::READY;
            $site->progress = 100;
            $site->save();
        } catch (\Throwable $exception) {
            $site->status = SiteStatus::INSTALLATION_FAILED;
            $site->save();
            throw $exception;
        }

        $sourceScript = $sourceSite->deploymentScript;
        if ($sourceScript && $site->deploymentScript) {
            app(UpdateDeploymentScript::class)->update($site->deploymentScript, [
                'script' => $sourceScript->content,
                'restart_workers' => (bool) data_get($sourceScript->configs, 'restart_workers', false),
            ]);
        }

        return $site;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $options
     * @return array<string, string>|array<empty, empty>
     */
    private function setupDatabase(Site $sourceSite, Server $targetServer, array $manifest, array $options): array
    {
        $sourceEnv = $this->envParser->parse($sourceSite->getEnv());

        $sourceDbName = (string) ($sourceEnv['DB_DATABASE'] ?? data_get($manifest, 'database.name', ''));
        if ($sourceDbName === '') {
            return [];
        }

        $targetDbName = trim((string) ($options['db_name'] ?? $sourceDbName));
        if ($targetDbName === '') {
            $targetDbName = $sourceDbName;
        }

        $existing = Database::query()
            ->where('server_id', $targetServer->id)
            ->where('name', $targetDbName)
            ->first();

        if ($existing === null) {
            $charset = (string) data_get($manifest, 'database.charset', 'utf8mb4');
            $collation = (string) data_get($manifest, 'database.collation', 'utf8mb4_unicode_ci');

            $existing = app(CreateDatabase::class)->create($targetServer, [
                'name' => $targetDbName,
                'charset' => $charset,
                'collation' => $collation,
            ]);
        }

        if ($existing->status !== DatabaseStatus::READY) {
            throw new RuntimeException('Target database is not ready for restore.');
        }

        $strategy = (string) ($options['db_user_strategy'] ?? 'clone');
        $primaryUser = null;

        if ($strategy === 'clone') {
            $primaryUser = $this->cloneDatabaseUsers($sourceSite, $sourceDbName, $targetServer, $targetDbName);
        }

        if ($primaryUser === null) {
            $primaryUser = $this->createFallbackDatabaseUser($targetServer, $targetDbName, $sourceEnv);
        }

        $connection = strtolower((string) ($sourceEnv['DB_CONNECTION'] ?? data_get($manifest, 'database.connection', 'mysql')));
        $defaultPort = in_array($connection, ['pgsql', 'postgres', 'postgresql'], true) ? '5432' : '3306';

        return [
            'connection' => $connection,
            'database' => $targetDbName,
            'host' => $primaryUser->host,
            'port' => (string) ($sourceEnv['DB_PORT'] ?? $defaultPort),
            'username' => $primaryUser->username,
            'password' => (string) $primaryUser->password,
        ];
    }

    private function copyEnv(Site $sourceSite, Site $targetSite, array $dbContext, bool $includeEnv): void
    {
        if (! $includeEnv) {
            return;
        }

        $envRaw = $sourceSite->getEnv();
        if (trim($envRaw) === '') {
            return;
        }

        if ($dbContext !== []) {
            $envRaw = $this->envParser->update($envRaw, 'DB_CONNECTION', (string) $dbContext['connection']);
            $envRaw = $this->envParser->update($envRaw, 'DB_DATABASE', (string) $dbContext['database']);
            $envRaw = $this->envParser->update($envRaw, 'DB_HOST', (string) $dbContext['host']);
            $envRaw = $this->envParser->update($envRaw, 'DB_PORT', (string) $dbContext['port']);
            $envRaw = $this->envParser->update($envRaw, 'DB_USERNAME', (string) $dbContext['username']);
            $envRaw = $this->envParser->update($envRaw, 'DB_PASSWORD', (string) $dbContext['password']);
        }

        $envRaw = $this->envParser->update($envRaw, 'APP_URL', 'https://'.$targetSite->domain);

        app(UpdateEnv::class)->update($targetSite, [
            'env' => $envRaw,
            'path' => $targetSite->path.'/.env',
        ]);
    }

    private function restoreDatabaseDump(SiteMoverMigration $migration, Server $targetServer, array $dbContext): void
    {
        if ($dbContext === []) {
            return;
        }

        $artifact = $migration->artifacts()
            ->where('type', 'db_dump')
            ->first();

        if ($artifact === null || ! is_file($artifact->path)) {
            return;
        }

        $remotePath = '/tmp/site-mover-'.$migration->id.'-db-restore.sql.gz';
        $targetServer->ssh()->upload($artifact->path, $remotePath);

        $restoreCommand = $this->databaseRestoreCommand(
            (string) $dbContext['connection'],
            (string) $dbContext['host'],
            (string) $dbContext['port'],
            (string) $dbContext['username'],
            (string) $dbContext['password'],
            (string) $dbContext['database'],
            $remotePath,
        );

        $targetServer->ssh()->exec($restoreCommand, 'site-mover-db-restore');
        $targetServer->os()->deleteFile($remotePath);
    }

    private function restoreStorageArchives(SiteMoverMigration $migration, Site $targetSite): void
    {
        $archives = $migration->artifacts()
            ->where('type', 'storage_archive')
            ->get();

        foreach ($archives as $index => $archive) {
            if (! is_file($archive->path)) {
                continue;
            }

            $relativePath = (string) data_get($archive->metadata_json, 'storage_path', 'storage/app/public');
            $destination = $this->targetStoragePath($targetSite, $relativePath);
            $remoteArchive = '/tmp/site-mover-'.$migration->id.'-storage-'.$index.'.tar.gz';

            $targetSite->server->ssh()->upload($archive->path, $remoteArchive);

            $command = sprintf(
                'set -e; mkdir -p %s; tar -xzf %s -C %s',
                escapeshellarg($destination),
                escapeshellarg($remoteArchive),
                escapeshellarg($destination),
            );

            $targetSite->server->ssh($targetSite->user)->exec($command, 'site-mover-storage-restore', $targetSite->id);
            $targetSite->server->os()->deleteFile($remoteArchive);
        }

        $targetSite->server->ssh($targetSite->user)->exec(
            'cd '.escapeshellarg($targetSite->path).' && php artisan storage:link || true',
            'site-mover-storage-link',
            $targetSite->id
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function recreateCronJobs(Site $sourceSite, Site $targetSite, array $manifest): void
    {
        $cronJobs = data_get($manifest, 'cron_jobs', []);
        foreach ($cronJobs as $cronJob) {
            $command = $this->replaceSourcePath((string) ($cronJob['command'] ?? ''), $sourceSite, $targetSite);
            $user = (string) ($cronJob['user'] ?? $targetSite->user);

            if (! in_array($user, $targetSite->getSshUsers(), true)) {
                $user = $targetSite->user;
            }

            app(CreateCronJob::class)->create($targetSite->server, [
                'command' => $command,
                'user' => $user,
                'frequency' => (string) ($cronJob['frequency'] ?? '* * * * *'),
            ], $targetSite);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function recreateWorkers(Site $sourceSite, Site $targetSite, array $manifest, string $horizonMode): void
    {
        $workers = data_get($manifest, 'workers', []);
        if ($workers === []) {
            return;
        }

        $processManagerService = $targetSite->server->processManager();
        if ($processManagerService === null) {
            return;
        }

        /** @var ProcessManager $processManager */
        $processManager = $processManagerService->handler();

        foreach ($workers as $workerData) {
            $name = $this->uniqueWorkerName($targetSite, (string) ($workerData['name'] ?? 'worker'));
            $command = $this->replaceSourcePath((string) ($workerData['command'] ?? ''), $sourceSite, $targetSite);
            $command = $this->applyHorizonMode($command, $horizonMode);

            $user = (string) ($workerData['user'] ?? $targetSite->user);
            if (! in_array($user, $targetSite->getSshUsers(), true)) {
                $user = $targetSite->user;
            }

            $worker = new Worker([
                'server_id' => $targetSite->server_id,
                'site_id' => $targetSite->id,
                'name' => $name,
                'command' => $command,
                'user' => $user,
                'auto_start' => (bool) ($workerData['auto_start'] ?? true),
                'auto_restart' => (bool) ($workerData['auto_restart'] ?? true),
                'numprocs' => max(1, (int) ($workerData['numprocs'] ?? 1)),
                'redirect_stderr' => (bool) ($workerData['redirect_stderr'] ?? true),
                'status' => WorkerStatus::CREATING,
            ]);
            $worker->save();

            try {
                $processManager->create(
                    $worker->id,
                    $worker->command,
                    $worker->user,
                    (bool) $worker->auto_start,
                    (bool) $worker->auto_restart,
                    (int) $worker->numprocs,
                    $worker->getLogFile(),
                    $targetSite->path,
                    $targetSite->id,
                );

                $worker->status = WorkerStatus::RUNNING;
                $worker->save();
            } catch (\Throwable $exception) {
                $worker->status = WorkerStatus::FAILED;
                $worker->save();
                throw $exception;
            }
        }
    }

    private function runPostMigrationCommands(Site $targetSite, bool $runMigrations): void
    {
        $path = escapeshellarg($targetSite->path);
        $siteId = $targetSite->id;

        if ($runMigrations) {
            $targetSite->server->ssh($targetSite->user)->exec(
                'cd '.$path.' && php artisan migrate --force',
                'site-mover-artisan-migrate',
                $siteId
            );
        }

        $targetSite->server->ssh($targetSite->user)->exec(
            'cd '.$path.' && php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear',
            'site-mover-artisan-clear',
            $siteId
        );
    }

    private function uniqueSiteUser(Server $server, string $base): string
    {
        $base = strtolower(trim($base));
        $base = preg_replace('/[^a-z0-9_\-]/', '', $base) ?: 'site';
        $base = substr($base, 0, 24);

        $candidate = $base;
        $counter = 1;

        while (
            Site::query()->where('server_id', $server->id)->where('user', $candidate)->exists() ||
            in_array($candidate, $server->getSshUsers(), true)
        ) {
            $candidate = substr($base, 0, 20).$counter;
            $counter++;
        }

        return $candidate;
    }

    private function uniqueDatabaseUsername(Server $server, string $base): string
    {
        $base = strtolower(trim($base));
        $base = preg_replace('/[^a-z0-9_\-]/', '', $base) ?: 'site_mover';
        $base = substr($base, 0, 24);

        $candidate = $base;
        $counter = 1;

        while (DatabaseUser::query()->where('server_id', $server->id)->where('username', $candidate)->exists()) {
            $candidate = substr($base, 0, 20).$counter;
            $counter++;
        }

        return $candidate;
    }

    private function uniqueWorkerName(Site $site, string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'site-worker';
        $candidate = $base;
        $counter = 1;

        while ($site->workers()->where('name', $candidate)->exists()) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function replaceSourcePath(string $value, Site $sourceSite, Site $targetSite): string
    {
        return str_replace($sourceSite->path, $targetSite->path, $value);
    }

    private function targetStoragePath(Site $targetSite, string $relativePath): string
    {
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        return rtrim($targetSite->path, '/').'/'.ltrim($relativePath, '/');
    }

    private function applyHorizonMode(string $command, string $horizonMode): string
    {
        $normalized = strtolower($horizonMode);
        if ($normalized === 'auto') {
            return $command;
        }

        $containsHorizon = str_contains(strtolower($command), 'artisan horizon');
        if ($normalized === 'horizon' && $containsHorizon) {
            return $command;
        }

        if ($normalized === 'queue-work' && ! $containsHorizon) {
            return $command;
        }

        if ($normalized === 'horizon') {
            return preg_replace('/artisan\s+queue:work[^\n]*/i', 'artisan horizon', $command) ?? $command;
        }

        return preg_replace('/artisan\s+horizon[^\n]*/i', 'artisan queue:work', $command) ?? $command;
    }

    private function cloneDatabaseUsers(Site $sourceSite, string $sourceDatabase, Server $targetServer, string $targetDatabase): ?DatabaseUser
    {
        $sourceUsers = $sourceSite->server->databaseUsers()
            ->get()
            ->filter(fn (DatabaseUser $user): bool => in_array($sourceDatabase, $user->databases ?? [], true));

        $primaryUser = null;

        foreach ($sourceUsers as $sourceUser) {
            $username = $this->uniqueDatabaseUsername($targetServer, $sourceUser->username);
            $host = $sourceUser->host ?: 'localhost';
            $remote = ! in_array($host, ['localhost', '127.0.0.1'], true);

            $created = app(CreateDatabaseUser::class)->create($targetServer, [
                'username' => $username,
                'password' => (string) $sourceUser->password,
                'permission' => $sourceUser->permission->value,
                'remote' => $remote,
                'host' => $host,
            ], [$targetDatabase]);

            if ($primaryUser === null) {
                $primaryUser = $created;
            }
        }

        return $primaryUser;
    }

    /**
     * @param  array<string, string>  $sourceEnv
     */
    private function createFallbackDatabaseUser(Server $targetServer, string $targetDatabase, array $sourceEnv): DatabaseUser
    {
        $base = 'migr_'.substr($targetDatabase, 0, 16);
        $username = $this->uniqueDatabaseUsername($targetServer, $base);

        $password = (string) ($sourceEnv['DB_PASSWORD'] ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(16));
        }

        return app(CreateDatabaseUser::class)->create($targetServer, [
            'username' => $username,
            'password' => $password,
            'permission' => 'admin',
            'remote' => false,
            'host' => 'localhost',
        ], [$targetDatabase]);
    }

    private function databaseRestoreCommand(
        string $connection,
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
        string $inputPath,
    ): string {
        $connection = strtolower($connection);

        if (in_array($connection, ['pgsql', 'postgres', 'postgresql'], true)) {
            $prefix = $password !== '' ? 'PGPASSWORD='.escapeshellarg($password).' ' : '';

            return sprintf(
                'gunzip -c %s | %spsql -h %s -p %s -U %s -d %s',
                escapeshellarg($inputPath),
                $prefix,
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
            );
        }

        $prefix = $password !== '' ? 'MYSQL_PWD='.escapeshellarg($password).' ' : '';

        return sprintf(
            'gunzip -c %s | %smysql -h %s -P %s -u %s %s',
            escapeshellarg($inputPath),
            $prefix,
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
        );
    }
}
