<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $migration_id
 * @property string $type
 * @property ?string $ref
 * @property string $path
 * @property ?string $checksum
 * @property array<string, mixed>|null $metadata_json
 */
class SiteMoverMigrationArtifact extends Model
{
    protected $table = 'site_mover_migration_artifacts';

    protected $fillable = [
        'migration_id',
        'type',
        'ref',
        'path',
        'checksum',
        'metadata_json',
    ];

    protected $casts = [
        'migration_id' => 'integer',
        'metadata_json' => 'array',
    ];

    /**
     * @return BelongsTo<SiteMoverMigration, covariant $this>
     */
    public function migration(): BelongsTo
    {
        return $this->belongsTo(SiteMoverMigration::class, 'migration_id');
    }
}
