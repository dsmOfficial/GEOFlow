<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\SmartImportJob;
use App\Services\GeoFlow\SmartImportService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SmartImportJieyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
            'geoflow.jiey.timeout_seconds' => 10,
            'geoflow.jiey.max_bytes' => 1024 * 1024,
        ]);
    }

    public function test_store_rejects_jiey_flow_when_disabled(): void
    {
        config(['geoflow.jiey.enabled' => false]);

        $this->postJson('/api/v1/smart-import', [
            'source_type' => 'jiey_flow',
            'project_id' => 51,
            'article_type' => 'project',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_store_requires_project_id_for_jiey_flow(): void
    {
        $this->postJson('/api/v1/smart-import', [
            'source_type' => 'jiey_flow',
            'article_type' => 'project',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_store_queues_jiey_flow_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/smart-import', [
            'source_type' => 'jiey_flow',
            'project_id' => 51,
            'article_type' => 'project',
            'article_count' => 3,
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.source_type', 'jiey_flow')
            ->assertJsonPath('data.project_id', 51)
            ->assertJsonPath('data.status', 'queued');

        $jobId = (int) $response->json('data.job_id');
        $this->assertDatabaseHas('smart_import_jobs', [
            'id' => $jobId,
            'source_type' => 'jiey_flow',
            'article_type' => 'project',
            'status' => 'queued',
        ]);
    }

    public function test_handle_imports_jiey_artifacts_into_knowledge_base_and_task(): void
    {
        $this->createChatModel();

        $prd = "# 生鲜商城 PRD\n\n## 1. 产品目标与范围\n\n产地直采、30分钟达、门店自提。";
        Http::fake([
            'https://api.gongxingglobal.com/api/v1/internal/flow/projects/51/artifacts' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    [
                        'id' => '22',
                        'projectId' => '51',
                        'artifactTypeSlug' => 'product-definition',
                        'title' => '产品定义',
                        'payloadJson' => json_encode([
                            'brand' => '鲜时达',
                            'content' => $prd,
                            'summary' => '果蔬即时零售电商',
                        ], JSON_UNESCAPED_UNICODE),
                        'externalUrl' => null,
                        'version' => 1,
                        'published' => 1,
                    ],
                    [
                        'id' => '20',
                        'projectId' => '51',
                        'artifactTypeSlug' => 'preview-site',
                        'title' => '预览站',
                        'payloadJson' => json_encode([
                            'url' => 'https://preview.gongxingglobal.com/previews/flow-51/',
                        ], JSON_UNESCAPED_UNICODE),
                        'externalUrl' => 'https://preview.gongxingglobal.com/previews/flow-51/',
                        'version' => 1,
                        'published' => 1,
                    ],
                ],
            ]),
            'https://ai.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"keywords":["生鲜","配送"],"titles":["如何做好社区生鲜配送"]}'],
                ]],
            ]),
        ]);

        MarkdownContentWriterAgent::fake([
            json_encode(['keywords' => ['生鲜电商', '产地直采', '门店自提', '即时配送', '果蔬']], JSON_UNESCAPED_UNICODE),
            json_encode(['titles' => [
                '社区生鲜电商如何做到 30 分钟达',
                '产地直采模式拆解',
                '门店自提提升复购的 5 个要点',
            ]], JSON_UNESCAPED_UNICODE),
        ]);

        $extraKb = KnowledgeBase::query()->create([
            'name' => 'jiey IDE 知识库',
            'description' => '',
            'content' => 'jiey IDE 是 AI 全栈代码生成器。',
            'character_count' => 20,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 20,
            'usage_count' => 0,
            'used_task_count' => 0,
        ]);
        $selectedPrompt = \App\Models\Prompt::query()->create([
            'name' => 'Jiey项目·全栈开发者视角',
            'type' => 'content',
            'content' => '围绕完整项目写作。',
            'variables' => '',
        ]);
        $imageLibrary = \App\Models\ImageLibrary::query()->create([
            'name' => '项目配图库',
            'description' => '',
            'image_count' => 5,
            'used_task_count' => 0,
        ]);

        // sqlite 最小测试库不含 task_schedules；任务生命周期在此 mock，聚焦 jiey 入库链路。
        $taskLifecycle = Mockery::mock(TaskLifecycleService::class);
        $taskLifecycle->shouldReceive('createTask')
            ->once()
            ->andReturnUsing(function (array $data) use ($extraKb, $selectedPrompt, $imageLibrary): array {
                $this->assertGreaterThan(0, (int) ($data['knowledge_base_id'] ?? 0));
                $this->assertContains((int) $extraKb->id, array_map('intval', $data['knowledge_base_ids'] ?? []));
                $this->assertSame((int) $selectedPrompt->id, (int) ($data['prompt_id'] ?? 0));
                $this->assertSame((int) $imageLibrary->id, (int) ($data['image_library_id'] ?? 0));
                $this->assertSame(2, (int) ($data['image_count'] ?? 0));
                $this->assertStringContainsString('jiey IDE 项目推广 · 全栈开发者', (string) ($data['name'] ?? ''));
                $this->assertStringContainsString('鲜时达', (string) ($data['name'] ?? ''));

                return ['id' => 99, 'name' => (string) $data['name'], 'status' => 'active'];
            });
        $taskLifecycle->shouldReceive('enqueueTask')
            ->once()
            ->with(99, 'generate_article', Mockery::type('array'))
            ->andReturn(['job_id' => 1001]);
        $this->app->instance(TaskLifecycleService::class, $taskLifecycle);

        $job = SmartImportJob::query()->create([
            'source_type' => 'jiey_flow',
            'article_type' => 'project',
            'input_data' => [
                'source_type' => 'jiey_flow',
                'article_type' => 'project',
                'project_id' => 51,
                'project_name' => '鲜时达',
                'article_count' => 3,
                'prompt_id' => (int) $selectedPrompt->id,
                'extra_knowledge_base_ids' => [(int) $extraKb->id],
                'image_library_id' => (int) $imageLibrary->id,
                'image_count' => 2,
            ],
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
        ]);

        app(SmartImportService::class)->handle($job);
        $job->refresh();

        $this->assertSame('completed', $job->status, (string) $job->error_message);
        $this->assertSame(100, (int) $job->progress_percent);
        $this->assertIsArray($job->result_json);

        $materials = $job->result_json['materials'] ?? [];
        $this->assertGreaterThan(0, (int) ($materials['knowledge_base_id'] ?? 0));
        $this->assertSame(51, (int) ($materials['jiey']['project_id'] ?? 0));
        $this->assertContains('22', $materials['jiey']['included_artifact_ids'] ?? []);

        $kb = KnowledgeBase::query()->findOrFail((int) $materials['knowledge_base_id']);
        $this->assertStringContainsString('产品目标与范围', (string) $kb->content);
        $this->assertStringContainsString('鲜时达', (string) $kb->name);
        $this->assertSame('Jiey Flow #51', (string) $kb->source_name);
        $this->assertSame('https://preview.gongxingglobal.com/previews/flow-51/', (string) $kb->source_url);
        $this->assertGreaterThan(0, $kb->chunks()->count());

        $this->assertSame(99, (int) ($job->result_json['task']['id'] ?? 0));
        $this->assertStringContainsString('jiey IDE 项目推广 · 全栈开发者', (string) ($job->result_json['task']['name'] ?? ''));
        $this->assertSame(1001, (int) ($job->result_json['enqueued_job_id'] ?? 0));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.gongxingglobal.com/api/v1/internal/flow/projects/51/artifacts'
            && $request->hasHeader('X-Jiey-Internal-Signature')
            && $request->hasHeader('X-Jiey-Internal-Timestamp'));
    }

    private function createChatModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }
}
