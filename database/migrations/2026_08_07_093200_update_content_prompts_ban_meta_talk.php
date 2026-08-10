<?php

use App\Services\GeoFlow\JieyProjectRolePromptCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 强化正文提示词：禁止确认语、角色前言等元话术进入文章正文。
     */
    public function up(): void
    {
        if (! Schema::hasTable('prompts')) {
            return;
        }

        $zhSuffix = "\n\n【输出边界】\n"
            ."请直接输出最终文章正文（Markdown），从标题或第一段正文开始写。\n"
            ."禁止输出确认语、角色前言、写作过程说明，例如“好的，”“文章如下。”“作为技术负责人，”“根据您的要求生成……”等。\n"
            ."不要重复提示词，不要输出占位符。";

        $enSuffix = "\n\n[Output Boundary]\n"
            ."Output only the final article body in Markdown. Start directly with the title or first body paragraph.\n"
            ."Do not include acknowledgements, role preambles, process notes, or phrases such as \"Sure,\", \"Here is the article,\", \"As a technical lead,\", or \"Based on your requirements,\".\n"
            ."Do not repeat the prompt or output placeholders.";

        $now = now();
        $prompts = DB::table('prompts')
            ->where('type', 'content')
            ->select(['id', 'name', 'content'])
            ->get();

        foreach ($prompts as $prompt) {
            $content = (string) ($prompt->content ?? '');
            if ($content === '') {
                continue;
            }

            if (
                str_contains($content, '禁止输出确认语')
                || str_contains($content, 'Do not include acknowledgements, role preambles')
            ) {
                continue;
            }

            $isEnglish = $this->looksLikeEnglishPrompt($content);
            $updated = rtrim($content)."\n".($isEnglish ? $enSuffix : $zhSuffix);

            DB::table('prompts')->where('id', (int) $prompt->id)->update([
                'content' => $updated,
                'updated_at' => $now,
            ]);
        }

        // Jiey 角色提示词由代码目录幂等刷新，覆盖旧的“你是一位……”开场。
        if (class_exists(JieyProjectRolePromptCatalog::class)) {
            app(JieyProjectRolePromptCatalog::class)->ensureInstalled();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 提示词可能已被人工编辑；不做破坏性回滚。
    }

    private function looksLikeEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }
};
