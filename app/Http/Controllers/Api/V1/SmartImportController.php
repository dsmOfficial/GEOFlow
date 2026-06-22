<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Jobs\ProcessSmartImportJob;
use App\Models\SmartImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartImportController extends BaseApiController
{
    /**
     * 发起智能导入（公开接口，无需认证）。
     */
    public function store(Request $request): JsonResponse
    {
        $this->validateStoreRequest($request);

        $sourceType = trim((string) $request->input('source_type'));
        $articleType = trim((string) $request->input('article_type'));
        $articleCount = max(1, min(50, (int) $request->input('article_count', 10)));

        $inputData = $request->only([
            'source_type', 'article_type', 'url', 'markdown_content',
            'markdown_name', 'article_count', 'project_name',
            'project_description', 'model_id',
        ]);
        $inputData['article_count'] = $articleCount;

        $job = SmartImportJob::query()->create([
            'source_type' => $sourceType,
            'article_type' => $articleType,
            'input_data' => $inputData,
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
        ]);

        ProcessSmartImportJob::dispatch((int) $job->id)->onQueue('geoflow');

        return $this->success($request, [
            'job_id' => (int) $job->id,
            'status' => 'queued',
            'source_type' => $sourceType,
            'article_type' => $articleType,
            'article_count' => $articleCount,
            'created_at' => $job->created_at?->toIso8601String(),
        ], 202);
    }

    /**
     * 查询智能导入进度（公开接口，无需认证）。
     */
    public function show(Request $request, int $job): JsonResponse
    {
        $smartImportJob = SmartImportJob::query()->find($job);

        if (! $smartImportJob) {
            throw new ApiException('job_not_found', 'Smart import job 不存在', 404);
        }

        $data = [
            'job_id' => (int) $smartImportJob->id,
            'status' => (string) $smartImportJob->status,
            'source_type' => (string) $smartImportJob->source_type,
            'article_type' => (string) $smartImportJob->article_type,
            'current_step' => $smartImportJob->current_step,
            'progress_percent' => (int) $smartImportJob->progress_percent,
            'result' => $smartImportJob->result_json,
            'error_message' => $smartImportJob->error_message,
            'created_at' => $smartImportJob->created_at?->toIso8601String(),
            'updated_at' => $smartImportJob->updated_at?->toIso8601String(),
        ];

        return $this->success($request, $data);
    }

    private function validateStoreRequest(Request $request): void
    {
        $sourceType = trim((string) $request->input('source_type'));
        $articleType = trim((string) $request->input('article_type'));

        $fieldErrors = [];

        if (! in_array($sourceType, ['url', 'markdown'], true)) {
            $fieldErrors['source_type'] = 'source_type 必须是 url 或 markdown';
        }

        if (! in_array($articleType, ['jiey_ide', 'project'], true)) {
            $fieldErrors['article_type'] = 'article_type 必须是 jiey_ide 或 project';
        }

        if ($sourceType === 'url') {
            $url = trim((string) $request->input('url'));
            if ($url === '') {
                $fieldErrors['url'] = 'URL 模式时 url 不能为空';
            }
        }

        if ($sourceType === 'markdown') {
            $content = trim((string) $request->input('markdown_content'));
            if ($content === '') {
                $fieldErrors['markdown_content'] = 'Markdown 模式时 markdown_content 不能为空';
            }
        }

        if ($articleType === 'project') {
            $projectName = trim((string) $request->input('project_name'));
            if ($projectName === '') {
                $fieldErrors['project_name'] = 'project 模式建议提供 project_name';
            }
        }

        if (! empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }
    }
}
