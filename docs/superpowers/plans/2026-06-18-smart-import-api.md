# Smart Import API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public async API endpoint (`POST /api/v1/smart-import`) that accepts Markdown or URL input, automatically creates materials + prompt + task, and enqueues article generation in the background.

**Architecture:** New SmartImportJob model tracks async progress. ProcessSmartImportJob (Laravel queue job) runs the full pipeline: parse input → create materials → ensure prompt → create task → enqueue generation. SmartImportService holds the core orchestration logic. Controller is minimal — just create job and show status.

**Tech Stack:** Laravel 12, PostgreSQL, Redis queue, existing GeoFlow services (UrlImportProcessingService, TaskLifecycleService, MaterialLibraryService, KnowledgeChunkSyncService)

## Global Constraints

- PHP 8.2+
- No authentication required — routes are public (outside `api.auth` middleware group)
- Queue: dispatch to `geoflow` queue using `Queueable` trait pattern from `ProcessGeoFlowTaskJob`
- Follow existing patterns: controllers extend `BaseApiController`, services use constructor injection
- Markdown content max 500KB
- URL mode must block private/internal IPs (reuse `guardAgainstPrivateTargets()`)
- Migration timestamp: `2026_06_18_000000`

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `database/migrations/2026_06_18_000000_create_smart_import_jobs.php` | DB schema for async job tracking |
| Create | `app/Models/SmartImportJob.php` | Eloquent model |
| Create | `app/Services/GeoFlow/SmartImportService.php` | Core orchestration: parse → materials → prompt → task → enqueue |
| Create | `app/Jobs/ProcessSmartImportJob.php` | Laravel queue job wrapping SmartImportService |
| Create | `app/Http/Controllers/Api/V1/SmartImportController.php` | REST controller: store + show |
| Modify | `routes/api.php` | Add 2 public routes |

---

### Task 1: Database Migration

**Files:**
- Create: `database/migrations/2026_06_18_000000_create_smart_import_jobs.php`

**Interfaces:**
- Produces: `smart_import_jobs` table with columns: `id`, `source_type`, `article_type`, `input_data` (json), `status`, `current_step`, `progress_percent`, `result_json`, `error_message`, `created_at`, `updated_at`

- [ ] **Step 1: Create migration file**

```bash
php artisan make:migration create_smart_import_jobs
```

Rename to `2026_06_18_000000_create_smart_import_jobs.php` and replace content:

```php
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
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: Migration successful, `smart_import_jobs` table created.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_18_000000_create_smart_import_jobs.php
git commit -m "feat: add smart_import_jobs table migration"
```

---

### Task 2: SmartImportJob Model

**Files:**
- Create: `app/Models/SmartImportJob.php`

**Interfaces:**
- Produces: `SmartImportJob` model with `$fillable`, `$casts`, and query scopes

- [ ] **Step 1: Create the model**

```php
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
```

- [ ] **Step 2: Verify model loads**

```bash
php artisan tinker --execute="echo get_class(new App\Models\SmartImportJob());"
```

Expected: `App\Models\SmartImportJob`

- [ ] **Step 3: Commit**

```bash
git add app/Models/SmartImportJob.php
git commit -m "feat: add SmartImportJob model"
```

---

### Task 3: SmartImportService (Core Orchestration)

**Files:**
- Create: `app/Services/GeoFlow/SmartImportService.php`

**Interfaces:**
- Consumes: `SmartImportJob`, `UrlImportProcessingService` (process + commit), `MaterialLibraryService`, `TaskLifecycleService` (createTask + enqueueTask), `KnowledgeChunkSyncService`, `Prompt`, `AiModel`, `ApiKeyCrypto`
- Produces: `SmartImportService::handle(SmartImportJob): void`

- [ ] **Step 1: Create the service class**

