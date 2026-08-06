# Smart Import API — 异步一键导入设计

日期：2026-06-18

## 1. 目标

提供一个**公开 API**（无需认证），支持通过 Markdown 上传或 URL 智能采集，自动完成素材入库 → 提示词创建 → 任务创建 → 入队生成文章的完整链路。接口为异步模式，立即返回 job 信息，后台队列执行。

## 2. 流程

```
POST /api/v1/smart-import  →  返回 job_id + queued 状态
    ↓ (后台队列 ProcessSmartImportJob)
1. 解析输入（Markdown 直接入库 / URL 抓取+AI分析）
2. 创建/更新素材（知识库、关键词库、标题库）
3. 查找或创建提示词（jiey_ide / project）
4. 创建任务（article_count 篇文章，status=active）
5. 入队生成
6. 更新 job 状态为 completed
```

## 3. API 契约

### 3.1 发起导入 — `POST /api/v1/smart-import`

**认证：** 无需认证，公开接口

**请求体（JSON）：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `source_type` | string | 是 | `url` 或 `markdown` |
| `url` | string | url 模式必填 | 目标网页 URL |
| `markdown_content` | string | markdown 模式必填 | Markdown 文本内容 |
| `markdown_name` | string | 否 | 知识库名称，不传则从内容推断 |
| `article_type` | string | 是 | `jiey_ide` 或 `project` |
| `article_count` | int | 否 | 生成文章数，默认 10，范围 1-50 |
| `project_name` | string | project 模式建议 | 项目名称 |
| `project_description` | string | project 模式建议 | 项目简介 |
| `model_id` | int | 否 | 指定 AI 模型，不传则自动选择 |

**响应 202：**

```json
{
  "request_id": "uuid",
  "data": {
    "job_id": 1,
    "status": "queued",
    "source_type": "url",
    "article_type": "jiey_ide",
    "article_count": 10,
    "created_at": "2026-06-18T10:00:00.000000Z"
  }
}
```

### 3.2 查询进度 — `GET /api/v1/smart-import/{job}`

**认证：** 无需认证，公开接口

**响应 200：**

```json
{
  "request_id": "uuid",
  "data": {
    "job_id": 1,
    "status": "processing",
    "source_type": "url",
    "article_type": "jiey_ide",
    "current_step": "analyzing_url",
    "progress_percent": 45,
    "result": null,
    "error_message": null,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

**完成时（status=completed）：**

```json
{
  "data": {
    "job_id": 1,
    "status": "completed",
    "progress_percent": 100,
    "result": {
      "materials": {
        "knowledge_base_id": 1,
        "keyword_library_id": 2,
        "title_library_id": 3,
        "keywords_count": 10,
        "titles_count": 50
      },
      "prompt": {
        "id": 5,
        "name": "jiey IDE 推广"
      },
      "task": {
        "id": 10,
        "name": "jiey IDE 推广 - example.com",
        "status": "active",
        "article_limit": 10
      },
      "enqueued_job_id": 42
    }
  }
}
```

### 3.3 步骤枚举

| 步骤 | 说明 |
|------|------|
| `queued` | 等待执行 |
| `parsing_input` | 解析 Markdown / 抓取 URL |
| `analyzing_url` | AI 分析网页内容（仅 URL 模式） |
| `committing_materials` | 入库知识库、关键词库、标题库 |
| `ensuring_prompt` | 查找或创建提示词 |
| `creating_task` | 创建任务 |
| `enqueuing` | 入队生成 |
| `completed` | 完成 |
| `failed` | 失败 |

## 4. 提示词设计

### 4.1 jiey_ide — jiey IDE 推广

```
你是 GEOFlow 的内容生成专家。请围绕 jiey IDE（界外共行）撰写一篇 GEO 优化文章。

jiey IDE 是一款 AI 全栈代码生成器桌面应用（支持 macOS/Windows/Linux），
用户只需用自然语言描述业务需求，即可自动生成四层代码：
- Spring Boot 后端 API
- Vue3 管理后台
- UniApp 跨端移动应用
- 响应式营销网站

