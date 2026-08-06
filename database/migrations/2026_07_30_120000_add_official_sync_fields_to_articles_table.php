<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'official_external_id')) {
                $table->string('official_external_id', 120)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('articles', 'official_remote_id')) {
                $table->string('official_remote_id', 120)->nullable()->after('official_external_id');
            }
            if (! Schema::hasColumn('articles', 'official_url')) {
                $table->string('official_url', 500)->nullable()->after('official_remote_id');
            }
            if (! Schema::hasColumn('articles', 'official_sync_status')) {
                $table->string('official_sync_status', 20)->nullable()->after('official_url');
            }
            if (! Schema::hasColumn('articles', 'official_synced_at')) {
                $table->timestamp('official_synced_at')->nullable()->after('official_sync_status');
            }
            if (! Schema::hasColumn('articles', 'official_last_error')) {
                $table->text('official_last_error')->nullable()->after('official_synced_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            foreach ([
                'official_last_error',
                'official_synced_at',
                'official_sync_status',
                'official_url',
                'official_remote_id',
                'official_external_id',
            ] as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