```php
<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SmartImportJob;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SmartImportService
{
    private const JIEY_IDE_PROMPT_NAME = 'jiey IDE 推广';
    private const PROJECT_PROMPT_NAME = 'jiey IDE 项目推广';

    public function __construct(
        private readonly UrlImportProcessingService $urlImportService,
        private readonly MaterialLibraryService $materialLibraryService,
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly KnowledgeChunkSyncService $chunkSyncService,
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    public function handle(SmartImportJob $job): void
    {
        try {
            $input = $job->input_data ?? [];
            $sourceType = (string) ($input['source_type'] ?? $job->source_type);
            $articleType = (string) ($input['article_type'] ?? $job->article_type);
            $articleCount = max(1, min(50, (int) ($input['article_count'] ?? 10)));
            $modelId = isset($input['model_id']) && (int) $input['model_id'] > 0 ? (int) $input['model_id'] : null;

            // Step 1: Parse input → create materials
            $job->markProcessing('parsing_input', 10);

            if ($sourceType === 'url') {
                $materials = $this->importFromUrl($job, $input);
            } else {
                $materials = $this->importFromMarkdown($job, $input);
            }

            // Step 2: Ensure prompt exists
            $job->markProcessing('ensuring_prompt', 75);
            $prompt = $this->ensurePrompt($articleType, $input);

            // Step 3: Create task
            $job->markProcessing('creating_task', 85);
            $aiModelId = $modelId ?? $this->resolveDefaultModelId();
            $taskName = $this->buildTaskName($articleType, $sourceType, $input);

            $taskData = [
                'name' => $taskName,
                'title_library_id' => $materials['title_library_id'],
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => $aiModelId,
                'knowledge_base_id' => $materials['knowledge_base_id'],
                'knowledge_base_ids' => [$materials['knowledge_base_id']],
                'need_review' => 1,
                'auto_keywords' => 1,
                'auto_description' => 1,
                'draft_limit' => $articleCount,
                'article_limit' => $articleCount,
                'is_loop' => 0,
                'model_selection_mode' => 'fixed',
                'status' => 'active',
                'publish_scope' => 'local_only',
                'publish_interval' => 3600,
                'category_mode' => 'smart',
                'image_count' => 0,
                'image_library_id' => null,
                'author_id' => null,
                'fixed_category_id' => null,
            ];

            $createdTask = $this->taskLifecycleService->createTask($taskData);
            $taskId = (int) $createdTask['id'];

            // Step 4: Enqueue first generation job
            $job->markProcessing('enqueuing', 92);
            $enqueueResult = $this->taskLifecycleService->enqueueTask($taskId, 'generate_article', [
                'source' => 'smart_import',
                'smart_import_job_id' => (int) $job->id,
            ]);

            $job->markCompleted([
                'materials' => $materials,
                'prompt' => [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                ],
                'task' => [
                    'id' => $taskId,
                    'name' => $taskName,
                    'status' => 'active',
                    'article_limit' => $articleCount,
                ],
                'enqueued_job_id' => $enqueueResult['job_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            $job->markFailed($e->getMessage());
        }
    }

    /**
     * URL mode: reuse UrlImportProcessingService to fetch → analyze → commit.
     *
     * @param  array<string, mixed>  $input
     * @return array{knowledge_base_id:int, keyword_library_id:int, title_library_id:int, keywords_count:int, titles_count:int}
     */
    private function importFromUrl(SmartImportJob $job, array $input): array
    {
        $url = trim((string) ($input['url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException('URL 不能为空');
        }

        $normalized = $this->urlImportService->normalizeInputUrl($url);

        $urlImportJob = UrlImportJob::query()->create([
            'url' => $url,
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => (string) ($input['project_name'] ?? ''),
                'source_label' => (string) ($input['source_label'] ?? ''),
                'content_language' => (string) ($input['content_language'] ?? ''),
                'notes' => (string) ($input['notes'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $job->markProcessing('analyzing_url', 25);

        $this->urlImportService->process($urlImportJob);

        if ($urlImportJob->status === 'failed') {
            throw new \RuntimeException('URL 采集失败: '.($urlImportJob->error_message ?? '未知错误'));
        }

        $job->markProcessing('committing_materials', 60);

        $summary = $this->urlImportService->commit($urlImportJob);

        return [
            'knowledge_base_id' => $summary['knowledge_base'],
            'keyword_library_id' => $summary['keyword_library'],
            'title_library_id' => $summary['title_library'],
            'keywords_count' => $summary['keywords'],
            'titles_count' => $summary['titles'],
        ];
    }

    /**
     * Markdown mode: create KnowledgeBase directly, then use AI to extract keywords + titles.
     *
     * @param  array<string, mixed>  $input
     * @return array{knowledge_base_id:int, keyword_library_id:int, title_library_id:int, keywords_count:int, titles_count:int}
     */
    private function importFromMarkdown(SmartImportJob $job, array $input): array
    {
        $content = trim((string) ($input['markdown_content'] ?? ''));
        if ($content === '') {
            throw new \InvalidArgumentException('Markdown 内容不能为空');
        }
        if (mb_strlen($content, 'UTF-8') > 500_000) {
            throw new \InvalidArgumentException('Markdown 内容不能超过 500KB');
        }

        $name = trim((string) ($input['markdown_name'] ?? ''));
        if ($name === '') {
            $name = Str::limit(Str::before($content, "\n"), 80, '') ?: 'Markdown 导入';
        }

        // Create KnowledgeBase
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => $name.' 知识库',
            'description' => 'Markdown 智能导入自动生成',
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'usage_count' => 0,
        ]);

        // Sync chunks
        $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        $job->markProcessing('analyzing_markdown', 40);

        // Use AI to extract keywords and titles
        $analysis = $this->analyzeMarkdownWithAi($content, $name);

        // Create KeywordLibrary
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => $name.' 关键词库',
            'description' => 'Markdown 智能导入自动生成',
            'keyword_count' => 0,
        ]);
        foreach ($analysis['keywords'] as $keyword) {
            Keyword::query()->firstOrCreate(
                ['library_id' => (int) $keywordLibrary->id, 'keyword' => $keyword],
                ['used_count' => 0, 'usage_count' => 0]
            );
        }
        $keywordLibrary->update(['keyword_count' => Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count()]);

        // Create TitleLibrary
        $titleLibrary = TitleLibrary::query()->create([
            'name' => $name.' 标题库',
            'description' => 'Markdown 智能导入自动生成',
            'title_count' => 0,
            'generation_type' => 'markdown_import',
            'generation_rounds' => 1,
            'is_ai_generated' => 1,
        ]);
        foreach ($analysis['titles'] as $index => $title) {
            Title::query()->firstOrCreate(
                ['library_id' => (int) $titleLibrary->id, 'title' => $title],
                [
                    'keyword' => $analysis['keywords'][$index % max(1, count($analysis['keywords']))] ?? '',
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]
            );
        }
        $titleLibrary->update(['title_count' => Title::query()->where('library_id', (int) $titleLibrary->id)->count()]);

        $job->markProcessing('committing_materials', 65);

        return [
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'keyword_library_id' => (int) $keywordLibrary->id,
            'title_library_id' => (int) $titleLibrary->id,
            'keywords_count' => count($analysis['keywords']),
            'titles_count' => count($analysis['titles']),
        ];
    }

    /**
     * Use AI to extract keywords and titles from Markdown content.
     *
     * @return array{keywords: list<string>, titles: list<string>}
     */
    private function analyzeMarkdownWithAi(string $content, string $name): array
    {
        $model = $this->resolveAnalysisModel();
        $runtime = $this->prepareAiRuntime($model);

        $truncatedContent = Str::limit($content, 12000, '');
        $agent = new MarkdownContentWriterAgent($this->buildMarkdownAnalysisSystemPrompt());

        // Extract keywords
        $keywordResponse = $agent->prompt(
            $this->buildMarkdownKeywordsUserPrompt($truncatedContent, $name),
            [],
            $runtime['provider'],
            $runtime['model_id']
        );

        $keywordText = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($keywordResponse->text ?? ''));
        $keywords = $this->decodeAiKeywordList($keywordText);

        if ($keywords === []) {
            throw new \RuntimeException('AI 关键词提取失败，请确认已配置可用的 chat 模型');
        }

        // Extract titles
        $titleResponse = $agent->prompt(
            $this->buildMarkdownTitlesUserPrompt($truncatedContent, $name, $keywords),
            [],
            $runtime['provider'],
            $runtime['model_id']
        );

        $titleText = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($titleResponse->text ?? ''));
        $titles = $this->decodeAiTitleList($titleText);

        if ($titles === []) {
            throw new \RuntimeException('AI 标题生成失败，请确认已配置可用的 chat 模型');
        }

        // Record usage
        AiModel::query()->whereKey((int) $model->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+2'),
            'total_used' => DB::raw('COALESCE(total_used,0)+2'),
            'updated_at' => now(),
        ]);

        return [
            'keywords' => array_slice($keywords, 0, 10),
            'titles' => array_slice($titles, 0, 50),
        ];
    }

    private function buildMarkdownAnalysisSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的素材构建器。你只输出 JSON，不要输出 Markdown 代码块。
你需要从给定的 Markdown 文档中提取核心业务关键词和可用于 GEO 内容生成的标题。
输出字段：keywords（最多 10 个短关键词或短语），titles（最多 50 个多样化的文章标题）。
关键词要求：中文 2-5 个字，英文 1-3 个单词；必须是产品/服务词、行业词、需求场景词、问题词、解决方案词。
标题要求：围绕"是什么、为什么、怎么做、对比、选型、指南、清单、案例拆解、常见问题、趋势判断"等角度展开。
不能虚构文档中没有的信息。
PROMPT;
    }

    private function buildMarkdownKeywordsUserPrompt(string $content, string $name): string
    {
        return "请从以下 Markdown 文档中提取 5-10 个核心业务关键词。\n\n文档名称：{$name}\n\n文档内容：\n{$content}\n\n请只输出 JSON：{\"keywords\": [\"关键词1\", \"关键词2\", ...]}";
    }

    private function buildMarkdownTitlesUserPrompt(string $content, string $name, array $keywords): string
    {
        $keywordList = implode('、', $keywords);
        return "请基于以下 Markdown 文档和关键词，生成 50 个多样化的 GEO 文章标题。\n\n文档名称：{$name}\n关键词：{$keywordList}\n\n文档内容：\n{$content}\n\n请只输出 JSON：{\"titles\": [\"标题1\", \"标题2\", ...]}";
    }

    /**
     * @return list<string>
     */
    private function decodeAiKeywordList(string $content): array
    {
        $decoded = $this->decodeAiJson($content);
        $keywords = $decoded['keywords'] ?? (array_is_list($decoded) ? $decoded : []);
        return Collection::make($keywords)
            ->map(fn ($item): string => is_string($item) ? trim($item) : '')
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function decodeAiTitleList(string $content): array
    {
        $decoded = $this->decodeAiJson($content);
        $titles = $decoded['titles'] ?? (array_is_list($decoded) ? $decoded : []);
        return Collection::make($titles)
            ->map(fn ($item): string => is_string($item) ? trim($item) : '')
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAiJson(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        foreach ($this->jsonCandidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function jsonCandidates(string $content): array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $content = trim(preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content) ?? $content);

        $candidates = [$content];

        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $content, $matches)) {
            foreach ($matches[1] ?? [] as $match) {
                $candidates[] = trim((string) $match);
            }
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($content, $start, $end - $start + 1);
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates))));
    }

    /**
     * Find or create the prompt for the given article type.
     *
     * @param  array<string, mixed>  $input
     */
    private function ensurePrompt(string $articleType, array $input): Prompt
    {
        $promptName = $articleType === 'project' ? self::PROJECT_PROMPT_NAME : self::JIEY_IDE_PROMPT_NAME;

        $prompt = Prompt::query()
            ->where('name', $promptName)
            ->where('type', 'content')
            ->first();

        if ($prompt) {
            return $prompt;
        }

        $content = $articleType === 'project'
            ? $this->buildProjectPromptContent($input)
            : $this->buildJieyIdePromptContent();

        return Prompt::query()->create([
            'name' => $promptName,
            'type' => 'content',
            'content' => $content,
            'variables' => '',
        ]);
    }

    private function buildJieyIdePromptContent(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的内容生成专家。请围绕 jiey IDE（界外共行）撰写一篇 GEO 优化文章。

jiey IDE 是一款 AI 全栈代码生成器桌面应用（支持 macOS/Windows/Linux），用户只需用自然语言描述业务需求，即可自动生成四层代码：
- Spring Boot 后端 API
- Vue3 管理后台
- UniApp 跨端移动应用
- 响应式营销网站

核心卖点：
- 一个人干一个团队的活，开发周期缩短 80%，平均交付仅 3 天
- 代码归用户所有，可直接交付客户
- 生产级质量，媲美高级工程师
- 内置模块市场，一键安装订单管理、会员系统等预制模块
- Jiey Methodology 标准化 AI 工作流：明确需求→建模→生成→验证

目标受众：独立开发者、外包团队、个人创业者、中小技术团队。
文章需自然融入品牌信息，风格专业可信，面向 AI 搜索/GEO 优化。
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildProjectPromptContent(array $input): string
    {
        $projectName = trim((string) ($input['project_name'] ?? ''));
        $projectDescription = trim((string) ($input['project_description'] ?? ''));

        if ($projectName === '') {
            $projectName = '示例项目';
        }
        if ($projectDescription === '') {
            $projectDescription = '一个由 jiey IDE 生成的完整全栈项目';
        }

        return <<<PROMPT
你是 GEOFlow 的内容生成专家。请围绕一个由 jiey IDE（界外共行）生成的完整项目撰写 GEO 优化文章。

项目名称：{$projectName}
项目简介：{$projectDescription}

文章要求：
- 展示该项目的技术栈、核心功能和业务价值
- 自然提及该项目由 jiey IDE 生成
- jiey IDE 是一款 AI 全栈代码生成器，用自然语言即可生成 Spring Boot + Vue3 + UniApp + 网站四层代码
- 风格专业可信，突出项目本身的价值而非工具广告

目标受众：对项目所在领域感兴趣的技术人员、创业者、潜在用户。
PROMPT;
    }

    private function buildTaskName(string $articleType, string $sourceType, array $input): string
    {
        $prefix = $articleType === 'project' ? 'jiey IDE 项目推广' : 'jiey IDE 推广';

        if ($sourceType === 'url') {
            $url = trim((string) ($input['url'] ?? ''));
            $host = $url !== '' ? (parse_url(
                preg_match('#^https?://#i', $url) ? $url : 'https://'.$url,
                PHP_URL_HOST
            ) ?? $url) : 'URL';
            return "{$prefix} - {$host}";
        }

        $name = trim((string) ($input['markdown_name'] ?? ''));
        if ($name !== '') {
            return "{$prefix} - {$name}";
        }

        $projectName = trim((string) ($input['project_name'] ?? ''));
        if ($projectName !== '') {
            return "{$prefix} - {$projectName}";
        }

        return $prefix.' - Markdown 导入';
    }

    private function resolveDefaultModelId(): int
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first(['id']);

        if (! $model) {
            throw new \RuntimeException('没有可用的 AI 模型，请先在后台配置至少一个 chat 模型');
        }

        return (int) $model->id;
    }

    private function resolveAnalysisModel(): AiModel
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($q): void {
                $q->whereNull('daily_limit')
                    ->orWhere('daily_limit', 0)
                    ->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first();

        if (! $model) {
            throw new \RuntimeException('没有可用的 AI 分析模型，请确认已配置至少一个 chat 模型且未超过每日限额');
        }

        return $model;
    }

    /**
     * @return array{provider:string, model_id:string, model:AiModel}
     */
    private function prepareAiRuntime(AiModel $model): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('AI 模型 Provider URL 未配置');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('AI 模型 API Key 未配置');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('smart_import_analysis', $driver, $providerUrl, $apiKey);

        return [
            'provider' => $providerName,
            'model_id' => (string) ($model->model_id ?? ''),
            'model' => $model,
        ];
    }
}
```

