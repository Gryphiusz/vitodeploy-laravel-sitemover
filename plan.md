# VitoDeploy Plugin Plan - Laravel Site Migration (Redis + Horizon)

## Goal
Add a guided migration feature inside VitoDeploy to move one Laravel site from Server A to Server B (both managed by the same Vito instance), including:
- Site creation + deploy configuration (repo, branch, deploy script, PHP version)
- Storage files (for selected paths)
- Database data + database user mapping/creation
- Cron jobs
- Workers/Horizon setup
- Redis usage verification
- Post-migration validation report

This is not a full server snapshot/clone tool. It migrates the app footprint of a single site.

## Explicit Scope Constraints
- DNS updates are out of scope for now.
- You are fronting the app with Nginx reverse proxy, so plugin cutover ends at "target site is ready and validated".
- Any proxy upstream switch is an external/manual step (outside this plugin).

## References
- Plugin development docs: https://vitodeploy.com/docs/plugins#plugin-development
- Docs source (v3.x): https://github.com/vitodeploy/vitodeploy.com/blob/main/versioned_docs/version-3.x/plugins.md
- Example plugin (Laravel Octane): https://github.com/vitodeploy/laravel-octane-plugin
- Vito source tree (3.x): https://github.com/vitodeploy/vito/tree/3.x

## Vito Plugin Contract Alignment
Use the extension points exactly as documented and implemented:
- Plugin root class must be `Plugin.php` extending `App\Plugins\AbstractPlugin`.
- Register feature/action in `boot()` via:
  - `App\Plugins\RegisterSiteFeature`
  - `App\Plugins\RegisterSiteFeatureAction`
- Site feature action handlers should extend `App\SiteFeatures\Action` and implement:
  - `name(): string`
  - `active(): bool`
  - `form(): ?App\DTOs\DynamicForm`
  - `handle(Illuminate\Http\Request): void`
- Optional workflow action registration uses `App\Plugins\RegisterWorkflowAction` and handler classes implementing `App\WorkflowActions\WorkflowActionInterface` (or extending `AbstractWorkflowAction`).
- Plugin lifecycle hooks are available in `Plugin.php`: `install()`, `uninstall()`, `enable()`, `disable()`.

## UX / Entry Points
### A) Site Feature + Actions (Primary MVP)
Register a Laravel site feature:
- Feature key: `laravel-migrate`
- Actions:
  1. `scan` (read-only): discover source topology and show migration manifest preview
  2. `migrate` (write): execute migration to target server/site
  3. `validate` (read-only): run checks against target and show report

### B) Optional Workflow Action (v1.1)
- Register workflow action key: `migrate-site`
- Purpose: allow chaining in Workflows after initial site/server prep

## Execution Model
Migration can be long-running, so the feature action should not perform all heavy work inline in HTTP request lifecycle.

Recommended pattern:
1. `Migrate` action validates input and creates migration record (`QUEUED`).
2. Dispatch `RunSiteMigration` job.
3. Job updates migration status through phases and writes logs/report.
4. UI action returns quickly with status pointer.

## Core Design
Split into 3 phases and persist a `MigrationManifest`.

### Phase 1 - DISCOVER (Source)
Collect all data required to recreate behavior on target:
- Site metadata:
  - `site_id`, `server_id`, `type`, `path`, `domain`, `aliases`
  - `repository`, `branch`, deployment script, PHP version, site user
- Storage paths:
  - default `storage/app/public`
  - optional user-defined paths (`textarea`, newline-separated input)
- Database:
  - source database name + engine + charset/collation
  - linked database users, host, permission model
- Cron jobs:
  - only cron jobs linked to this site (via `site_id`), plus scheduler command detection
- Workers/Horizon:
  - worker rows linked to site
  - command patterns (`horizon` vs `queue:work`)
  - `numprocs`, autorestart/autostart, user, etc.
- Redis:
  - detect Redis usage from env keys (do not expose secret values)
  - optional connectivity check (`redis-cli ping` or artisan-level check)

Output:
- `manifest_json` persisted in plugin DB

### Phase 2 - TRANSFER (Artifacts)
Preferred order:
1. Use Vito backup actions/models for artifact creation and restore compatibility.
2. Fall back to SSH-native dump/archive transfer only if needed.

Artifacts:
- DB backup file ref
- One or more file backup refs for storage paths

Important note when using Vito backup APIs:
- Creating backups via internal actions requires a storage provider and creates backup records.
- Use temporary backup records and clean them after migration to avoid leaving periodic backup config unintentionally.

### Phase 3 - RESTORE & REPRODUCE (Target)
1. Preflight checks:
   - required services available on target (webserver/php/database/redis/process manager)
   - target PHP version compatibility
2. Create target Laravel site:
   - use internal site creation flow (`CreateSite`) with target domain/user/path strategy
   - apply repo/branch/deploy config
3. Configure env:
   - secure env copy strategy (`UpdateEnv` or SSH write)
   - never reveal secret values in UI
4. Database:
   - create DB (charset/collation)
   - create/link DB users
   - restore DB backup into target DB
5. Files:
   - restore selected storage paths into target site path
   - run `php artisan storage:link` where applicable
