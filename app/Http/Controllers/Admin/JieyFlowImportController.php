<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSmartImportJob;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SmartImportJob;
use App\Services\GeoFlow\JieyInternalFlowClient;
use App\Services\GeoFlow\JieyProjectRolePromptCatalog;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台 Jiey Flow 项目导入：填写 project_id 后走 SmartImport 全链路。
 */
class JieyFlowImportController extends Controller
{
    public function __construct(
        private readonly JieyInternalFlowClient $jieyClient,
        private readonly UrlImportProcessingService $urlImportProcessingService,
        private readonly JieyProjectRolePromptCatalog $rolePromptCatalog,
    ) {}

    public function index(): View
    {
        $rolePromptOptions = $this->rolePromptCatalog->options();
        $defaultPromptId = (int) ($rolePromptOptions[0]['id'] ?? 0);

        return view('admin.jiey-flow-import.index', [
            'pageTitle' => __('admin.jiey_flow_import.page_title'),
            'activeMenu' => 'materials',
            'jieyReady' => $this->isJieyReady(),
            'jieyStatusMessage' => $this->jieyStatusMessage(),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel(),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
            'promptOptions' => $this->loadPromptOptions(),
            'rolePromptOptions' => $rolePromptOptions,
            'defaultPromptId' => $defaultPromptId,
            'knowledgeBaseOptions' => $this->loadKnowledgeBaseOptions(),
            'imageLibraryOptions' => $this->loadImageLibraryOptions(),
            'maxArticleImages' => max(0, (int) config('geoflow.max_article_images', 10)),
            'recentJobs' => SmartImportJob::query()
                ->where('source_type', 'jiey_flow')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'article_type' => ['required', 'in:jiey_ide,project'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'project_description' => ['nullable', 'string', 'max:1000'],
            'article_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'include_unpublished' => ['nullable', 'boolean'],
            'artifact_type_slugs' => ['nullable', 'string', 'max:500'],
            'prompt_id' => ['nullable', 'integer', 'min:0'],
            'extra_knowledge_base_ids' => ['nullable', 'array', 'max:4'],
            'extra_knowledge_base_ids.*' => ['integer', 'min:1'],
            'image_library_id' => ['nullable', 'integer', 'min:0'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:'.max(0, (int) config('geoflow.max_article_images', 10))],
        ]);

        if (! $this->isJieyReady()) {
            return back()
                ->withInput()
                ->withErrors(['project_id' => $this->jieyStatusMessage()]);
        }

        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.ai-models.index')
                ->withInput()
                ->withErrors(['ai_model' => $exception->getMessage()]);
        }

        $promptId = max(0, (int) ($validated['prompt_id'] ?? 0));
        if ($promptId > 0 && ! Prompt::query()->whereKey($promptId)->where('type', 'content')->exists()) {
            return back()
                ->withInput()
                ->withErrors(['prompt_id' => __('admin.jiey_flow_import.error.prompt_invalid')]);
        }

        $extraKnowledgeBaseIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            is_array($validated['extra_knowledge_base_ids'] ?? null) ? $validated['extra_knowledge_base_ids'] : []
        ), static fn (int $id): bool => $id > 0)));
        if (count($extraKnowledgeBaseIds) > 4) {
            $extraKnowledgeBaseIds = array_slice($extraKnowledgeBaseIds, 0, 4);
        }
        if ($extraKnowledgeBaseIds !== []) {
            $existingCount = KnowledgeBase::query()->whereIn('id', $extraKnowledgeBaseIds)->count();
            if ($existingCount !== count($extraKnowledgeBaseIds)) {
                return back()
                    ->withInput()
                    ->withErrors(['extra_knowledge_base_ids' => __('admin.jiey_flow_import.error.knowledge_base_invalid')]);
            }
        }

        $imageLibraryId = max(0, (int) ($validated['image_library_id'] ?? 0));
        $maxArticleImages = max(0, (int) config('geoflow.max_article_images', 10));
        $imageCount = max(0, min($maxArticleImages, (int) ($validated['image_count'] ?? 0)));
        if ($imageLibraryId > 0 && ! ImageLibrary::query()->whereKey($imageLibraryId)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['image_library_id' => __('admin.jiey_flow_import.error.image_library_invalid')]);
        }
        if ($imageLibraryId <= 0) {
            $imageCount = 0;
        } elseif ($imageCount <= 0) {
            $imageCount = 1;
        }

        $projectId = (int) $validated['project_id'];
        $articleType = (string) $validated['article_type'];
        $articleCount = max(1, min(50, (int) ($validated['article_count'] ?? 10)));
        $slugRaw = trim((string) ($validated['artifact_type_slugs'] ?? ''));
        $slugs = $slugRaw === ''
            ? null
            : array_values(array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', $slugRaw)
            )));

        $inputData = [
            'source_type' => 'jiey_flow',
            'article_type' => $articleType,
            'project_id' => $projectId,
            'project_name' => trim((string) ($validated['project_name'] ?? '')),
            'project_description' => trim((string) ($validated['project_description'] ?? '')),
            'article_count' => $articleCount,
            'include_unpublished' => (bool) ($validated['include_unpublished'] ?? false),
            'prompt_id' => $promptId,
            'extra_knowledge_base_ids' => $extraKnowledgeBaseIds,
            'image_library_id' => $imageLibraryId > 0 ? $imageLibraryId : null,
            'image_count' => $imageCount,
        ];
        if (is_array($slugs) && $slugs !== []) {
            $inputData['artifact_type_slugs'] = $slugs;
        }

        $job = SmartImportJob::query()->create([
            'source_type' => 'jiey_flow',
            'article_type' => $articleType,
            'input_data' => $inputData,
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
        ]);

        ProcessSmartImportJob::dispatch((int) $job->id)->onQueue('geoflow');

        return redirect()
            ->route('admin.jiey-flow-import.show', ['jobId' => (int) $job->id])
            ->with('message', __('admin.jiey_flow_import.message.queued', ['id' => (int) $job->id]));
    }

    public function history(): View
    {
        $jobs = SmartImportJob::query()
            ->where('source_type', 'jiey_flow')
            ->orderByDesc('id')
            ->paginate(20);

        $stats = [
            'total' => (int) SmartImportJob::query()->where('source_type', 'jiey_flow')->count(),
            'queued' => (int) SmartImportJob::query()->where('source_type', 'jiey_flow')->where('status', 'queued')->count(),
            'processing' => (int) SmartImportJob::query()->where('source_type', 'jiey_flow')->where('status', 'processing')->count(),
            'completed' => (int) SmartImportJob::query()->where('source_type', 'jiey_flow')->where('status', 'completed')->count(),
            'failed' => (int) SmartImportJob::query()->where('source_type', 'jiey_flow')->where('status', 'failed')->count(),
        ];

        return view('admin.jiey-flow-import.history', [
            'pageTitle' => __('admin.jiey_flow_import.history_page_title'),
            'activeMenu' => 'materials',
            'jobs' => $jobs,
            'stats' => $stats,
        ]);
    }

    public function show(int $jobId): View
    {
        $job = SmartImportJob::query()
            ->where('source_type', 'jiey_flow')
            ->whereKey($jobId)
            ->firstOrFail();

        return view('admin.jiey-flow-import.show', [
            'pageTitle' => __('admin.jiey_flow_import.show_page_title', ['id' => (int) $job->id]),
            'activeMenu' => 'materials',
            'job' => $job,
            'input' => is_array($job->input_data) ? $job->input_data : [],
            'result' => is_array($job->result_json) ? $job->result_json : [],
            'steps' => $this->workflowSteps(),
        ]);
    }

    public function status(int $jobId): JsonResponse
    {
        $job = SmartImportJob::query()
            ->where('source_type', 'jiey_flow')
            ->whereKey($jobId)
            ->firstOrFail();

        return response()->json($this->statusPayload($job));
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function loadPromptOptions(): array
    {
        $this->rolePromptCatalog->ensureInstalled();

        return Prompt::query()
            ->where('type', 'content')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Prompt $prompt): array => [
                'id' => (int) $prompt->id,
                'name' => (string) $prompt->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function loadKnowledgeBaseOptions(): array
    {
        return KnowledgeBase::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(static fn (KnowledgeBase $kb): array => [
                'id' => (int) $kb->id,
                'name' => (string) $kb->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,name:string,count:int}>
     */
    private function loadImageLibraryOptions(): array
    {
        return ImageLibrary::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'image_count'])
            ->map(static fn (ImageLibrary $library): array => [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'count' => (int) ($library->image_count ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(SmartImportJob $job): array
    {
        $input = is_array($job->input_data) ? $job->input_data : [];
        $result = is_array($job->result_json) ? $job->result_json : [];

        return [
            'job_id' => (int) $job->id,
            'status' => (string) $job->status,
            'current_step' => (string) ($job->current_step ?? 'queued'),
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) ($job->error_message ?? ''),
            'project_id' => (int) ($input['project_id'] ?? 0),
            'project_name' => (string) ($input['project_name'] ?? ''),
            'result' => $result,
            'is_finished' => $job->isFinished(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workflowSteps(): array
    {
        return [
            'queued' => __('admin.jiey_flow_import.workflow.queued'),
            'parsing_input' => __('admin.jiey_flow_import.workflow.parsing_input'),
            'fetching_jiey_artifacts' => __('admin.jiey_flow_import.workflow.fetching_jiey_artifacts'),
            'normalizing_artifacts' => __('admin.jiey_flow_import.workflow.normalizing_artifacts'),
            'analyzing_markdown' => __('admin.jiey_flow_import.workflow.analyzing_markdown'),
            'committing_materials' => __('admin.jiey_flow_import.workflow.committing_materials'),
            'ensuring_prompt' => __('admin.jiey_flow_import.workflow.ensuring_prompt'),
            'creating_task' => __('admin.jiey_flow_import.workflow.creating_task'),
            'enqueuing' => __('admin.jiey_flow_import.workflow.enqueuing'),
            'completed' => __('admin.jiey_flow_import.workflow.completed'),
        ];
    }

    private function isJieyReady(): bool
    {
        try {
            $this->jieyClient->assertConfigured();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function jieyStatusMessage(): string
    {
        try {
            $this->jieyClient->assertConfigured();

            return __('admin.jiey_flow_import.config.ready');
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