- [ ] **Step 2: Verify the service instantiates**

```bash
php artisan tinker --execute="echo get_class(app(App\Services\GeoFlow\SmartImportService::class));"
```

Expected: `App\Services\GeoFlow\SmartImportService`

- [ ] **Step 3: Commit**

```bash
git add app/Services/GeoFlow/SmartImportService.php
git commit -m "feat: add SmartImportService for async import orchestration"
```

---

### Task 4: ProcessSmartImportJob Queue Job

**Files:**
- Create: `app/Jobs/ProcessSmartImportJob.php`

**Interfaces:**
- Consumes: `SmartImportJob`, `SmartImportService`
- Produces: dispatched to `geoflow` queue

- [ ] **Step 1: Create the Job class**

```php
<?php

namespace App\Jobs;

use App\Models\SmartImportJob;
use App\Services\GeoFlow\SmartImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSmartImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $smartImportJobId
    ) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'geoflow',
            'smart_import',
            'smart_import_job:'.$this->smartImportJobId,
        ];
    }

    public function handle(SmartImportService $service): void
    {
        $job = SmartImportJob::query()->find($this->smartImportJobId);

        if (! $job) {
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        $service->handle($job);
    }
}
```

- [ ] **Step 2: Verify the Job class loads**

```bash
php artisan tinker --execute="echo get_class(new App\Jobs\ProcessSmartImportJob(1));"
```