核心卖点：
- 一个人干一个团队的活，开发周期缩短 80%，平均交付仅 3 天
- 代码归用户所有，可直接交付客户
- 生产级质量，媲美高级工程师
- 内置模块市场，一键安装订单管理、会员系统等预制模块
- Jiey Methodology 标准化 AI 工作流：明确需求→建模→生成→验证

目标受众：独立开发者、外包团队、个人创业者、中小技术团队。
文章需自然融入品牌信息，风格专业可信，面向 AI 搜索/GEO 优化。
```

### 4.2 project — jiey IDE 项目推广

```
你是 GEOFlow 的内容生成专家。请围绕一个由 jiey IDE（界外共行）生成的完整项目撰写 GEO 优化文章。

项目名称：{project_name}
项目简介：{project_description}

文章要求：
- 展示该项目的技术栈、核心功能和业务价值
- 自然提及该项目由 jiey IDE 生成
- jiey IDE 是一款 AI 全栈代码生成器，用自然语言即可生成 Spring Boot + Vue3 + UniApp + 网站四层代码
- 风格专业可信，突出项目本身的价值而非工具广告

目标受众：对项目所在领域感兴趣的技术人员、创业者、潜在用户。
```

## 5. 核心逻辑（SmartImportService）

### 5.1 解析输入

- **url 模式：** 复用 `UrlImportProcessingService` 的 `process()` + `commit()` 方法（抓取→AI 分析→入库素材）
- **markdown 模式：** 直接创建 KnowledgeBase（content=markdown_content，file_type=markdown），然后调用 AI 从 Markdown 提取关键词和标题，创建 KeywordLibrary 和 TitleLibrary

### 5.2 Markdown 模式的 AI 提取

当 source_type=markdown 时，需要调用 AI 从 Markdown 内容中提取关键词和标题，复用 `UrlImportProcessingService` 中已有的清洗/关键词/标题 AI prompt 体系。

### 5.3 提示词复用

- 每次请求都查找已有提示词（按 name 匹配），存在则复用，不存在则创建
- jiey_ide 提示词 name: "jiey IDE 推广"
- project 提示词 name: "jiey IDE 项目推广"

### 5.4 创建任务

- 使用 `TaskLifecycleService::createTask()` 创建任务
- 关联知识库（knowledge_base_id + knowledge_base_ids）
- 关联标题库、提示词、AI 模型
- status=active，article_limit=article_count
- publish_scope=local_only（默认仅发布到本站）
- need_review=1（需审核）

### 5.5 入队生成

- 使用 `TaskLifecycleService::enqueueTask()` 投递第一条生成任务
- 后续由调度器自动按 publish_interval 生成剩余文章

## 6. 新增文件清单

| 文件 | 说明 |
|------|------|
| `app/Models/SmartImportJob.php` | 异步任务记录模型 |
| `app/Jobs/ProcessSmartImportJob.php` | 队列 Job |
| `app/Services/GeoFlow/SmartImportService.php` | 核心编排逻辑 |
| `app/Http/Controllers/Api/V1/SmartImportController.php` | API 控制器 |
| `database/migrations/2026_06_18_000000_create_smart_import_jobs.php` | 迁移 |
| `routes/api.php` | 新增 2 条路由 |

## 7. 错误处理

- 参数校验失败 → 422（含 field_errors）
- URL 抓取失败 → job 状态 failed，error_message 记录原因
- AI 分析失败（所有模型不可用）→ job 状态 failed
- 任务创建失败 → job 状态 failed
- 部分成功不回滚：已入库的素材保留，job.result 记录已完成步骤

## 8. 安全约束

- 接口无需认证，但建议通过 Nginx/网关限制访问频率
- URL 模式复用 `UrlImportProcessingService.guardAgainstPrivateTargets()`，阻止内网地址
- Markdown 内容最大 500KB
