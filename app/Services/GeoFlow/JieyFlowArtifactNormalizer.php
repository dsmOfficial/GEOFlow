<?php

namespace App\Services\GeoFlow;

/**
 * 将 Jiey Flow artifacts 规范为 GEOFlow 知识库 Markdown。
 *
 * - 优先纳入 payloadJson.content 非空的文本类产出
 * - preview-site 等无正文项记入元数据
 * - 按规范化正文 sha256 去重
 * - jsonOutput=true 且 content 为 JSON 时转为结构化摘要，避免转义 JSON 污染知识库
 */
final class JieyFlowArtifactNormalizer
{
    private const MAX_MARKDOWN_CHARS = 500_000;

    /** @var list<string> 默认优先纳入的文本类 slug（仍以 content 是否可抽取为准） */
    private const PREFERRED_TEXT_SLUGS = [
        'product-definition',
        'prd-doc',
        'prd',
        'business-plan',
        'requirement',
        'requirements',
    ];

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  array{
     *   project_id?:int,
     *   project_name?:string,
     *   project_description?:string,
     *   artifact_type_slugs?:list<string>,
     *   include_unpublished?:bool
     * }  $options
     * @return array{
     *   markdown:string,
     *   name:string,
     *   description:string,
     *   brand:string,
     *   preview_url:string,
     *   project_id:int,
     *   artifact_count:int,
     *   included_artifact_ids:list<string>,
     *   skipped:list<array{id:string,reason:string}>
     * }
     */
    public function toMarkdown(array $artifacts, array $options = []): array
    {
        $projectId = max(0, (int) ($options['project_id'] ?? 0));
        $includeUnpublished = (bool) ($options['include_unpublished'] ?? false);
        $slugWhitelist = $this->normalizeSlugList($options['artifact_type_slugs'] ?? null);

        $previewUrl = '';
        $brand = '';
        $summary = '';
        $skipped = [];
        $candidates = [];

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $id = trim((string) ($artifact['id'] ?? ''));
            $slug = strtolower(trim((string) ($artifact['artifactTypeSlug'] ?? '')));
            $title = trim((string) ($artifact['title'] ?? ''));
            $published = (int) ($artifact['published'] ?? 0);
            $version = (int) ($artifact['version'] ?? 0);
            $externalUrl = trim((string) ($artifact['externalUrl'] ?? ''));

            if (! $includeUnpublished && $published !== 1) {
                $skipped[] = ['id' => $id !== '' ? $id : 'unknown', 'reason' => 'unpublished'];

                continue;
            }

            if ($slugWhitelist !== null && ($slug === '' || ! in_array($slug, $slugWhitelist, true))) {
                $skipped[] = ['id' => $id !== '' ? $id : 'unknown', 'reason' => 'slug_filtered'];

                continue;
            }

            $payload = $this->decodePayload($artifact['payloadJson'] ?? null);
            if ($externalUrl === '' && is_string($payload['url'] ?? null)) {
                $externalUrl = trim((string) $payload['url']);
            }

            if ($slug === 'preview-site' || ($externalUrl !== '' && ! $this->payloadHasTextContent($payload))) {
                if ($previewUrl === '' && $externalUrl !== '') {
                    $previewUrl = $externalUrl;
                }
                $skipped[] = ['id' => $id !== '' ? $id : 'unknown', 'reason' => 'no_text_content'];

                continue;
            }

            if (is_string($payload['brand'] ?? null) && trim((string) $payload['brand']) !== '' && $brand === '') {
                $brand = trim((string) $payload['brand']);
            }
            if (is_string($payload['summary'] ?? null) && trim((string) $payload['summary']) !== '' && $summary === '') {
                $summary = trim((string) $payload['summary']);
            }

            $content = $this->extractContentMarkdown($payload, $slug, $title);
            if ($content === '') {
                if ($previewUrl === '' && $externalUrl !== '') {
                    $previewUrl = $externalUrl;
                }
                $skipped[] = ['id' => $id !== '' ? $id : 'unknown', 'reason' => 'no_text_content'];

                continue;
            }

            $normalizedHash = hash('sha256', $this->normalizeForDedupe($content));
            $priority = $this->priorityScore($slug, $title, $version, $id);

            $candidates[] = [
                'id' => $id !== '' ? $id : 'unknown',
                'slug' => $slug,
                'title' => $title !== '' ? $title : ($slug !== '' ? $slug : '未命名产出'),
                'content' => $content,
                'hash' => $normalizedHash,
                'priority' => $priority,
                'version' => $version,
            ];
        }

