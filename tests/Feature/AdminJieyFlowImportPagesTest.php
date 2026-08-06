<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SmartImportJob;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminJieyFlowImportPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_super_admin_can_open_jiey_import_pages(): void
    {
        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
        ]);
        $this->createChatModel();
        $admin = $this->superAdmin();
        \App\Models\KnowledgeBase::query()->create([
            'name' => 'jiey IDE 知识库',
            'description' => '',
            'content' => 'jiey product facts',
            'character_count' => 10,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 10,
            'usage_count' => 0,
            'used_task_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.jiey-flow-import'))
            ->assertOk()
            ->assertSee(__('admin.jiey_flow_import.page_heading'), false)
            ->assertSee('name="project_id"', false)
            ->assertSee('name="prompt_id"', false)
            ->assertSee('name="extra_knowledge_base_ids[]"', false)
            ->assertSee('name="image_library_id"', false)
            ->assertSee('name="image_count"', false)
            ->assertSee('Jiey项目·全栈开发者视角', false)
            ->assertSee('Jiey项目·独立创业者视角', false)
            ->assertSee('Jiey项目·产品经理视角', false)
            ->assertSee('Jiey项目·CTO技术管理视角', false)
            ->assertSee('Jiey项目·行业顾问视角', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(route('admin.jiey-flow-import'), false)
            ->assertSee(__('admin.jiey_flow_import.materials_card_title'), false);
    }

    public function test_super_admin_can_queue_jiey_import_job_from_admin_form(): void
    {
        Queue::fake();
        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
        ]);
        $this->createChatModel();
        $admin = $this->superAdmin();

        $prompt = \App\Models\Prompt::query()->create([
            'name' => '自定义项目提示词',
            'type' => 'content',
            'content' => '围绕完整项目写作。',
            'variables' => '',
        ]);
        $extraKb = \App\Models\KnowledgeBase::query()->create([
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
        $imageLibrary = \App\Models\ImageLibrary::query()->create([
            'name' => '项目配图库',
            'description' => '',
            'image_count' => 3,
            'used_task_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.jiey-flow-import.store'), [
                'project_id' => 51,
                'article_type' => 'project',
                'project_name' => '鲜时达',
                'article_count' => 5,
                'prompt_id' => (int) $prompt->id,
                'extra_knowledge_base_ids' => [(int) $extraKb->id],
                'image_library_id' => (int) $imageLibrary->id,
                'image_count' => 2,
            ]);

        $job = SmartImportJob::query()->firstOrFail();
        $this->assertSame('jiey_flow', $job->source_type);
        $this->assertSame(51, (int) ($job->input_data['project_id'] ?? 0));
        $this->assertSame('鲜时达', (string) ($job->input_data['project_name'] ?? ''));
        $this->assertSame((int) $prompt->id, (int) ($job->input_data['prompt_id'] ?? 0));
        $this->assertSame([(int) $extraKb->id], $job->input_data['extra_knowledge_base_ids'] ?? []);
        $this->assertSame((int) $imageLibrary->id, (int) ($job->input_data['image_library_id'] ?? 0));
        $this->assertSame(2, (int) ($job->input_data['image_count'] ?? 0));

        $response->assertRedirect(route('admin.jiey-flow-import.show', ['jobId' => (int) $job->id]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.jiey-flow-import.show', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertSee('project 51', false)
            ->assertSee('鲜时达', false);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.jiey-flow-import.status', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('job_id', (int) $job->id)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('project_id', 51);
    }

    public function test_store_rejects_when_jiey_not_configured(): void
    {
        config([
            'geoflow.jiey.enabled' => false,
            'geoflow.jiey.internal_secret' => '',
        ]);
        $this->createChatModel();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.jiey-flow-import'))
            ->post(route('admin.jiey-flow-import.store'), [
                'project_id' => 51,
                'article_type' => 'project',
            ])
            ->assertRedirect(route('admin.jiey-flow-import'))
            ->assertSessionHasErrors('project_id');
    }

    private function superAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'jiey_super_admin',
            'password' => 'secret-123',
            'email' => 'jiey-super@example.com',
            'display_name' => 'Jiey Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function createChatModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Chat for Jiey UI',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }
}
