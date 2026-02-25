<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Enums\MigrationStatus;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models\SiteMoverMigration;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Services\DiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Scan extends Action
{
    public function name(): string
    {
        return 'Scan';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('storage_paths')
                ->textarea()
                ->label('Storage Paths')
                ->default("storage/app/public\nstorage/app/private")
                ->description('Optional, one path per line. Leave empty to use default storage/app/public.'),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'storage_paths' => ['nullable', 'string'],
        ])->validate();

        $storagePaths = $this->storagePathsFromText((string) $request->input('storage_paths', ''));

        $manifest = app(DiscoveryService::class)->discover($this->site, $storagePaths);

        $migration = SiteMoverMigration::query()->create([
            'source_site_id' => $this->site->id,
            'source_server_id' => $this->site->server_id,
            'status' => MigrationStatus::SCANNED,
            'manifest_json' => $manifest,
            'options_json' => [
                'storage_paths' => $storagePaths,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $request->session()->flash(
            'success',
            sprintf(
                'Scan complete. Migration #%d created with %d storage paths, %d cron job(s), and %d worker(s).',
                $migration->id,
                count(data_get($manifest, 'storage_paths', [])),
                count(data_get($manifest, 'cron_jobs', [])),
                count(data_get($manifest, 'workers', [])),
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function storagePathsFromText(string $text): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\r\n|\n|\r/', $text) ?: []
        )));
    }
}
