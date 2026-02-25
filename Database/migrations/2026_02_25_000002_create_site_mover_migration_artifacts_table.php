<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_mover_migration_artifacts')) {
            return;
        }

        Schema::create('site_mover_migration_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('migration_id');
            $table->string('type');
            $table->string('ref')->nullable();
            $table->text('path');
            $table->string('checksum')->nullable();
            $table->longText('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('migration_id')
                ->references('id')
                ->on('site_mover_migrations')
                ->onDelete('cascade');

            $table->index(['migration_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_mover_migration_artifacts');
    }
};
