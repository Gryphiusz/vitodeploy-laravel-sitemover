<?php

namespace App\Vito\Plugins\Arnobolt\SiteMover;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterSiteFeature;
use App\Plugins\RegisterSiteFeatureAction;
use App\Vito\Plugins\Arnobolt\SiteMover\Actions\History;
use App\Vito\Plugins\Arnobolt\SiteMover\Actions\Migrate;
use App\Vito\Plugins\Arnobolt\SiteMover\Actions\Scan;
use App\Vito\Plugins\Arnobolt\SiteMover\Actions\Validate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Site Mover';

    protected string $description = 'Migrate Laravel sites between VitoDeploy-managed servers.';

    public function boot(): void
    {
        RegisterSiteFeature::make('laravel', 'laravel-migrate')
            ->label('Migrate Site')
            ->description('Scan, migrate, and validate a Laravel site migration to another server')
            ->register();

        RegisterSiteFeatureAction::make('laravel', 'laravel-migrate', 'scan')
            ->label('Scan')
            ->handler(Scan::class)
            ->register();

        RegisterSiteFeatureAction::make('laravel', 'laravel-migrate', 'migrate')
            ->label('Migrate')
            ->handler(Migrate::class)
            ->register();

        RegisterSiteFeatureAction::make('laravel', 'laravel-migrate', 'history')
            ->label('History')
            ->handler(History::class)
            ->register();

        RegisterSiteFeatureAction::make('laravel', 'laravel-migrate', 'validate')
            ->label('Validate')
            ->handler(Validate::class)
            ->register();
    }

    public function install(): void
    {
        $this->runMigrations();
    }

    public function enable(): void
    {
        $this->runMigrations();
    }

    public function uninstall(): void
    {
        try {
            Artisan::call('migrate:rollback', [
                '--path' => $this->migrationPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Site Mover plugin uninstall rollback failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            Schema::disableForeignKeyConstraints();
            Schema::dropIfExists('site_mover_migration_artifacts');
            Schema::dropIfExists('site_mover_migrations');
            Schema::enableForeignKeyConstraints();
        } catch (\Throwable $exception) {
            Log::warning('Site Mover plugin uninstall table cleanup failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function runMigrations(): void
    {
        try {
            Artisan::call('migrate', [
                '--path' => $this->migrationPath(),
                '--realpath' => true,
                '--force' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Site Mover plugin migration failed', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function migrationPath(): string
    {
        return __DIR__.'/Database/migrations';
    }
}