        // 去重：同 hash 保留 priority 更高者
        $bestByHash = [];
        foreach ($candidates as $candidate) {
            $hash = (string) $candidate['hash'];
            if (! isset($bestByHash[$hash]) || $candidate['priority'] > $bestByHash[$hash]['priority']) {
                if (isset($bestByHash[$hash])) {
                    $skipped[] = [
                        'id' => (string) $bestByHash[$hash]['id'],
                        'reason' => 'duplicate_content',
                    ];
                }
                $bestByHash[$hash] = $candidate;
            } else {
                $skipped[] = [
                    'id' => (string) $candidate['id'],
                    'reason' => 'duplicate_content',
                ];
            }
        }

        $selected = array_values($bestByHash);
        usort($selected, static function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }

            return strcmp((string) $b['id'], (string) $a['id']);
        });

        $nameHint = trim((string) ($options['project_name'] ?? ''));
        if ($nameHint === '') {
            $nameHint = $brand !== '' ? $brand : ($projectId > 0 ? 'Jiey 项目 #'.$projectId : 'Jiey Flow 导入');
        }

        $descriptionHint = trim((string) ($options['project_description'] ?? ''));
        if ($descriptionHint === '') {
            $descriptionHint = $summary;
        }

        $markdown = $this->assembleMarkdown(
            $nameHint,
            $projectId,
            $previewUrl,
            $selected
        );

        $includedIds = array_map(static fn (array $row): string => (string) $row['id'], $selected);

        return [
            'markdown' => $markdown,
            'name' => $nameHint,
            'description' => $descriptionHint,
            'brand' => $brand,
            'preview_url' => $previewUrl,
            'project_id' => $projectId,
            'artifact_count' => count($artifacts),
            'included_artifact_ids' => $includedIds,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<string>|null  $slugs
     * @return list<string>|null
     */
    private function normalizeSlugList(mixed $slugs): ?array
    {
        if (! is_array($slugs) || $slugs === []) {
            return null;
        }

        $normalized = [];
        foreach ($slugs as $slug) {
            if (! is_string($slug) && ! is_numeric($slug)) {
                continue;
            }
            $value = strtolower(trim((string) $slug));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payloadJson): array
    {
        if (is_array($payloadJson)) {
            return $payloadJson;
        }

        if (! is_string($payloadJson) || trim($payloadJson) === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasTextContent(array $payload): bool
    {
        return $this->extractContentMarkdown($payload, '', '') !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractContentMarkdown(array $payload, string $slug, string $title): string
    {
        $rawContent = $payload['content'] ?? null;
        if (! is_string($rawContent)) {
            return '';
        }

        $content = trim($rawContent);
        if ($content === '') {
            return '';
        }

        $jsonOutput = (bool) ($payload['jsonOutput'] ?? false);
        if ($jsonOutput || $this->looksLikeJsonObject($content)) {
            $asJson = json_decode($content, true);
            if (is_array($asJson)) {
                return $this->jsonPayloadToMarkdown($asJson, $slug, $title);
            }
        }

        return $content;
    }

    private function looksLikeJsonObject(string $content): bool
    {
        $trimmed = ltrim($content);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonPayloadToMarkdown(array $data, string $slug, string $title): string
    {
        $lines = [];
        $heading = $title !== '' ? $title : ($slug !== '' ? $slug : '结构化产出');
        $lines[] = '### '.$heading;

        $brand = $data['brand'] ?? null;
        if (is_array($brand)) {
            $brandName = trim((string) ($brand['name'] ?? ''));
            $brandDesc = trim((string) ($brand['description'] ?? ''));
            if ($brandName !== '') {
                $lines[] = '- 品牌：'.$brandName;
            }
            if ($brandDesc !== '') {
                $lines[] = '- 品牌说明：'.$brandDesc;
            }
        } elseif (is_string($brand) && trim($brand) !== '') {
            $lines[] = '- 品牌：'.trim($brand);
        }

        if (isset($data['portalPlan']) && is_array($data['portalPlan'])) {
            $portals = [];
            foreach ($data['portalPlan'] as $portal) {
                if (! is_array($portal)) {
                    continue;
                }
                $label = trim((string) ($portal['label'] ?? $portal['key'] ?? ''));
                if ($label !== '') {
                    $portals[] = $label;
                }
            }
            if ($portals !== []) {
                $lines[] = '- 端规划：'.implode('、', $portals);
            }
        }

        if (isset($data['pages']) && is_array($data['pages'])) {
            $lines[] = '';
            $lines[] = '#### 页面清单';
            $count = 0;
            foreach ($data['pages'] as $page) {
                if (! is_array($page)) {
                    continue;
                }
                $pageTitle = trim((string) ($page['title'] ?? ''));
                $path = trim((string) ($page['path'] ?? ''));
                $portal = trim((string) ($page['portal'] ?? ''));
                if ($pageTitle === '' && $path === '') {
                    continue;
                }
                $label = $pageTitle !== '' ? $pageTitle : $path;
                $meta = array_filter([$portal, $path], static fn (string $part): bool => $part !== '');
                $lines[] = '- '.$label.($meta !== [] ? '（'.implode(' / ', $meta).'）' : '');
                $count++;
                if ($count >= 80) {
                    $lines[] = '- …';
                    break;
                }
            }
        }

        // 若几乎没有可识别字段，退回紧凑 JSON，避免丢信息
        if (count($lines) <= 1) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return $encoded !== false ? "```json\n".$encoded."\n```" : '';
        }

        return trim(implode("\n", $lines));
    }

    private function normalizeForDedupe(string $content): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);

        return mb_strtolower($normalized, 'UTF-8');
    }

    private function priorityScore(string $slug, string $title, int $version, string $id): int
    {
        $score = 0;
        $preferredIndex = array_search($slug, self::PREFERRED_TEXT_SLUGS, true);
        if ($preferredIndex !== false) {
            $score += 1000 - ((int) $preferredIndex * 10);
        }
        if (str_contains($slug, 'prd') || str_contains($slug, 'product')) {
            $score += 200;
        }
        if (str_contains($slug, 'xdna') || str_contains($slug, 'design')) {
            $score += 50;
        }
        $score += min(100, $version * 10);
        $score += min(50, mb_strlen($title, 'UTF-8'));
        if (ctype_digit($id)) {
            $score += min(20, (int) $id % 20);
        }

        return $score;
    }

    /**
     * @param  list<array{id:string,slug:string,title:string,content:string,hash:string,priority:int,version:int}>  $selected
     */
    private function assembleMarkdown(string $name, int $projectId, string $previewUrl, array $selected): string
    {
        $parts = [];
        $parts[] = '# '.$name.' 知识库';
        $parts[] = '';
        $parts[] = '> 来源：Jiey Flow'.($projectId > 0 ? ' project_id='.$projectId : '');
        if ($previewUrl !== '') {
            $parts[] = '> 预览：'.$previewUrl;
        }
        $parts[] = '';

        $used = 0;
        $header = implode("\n", $parts);
        $used += mb_strlen($header, 'UTF-8');

        foreach ($selected as $item) {
            $section = [];
            $section[] = '## '.(string) $item['title'];
            $section[] = '<!-- artifact_id='.(string) $item['id'].' type='.(string) $item['slug'].' -->';
            $section[] = '';
            $section[] = (string) $item['content'];
            $section[] = '';
            $block = implode("\n", $section);
            $blockLen = mb_strlen($block, 'UTF-8');
            if ($used + $blockLen > self::MAX_MARKDOWN_CHARS) {
                break;
            }
            $parts[] = $block;
            $used += $blockLen;
        }

        return trim(implode("\n", $parts));
    }
}
