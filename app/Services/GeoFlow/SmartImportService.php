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

    /** 普通 Markdown/业务向导入的关键词上限 */
    private const KEYWORD_LIMIT_BUSINESS = 20;

    /** Jiey 技术/项目制作向导入的关键词上限（模块、技术栈、链路更多） */
    private const KEYWORD_LIMIT_TECHNICAL_PROJECT = 30;

    private const TITLE_LIMIT = 50;

    public function __construct(
        private readonly UrlImportProcessingService $urlImportService,
        private readonly MaterialLibraryService $materialLibraryService,
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly KnowledgeChunkSyncService $chunkSyncService,
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly JieyInternalFlowClient $jieyClient,
        private readonly JieyFlowArtifactNormalizer $jieyNormalizer,
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
            } elseif ($sourceType === 'jiey_flow') {
                $materials = $this->importFromJieyFlow($job, $input);
                // jiey 路径可能回填 project_name / project_description
                $input = array_merge($input, $job->fresh()?->input_data ?? []);
            } else {
                $materials = $this->importFromMarkdown($job, $input);
            }

            // Step 2: Ensure prompt exists
            $job->markProcessing('ensuring_prompt', 75);
            $prompt = $this->ensurePrompt($articleType, $input);
            $knowledgeBaseIds = $this->resolveTaskKnowledgeBaseIds(
                (int) $materials['knowledge_base_id'],
                $input
            );

            // Step 3: Create task
            $job->markProcessing('creating_task', 85);
            $aiModelId = $modelId ?? $this->resolveDefaultModelId();
            $taskName = $this->buildTaskName($articleType, $sourceType, $input, $prompt);

            $imageOptions = $this->resolveTaskImageOptions($input);

            $taskData = [
                'name' => $taskName,
                'title_library_id' => $materials['title_library_id'],
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => $aiModelId,
                'knowledge_base_id' => $knowledgeBaseIds[0] ?? (int) $materials['knowledge_base_id'],
                'knowledge_base_ids' => $knowledgeBaseIds,
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
                'image_count' => $imageOptions['image_count'],
                'image_library_id' => $imageOptions['image_library_id'],
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

        return $this->commitMarkdownMaterials(
            $job,
            $content,
            $name,
            'Markdown 智能导入自动生成',
            'markdown_import',
            []
        );
    }

    /**
     * Jiey Flow mode: HMAC 拉取 artifacts → 规范为 Markdown → 复用 markdown 入库链路。
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *   knowledge_base_id:int,
     *   keyword_library_id:int,
     *   title_library_id:int,
     *   keywords_count:int,
     *   titles_count:int,
     *   jiey:array<string,mixed>
     * }
     */
    private function importFromJieyFlow(SmartImportJob $job, array $input): array
    {
        $projectId = max(0, (int) ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            throw new \InvalidArgumentException('jiey_flow 模式必须提供有效的 project_id');
        }

        $job->markProcessing('fetching_jiey_artifacts', 20);
        $artifacts = $this->jieyClient->getProjectArtifacts($projectId);
        if ($artifacts === []) {
            throw new \RuntimeException('Jiey project #'.$projectId.' 没有可导入的 artifacts');
        }

        $job->markProcessing('normalizing_artifacts', 35);

        $slugFilter = $input['artifact_type_slugs'] ?? null;
        if (is_string($slugFilter) && trim($slugFilter) !== '') {
            $slugFilter = array_values(array_filter(array_map('trim', explode(',', $slugFilter))));
        }
        if (! is_array($slugFilter)) {
            $slugFilter = null;
        }

        $normalized = $this->jieyNormalizer->toMarkdown($artifacts, [
            'project_id' => $projectId,
            'project_name' => (string) ($input['project_name'] ?? ''),
            'project_description' => (string) ($input['project_description'] ?? ''),
            'artifact_type_slugs' => $slugFilter,
            'include_unpublished' => (bool) ($input['include_unpublished'] ?? false),
        ]);

        $content = trim((string) ($normalized['markdown'] ?? ''));
        if ($content === '') {
            throw new \RuntimeException('Jiey project #'.$projectId.' 的 artifacts 未解析出可用正文');
        }

        // 回填 project 元数据，便于后续 ensurePrompt / 任务命名
        if (trim((string) ($input['project_name'] ?? '')) === '' && trim((string) ($normalized['name'] ?? '')) !== '') {
            $input['project_name'] = (string) $normalized['name'];
            $job->input_data = array_merge($job->input_data ?? [], ['project_name' => $input['project_name']]);
            $job->save();
        }
        if (trim((string) ($input['project_description'] ?? '')) === '' && trim((string) ($normalized['description'] ?? '')) !== '') {
            $input['project_description'] = (string) $normalized['description'];
            $job->input_data = array_merge($job->input_data ?? [], ['project_description' => $input['project_description']]);
            $job->save();
        }

        $name = trim((string) ($normalized['name'] ?? ''));
        if ($name === '') {
            $name = 'Jiey 项目 #'.$projectId;
        }

        $materials = $this->commitMarkdownMaterials(
            $job,
            $content,
            $name,
            trim((string) ($normalized['description'] ?? '')) !== ''
                ? (string) $normalized['description']
                : 'Jiey Flow 智能导入自动生成',
            'jiey_flow_import',
            [
                'source_name' => 'Jiey Flow #'.$projectId,
                'source_url' => (string) ($normalized['preview_url'] ?? ''),
                'source_type' => 'other',
                'business_line' => (string) ($normalized['brand'] ?? ''),
                'review_status' => 'unreviewed',
            ]
        );

        $materials['jiey'] = [
            'project_id' => $projectId,
            'artifact_count' => (int) ($normalized['artifact_count'] ?? count($artifacts)),
            'included_artifact_ids' => $normalized['included_artifact_ids'] ?? [],
            'skipped' => $normalized['skipped'] ?? [],
            'preview_url' => (string) ($normalized['preview_url'] ?? ''),
            'brand' => (string) ($normalized['brand'] ?? ''),
        ];

        return $materials;
    }

    /**
     * 将 Markdown 正文落地为知识库 + 关键词库 + 标题库（markdown / jiey_flow 共用）。
     *
     * @param  array<string, mixed>  $knowledgeMeta
     * @return array{knowledge_base_id:int, keyword_library_id:int, title_library_id:int, keywords_count:int, titles_count:int}
     */
    private function commitMarkdownMaterials(
        SmartImportJob $job,
        string $content,
        string $name,
        string $description,
        string $titleGenerationType,
        array $knowledgeMeta = []
    ): array {
        if (mb_strlen($content, 'UTF-8') > 500_000) {
            throw new \InvalidArgumentException('Markdown 内容不能超过 500KB');
        }

        $kbPayload = array_merge([
            'name' => $name.' 知识库',
            'description' => $description,
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'usage_count' => 0,
        ], array_filter(
            $knowledgeMeta,
            static fn ($value): bool => $value !== null && $value !== ''
        ));

        $knowledgeBase = KnowledgeBase::query()->create($kbPayload);
        $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);

        $job->markProcessing('analyzing_markdown', 40);
        // jiey_flow 导入默认走技术/项目制作导向，便于输出工程向关键词与标题。
        $analysisFocus = $titleGenerationType === 'jiey_flow_import' ? 'technical_project' : 'business';
        $analysis = $this->analyzeMarkdownWithAi($content, $name, $analysisFocus);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => $name.' 关键词库',
            'description' => $description,
            'keyword_count' => 0,
        ]);
        foreach ($analysis['keywords'] as $keyword) {
            Keyword::query()->firstOrCreate(
                ['library_id' => (int) $keywordLibrary->id, 'keyword' => $keyword],
                ['used_count' => 0, 'usage_count' => 0]
            );
        }
        $keywordLibrary->update(['keyword_count' => Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count()]);

        $titleLibrary = TitleLibrary::query()->create([
            'name' => $name.' 标题库',
            'description' => $description,
            'title_count' => 0,
            'generation_type' => $titleGenerationType,
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
     * @param  string  $focus  business|technical_project
     * @return array{keywords: list<string>, titles: list<string>}
     */
    private function analyzeMarkdownWithAi(string $content, string $name, string $focus = 'business'): array
    {
        $focus = $focus === 'technical_project' ? 'technical_project' : 'business';
        $model = $this->resolveAnalysisModel();
        $runtime = $this->prepareAiRuntime($model);

        $truncatedContent = Str::limit($content, 12000, '');
        $agent = new MarkdownContentWriterAgent($this->buildMarkdownAnalysisSystemPrompt($focus));

        // Extract keywords
        $keywordResponse = $agent->prompt(
            $this->buildMarkdownKeywordsUserPrompt($truncatedContent, $name, $focus),
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
            $this->buildMarkdownTitlesUserPrompt($truncatedContent, $name, $keywords, $focus),
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

        $keywordLimit = $this->keywordLimitForFocus($focus);

        return [
            'keywords' => array_slice($keywords, 0, $keywordLimit),
            'titles' => array_slice($titles, 0, self::TITLE_LIMIT),
        ];
    }

    private function keywordLimitForFocus(string $focus): int
    {
        return $focus === 'technical_project'
            ? self::KEYWORD_LIMIT_TECHNICAL_PROJECT
            : self::KEYWORD_LIMIT_BUSINESS;
    }

    private function buildMarkdownAnalysisSystemPrompt(string $focus = 'business'): string
    {
        if ($focus === 'technical_project') {
            $keywordLimit = self::KEYWORD_LIMIT_TECHNICAL_PROJECT;

            return <<<PROMPT
你是 GEOFlow 的技术项目素材构建器。你只输出 JSON，不要输出 Markdown 代码块。
你需要从给定的项目资料（PRD、产品定义、页面契约、技术说明等）中，提取描述「整个项目」的技术/制作向关键词，以及以「项目整体」为主角的 GEO 文章标题。
输出字段：keywords（最多 {$keywordLimit} 个短关键词或短语），titles（最多 50 个多样化的文章标题）。
关键词要求：
- 中文 2-8 个字，英文 1-4 个单词；
- 优先能概括整项目的词：项目定位、行业场景、整体架构、全栈交付、核心业务闭环、技术栈组合、多端形态、工程交付方式；
- 可以包含支撑整项目理解的关键能力词（如即时零售、库存履约、四端协同），但不要堆砌过细的页面名、按钮名、单表字段名；
- 例如：全栈项目、生鲜即时零售、小程序电商、四端架构、Spring Boot、Vue3、履约闭环、从0到1交付；
- 避免纯营销口号；尽量使用文档中真实出现的项目级概念。
标题要求（非常重要）：
- 标题必须把「整个项目」当作主角，而不是某个小模块、单个页面、单个接口或单个功能点；
- 优先角度：项目是什么、从 0 到 1 怎么做、整体架构、业务闭环、技术选型、多端如何协同、如何交付一个完整系统、项目复盘/实践总结；
- 禁止写成「某模块怎么做」「某页面实现」「某接口设计」「库存锁定细节」「分拣任务池」这类局部专题标题；
- 若需提及局部能力，也只能作为整项目叙事的一部分，标题仍要落在项目整体；
- 面向独立开发者、外包团队、技术负责人；
- 可自然关联“用 AI/代码生成工具加速完整项目制作”，但不要写成硬广；
- 不能虚构文档中没有的技术栈、业务或能力。
PROMPT;
        }

        $keywordLimit = self::KEYWORD_LIMIT_BUSINESS;

        return <<<PROMPT
你是 GEOFlow 的素材构建器。你只输出 JSON，不要输出 Markdown 代码块。
你需要从给定的 Markdown 文档中提取核心业务关键词和可用于 GEO 内容生成的标题。
输出字段：keywords（最多 {$keywordLimit} 个短关键词或短语），titles（最多 50 个多样化的文章标题）。
关键词要求：中文 2-5 个字，英文 1-3 个单词；必须是产品/服务词、行业词、需求场景词、问题词、解决方案词。
标题要求：围绕"是什么、为什么、怎么做、对比、选型、指南、清单、案例拆解、常见问题、趋势判断"等角度展开。
不能虚构文档中没有的信息。
PROMPT;
    }

    private function buildMarkdownKeywordsUserPrompt(string $content, string $name, string $focus = 'business'): string
    {
        if ($focus === 'technical_project') {
            $min = 15;
            $max = self::KEYWORD_LIMIT_TECHNICAL_PROJECT;

            return "请从以下项目资料中提取 {$min}-{$max} 个能描述「整个项目」的技术/项目制作关键词。"
                ."优先：项目定位、行业场景、整体架构、技术栈组合、多端形态、核心业务闭环、交付方式。"
                ."不要只抽某个小模块/页面/接口的细碎词；关键词应服务于写整项目文章。\n\n"
                ."文档名称：{$name}\n\n文档内容：\n{$content}\n\n"
                ."请只输出 JSON：{\"keywords\": [\"关键词1\", \"关键词2\", ...]}";
        }

        $min = 10;
        $max = self::KEYWORD_LIMIT_BUSINESS;

        return "请从以下 Markdown 文档中提取 {$min}-{$max} 个核心业务关键词。\n\n文档名称：{$name}\n\n文档内容：\n{$content}\n\n请只输出 JSON：{\"keywords\": [\"关键词1\", \"关键词2\", ...]}";
    }

    private function buildMarkdownTitlesUserPrompt(string $content, string $name, array $keywords, string $focus = 'business'): string
    {
        $keywordList = implode('、', $keywords);
        if ($focus === 'technical_project') {
            return "请基于以下项目资料和关键词，生成 50 个以「项目整体」为主角的技术/项目制作向 GEO 文章标题。\n"
                ."要求：\n"
                ."1. 每条标题都要能写一篇完整项目文章，而不是某个小模块专题；\n"
                ."2. 优先角度：项目全景、从 0 到 1、整体架构、业务闭环、技术选型、多端协同、完整交付、实践复盘；\n"
                ."3. 禁止标题只聚焦单一小模块/页面/接口/字段（如仅写库存锁定、分拣池、某个详情页）；\n"
                ."4. 标题中尽量出现项目名或项目定位，让人一眼知道是在讲整个系统；\n"
                ."5. 读者是开发者与技术决策者，不要写成纯营销软文。\n\n"
                ."文档名称：{$name}\n关键词：{$keywordList}\n\n文档内容：\n{$content}\n\n"
                ."请只输出 JSON：{\"titles\": [\"标题1\", \"标题2\", ...]}";
        }

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
        $selectedPromptId = max(0, (int) ($input['prompt_id'] ?? 0));
        if ($selectedPromptId > 0) {
            $selected = Prompt::query()
                ->whereKey($selectedPromptId)
                ->where('type', 'content')
                ->first();
            if ($selected) {
                return $selected;
            }

            throw new \InvalidArgumentException('指定的提示词不存在或不是正文提示词');
        }

        $promptName = $articleType === 'project' ? self::PROJECT_PROMPT_NAME : self::JIEY_IDE_PROMPT_NAME;

        $content = $articleType === 'project'
            ? $this->buildProjectPromptContent($input)
            : $this->buildJieyIdePromptContent();

        $prompt = Prompt::query()
            ->where('name', $promptName)
            ->where('type', 'content')
            ->first();

        if ($prompt) {
            // project 类型每次按当前项目刷新提示词，避免复用旧项目模板导致正文偏题到局部模块。
            if ($articleType === 'project' && trim((string) $prompt->content) !== trim($content)) {
                $prompt->update([
                    'content' => $content,
                    'variables' => '',
                ]);
            }

            return $prompt->refresh();
        }

        return Prompt::query()->create([
            'name' => $promptName,
            'type' => 'content',
            'content' => $content,
            'variables' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{image_library_id:?int, image_count:int}
     */
    private function resolveTaskImageOptions(array $input): array
    {
        $imageLibraryId = max(0, (int) ($input['image_library_id'] ?? 0));
        $maxArticleImages = max(0, (int) config('geoflow.max_article_images', 10));
        $imageCount = max(0, min($maxArticleImages, (int) ($input['image_count'] ?? 0)));

        if ($imageLibraryId <= 0) {
            return [
                'image_library_id' => null,
                'image_count' => 0,
            ];
        }

        $exists = \App\Models\ImageLibrary::query()->whereKey($imageLibraryId)->exists();
        if (! $exists) {
            throw new \InvalidArgumentException('指定的图片库不存在');
        }

        return [
            'image_library_id' => $imageLibraryId,
            'image_count' => $imageCount > 0 ? $imageCount : 1,
        ];
    }

    /**
     * 主知识库 + 可选附加知识库；主库始终排第一，总数不超过 5。
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function resolveTaskKnowledgeBaseIds(int $primaryKnowledgeBaseId, array $input): array
    {
        $ids = [$primaryKnowledgeBaseId];
        $extra = $input['extra_knowledge_base_ids'] ?? $input['knowledge_base_ids'] ?? [];
        if (! is_array($extra)) {
            $extra = [];
        }

        foreach ($extra as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= 5) {
                break;
            }
        }

        $existing = KnowledgeBase::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $existingSet = array_fill_keys($existing, true);

        $validated = [];
        foreach ($ids as $id) {
            if (isset($existingSet[$id])) {
                $validated[] = $id;
            }
        }

        if ($validated === []) {
            throw new \RuntimeException('任务未绑定到有效知识库');
        }

        return $validated;
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

输出边界：
- 直接输出最终 Markdown 正文，从标题或第一段开始
- 禁止确认语、角色前言、写作过程说明，例如“好的，”“文章如下。”“根据您的要求生成……”“作为……我将输出……”
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

写作目标（非常重要）：
- 文章主题必须是「整个项目」的全景介绍与实践，而不是某个小模块、单个页面、单个接口或局部功能点专题
- 读者看完应理解：这是什么项目、解决什么问题、整体怎么构成、核心业务如何闭环、技术上如何落地、如何交付

文章结构建议：
1. 项目定位与要解决的问题
2. 目标用户与核心业务场景
3. 整体架构与多端形态（可概括，不要陷入单页细节）
4. 核心业务闭环（从主链路讲清楚，局部能力只作支撑）
5. 技术选型与工程交付方式
6. 项目价值、适用场景与实践总结

硬性约束：
- 不要把全文写成某一个小模块教程（如只写库存锁定、分拣池、售后审核页）
- 可以引用局部能力证明整项目完整性，但每个局部都要回到项目整体叙事
- 自然提及该项目由 jiey IDE 生成；jiey IDE 是 AI 全栈代码生成器，可用自然语言生成 Spring Boot + Vue3 + UniApp + 网站等代码
- 风格专业可信，突出项目本身，而不是工具硬广
- 请基于参考知识中的项目资料写作，不要编造资料中不存在的能力

目标受众：对项目所在领域感兴趣的技术人员、创业者、潜在用户。

输出边界：
- 直接输出最终 Markdown 正文，从标题或第一段开始
- 禁止确认语、角色前言、写作过程说明，例如“好的，”“文章如下。”“根据您的要求生成……”“作为……我将输出……”
PROMPT;
    }

    private function buildTaskName(string $articleType, string $sourceType, array $input, ?Prompt $prompt = null): string
    {
        $prefix = $articleType === 'project' ? 'jiey IDE 项目推广' : 'jiey IDE 推广';
        $roleLabel = $this->resolveTaskRoleLabel($input, $prompt);
        if ($roleLabel !== null) {
            $prefix .= ' · '.$roleLabel;
        }

        if ($sourceType === 'url') {
            $url = trim((string) ($input['url'] ?? ''));
            $host = $url !== '' ? (parse_url(
                preg_match('#^https?://#i', $url) ? $url : 'https://'.$url,
                PHP_URL_HOST
            ) ?? $url) : 'URL';
            return "{$prefix} - {$host}";
        }

        if ($sourceType === 'jiey_flow') {
            $projectName = trim((string) ($input['project_name'] ?? ''));
            if ($projectName !== '') {
                return "{$prefix} - {$projectName}";
            }
            $projectId = max(0, (int) ($input['project_id'] ?? 0));

            return $projectId > 0
                ? "{$prefix} - Jiey #{$projectId}"
                : "{$prefix} - Jiey Flow";
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveTaskRoleLabel(array $input, ?Prompt $prompt = null): ?string
    {
        $promptName = trim((string) ($prompt?->name ?? ''));
        if ($promptName === '' && max(0, (int) ($input['prompt_id'] ?? 0)) > 0) {
            $promptName = trim((string) (Prompt::query()
                ->whereKey((int) $input['prompt_id'])
                ->value('name') ?? ''));
        }

        if ($promptName === '') {
            return null;
        }

        $roleLabel = app(JieyProjectRolePromptCatalog::class)->labelForPromptName($promptName);
        if ($roleLabel !== null) {
            return $roleLabel;
        }

        // 非内置角色但显式选了提示词时，用精简后的提示词名区分
        $compact = preg_replace('/^Jiey项目·/u', '', $promptName) ?? $promptName;
        $compact = preg_replace('/视角$/u', '', $compact) ?? $compact;
        $compact = trim((string) $compact);

        if ($compact === '' || $compact === $promptName) {
            $compact = mb_strlen($promptName, 'UTF-8') > 16
                ? mb_substr($promptName, 0, 16, 'UTF-8')
                : $promptName;
        }

        return $compact !== '' ? $compact : null;
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
