<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models\SiteMoverMigration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class History extends Action
{
    public function name(): string
    {
        return 'History';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        if (! Schema::hasTable('site_mover_migrations')) {
            return DynamicForm::make([
                DynamicField::make('history_missing_table')
                    ->alert()
                    ->options(['type' => 'warning'])
                    ->description('Migration history table is missing. Re-enable the plugin to run its migrations.'),
            ]);
        }

        $options = $this->migrationIdOptions();

        return DynamicForm::make([
            DynamicField::make('history_summary')
                ->alert()
                ->options(['type' => 'info'])
                ->description($this->recentSummaryText()),
            DynamicField::make('migration_id')
                ->select()
                ->label('Migration ID')
                ->options($options)
                ->default($options[0] ?? null)
                ->description('Select a migration and submit to view its latest report summary in a flash message.'),
        ]);
    }

    public function handle(Request $request): void
    {
        if (! Schema::hasTable('site_mover_migrations')) {
            $request->session()->flash('error', 'Migration history table is missing. Re-enable plugin to recreate tables.');

            return;
        }

        Validator::make($request->all(), [
            'migration_id' => ['nullable', 'integer', 'exists:site_mover_migrations,id'],
        ])->validate();

        $migration = null;
        if ($request->filled('migration_id')) {
            $migration = SiteMoverMigration::query()->find((int) $request->input('migration_id'));
            if ($migration !== null && (int) $migration->source_site_id !== (int) $this->site->id) {
                abort(422, 'Selected migration does not belong to this site.');
            }
        }

        if ($migration === null) {
            $migration = SiteMoverMigration::query()
                ->where('source_site_id', $this->site->id)
                ->latest('id')
                ->first();
        }

        if ($migration === null) {
            $request->session()->flash('error', 'No migration records found for this site yet.');

            return;
        }

        $summary = [
            sprintf('Migration #%d', $migration->id),
            sprintf('status=%s', $migration->status->value),
            sprintf('target_server=%s', $migration->target_server_id ?: '-'),
            sprintf('target_site=%s', $migration->target_site_id ?: '-'),
            sprintf('started=%s', optional($migration->started_at)?->toDateTimeString() ?? '-'),
            sprintf('finished=%s', optional($migration->finished_at)?->toDateTimeString() ?? '-'),
        ];

        if ($migration->error_message) {
            $summary[] = 'error='.$migration->error_message;
        }

        $report = $migration->report_json ?? [];
        if ($report !== []) {
            $passed = (int) data_get($report, 'summary.passed', 0);
            $total = (int) data_get($report, 'summary.total', 0);
            $summary[] = sprintf('checks=%d/%d', $passed, $total);

            $failedChecks = collect((array) data_get($report, 'checks', []))
                ->filter(fn (array $check): bool => ! (bool) ($check['ok'] ?? false))
                ->map(fn (array $check): string => (string) ($check['name'] ?? 'unknown'))
                ->values()
                ->all();

            if ($failedChecks !== []) {
                $summary[] = 'failed='.implode(',', $failedChecks);
            }
        }

        $request->session()->flash('success', implode(' | ', $summary));
    }

    /**
     * @return array<int, string>
     */
    private function migrationIdOptions(): array
    {
        return SiteMoverMigration::query()
            ->where('source_site_id', $this->site->id)
            ->latest('id')
            ->limit(20)
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function recentSummaryText(): string
    {
        $migrations = SiteMoverMigration::query()
            ->where('source_site_id', $this->site->id)
            ->latest('id')
            ->limit(5)
            ->get();

        if ($migrations->isEmpty()) {
            return 'No migration runs yet. Use Scan/Migrate first.';
        }

        return $migrations
            ->map(function (SiteMoverMigration $migration): string {
                $checks = data_get($migration->report_json ?? [], 'summary.total');
                $checksLabel = is_numeric($checks) ? 'checks='.$checks : 'checks=-';

                return sprintf(
                    '#%d %s target:%s/%s %s',
                    $migration->id,
                    $migration->status->value,
                    $migration->target_server_id ?: '-',
                    $migration->target_site_id ?: '-',
                    $checksLabel,
                );
            })
            ->implode(' || ');
    }
}
