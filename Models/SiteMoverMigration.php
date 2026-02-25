<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models;

use App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Enums\MigrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $source_site_id
 * @property int $source_server_id
 * @property ?int $target_server_id
 * @property ?int $target_site_id
 * @property MigrationStatus $status
 * @property array<string, mixed>|null $manifest_json
 * @property array<string, mixed>|null $options_json
 * @property array<string, mixed>|null $report_json
 * @property ?string $error_message
 */
class SiteMoverMigration extends Model
{
    protected $table = 'site_mover_migrations';

    protected $fillable = [
        'source_site_id',
        'source_server_id',
        'target_server_id',
        'target_site_id',
        'status',
        'manifest_json',
        'options_json',
        'report_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'source_site_id' => 'integer',
        'source_server_id' => 'integer',
        'target_server_id' => 'integer',
        'target_site_id' => 'integer',
        'status' => MigrationStatus::class,
        'manifest_json' => 'array',
        'options_json' => 'array',
        'report_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<SiteMoverMigrationArtifact, covariant $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(SiteMoverMigrationArtifact::class, 'migration_id');
    }
}
