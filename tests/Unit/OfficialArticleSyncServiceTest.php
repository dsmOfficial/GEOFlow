<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\OfficialArticleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfficialArticleSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geoflow.jiey.enabled' => true,
            'geoflow.jiey.api_base' => 'https://api.gongxingglobal.com',
            'geoflow.jiey.internal_secret' => 'test-jiey-secret',
            'geoflow.jiey.official_source_link_enabled' => true,
            'geoflow.jiey.official_default_category_slug' => 'tech-blog',
        ]);
    }

    public function test_it_syncs_article_and_appends_source_link(): void
    {
        if (! Schema::hasColumn('articles', 'official_sync_status')) {
            $this->markTestSkipped('official sync columns unavailable');
        }

        $category = Category::query()->create([
            'name' => '技术博客',
            'slug' => 'tech-blog',
            'description' => '',
            'sort_order' => 1,
        ]);
        $author = Author::query()->create([
            'name' => '测试作者',
            'bio' => '',
            'email' => '',
            'avatar' => '',
            'website' => '',
            'social_links' => '',
        ]);

        $article = Article::query()->create([
            'title' => 'GEO同事联调测试文章',
            'slug' => 'geo-colleague-test',
            'excerpt' => 'GEO → Jiey CMS 联调',
            'content' => "# GEO同事联调\n\n这是同步到 Jiey 官网的测试正文。",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => null,
            'original_keyword' => '联调',
            'keywords' => 'GEO,Jiey,联调',
            'meta_description' => 'GEOFlow同步到官网联调',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'view_count' => 0,
        ]);

        Http::fake([
            'https://api.gongxingglobal.com/api/v1/internal/geoflow/articles' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'id' => 'cms-1001',
                    'url' => 'https://www.gongxingglobal.com/blog/geo-colleague-test',
                    'external_id' => 'geoflow-article-'.$article->id,
                ],
            ]),
        ]);

        $result = app(OfficialArticleSyncService::class)->sync($article->fresh());

        $this->assertSame('synced', $result['sync_status']);
        $this->assertSame('https://www.gongxingglobal.com/blog/geo-colleague-test', $result['official_url']);
        $this->assertTrue($result['source_link_appended']);

        $article->refresh();
        $this->assertSame('synced', (string) $article->official_sync_status);
        $this->assertSame('cms-1001', (string) $article->official_remote_id);
        $this->assertStringContainsString('official-source-link:start', (string) $article->content);
        $this->assertStringContainsString('https://www.gongxingglobal.com/blog/geo-colleague-test', (string) $article->content);

        Http::assertSent(function ($request) use ($article): bool {
            if ($request->url() !== 'https://api.gongxingglobal.com/api/v1/internal/geoflow/articles') {
                return false;
            }
            if (! $request->hasHeader('X-Jiey-Internal-Signature') || ! $request->hasHeader('X-Jiey-Internal-Timestamp')) {
                return false;
            }

            $data = $request->data();
            $this->assertSame('publish', $data['action'] ?? null);
            $this->assertSame('geoflow-article-'.$article->id, $data['article']['external_id'] ?? null);
            $this->assertSame('GEO同事联调测试文章', $data['article']['title'] ?? null);
            $this->assertSame('tech-blog', $data['article']['category_slug'] ?? null);
            $this->assertSame('publish', $data['article']['status'] ?? null);

            $ts = $request->header('X-Jiey-Internal-Timestamp')[0] ?? '';
            $sig = $request->header('X-Jiey-Internal-Signature')[0] ?? '';
            $raw = $request->body();
            $payload = $ts.'.POST./api/v1/internal/geoflow/articles.'.hash('sha256', $raw);
            $expected = hash_hmac('sha256', $payload, 'test-jiey-secret');

            return hash_equals($expected, $sig);
        });
    }

    public function test_append_source_link_is_idempotent(): void
    {
        $service = app(OfficialArticleSyncService::class);
        $article = new Article([
            'content' => "# 标题\n\n正文",
        ]);

        // 不落库，直接测纯函数式拼接
        $ref = new \ReflectionClass($service);
        // use public method via temporary model save not needed: method is public
        $content1 = $this->applyLink($service, "# 标题\n\n正文", 'https://a.example/x');
        $content2 = $this->applyLink($service, $content1, 'https://b.example/y');

        $this->assertSame(1, substr_count($content2, 'official-source-link:start'));
        $this->assertStringContainsString('https://b.example/y', $content2);
        $this->assertStringNotContainsString('https://a.example/x', $content2);
    }

    private function applyLink(OfficialArticleSyncService $service, string $content, string $url): string
    {
        $category = Category::query()->first() ?? Category::query()->create([
            'name' => '技术博客',
            'slug' => 'tech-blog-'.substr(md5($url), 0, 6),
            'description' => '',
            'sort_order' => 1,
        ]);
        $author = Author::query()->first() ?? Author::query()->create([
            'name' => '测试作者',
            'bio' => '',
            'email' => '',
            'avatar' => '',
            'website' => '',
            'social_links' => '',
        ]);

        $article = Article::query()->create([
            'title' => 't',
            'slug' => 's-'.md5($url.microtime(true)),
            'excerpt' => '',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => null,
            'original_keyword' => '',
            'keywords' => '',
            'meta_description' => '',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'view_count' => 0,
        ]);
        $service->appendOrReplaceSourceLink($article, $url);

        return (string) $article->fresh()->content;
    }
}
