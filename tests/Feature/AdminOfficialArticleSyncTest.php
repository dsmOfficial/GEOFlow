<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminOfficialArticleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
            'geoflow.jiey.official_source_link_enabled' => true,
            'geoflow.jiey.official_default_category_slug' => 'tech-blog',
        ]);
    }

    public function test_edit_page_shows_official_sync_button(): void
    {
        if (! Schema::hasColumn('articles', 'official_sync_status')) {
            $this->markTestSkipped('official sync columns unavailable');
        }

        $admin = $this->admin();
        $article = $this->createArticle();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertSee(__('admin.article_edit.official_sync.button'), false)
            ->assertSee(route('admin.articles.sync-official', ['articleId' => (int) $article->id]), false);
    }

    public function test_articles_list_shows_official_sync_status(): void
    {
        if (! Schema::hasColumn('articles', 'official_sync_status')) {
            $this->markTestSkipped('official sync columns unavailable');
        }

        $admin = $this->admin();
        $synced = $this->createArticle([
            'title' => '已同步官网文章',
            'slug' => 'official-list-synced',
            'official_sync_status' => 'synced',
            'official_url' => 'https://www.gongxingglobal.com/blog/list-synced',
            'official_synced_at' => now(),
        ]);
        $failed = $this->createArticle([
            'title' => '同步失败官网文章',
            'slug' => 'official-list-failed',
            'official_sync_status' => 'failed',
            'official_last_error' => 'upstream timeout',
        ]);
        $unsynced = $this->createArticle([
            'title' => '未同步官网文章',
            'slug' => 'official-list-empty',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee($synced->title, false)
            ->assertSee($failed->title, false)
            ->assertSee($unsynced->title, false)
            ->assertSee(__('admin.articles.official_sync.prefix').': '.__('admin.articles.official_sync.status.synced'), false)
            ->assertSee(__('admin.articles.official_sync.prefix').': '.__('admin.articles.official_sync.status.failed'), false)
            ->assertSee(__('admin.articles.official_sync.prefix').': '.__('admin.articles.official_sync.status.empty'), false)
            ->assertSee('https://www.gongxingglobal.com/blog/list-synced', false)
            ->assertSee('www.gongxingglobal.com', false);
    }

    public function test_admin_can_sync_article_to_official_site(): void
    {
        if (! Schema::hasColumn('articles', 'official_sync_status')) {
            $this->markTestSkipped('official sync columns unavailable');
        }

        $admin = $this->admin();
        $article = $this->createArticle();

        Http::fake([
            'https://api.gongxingglobal.com/api/v1/internal/geoflow/articles' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'id' => 'cms-88',
                    'url' => 'https://www.gongxingglobal.com/blog/demo-article',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.sync-official', ['articleId' => (int) $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertSessionHas('message');

        $article->refresh();
        $this->assertSame('synced', (string) $article->official_sync_status);
        $this->assertSame('https://www.gongxingglobal.com/blog/demo-article', (string) $article->official_url);
        $this->assertStringContainsString('原文链接', (string) $article->content);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.official-sync-status', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertJsonPath('sync_status', 'synced')
            ->assertJsonPath('official_url', 'https://www.gongxingglobal.com/blog/demo-article');
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'official_sync_admin',
            'password' => 'secret-123',
            'email' => 'official-sync@example.com',
            'display_name' => 'Official Sync Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'tech-blog'],
            [
                'name' => '技术博客',
                'description' => '',
                'sort_order' => 1,
            ],
        );
        $author = Author::query()->firstOrCreate(
            ['name' => '测试作者'],
            [
                'bio' => '',
                'email' => '',
                'avatar' => '',
                'website' => '',
                'social_links' => '',
            ],
        );

        return Article::query()->create(array_merge([
            'title' => '官网同步测试文章',
            'slug' => 'official-sync-demo-'.uniqid(),
            'excerpt' => '摘要',
            'content' => "# 标题\n\n正文内容",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => null,
            'original_keyword' => '同步',
            'keywords' => '同步,官网',
            'meta_description' => '描述',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'view_count' => 0,
        ], $overrides));
    }
}