Expected: `App\Jobs\ProcessSmartImportJob`

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/ProcessSmartImportJob.php
git commit -m "feat: add ProcessSmartImportJob queue job"
```

---

### Task 5: SmartImportController + Routes

**Files:**
- Create: `app/Http/Controllers/Api/V1/SmartImportController.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `SmartImportJob`, `ProcessSmartImportJob`, `SmartImportService` (for handle via Job)
- Produces: `POST /api/v1/smart-import` (202), `GET /api/v1/smart-import/{job}` (200)

- [ ] **Step 1: Create the controller**

```php
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
```

- [ ] **Step 2: Add routes to `routes/api.php`**

Add after line 22 (`->middleware(['api.request_id'])`) and before the `Route::middleware(['api.auth'])` group. The new routes go inside the `prefix('v1')` group but outside `api.auth`:

Open `routes/api.php` and add these lines after the `Route::post('auth/login', ...)` line (line 25) and before the `Route::middleware(['api.auth'])` block (line 28):

```php
        // 公开：智能导入（无需认证）
        Route::post('smart-import', [SmartImportController::class, 'store']);
        Route::get('smart-import/{job}', [SmartImportController::class, 'show'])->whereNumber('job');
```

Also add the import at the top:

```php
use App\Http\Controllers\Api\V1\SmartImportController;
```

