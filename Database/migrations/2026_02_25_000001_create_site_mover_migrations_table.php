<?php

use App\Vito\Plugins\Arnobolt\SiteMover\Enums\MigrationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_mover_migrations')) {
            return;
        }

        Schema::create('site_mover_migrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_site_id');
            $table->unsignedBigInteger('source_server_id');
            $table->unsignedBigInteger('target_server_id')->nullable();
            $table->unsignedBigInteger('target_site_id')->nullable();
            $table->string('status')->default(MigrationStatus::QUEUED->value);
            $table->longText('manifest_json')->nullable();
            $table->longText('options_json')->nullable();
            $table->longText('report_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_site_id', 'created_at']);
            $table->index(['target_server_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_mover_migrations');
    }
};
