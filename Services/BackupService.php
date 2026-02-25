<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Services;

use App\Models\Site;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models\SiteMoverMigration;
use RuntimeException;

class BackupService
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function createArtifacts(SiteMoverMigration $migration, Site $sourceSite, array $manifest): array
    {
        $artifactDir = $this->artifactDirectory($migration->id);
        if (! is_dir($artifactDir)) {
            mkdir($artifactDir, 0775, true);
        }

        $artifacts = [
            'database' => null,
            'storage' => [],
        ];

        $database = data_get($manifest, 'database', []);
        if (! empty($database['name'])) {
            $artifacts['database'] = $this->createDatabaseDump($migration, $sourceSite, $database, $artifactDir);
        }

        /** @var array<int, string> $storagePaths */
        $storagePaths = data_get($manifest, 'storage_paths', []);
        foreach ($storagePaths as $index => $storagePath) {
            $artifacts['storage'][] = $this->createStorageArchive($migration, $sourceSite, $storagePath, $artifactDir, $index);
        }

        return $artifacts;
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private function createDatabaseDump(
        SiteMoverMigration $migration,
        Site $sourceSite,
        array $database,
        string $artifactDir,
    ): array {
        $connection = strtolower((string) ($database['connection'] ?? 'mysql'));
        $dbName = (string) $database['name'];

        $sourceEnv = app(\App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Support\EnvParser::class)
            ->parse($sourceSite->getEnv());

        $host = $sourceEnv['DB_HOST'] ?? '127.0.0.1';
        $port = $sourceEnv['DB_PORT'] ?? ($connection === 'pgsql' ? '5432' : '3306');
        $username = $sourceEnv['DB_USERNAME'] ?? '';
        $password = $sourceEnv['DB_PASSWORD'] ?? '';

        if ($username === '') {
            throw new RuntimeException('Cannot create database dump: DB_USERNAME is missing from source .env');
        }

        $remoteDumpPath = '/tmp/site-mover-'.$migration->id.'-db.sql.gz';
        $localDumpPath = $artifactDir.'/db.sql.gz';

        $dumpCommand = $this->databaseDumpCommand(
            $connection,
            $host,
            $port,
            $username,
            $password,
            $dbName,
            $remoteDumpPath,
        );

        $sourceSite->server->ssh()->exec($dumpCommand, 'site-mover-db-dump', $sourceSite->id);
        $sourceSite->server->ssh()->download($localDumpPath, $remoteDumpPath, 'site-mover-db-dump-download', $sourceSite->id);
        $sourceSite->server->os()->deleteFile($remoteDumpPath);

        $checksum = hash_file('sha256', $localDumpPath) ?: null;

        $artifact = $migration->artifacts()->create([
            'type' => 'db_dump',
            'ref' => basename($localDumpPath),
            'path' => $localDumpPath,
            'checksum' => $checksum,
            'metadata_json' => [
                'connection' => $connection,
                'database' => $dbName,
                'host' => $host,
                'port' => $port,
            ],
        ]);

        return [
            'id' => $artifact->id,
            'path' => $localDumpPath,
            'connection' => $connection,
            'database' => $dbName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createStorageArchive(
        SiteMoverMigration $migration,
        Site $sourceSite,
        string $storagePath,
        string $artifactDir,
        int $index,
    ): array {
        $sourcePath = $this->sourcePath($sourceSite, $storagePath);
        $remoteArchivePath = '/tmp/site-mover-'.$migration->id.'-storage-'.$index.'.tar.gz';
        $localArchivePath = $artifactDir.'/storage-'.$index.'.tar.gz';

        $command = <<<'BASH'
set -e
SRC=%s
OUT=%s

if [ ! -e "$SRC" ]; then
  echo "Path not found: $SRC"
  exit 1
fi

if [ -d "$SRC" ]; then
  tar -czf "$OUT" -C "$SRC" .
else
  PARENT=$(dirname "$SRC")
  NAME=$(basename "$SRC")
  tar -czf "$OUT" -C "$PARENT" "$NAME"
fi
BASH;

        $sourceSite->server->ssh()->exec(
            sprintf($command, escapeshellarg($sourcePath), escapeshellarg($remoteArchivePath)),
            'site-mover-storage-backup',
            $sourceSite->id
        );

        $sourceSite->server->ssh()->download($localArchivePath, $remoteArchivePath, 'site-mover-storage-download', $sourceSite->id);
        $sourceSite->server->os()->deleteFile($remoteArchivePath);

        $checksum = hash_file('sha256', $localArchivePath) ?: null;

        $artifact = $migration->artifacts()->create([
            'type' => 'storage_archive',
            'ref' => basename($localArchivePath),
            'path' => $localArchivePath,
            'checksum' => $checksum,
            'metadata_json' => [
                'storage_path' => $storagePath,
                'source_path' => $sourcePath,
            ],
        ]);

        return [
            'id' => $artifact->id,
            'path' => $localArchivePath,
            'storage_path' => $storagePath,
            'source_path' => $sourcePath,
        ];
    }

    private function sourcePath(Site $sourceSite, string $storagePath): string
    {
        if (str_starts_with($storagePath, '/')) {
            return $storagePath;
        }

        return rtrim($sourceSite->path, '/').'/'.ltrim($storagePath, '/');
    }

    private function artifactDirectory(int $migrationId): string
    {
        return storage_path('app/site-mover/'.$migrationId);
    }

    private function databaseDumpCommand(
        string $connection,
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
        string $outputPath,
    ): string {
        if (in_array($connection, ['pgsql', 'postgres', 'postgresql'], true)) {
            $prefix = $password !== '' ? 'PGPASSWORD='.escapeshellarg($password).' ' : '';

            return sprintf(
                '%spg_dump -h %s -p %s -U %s -d %s | gzip > %s',
                $prefix,
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($outputPath),
            );
        }

        $prefix = $password !== '' ? 'MYSQL_PWD='.escapeshellarg($password).' ' : '';

        return sprintf(
            '%smysqldump --single-transaction --quick -h %s -P %s -u %s %s | gzip > %s',
            $prefix,
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($outputPath),
        );
    }
}
