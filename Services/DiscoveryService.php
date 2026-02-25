<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Services;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Support\EnvParser;

class DiscoveryService
{
    public function __construct(private readonly EnvParser $envParser) {}

    /**
     * @param  array<int, string>  $storagePaths
     * @return array<string, mixed>
     */
    public function discover(Site $site, array $storagePaths = []): array
    {
        $storagePaths = $this->envParser->normalizeStoragePaths(array_merge(['storage/app/public'], $storagePaths));

        $envRaw = $site->getEnv();
        $env = $this->envParser->parse($envRaw);
        $dbName = $env['DB_DATABASE'] ?? '';

        $database = null;
        if ($dbName !== '') {
            $database = Database::query()
                ->where('server_id', $site->server_id)
                ->where('name', $dbName)
                ->first();
        }

        $dbUsers = $this->databaseUsersForDatabase($site, $dbName);

        $workers = $site->workers()
            ->get()
            ->map(function ($worker): array {
                return [
                    'id' => $worker->id,
                    'name' => $worker->name,
                    'command' => $worker->command,
                    'user' => $worker->user,
                    'auto_start' => (bool) $worker->auto_start,
                    'auto_restart' => (bool) $worker->auto_restart,
                    'numprocs' => (int) $worker->numprocs,
                    'redirect_stderr' => (bool) $worker->redirect_stderr,
                ];
            })
            ->values()
            ->all();

        $cronJobs = $site->cronJobs()
            ->where('hidden', false)
            ->get()
            ->map(function ($cron): array {
                return [
                    'id' => $cron->id,
                    'command' => $cron->command,
                    'frequency' => $cron->frequency,
                    'user' => $cron->user,
                ];
            })
            ->values()
            ->all();

        $horizonDetected = collect($workers)
            ->contains(fn (array $worker): bool => str_contains(strtolower($worker['command']), 'horizon'));

        $redisUsed = $this->redisUsed($env);

        $redisConnectivity = null;
        if ($redisUsed) {
            $redisConnectivity = $this->checkRedisConnectivity($site, $env);
        }

        return [
            'discovered_at' => now()->toIso8601String(),
            'site' => [
                'id' => $site->id,
                'server_id' => $site->server_id,
                'type' => $site->type,
                'domain' => $site->domain,
                'aliases' => $site->aliases ?? [],
                'path' => $site->path,
                'user' => $site->user,
                'php_version' => $site->php_version,
                'web_directory' => $site->web_directory,
                'repository' => $site->repository,
                'branch' => $site->branch,
                'source_control_id' => $site->source_control_id,
                'deployment_script' => $site->deploymentScript?->content,
                'deployment_restart_workers' => (bool) data_get($site->deploymentScript?->configs, 'restart_workers', false),
                'composer' => (bool) data_get($site->type_data, 'composer', true),
            ],
            'storage_paths' => $storagePaths,
            'database' => [
                'connection' => strtolower($env['DB_CONNECTION'] ?? 'mysql'),
                'name' => $dbName,
                'host' => $this->maskValue($env['DB_HOST'] ?? ''),
                'port' => $this->maskValue($env['DB_PORT'] ?? ''),
                'charset' => $database?->charset,
                'collation' => $database?->collation,
                'users' => $dbUsers,
            ],
            'cron_jobs' => $cronJobs,
            'workers' => $workers,
            'horizon' => [
                'detected' => $horizonDetected,
            ],
            'redis' => [
                'used' => $redisUsed,
                'service_installed' => $site->server->memoryDatabase() !== null,
                'host' => $this->maskValue($env['REDIS_HOST'] ?? ''),
                'port' => $this->maskValue($env['REDIS_PORT'] ?? ''),
                'connectivity' => $redisConnectivity,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function databaseUsersForDatabase(Site $site, string $databaseName): array
    {
        if ($databaseName === '') {
            return [];
        }

        return $site->server->databaseUsers()
            ->get()
            ->filter(function (DatabaseUser $databaseUser) use ($databaseName): bool {
                return in_array($databaseName, $databaseUser->databases ?? [], true);
            })
            ->map(function (DatabaseUser $databaseUser): array {
                return [
                    'username' => $databaseUser->username,
                    'host' => $databaseUser->host,
                    'permission' => $databaseUser->permission->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $env
     */
    private function redisUsed(array $env): bool
    {
        if (($env['QUEUE_CONNECTION'] ?? '') === 'redis') {
            return true;
        }

        if (($env['CACHE_STORE'] ?? '') === 'redis') {
            return true;
        }

        return ($env['REDIS_HOST'] ?? '') !== '' || ($env['REDIS_URL'] ?? '') !== '';
    }

    /**
     * @param  array<string, string>  $env
     */
    private function checkRedisConnectivity(Site $site, array $env): ?bool
    {
        $host = $env['REDIS_HOST'] ?? '127.0.0.1';
        $port = $env['REDIS_PORT'] ?? '6379';
        $password = $env['REDIS_PASSWORD'] ?? '';

        $command = 'redis-cli -h '.escapeshellarg($host).' -p '.escapeshellarg($port);
        if ($password !== '') {
            $command .= ' -a '.escapeshellarg($password);
        }
        $command .= ' ping';

        try {
            $output = trim($site->server->ssh()->exec($command, 'site-mover-redis-check', $site->id));

            return str_contains(strtoupper($output), 'PONG');
        } catch (\Throwable) {
            return null;
        }
    }

    private function maskValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 1).str_repeat('*', max($length - 2, 1)).substr($value, -1);
    }
}
