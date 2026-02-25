<?php

namespace App\Vito\Plugins\Arnobolt\SiteMover\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Site;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Arnobolt\SiteMover\Models\SiteMoverMigration;
use App\Vito\Plugins\Arnobolt\SiteMover\Services\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Validate extends Action
{
    public function name(): string
    {
        return 'Validate';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('target_site_id')
                ->text()
                ->label('Target Site ID')
                ->description('ID of the migrated site to validate.'),
            DynamicField::make('healthcheck_url')
                ->text()
                ->label('Healthcheck URL or Path')
                ->default('/'),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'target_site_id' => ['required', 'integer', 'exists:sites,id'],
            'healthcheck_url' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $targetSite = Site::query()->findOrFail((int) $request->input('target_site_id'));
        $healthcheckUrl = (string) $request->input('healthcheck_url', '/');

        $report = app(ValidationService::class)->run($targetSite, $healthcheckUrl);

        $migration = SiteMoverMigration::query()
            ->where('source_site_id', $this->site->id)
            ->where('target_site_id', $targetSite->id)
            ->latest('id')
            ->first();

        if ($migration) {
            $migration->report_json = $report;
            $migration->save();
        }

        $passed = (int) data_get($report, 'summary.passed', 0);
        $total = (int) data_get($report, 'summary.total', 0);

        $request->session()->flash(
            'success',
            sprintf('Validation complete. %d/%d checks passed for target site #%d.', $passed, $total, $targetSite->id)
        );
    }
}
