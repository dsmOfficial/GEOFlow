<?php

namespace Tests\Unit;

use App\Services\GeoFlow\JieyFlowArtifactNormalizer;
use Tests\TestCase;

class JieyFlowArtifactNormalizerTest extends TestCase
{
    public function test_it_extracts_markdown_skips_preview_and_dedupes_duplicate_prd(): void
    {
        $prd = "# 生鲜商城 PRD\n\n## 1. 产品目标与范围\n\n产地直采、30分钟达。";
        $artifacts = [
            [
                'id' => '22',
                'projectId' => '51',
                'artifactTypeSlug' => 'product-definition',
                'title' => '谱系回填 · 产品定义（自 PRD）',
                'payloadJson' => json_encode([
                    'slug' => 'product-definition',
                    'brand' => '鲜时达',
                    'content' => $prd,
                    'summary' => '单城单区试点的C端果蔬即时零售电商',
                ], JSON_UNESCAPED_UNICODE),
                'externalUrl' => null,
                'version' => 1,
                'published' => 1,
            ],
            [
                'id' => '21',
                'projectId' => '51',
                'artifactTypeSlug' => 'prd-doc',
                'title' => '谱系回填 · 填充详细段落 · prd-doc',
                'payloadJson' => json_encode([
                    'slug' => 'prd.detailed',
                    'content' => $prd,
                    'jsonOutput' => false,
                ], JSON_UNESCAPED_UNICODE),
                'externalUrl' => null,
                'version' => 1,
                'published' => 1,
            ],
            [
                'id' => '20',
                'projectId' => '51',
                'artifactTypeSlug' => 'preview-site',
                'title' => '发布预览 · preview-site',
                'payloadJson' => json_encode([
                    'url' => 'https://preview.gongxingglobal.com/previews/flow-51/',
                    'status' => 'published',
                    'workId' => 'flow-51',
                ], JSON_UNESCAPED_UNICODE),
                'externalUrl' => 'https://preview.gongxingglobal.com/previews/flow-51/',
                'version' => 1,
                'published' => 1,
            ],
            [
                'id' => '19',
                'projectId' => '51',
                'artifactTypeSlug' => 'xdna-v2',
                'title' => '从 PRD 生成页面契约 · xdna-v2',
                'payloadJson' => json_encode([
                    'slug' => 'design.xdna',
                    'content' => json_encode([
                        'version' => '2.2',
                        'brand' => [
                            'name' => '鲜时达',
                            'description' => '果蔬即时零售',
                        ],
                        'portalPlan' => [
                            ['key' => 'mobile', 'label' => '用户端小程序'],
                            ['key' => 'admin', 'label' => '管理后台'],
                        ],
                        'pages' => [
                            [
                                'portal' => 'mobile',
                                'path' => '/pages/home/index',
                                'title' => '首页',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'jsonOutput' => true,
                ], JSON_UNESCAPED_UNICODE),
                'externalUrl' => null,
                'version' => 1,
                'published' => 1,
            ],
        ];

        $result = app(JieyFlowArtifactNormalizer::class)->toMarkdown($artifacts, [
            'project_id' => 51,
        ]);

        $this->assertSame('鲜时达', $result['name']);
        $this->assertSame('单城单区试点的C端果蔬即时零售电商', $result['description']);
        $this->assertSame('鲜时达', $result['brand']);
        $this->assertSame('https://preview.gongxingglobal.com/previews/flow-51/', $result['preview_url']);
        $this->assertSame(4, $result['artifact_count']);

        // product-definition 优先于重复的 prd-doc；preview 跳过；xdna 转摘要纳入
        $this->assertContains('22', $result['included_artifact_ids']);
        $this->assertContains('19', $result['included_artifact_ids']);
        $this->assertNotContains('21', $result['included_artifact_ids']);
        $this->assertNotContains('20', $result['included_artifact_ids']);

        $skipReasons = collect($result['skipped'])->keyBy('id');
        $this->assertSame('duplicate_content', $skipReasons['21']['reason'] ?? null);
        $this->assertSame('no_text_content', $skipReasons['20']['reason'] ?? null);

        $this->assertStringContainsString('# 鲜时达 知识库', $result['markdown']);
        $this->assertStringContainsString('project_id=51', $result['markdown']);
        $this->assertStringContainsString('预览：https://preview.gongxingglobal.com/previews/flow-51/', $result['markdown']);
        $this->assertStringContainsString('产品目标与范围', $result['markdown']);
        $this->assertStringContainsString('用户端小程序', $result['markdown']);
        $this->assertStringContainsString('首页', $result['markdown']);
        $this->assertStringNotContainsString('```json', $result['markdown']);
    }

    public function test_it_skips_unpublished_by_default(): void
    {
        $artifacts = [
            [
                'id' => '1',
                'artifactTypeSlug' => 'prd-doc',
                'title' => '草稿 PRD',
                'payloadJson' => json_encode(['content' => '# Draft'], JSON_UNESCAPED_UNICODE),
                'published' => 0,
                'version' => 1,
            ],
        ];

        $result = app(JieyFlowArtifactNormalizer::class)->toMarkdown($artifacts, [
            'project_id' => 9,
            'project_name' => '测试项目',
        ]);

        $this->assertSame([], $result['included_artifact_ids']);
        $this->assertSame('unpublished', $result['skipped'][0]['reason'] ?? null);
        $this->assertStringContainsString('# 测试项目 知识库', $result['markdown']);
    }

    public function test_slug_whitelist_filters_artifacts(): void
    {
        $artifacts = [
            [
                'id' => '1',
                'artifactTypeSlug' => 'prd-doc',
                'title' => 'PRD',
                'payloadJson' => json_encode(['content' => '# PRD body'], JSON_UNESCAPED_UNICODE),
                'published' => 1,
                'version' => 1,
            ],
            [
                'id' => '2',
                'artifactTypeSlug' => 'xdna-v2',
                'title' => 'XDNA',
                'payloadJson' => json_encode([
                    'content' => json_encode(['brand' => ['name' => 'X']], JSON_UNESCAPED_UNICODE),
                    'jsonOutput' => true,
                ], JSON_UNESCAPED_UNICODE),
                'published' => 1,
                'version' => 1,
            ],
        ];

        $result = app(JieyFlowArtifactNormalizer::class)->toMarkdown($artifacts, [
            'project_id' => 1,
            'artifact_type_slugs' => ['prd-doc'],
        ]);

        $this->assertSame(['1'], $result['included_artifact_ids']);
        $this->assertSame('slug_filtered', collect($result['skipped'])->firstWhere('id', '2')['reason'] ?? null);
    }
}
