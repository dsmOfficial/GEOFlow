<?php

namespace App\Services\GeoFlow;

use App\Models\Prompt;

/**
 * Jiey 项目导入用的多角色正文提示词目录。
 *
 * 按固定 name 幂等写入 prompts 表，供后台下拉选择。
 */
final class JieyProjectRolePromptCatalog
{
    public const ROLE_FULLSTACK = 'full_stack_developer';

    public const ROLE_INDIE = 'indie_founder';

    public const ROLE_PM = 'product_manager';

    public const ROLE_CTO = 'cto_tech_lead';

    public const ROLE_CONSULTANT = 'industry_consultant';

    /**
     * @return list<array{role_key:string,name:string,label:string,content:string}>
     */
    public function definitions(): array
    {
        return [
            [
                'role_key' => self::ROLE_FULLSTACK,
                'name' => 'Jiey项目·全栈开发者视角',
                'label' => '全栈开发者',
                'content' => $this->fullstackPrompt(),
            ],
            [
                'role_key' => self::ROLE_INDIE,
                'name' => 'Jiey项目·独立创业者视角',
                'label' => '独立创业者',
                'content' => $this->indiePrompt(),
            ],
            [
                'role_key' => self::ROLE_PM,
                'name' => 'Jiey项目·产品经理视角',
                'label' => '产品经理',
                'content' => $this->pmPrompt(),
            ],
            [
                'role_key' => self::ROLE_CTO,
                'name' => 'Jiey项目·CTO技术管理视角',
                'label' => 'CTO/技术管理',
                'content' => $this->ctoPrompt(),
            ],
            [
                'role_key' => self::ROLE_CONSULTANT,
                'name' => 'Jiey项目·行业顾问视角',
                'label' => '行业顾问',
                'content' => $this->consultantPrompt(),
            ],
        ];
    }

