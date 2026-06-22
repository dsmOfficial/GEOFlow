<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartImportJob extends Model
{
    protected $table = 'smart_import_jobs';

    protected $fillable = [
        'source_type',
        'article_type',
        'input_data',
        'status',
        'current_step',
        'progress_percent',
        'result_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'result_json' => 'array',
            'progress_percent' => 'integer',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function markProcessing(string $step, int $progress): void
    {
        $this->update([
            'status' => 'processing',
            'current_step' => $step,
            'progress_percent' => $progress,
        ]);
    }

    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => 'completed',
            'current_step' => 'completed',
            'progress_percent' => 100,
            'result_json' => $result,
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'current_step' => 'failed',
            'progress_percent' => 100,
            'error_message' => $errorMessage,
        ]);
    }
}
