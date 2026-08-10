<?php

namespace Tests\Unit;

use App\Support\GeoFlow\ArticleGeneratedContentSanitizer;
use Tests\TestCase;

class ArticleGeneratedContentSanitizerTest extends TestCase
{
    public function test_it_strips_role_preamble_before_article_body(): void
    {
        $content = "好的，作为技术负责人，我将从架构决策、长期演进和复杂度控制的角度，输出这份项目评估。\n\n# 项目评估\n\n这是正文第一段。";

        $this->assertSame(
            "# 项目评估\n\n这是正文第一段。",
            ArticleGeneratedContentSanitizer::stripOpeningMetaTalk($content)
        );
    }

    public function test_it_strips_article_intro_phrases(): void
    {
        $this->assertSame(
            "# 完整项目实践\n\n正文开始。",
            ArticleGeneratedContentSanitizer::stripOpeningMetaTalk("好的，文章如下。\n\n# 完整项目实践\n\n正文开始。")
        );

        $this->assertSame(
            "# 项目评估\n\n正文开始。",
            ArticleGeneratedContentSanitizer::stripOpeningMetaTalk("好的，这是根据您的要求生成的项目评估文章。\n\n# 项目评估\n\n正文开始。")
        );
    }

    public function test_it_keeps_normal_body_that_starts_with_hao_de_mid_sentence(): void
    {
        $content = "团队达成一致后，好的方案通常具备清晰边界。\n\n第二段继续。";

        $this->assertSame($content, ArticleGeneratedContentSanitizer::stripOpeningMetaTalk($content));
    }
}
