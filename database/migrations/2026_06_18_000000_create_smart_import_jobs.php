<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20)->comment('url / markdown');
            $table->string('article_type', 20)->comment('jiey_ide / project');
            $table->json('input_data')->nullable()->comment('原始请求参数');
            $table->string('status', 20)->default('queued')->comment('queued / processing / completed / failed');
            $table->string('current_step', 30)->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->json('result_json')->nullable()->comment('完成后写入素材/任务 ID');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_import_jobs');
    }
};
