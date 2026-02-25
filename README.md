# Site Mover Plugin

Laravel site migration plugin for VitoDeploy.

## Features

- `Scan`: discover source site migration surface (storage, DB, cron, workers, Redis usage)
- `Migrate`: run migration from one Vito-managed server to another
- `History`: inspect recent migration statuses and report summaries for a site
- `Validate`: run post-migration checks on target site

## Current Scope

- Migrates app footprint (site config, DB dump/restore, storage paths, cron/jobs/workers)
- Does **not** automate DNS or reverse-proxy cutover

## Installation (Local Development)

If your plugin repository is `gryphiusz/vitodeploy-laravel-sitemover`, place it at:

`app/Vito/Plugins/Gryphiusz/VitodeployLaravelSitemover`

Then in Vito UI:

1. Go to `Admin > Plugins > Discover`
2. Install and enable `Site Mover`

On install/enable, plugin migrations are executed from:

`Database/migrations`

## Usage

1. Open a Laravel site
2. Go to `Features > Migrate Site`
3. Run `Scan` first
4. Run `Migrate` with target server/domain/options
5. Run `History` to inspect status/report summary for recent migrations
6. Run `Validate` using target site ID

## Notes

- The migration job runs on queue `default` and relies on Vito background workers.
- Large file transfers can take time; check plugin logs and migration records.
- On plugin uninstall, the plugin attempts rollback and then force-drops its own tables.
