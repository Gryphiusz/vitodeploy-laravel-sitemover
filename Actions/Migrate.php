<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Enums\MigrationStatus;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Jobs\RunSiteMigration;
use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models\SiteMoverMigration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Migrate extends Action
{
    public function name(): string
    {
        return 'Migrate';
    }

    public function active(): bool
    {
        return count($this->targetServers()) > 0;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('target_hint')
                ->alert()
                ->options(['type' => 'info'])
                ->description($this->targetServerHint()),
            DynamicField::make('target_server_id')
                ->select()
                ->label('Target Server')
                ->options($this->targetServerOptions())
                ->description('Select the target server ID from your current project.'),
            DynamicField::make('target_domain')
                ->text()
                ->label('Target Domain')
                ->default($this->site->domain.'-migrated')
                ->description('This plugin does not update DNS/proxy routing.'),
            DynamicField::make('target_user')
                ->text()
                ->label('Target User (Optional)')
                ->description('Leave empty to auto-generate a unique site user on target server.'),
            DynamicField::make('db_name')
                ->text()
                ->label('Target Database Name')
                ->description('Leave empty to reuse source DB name.'),
            DynamicField::make('db_user_strategy')
                ->select()
                ->label('DB User Strategy')
                ->options(['clone', 'create'])
                ->default('clone')
                ->description('clone: replicate users from source. create: create one fallback user.'),
            DynamicField::make('storage_paths')
                ->textarea()
                ->label('Storage Paths')
                ->default("storage/app/public\nstorage/app/private")
                ->description('One relative path per line.'),
            DynamicField::make('include_env')
                ->checkbox()
                ->label('Copy .env')
                ->default(true)
                ->description('Sensitive values are not displayed, but they are copied to target if enabled.'),
            DynamicField::make('horizon_mode')
                ->select()
                ->label('Worker Mode')
                ->options(['auto', 'horizon', 'queue-work'])
                ->default('auto'),
            DynamicField::make('downtime_mode')
                ->select()
                ->label('Downtime Mode')
                ->options(['test', 'final-sync'])
                ->default('test')
                ->description('No DNS/proxy switch is performed by this plugin.'),
            DynamicField::make('run_database_migrations')
                ->checkbox()
                ->label('Run php artisan migrate --force')
                ->default(false),
            DynamicField::make('healthcheck_url')
                ->text()
                ->label('Healthcheck URL or Path')
                ->default('/'),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'target_server_id' => ['required', 'integer', 'exists:servers,id'],
            'target_domain' => ['required', 'string', 'max:255'],
            'target_user' => ['nullable', 'string', 'max:32'],
            'db_name' => ['nullable', 'string', 'max:255'],
            'db_user_strategy' => ['required', 'in:clone,create'],
            'storage_paths' => ['nullable', 'string'],
            'include_env' => ['nullable'],
            'horizon_mode' => ['required', 'in:auto,horizon,queue-work'],
            'downtime_mode' => ['required', 'in:test,final-sync'],
            'run_database_migrations' => ['nullable'],
            'healthcheck_url' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $targetServerId = (int) $request->input('target_server_id');
        if ($targetServerId === (int) $this->site->server_id) {
            abort(422, 'Target server must be different from source server.');
        }

        $targetServer = Server::query()->findOrFail($targetServerId);
        if ((int) $targetServer->project_id !== (int) $this->site->server->project_id) {
            abort(422, 'Target server must belong to the same project as source site.');
        }

        $migration = SiteMoverMigration::query()->create([
            'source_site_id' => $this->site->id,
            'source_server_id' => $this->site->server_id,
            'target_server_id' => $targetServerId,
            'status' => MigrationStatus::QUEUED,
            'options_json' => [
                'target_server_id' => $targetServerId,
                'target_domain' => trim((string) $request->input('target_domain')),
                'target_user' => trim((string) $request->input('target_user', '')),
                'db_name' => trim((string) $request->input('db_name', '')),
                'db_user_strategy' => (string) $request->input('db_user_strategy', 'clone'),
                'storage_paths' => $this->storagePathsFromText((string) $request->input('storage_paths', '')),
                'include_env' => filter_var($request->input('include_env', true), FILTER_VALIDATE_BOOL),
                'horizon_mode' => (string) $request->input('horizon_mode', 'auto'),
                'downtime_mode' => (string) $request->input('downtime_mode', 'test'),
                'run_database_migrations' => filter_var($request->input('run_database_migrations', false), FILTER_VALIDATE_BOOL),
                'healthcheck_url' => (string) $request->input('healthcheck_url', '/'),
            ],
        ]);

        RunSiteMigration::dispatch($migration->id);

        $request->session()->flash(
            'success',
            sprintf('Migration #%d queued. It will run in the background and update status when complete.', $migration->id)
        );
    }

    /**
     * @return array<int, string>
     */
    private function targetServerOptions(): array
    {
        return collect($this->targetServers())
            ->map(fn (Server $server): string => (string) $server->id)
            ->values()
            ->all();
    }

    private function targetServerHint(): string
    {
        $lines = collect($this->targetServers())
            ->map(fn (Server $server): string => sprintf('%d = %s (%s)', $server->id, $server->name, $server->ip))
            ->implode("\n");

        return $lines !== ''
            ? "Available target servers:\n".$lines
            : 'No target servers are available in this project.';
    }

    /**
     * @return array<int, Server>
     */
    private function targetServers(): array
    {
        return Server::query()
            ->where('project_id', $this->site->server->project_id)
            ->where('id', '!=', $this->site->server_id)
            ->orderBy('name')
            ->get()
            ->all();
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