- [ ] **Step 3: Verify routes are registered**

```bash
php artisan route:list | grep smart-import
```

Expected: Two routes showing `POST api/v1/smart-import` and `GET api/v1/smart-import/{job}`.

- [ ] **Step 4: Test validation (POST with missing params)**

Start the dev server if not running, then:

```bash
curl -s -X POST http://localhost:18080/api/v1/smart-import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{}' | python3 -m json.tool
```

Expected: 422 with `field_errors` listing `source_type`, `article_type`.

- [ ] **Step 5: Test successful POST (Markdown mode)**

```bash
curl -s -X POST http://localhost:18080/api/v1/smart-import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "source_type": "markdown",
    "article_type": "jiey_ide",
    "markdown_content": "# Test\n\nThis is a test document about AI code generation tools.",
    "markdown_name": "AI 代码生成器介绍",
    "article_count": 10
  }' | python3 -m json.tool
```

Expected: 202 with `job_id`, `status: "queued"`.

- [ ] **Step 6: Test GET progress**

```bash
curl -s http://localhost:18080/api/v1/smart-import/1 \
  -H 'Accept: application/json' | python3 -m json.tool
```

Expected: 200 with job status (likely `processing` or `completed`/`failed` depending on queue worker).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/SmartImportController.php routes/api.php
git commit -m "feat: add SmartImportController and public API routes"
```

---

### Task 6: End-to-End Verification

**Files:**
- None (verification only)

- [ ] **Step 1: Test URL mode**

```bash
curl -s -X POST http://localhost:18080/api/v1/smart-import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "source_type": "url",
    "article_type": "jiey_ide",
    "url": "https://www.jiewaigongxing.com/",
    "article_count": 5
  }' | python3 -m json.tool
