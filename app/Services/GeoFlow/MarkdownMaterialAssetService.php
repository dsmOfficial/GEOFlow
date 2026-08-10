<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * 从 Markdown 正文生成可复用的关键词库和标题库。
 */
final class MarkdownMaterialAssetService
{
    private const KEYWORD_LIMIT = 20;

    private const TITLE_LIMIT = 50;

    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @return array{keyword_library_id:int,title_library_id:int,keywords_count:int,titles_count:int,fallback_used:bool,fallback_reason:?string}
     */
    public function create(string $name, string $content): array
    {
        $name = trim($name) !== '' ? trim($name) : 'Markdown 导入';
        $content = trim($content);
        $fallbackUsed = false;
        $fallbackReason = null;

        try {
            $analysis = $this->analyzeWithAi($name, $content);
        } catch (Throwable $exception) {
            report($exception);
            $fallbackUsed = true;
            $fallbackReason = $exception->getMessage();
            $analysis = $this->fallbackAnalysis($name, $content);
        }

        $keywords = $this->normalizeList($analysis['keywords'] ?? [], 200, self::KEYWORD_LIMIT);
        $titles = $this->normalizeList($analysis['titles'] ?? [], 500, self::TITLE_LIMIT);
        if ($keywords === [] || $titles === []) {
            $fallbackUsed = true;
            $fallbackReason ??= 'empty_result';
            $fallback = $this->fallbackAnalysis($name, $content);
            $keywords = $keywords !== [] ? $keywords : $this->normalizeList($fallback['keywords'], 200, self::KEYWORD_LIMIT);
            $titles = $titles !== [] ? $titles : $this->normalizeList($fallback['titles'], 500, self::TITLE_LIMIT);
        }

        return DB::transaction(function () use ($name, $keywords, $titles, $fallbackUsed, $fallbackReason): array {
            $keywordLibrary = KeywordLibrary::query()->create([
                'name' => $name.' 关键词库',
                'description' => 'Markdown 上传自动生成',
                'keyword_count' => 0,
            ]);
            foreach ($keywords as $keyword) {
                Keyword::query()->firstOrCreate(
                    ['library_id' => (int) $keywordLibrary->id, 'keyword' => $keyword],
                    ['used_count' => 0, 'usage_count' => 0]
                );
            }
            $keywordCount = Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count();
            $keywordLibrary->update(['keyword_count' => $keywordCount]);

            $titleLibrary = TitleLibrary::query()->create([
                'name' => $name.' 标题库',
                'description' => 'Markdown 上传自动生成',
                'title_count' => 0,
                'generation_type' => 'markdown_upload',
                'keyword_library_id' => (int) $keywordLibrary->id,
                'generation_rounds' => 1,
                'is_ai_generated' => $fallbackUsed ? 0 : 1,
            ]);
            foreach ($titles as $index => $title) {
                Title::query()->firstOrCreate(
                    ['library_id' => (int) $titleLibrary->id, 'title' => $title],
                    [
                        'keyword' => $keywords[$index % max(1, count($keywords))] ?? '',
                        'is_ai_generated' => ! $fallbackUsed,
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]
                );
            }
            $titleCount = Title::query()->where('library_id', (int) $titleLibrary->id)->count();
            $titleLibrary->update(['title_count' => $titleCount]);

            return [
                'keyword_library_id' => (int) $keywordLibrary->id,
                'title_library_id' => (int) $titleLibrary->id,
                'keywords_count' => $keywordCount,
                'titles_count' => $titleCount,
                'fallback_used' => $fallbackUsed,
                'fallback_reason' => $fallbackReason,
            ];
        });
    }

    /**
     * @return array{keywords:list<string>,titles:list<string>}
     */
    private function analyzeWithAi(string $name, string $content): array
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')->orWhere('daily_limit', 0)->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first();
        if (! $model instanceof AiModel) {
            throw new \RuntimeException('没有可用的 AI 分析模型');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new \RuntimeException('AI 模型连接信息未配置');
        }
        $provider = OpenAiRuntimeProvider::registerProvider(
            'markdown_upload_analysis',
            OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? '')),
            $providerUrl,
            $apiKey,
        );
        $agent = new MarkdownContentWriterAgent(
            '你是 GEOFlow 的素材构建器。只输出 JSON，不要输出 Markdown 代码块。关键词必须来自文档，标题必须适合围绕文档写成完整文章。',
        );
        $excerpt = Str::limit($content, 12000, '');
        $keywordResponse = $agent->prompt(
            '从以下 Markdown 提取 10-'.self::KEYWORD_LIMIT.' 个核心业务关键词。只输出 JSON：{"keywords":["关键词"]}'
                ."\n\n文档名称：{$name}\n文档内容：\n{$excerpt}",
            [], $provider, (string) ($model->model_id ?? '')
        );
        $keywords = $this->decodeList(OpenAiRuntimeProvider::normalizeGeneratedText((string) ($keywordResponse->text ?? '')), 'keywords');
        if ($keywords === []) {
            throw new \RuntimeException('AI 关键词提取失败');
        }
        $titleResponse = $agent->prompt(
            "基于以下 Markdown 和关键词生成最多 50 个多样化 GEO 文章标题。每个标题都应围绕文档整体。只输出 JSON：{\"titles\":[\"标题\"]}\n\n文档名称：{$name}\n关键词：".implode('、', $keywords)."\n文档内容：\n{$excerpt}",
            [], $provider, (string) ($model->model_id ?? '')
        );
        $titles = $this->decodeList(OpenAiRuntimeProvider::normalizeGeneratedText((string) ($titleResponse->text ?? '')), 'titles');
        if ($titles === []) {
            throw new \RuntimeException('AI 标题生成失败');
        }

        AiModel::query()->whereKey((int) $model->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+2'),
            'total_used' => DB::raw('COALESCE(total_used,0)+2'),
            'updated_at' => now(),
        ]);

        return ['keywords' => $keywords, 'titles' => $titles];
    }

    /** @return list<string> */
    private function decodeList(string $text, string $key): array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/mi', '', $text) ?? $text);
        $decoded = json_decode($text, true);
        $values = is_array($decoded) && isset($decoded[$key]) ? $decoded[$key] : (is_array($decoded) && array_is_list($decoded) ? $decoded : []);

        return is_array($values) ? array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $values))) : [];
    }

    /** @return list<string> */
    private function normalizeList(array $values, int $maxLength, int $limit): array
    {
        return collect($values)
            ->map(static fn ($value): string => trim((string) $value))
            ->map(static fn (string $value): string => trim((string) (preg_replace('/^\d+[\.\)\-、\s]*/u', '', $value) ?? $value)))
            ->filter(static fn (string $value): bool => $value !== '' && mb_strlen($value, 'UTF-8') <= $maxLength)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{keywords:list<string>,titles:list<string>} */
    private function fallbackAnalysis(string $name, string $content): array
    {
        $titles = [];
        $keywords = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $matches) === 1) {
                $heading = trim($matches[1]);
                if ($heading !== '') {
                    $titles[] = $heading;
                    $keywords[] = trim(preg_replace('/[：:，,。！？!?].*$/u', '', $heading) ?? $heading);
                }
            }
        }
        $keywords[] = $name;
        $titles[] = $name.'：核心内容与实践指南';

        return ['keywords' => $keywords, 'titles' => $titles];
    }
}
