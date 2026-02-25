<?php

namespace App\Vito\Plugins\Arnobolt\SiteMover\Jobs;

use App\Models\Site;
use App\Vito\Plugins\Arnobolt\SiteMover\Enums\MigrationStatus;
use App\Vito\Plugins\Arnobolt\SiteMover\Models\SiteMoverMigration;
use App\Vito\Plugins\Arnobolt\SiteMover\Services\BackupService;
use App\Vito\Plugins\Arnobolt\SiteMover\Services\DiscoveryService;
use App\Vito\Plugins\Arnobolt\SiteMover\Services\RestoreService;
use App\Vito\Plugins\Arnobolt\SiteMover\Services\ValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class RunSiteMigration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(private readonly int $migrationId)
    {
        $this->onQueue('default');
    }

    public function handle(
        DiscoveryService $discoveryService,
        BackupService $backupService,
        RestoreService $restoreService,
        ValidationService $validationService,
    ): void {
        $migration = SiteMoverMigration::query()->find($this->migrationId);
        if ($migration === null) {
            return;
        }

        /** @var Site $sourceSite */
        $sourceSite = Site::query()->findOrFail($migration->source_site_id);

        $options = $migration->options_json ?? [];
        $storagePaths = $this->storagePathsFromOptions($options);

        try {
            $migration->status = MigrationStatus::DISCOVERING;
            $migration->started_at = now();
            $migration->save();

            $manifest = $discoveryService->discover($sourceSite, $storagePaths);
            $migration->manifest_json = $manifest;
            $migration->save();

            $migration->status = MigrationStatus::BACKING_UP;
            $migration->save();

            $backupService->createArtifacts($migration, $sourceSite, $manifest);

            $migration->status = MigrationStatus::RESTORING;
            $migration->save();

            $targetSite = $restoreService->restore($migration, $sourceSite, $manifest);

            $migration->status = MigrationStatus::VALIDATING;
            $migration->save();

            $healthcheckUrl = (string) ($options['healthcheck_url'] ?? '/');
            $report = $validationService->run($targetSite, $healthcheckUrl);

            $migration->status = MigrationStatus::SUCCESS;
            $migration->report_json = $report;
            $migration->finished_at = now();
            $migration->save();
        } catch (\Throwable $exception) {
            $migration->status = MigrationStatus::FAILED;
            $migration->error_message = $exception->getMessage();
            $migration->finished_at = now();
            $migration->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function storagePathsFromOptions(array $options): array
    {
        $storagePaths = $options['storage_paths'] ?? [];

        if (is_string($storagePaths)) {
            $storagePaths = preg_split('/\r\n|\n|\r/', $storagePaths) ?: [];
        }

        if (! is_array($storagePaths)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($value) => trim((string) $value), $storagePaths)));
    }
}