```

Expected: 202, `job_id` returned. Check status after ~30s — should show `completed` with materials + task IDs.

- [ ] **Step 2: Test project mode with Markdown**

```bash
curl -s -X POST http://localhost:18080/api/v1/smart-import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "source_type": "markdown",
    "article_type": "project",
    "markdown_content": "# 电商平台\n\n一个完整的 B2C 电商平台，包含商品管理、订单系统、支付集成、用户中心等模块。\n\n技术栈：Spring Boot + Vue3 + UniApp",
    "markdown_name": "电商平台项目",
    "project_name": "电商平台",
    "project_description": "一个由 jiey IDE 生成的完整 B2C 电商平台，包含商品管理、订单系统、支付集成等",
    "article_count": 5
  }' | python3 -m json.tool
```

Expected: 202, then after processing: `completed` with prompt name "jiey IDE 项目推广" and task created.

- [ ] **Step 3: Verify materials in database**

```bash
php artisan tinker --execute="echo App\Models\SmartImportJob::latest()->first()->result_json;"
```

Expected: JSON with materials, prompt, task IDs.

- [ ] **Step 4: Run existing test suite**

```bash
php artisan test
```

Expected: All existing tests pass (no regressions).

- [ ] **Step 5: Commit**

```bash
git commit -m "verify: smart-import API end-to-end tests pass"
```