    /**
     * 根据提示词名解析短角色标签，供任务命名区分文章类型。
     */
    public function labelForPromptName(?string $promptName): ?string
    {
        $promptName = trim((string) $promptName);
        if ($promptName === '') {
            return null;
        }

        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $promptName) {
                return $definition['label'];
            }
        }

        // 兼容手工改名但仍带“Jiey项目·xxx视角”前缀的情况
        if (preg_match('/^Jiey项目·(.+?)视角$/u', $promptName, $matches) === 1) {
            $label = trim((string) ($matches[1] ?? ''));

            return $label !== '' ? $label : null;
        }

        return null;
    }

    /**
     * 确保角色提示词存在；已存在则刷新 content（保持 name 稳定便于选择）。
     *
     * @return list<Prompt>
     */
    public function ensureInstalled(): array
    {
        $prompts = [];
        foreach ($this->definitions() as $definition) {
            $prompt = Prompt::query()->updateOrCreate(
                [
                    'name' => $definition['name'],
                    'type' => 'content',
                ],
                [
                    'content' => $definition['content'],
                    'variables' => 'title,keyword,Knowledge',
                ]
            );
            $prompts[] = $prompt;
        }

        return $prompts;
    }

    /**
     * @return list<array{id:int,name:string,role_key:string}>
     */
    public function options(): array
    {
        $this->ensureInstalled();
        $byName = Prompt::query()
            ->where('type', 'content')
            ->whereIn('name', array_column($this->definitions(), 'name'))
            ->get(['id', 'name'])
            ->keyBy('name');

        $options = [];
        foreach ($this->definitions() as $definition) {
            $prompt = $byName->get($definition['name']);
            if (! $prompt) {
                continue;
            }
            $options[] = [
                'id' => (int) $prompt->id,
                'name' => (string) $prompt->name,
                'role_key' => $definition['role_key'],
            ];
        }

        return $options;
    }

    private function sharedProjectRules(): string
    {
        return <<<'PROMPT'
写作总目标：
- 文章主题必须是「整个项目」的全景叙事，而不是某个小模块、单个页面、单个接口或局部功能点专题
- 请优先吸收参考知识中的项目资料；涉及 jiey IDE 的产品事实时，优先依据参考知识中的 jiey 相关内容，不要编造
- 可以自然提及项目可由 jiey IDE 加速生成/交付，但不要写成工具硬广
- 直接输出最终 Markdown 正文，从标题或第一段开始，不要输出确认语、角色自我介绍、提纲说明、占位符或证据编号（如 [K1]）
- 禁止输出“好的”“文章如下”“根据您的要求生成……”“作为技术负责人，我将……”等元话术

建议结构（可按角色侧重点调整篇幅，但不要丢掉项目整体）：
1. 项目定位与要解决的问题
2. 目标用户与核心场景
3. 整体方案/架构与业务闭环
4. 关键能力如何支撑整项目（局部能力只作证明，不喧宾夺主）
5. 落地路径、风险与价值总结
PROMPT;
    }

    private function fullstackPrompt(): string
    {
        return <<<PROMPT
以资深全栈开发者的视角，给同行写一篇关于完整项目的技术实践文章。
这是内部写作立场，正文中不要自我介绍角色身份，也不要输出确认语。

写作立场：
- 关注技术选型、分层架构、多端协同、数据模型、核心链路实现与工程交付
- 用开发者能落地的语言讲清楚「整系统怎么搭起来」
- 可适度展开技术细节，但仍要服务项目整体，而不是写成单模块教程

{$this->sharedProjectRules()}

额外要求：
- 标题与正文都要让人感到这是在讲一个可交付的完整系统
- 避免陷入某个 CRUD 页面或单一接口字段说明
PROMPT;
    }

    private function indiePrompt(): string
    {
        return <<<PROMPT
以独立创业者/独立开发者的视角，分享如何用更小团队做出完整项目并交付上线。
这是内部写作立场，正文中不要自我介绍角色身份，也不要输出确认语。

写作立场：
- 关注一人/小团队如何从 0 到 1：需求收敛、MVP 边界、交付节奏、成本与效率
- 强调完整项目闭环带来的商业价值，而不是炫技式模块细节
- 可自然提到借助 AI 全栈生成能力缩短交付周期，但重点仍是项目本身

{$this->sharedProjectRules()}

额外要求：
- 语言务实、可行动，面向同样想快速做出完整产品的创业者
- 讲清「先做什么、后做什么、如何验证」
PROMPT;
    }

    private function pmPrompt(): string
    {
        return <<<PROMPT
以产品经理的视角，撰写面向产品和业务协作的项目解读文章。
这是内部写作立场，正文中不要自我介绍角色身份，也不要输出确认语。

写作立场：
- 关注问题定义、用户画像、核心场景、需求优先级、验收标准与业务闭环
- 技术内容只服务于产品目标解释，不陷入实现细节堆砌
- 让读者理解这个项目为什么值得做、怎么验证、如何迭代

{$this->sharedProjectRules()}

额外要求：
- 多用场景与用户故事串联整项目，而不是模块清单罗列
- 明确范围取舍：做什么、不做什么、为什么
PROMPT;
    }

    private function ctoPrompt(): string
    {
        return <<<PROMPT
以 CTO / 技术负责人的视角，写给技术管理与架构决策者的项目评估文章。
这是内部写作立场，正文中不要出现“作为技术负责人”“我将输出这份评估”等自我介绍或确认语。

写作立场：
- 关注架构取舍、可扩展性、稳定性、研发效能、团队协作与交付风险
- 从「能否长期演进、能否控住复杂度」评估整个项目
- 对关键链路给出管理视角判断，而不是写工程师手册式细节

{$this->sharedProjectRules()}

额外要求：
- 突出技术决策背后的业务与组织原因
- 可讨论风险、SLO/SLA、扩展边界、人员配置与里程碑
PROMPT;
    }

    private function consultantPrompt(): string
    {
        return <<<PROMPT
以行业顾问的视角，撰写给客户/决策层看的项目方案解读文章。
这是内部写作立场，正文中不要自我介绍角色身份，也不要输出确认语。

写作立场：
- 关注行业痛点、方案价值、实施路径、投入产出与落地可行性
- 用业务语言讲清完整项目如何解决行业问题
- 技术架构只作支撑论据，避免过深代码层表述

{$this->sharedProjectRules()}

额外要求：
- 面向非纯技术读者也要读得懂
- 强调行业场景、竞争差异与落地建议
PROMPT;
    }
}
