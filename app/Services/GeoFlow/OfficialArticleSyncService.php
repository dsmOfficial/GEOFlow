<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\SmartImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 将 GEOFlow 文章同步到 Jiey 官网 CMS，并在成功后回写文末原文链接。
 */
final class OfficialArticleSyncService
{
    public const SOURCE_LINK_START = '<!-- official-source-link:start -->';

    public const SOURCE_LINK_END = '<!-- official-source-link:end -->';

    public function __construct(
        private readonly JieyInternalFlowClient $jieyClient,
    ) {}

    /**
     * @return array{
     *   article_id:int,
     *   sync_status:string,
     *   official_url:?string,
     *   remote_id:?string,
     *   external_id:string,
     *   source_link_appended:bool,
     *   last_error_message:?string,
     *   action:string
     * }
     */
    public function sync(Article $article, bool $force = false): array
    {
        $article->loadMissing(['category:id,slug,name', 'task:id,name']);

        $this->assertCanSync($article);

        $externalId = $this->resolveExternalId($article);
        $action = $this->resolveAction($article, $force);
        $payload = $this->buildPayload($article, $externalId, $action);

        $this->markSyncing($article, $externalId);

        try {
            $response = $this->jieyClient->syncGeoflowArticle($payload);
            $parsed = $this->parseSuccessResponse($response, $externalId);

            $sourceLinkAppended = false;
            if ((bool) config('geoflow.jiey.official_source_link_enabled', true) && $parsed['official_url'] !== null) {
                $sourceLinkAppended = $this->appendOrReplaceSourceLink($article, $parsed['official_url']);
            }

            $this->markSynced($article, $externalId, $parsed['remote_id'], $parsed['official_url']);

            return [
                'article_id' => (int) $article->id,
                'sync_status' => 'synced',
                'official_url' => $parsed['official_url'],
                'remote_id' => $parsed['remote_id'],
                'external_id' => $externalId,
                'source_link_appended' => $sourceLinkAppended,
                'last_error_message' => null,
                'action' => $action,
            ];
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage()) !== '' ? $exception->getMessage() : $exception::class;
            $this->markFailed($article, $externalId, $message);

            throw new RuntimeException($message, 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Article $article): array
    {
        return [
            'article_id' => (int) $article->id,
            'sync_status' => (string) ($article->official_sync_status ?? ''),
            'official_url' => $this->nullableString($article->official_url ?? null),
            'remote_id' => $this->nullableString($article->official_remote_id ?? null),
            'external_id' => $this->nullableString($article->official_external_id ?? null)
                ?: $this->resolveExternalId($article),
            'source_link_appended' => str_contains((string) $article->content, self::SOURCE_LINK_START),
            'last_error_message' => $this->nullableString($article->official_last_error ?? null),
            'synced_at' => $article->official_synced_at?->toIso8601String(),
            'can_sync' => $this->canSync($article),
        ];
    }

    public function canSync(Article $article): bool
    {
        try {
            $this->assertCanSync($article);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertCanSync(Article $article): void
    {
        if ($article->trashed()) {
            throw new RuntimeException('已删除文章不能同步到官网');
        }

        if (trim((string) $article->title) === '' || trim((string) $article->content) === '') {
            throw new RuntimeException('文章标题或正文为空，无法同步到官网');
        }

        $this->jieyClient->assertConfigured();

        if (! $this->hasOfficialSyncColumns()) {
            throw new RuntimeException('官网同步字段尚未迁移，请先执行数据库迁移');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Article $article, string $externalId, string $action): array
    {
        $categorySlug = trim((string) ($article->category?->slug ?? ''));
        if ($categorySlug === '') {
            $categorySlug = trim((string) config('geoflow.jiey.official_default_category_slug', 'tech-blog'));
        }
        if ($categorySlug === '') {
            $categorySlug = 'tech-blog';
        }

        $slug = trim((string) $article->slug);
        if ($slug === '') {
            $slug = $externalId;
        }

        $source = [
            'task_id' => (int) ($article->task_id ?? 0) ?: null,
            'jiey_project_id' => $this->resolveJieyProjectId($article),
            'smart_import_job_id' => $this->resolveSmartImportJobId($article),
            'geoflow_article_id' => (int) $article->id,
        ];

        return [
            'action' => $action,
            'article' => [
                'external_id' => $externalId,
                'title' => (string) $article->title,
                'slug' => $slug,
                'content' => (string) $article->content,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'category_slug' => $categorySlug,
                'status' => 'publish',
                'type' => 'blog',
            ],
            'source' => array_filter(
                $source,
                static fn ($value): bool => $value !== null && $value !== '' && $value !== 0
            ),
        ];
    }

    private function resolveAction(Article $article, bool $force): string
    {
        $hasRemote = trim((string) ($article->official_remote_id ?? '')) !== ''
            || trim((string) ($article->official_url ?? '')) !== '';

        if ($hasRemote && ! $force) {
            return 'update';
        }

        // force 或首次：publish；若远端已存在，接口侧应按 external_id 幂等
        return $hasRemote ? 'update' : 'publish';
    }

    private function resolveExternalId(Article $article): string
    {
        $existing = trim((string) ($article->official_external_id ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return 'geoflow-article-'.(int) $article->id;
    }

    private function resolveJieyProjectId(Article $article): ?int
    {
        $taskId = (int) ($article->task_id ?? 0);
        if ($taskId <= 0 || ! Schema::hasTable('smart_import_jobs')) {
            return null;
        }

        $jobs = SmartImportJob::query()
            ->where('source_type', 'jiey_flow')
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'result_json', 'input_data']);

        foreach ($jobs as $job) {
            $result = is_array($job->result_json) ? $job->result_json : [];
            $resultTaskId = (int) data_get($result, 'task.id', 0);
            if ($resultTaskId === $taskId) {
                $projectId = (int) data_get($result, 'materials.jiey.project_id', 0);
                if ($projectId <= 0) {
                    $projectId = (int) data_get($job->input_data, 'project_id', 0);
                }

                return $projectId > 0 ? $projectId : null;
            }
        }

        return null;
    }

    private function resolveSmartImportJobId(Article $article): ?int
    {
        $taskId = (int) ($article->task_id ?? 0);
        if ($taskId <= 0 || ! Schema::hasTable('smart_import_jobs')) {
            return null;
        }

        $jobs = SmartImportJob::query()
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'result_json']);

        foreach ($jobs as $job) {
            $result = is_array($job->result_json) ? $job->result_json : [];
            if ((int) data_get($result, 'task.id', 0) === $taskId) {
                return (int) $job->id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{official_url:?string, remote_id:?string}
     */
    private function parseSuccessResponse(array $response, string $externalId): array
    {
        $code = (int) ($response['code'] ?? 200);
        if ($code !== 200 && $code !== 0) {
            $message = is_string($response['message'] ?? null) ? (string) $response['message'] : '官网同步失败';

            throw new RuntimeException($message);
        }

        $data = $response['data'] ?? $response;
        if (! is_array($data)) {
            $data = [];
        }

        $articleData = is_array($data['article'] ?? null) ? $data['article'] : $data;

        $officialUrl = $this->firstString($articleData, [
            'url', 'remote_url', 'official_url', 'article_url', 'permalink', 'link',
        ]);
        $remoteId = $this->firstString($articleData, [
            'id', 'remote_id', 'article_id', 'cms_id',
        ]);

        if ($remoteId === null) {
            $remoteId = $this->firstString($data, ['id', 'remote_id']);
        }
        if ($officialUrl === null) {
            $officialUrl = $this->firstString($data, ['url', 'remote_url', 'official_url']);
        }

        // 有些接口只回 id；允许 url 为空，但尽量保留 remote id
        if ($remoteId === null && $officialUrl === null) {
            // 仍然视为成功，使用 external_id 作为占位 remote_id，避免中断回写
            $remoteId = $externalId;
        }

        return [
            'official_url' => $officialUrl,
            'remote_id' => $remoteId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }
        }

        return null;
    }

    public function appendOrReplaceSourceLink(Article $article, string $officialUrl): bool
    {
        $officialUrl = trim($officialUrl);
        if ($officialUrl === '') {
            return false;
        }

        $template = (string) config(
            'geoflow.jiey.official_source_link_template',
            '**原文链接：** [{url}]({url})'
        );
        $linkMarkdown = str_replace('{url}', $officialUrl, $template);
        $block = self::SOURCE_LINK_START."\n".$linkMarkdown."\n".self::SOURCE_LINK_END;

        $content = (string) $article->content;
        if (str_contains($content, self::SOURCE_LINK_START) && str_contains($content, self::SOURCE_LINK_END)) {
            $updated = preg_replace(
                '/'.preg_quote(self::SOURCE_LINK_START, '/').'.*?'.preg_quote(self::SOURCE_LINK_END, '/').'/s',
                $block,
                $content,
                1
            );
            $content = is_string($updated) ? $updated : $content;
        } else {
            $content = rtrim($content)."\n\n---\n\n".$block."\n";
        }

        if ($content === (string) $article->content) {
            return str_contains($content, $officialUrl);
        }

        $article->forceFill(['content' => $content])->save();

        return true;
    }

    private function markSyncing(Article $article, string $externalId): void
    {
        if (! $this->hasOfficialSyncColumns()) {
            return;
        }

        $article->forceFill([
            'official_external_id' => $externalId,
            'official_sync_status' => 'sending',
            'official_last_error' => null,
        ])->save();
    }

    private function markSynced(Article $article, string $externalId, ?string $remoteId, ?string $officialUrl): void
    {
        if (! $this->hasOfficialSyncColumns()) {
            return;
        }

        $article->forceFill([
            'official_external_id' => $externalId,
            'official_remote_id' => $remoteId,
            'official_url' => $officialUrl,
            'official_sync_status' => 'synced',
            'official_synced_at' => now(),
            'official_last_error' => null,
        ])->save();
    }

    private function markFailed(Article $article, string $externalId, string $message): void
    {
        if (! $this->hasOfficialSyncColumns()) {
            return;
        }

        $article->forceFill([
            'official_external_id' => $externalId,
            'official_sync_status' => 'failed',
            'official_last_error' => mb_substr($message, 0, 1000, 'UTF-8'),
        ])->save();
    }

    private function hasOfficialSyncColumns(): bool
    {
        return Schema::hasTable('articles')
            && Schema::hasColumn('articles', 'official_sync_status')
            && Schema::hasColumn('articles', 'official_url');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
