<?php

namespace App\Support\GeoFlow;

/**
 * 清洗模型生成文章开头的确认语、角色前言等非正文内容。
 */
final class ArticleGeneratedContentSanitizer
{
    /**
     * 删除文首连续的元话术，保留真正文章正文。
     */
    public static function stripOpeningMetaTalk(string $content): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $content));
        if ($normalized === '') {
            return '';
        }

        $paragraphs = preg_split("/\n{2,}/u", $normalized) ?: [];
        $index = 0;
        while ($index < count($paragraphs) && self::isOpeningMetaTalkParagraph((string) $paragraphs[$index])) {
            $index++;
        }

        if ($index === 0) {
            return self::stripLeadingMetaTalkLines($normalized);
        }

        $remaining = trim(implode("\n\n", array_slice($paragraphs, $index)));

        return $remaining !== '' ? self::stripLeadingMetaTalkLines($remaining) : '';
    }

    private static function isOpeningMetaTalkParagraph(string $paragraph): bool
    {
        $text = trim($paragraph);
        if ($text === '') {
            return true;
        }

        // 真正正文通常以 Markdown 标题、列表或较长叙述开始。
        if (preg_match('/^#{1,6}\s+\S/u', $text) === 1) {
            return false;
        }

        $compact = preg_replace('/\s+/u', '', $text) ?? $text;
        if (mb_strlen($compact, 'UTF-8') > 160) {
            return false;
        }

        $patterns = [
            '/^好的[，,：:\s]/u',
            '/文章如下/u',
            '/正文如下/u',
            '/根据您的要求/u',
            '/根据你的要求/u',
            '/我将.{0,40}(输出|生成|撰写|写)/u',
            '/我来.{0,40}(输出|生成|撰写|写)/u',
            '/作为.{0,30}(技术负责人|CTO|产品经理|顾问|开发者|创业者)/u',
            '/^(Sure|Of course|Certainly|Alright|Okay)[,!.\s]/iu',
            '/^Here(\'s| is)\b/iu',
            '/as (a |an )?(CTO|technical lead|product manager)/iu',
            '/I will (now )?(write|output|generate|produce)/iu',
            '/project evaluation article/iu',
            '/项目评估文章/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function stripLeadingMetaTalkLines(string $content): string
    {
        $lines = preg_split('/\n/u', $content) ?: [];
        $index = 0;
        while ($index < count($lines)) {
            $line = trim((string) $lines[$index]);
            if ($line === '') {
                $index++;

                continue;
            }
            if (! self::isOpeningMetaTalkParagraph($line)) {
                break;
            }
            $index++;
        }

        return trim(implode("\n", array_slice($lines, $index)));
    }
}