6. Workers/Horizon:
   - recreate worker entries (`CreateWorker`) with equivalent commands/settings
   - ensure horizon/queue workers are running
7. Cron jobs:
   - recreate site-level cron jobs (`CreateCronJob`)
8. Deploy + cache hygiene:
   - run deployment
   - optional post commands (`migrate --force`, cache clears)
9. Validation:
   - HTTP check URL (`/` default)
   - `php artisan about` / version check
   - DB connectivity (`migrate:status` or equivalent)
   - Redis connectivity
   - worker/horizon process status

## Safety / Downtime Modes (No DNS)
Provide two modes:
- `test`: full migration + validation on target only (no routing changes)
- `final-sync`: re-run latest DB/files sync before validation (still no DNS/proxy automation)

## Security / Secrets
- Never expose `.env` secrets in forms/logs/reports.
- Store sensitive runtime values encrypted where possible.
- Redact command output in migration logs if secrets may appear.
- Provide explicit `include_env` toggle with warning alert in form.

## Data Model (Plugin-Owned)
Use plugin-specific table names to avoid collisions:
- `site_mover_migrations`
  - `id`, `source_site_id`, `source_server_id`, `target_server_id`
  - `status`, `manifest_json`, `options_json`
  - `started_at`, `finished_at`, `error_message`
- `site_mover_migration_artifacts`
  - `id`, `migration_id`, `type`, `ref`, `path`, `checksum`, `metadata_json`

Status enum example:
- `QUEUED`, `DISCOVERING`, `BACKING_UP`, `RESTORING`, `VALIDATING`, `SUCCESS`, `FAILED`

## File/Folder Structure
`app/Vito/Plugins/<YourUsername>/<YourRepoName>/`

Suggested structure:
- `Plugin.php`
- `Actions/`
  - `Scan.php`
  - `Migrate.php`
  - `Validate.php`
- `Jobs/`
  - `RunSiteMigration.php`
- `DTO/`
  - `MigrationManifest.php`
  - `MigrationOptions.php`
- `Services/`
  - `DiscoveryService.php`
  - `BackupService.php`
  - `RestoreService.php`
  - `ValidationService.php`
  - `WorkerMigrationService.php`
  - `CronMigrationService.php`
  - `RedisCheckService.php`
- `Database/migrations/`
- `Views/` (optional, if `RegisterViews` is used)
- `WorkflowActions/` (optional, for v1.1)

## Plugin.php Registration Skeleton
- Register feature:
  - `RegisterSiteFeature::make('laravel', 'laravel-migrate')`
- Register actions:
  - `scan`, `migrate`, `validate` via `RegisterSiteFeatureAction`
- Keep primary action forms in action classes (`form()`), so each action has access to `$this->site`.

Recommended migrate form fields:
- `target_server_id` (`select`)
- `target_domain` (`text`)
- `target_user` (`text`, optional)
- `target_site_name` (`text`, optional)
- `db_name` (`text`, default derived)
- `db_user_strategy` (`select`: clone/create/map)
- `storage_paths` (`textarea`, newline separated)
- `include_env` (`checkbox`)
- `horizon_mode` (`select`: auto/horizon/queue-work)
- `downtime_mode` (`select`: test/final-sync)
- `healthcheck_url` (`text`)

## Install/Uninstall Hooks
Use plugin lifecycle hooks for plugin-owned schema:
- `install()`: run plugin migrations / bootstrap defaults
- `uninstall()`: optionally clean plugin tables/artifacts (or keep history if preferred)

## Integration Points in Vito Source (3.x)
Prefer internal actions/services over direct raw SQL/model mutation:
- Site: `App\Actions\Site\CreateSite`, `Deploy`, `UpdateEnv`, etc.
- Database: `App\Actions\Database\CreateDatabase`, `CreateDatabaseUser`
- Cron: `App\Actions\CronJob\CreateCronJob`
- Workers: `App\Actions\Worker\CreateWorker`
- Backup/Restore: `App\Actions\Backup\ManageBackup`, `RunBackup`, `RestoreBackup`
- SSH command execution: server/site SSH helpers already used by built-in actions

## Pseudocode (Core Flow)
`MigrateAction::handle(Request $request)`:
1. validate form input
2. create migration row (`QUEUED`)
3. dispatch `RunSiteMigration(migrationId)`
4. flash success with "migration started"

`RunSiteMigration::handle()`:
1. set status `DISCOVERING`, build manifest
2. set status `BACKING_UP`, create artifacts
3. set status `RESTORING`, create target + restore DB/files + recreate workers/crons
4. set status `VALIDATING`, run checks and build report
5. set status `SUCCESS` or `FAILED`, persist error/report

## Definition of "Simple"
1. Open site -> Features -> `Migrate Site`
2. Click `Scan` to preview what will move
3. Click `Migrate`, select target + options
4. Wait for completion and review validation report
5. (External) update Nginx reverse-proxy routing when you decide to cut over

## MVP Scope
Must-have:
- Site discovery manifest
- DB + storage migration
- Cron recreation
- Worker/Horizon recreation
- Redis usage/connectivity validation
- End-to-end validation report
- No DNS/proxy automation

Nice-to-have:
- Optional workflow action
- Final-sync mode improvements
- Custom plugin view pages for richer progress UI
