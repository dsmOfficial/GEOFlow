# 生成文章字段、官网同步与 Callback 对接说明

日期：2026-07-30  
更新：按「后台一键同步官网 + 文末标注官网原文链接」需求修订  
范围：任务 Worker 生成的文章（含 Jiey Flow 项目导入后创建的任务）

---

## 0. 业务目标（本次需求）

1. 将 GEOFlow 生成的文章 **同步到官网**
2. 同步成功后，在文章正文 **结尾标注官网原文链接**
3. 本项目后台需要 **「同步到官网」操作按钮**（单篇 + 建议支持批量）

### 0.1 名词约定

| 名词 | 含义 |
|------|------|
| GEOFlow 本地站 | 本系统前台，公开 URL：`{SITE_URL}/article/{slug}` |
| 官网 | 对外主站（通常是独立品牌官网 / WordPress / 自建站）。可由分发渠道配置，也可配置为本地站本身 |
| 原文链接 | 官网上该文章的最终可访问 URL，用于文末标注 |
| 同步到官网 | 将文章标题/正文/摘要/SEO 等推送到官网，并拿回 `remote_url` |

> 推荐：官网通过 **分发渠道（Distribution Channel）** 配置；  
> 若官网就是本系统前台，则「同步」= 本站发布（`status=published`），原文链接 = `route('site.article', slug)`。

---

## 1. 端到端流程

```
Smart Import / 后台建任务
        │
        ▼
Task（提示词、知识库、标题库、可选图片库、可选分发渠道）
        │
        ▼ generate_article
Worker 生成 articles 草稿
        │
        ▼ 人工审核（可选）
        │
        ├─【路径 A：本站即官网】
        │     后台点「发布/同步到官网」
        │     → status=published
        │     → official_url = {SITE_URL}/article/{slug}
        │     → 文末追加原文链接
        │
        └─【路径 B：外部官网（推荐）】
              后台点「同步到官网」
              → 校验可同步
              → 入队分发（distribution）
              → 官网返回 remote_url
              → 回写文章/分发记录
              → 文末追加「原文链接：{remote_url}」
```

### 1.1 与现有能力的关系

| 现有能力 | 可复用点 |
|----------|----------|
| `DistributionOrchestrator::enqueueForArticle()` | 文章发布/更新后入队外发 |
| `article_distributions.remote_url` | 官网回传的原文 URL |
| 前台 `route('site.article', $slug)` | 本地站原文 URL |
| 文章编辑页 / 列表批量操作 | 放置「同步到官网」按钮 |

### 1.2 关键约束

- **Jiey 导入 completed ≠ 文章完成**，更不等于已同步官网
- 同步官网应发生在 **文章已生成且通过风控/审核策略** 之后
- 文末原文链接应以 **官网最终 URL** 为准，不应写死未确认地址

---

## 2. `articles` 主表字段

| 字段 | 类型（逻辑） | 生成时 | 同步官网时 | 说明 |
|------|--------------|--------|------------|------|
| `id` | int | 自动 | 不变 | 文章主键 |
| `title` | string | 是 | 推送 | 标题 |
| `slug` | string | 是 | 推送/用于本地 URL | 唯一 slug |
| `excerpt` | text | 是 | 推送 | 摘要 |
| `content` | text/markdown | 是 | 推送（含文末链接） | 正文 |
| `category_id` | int\|null | 可能 | 可映射官网分类 | 分类 |
| `author_id` | int\|null | 可能 | 可映射官网作者 | 作者 |
| `task_id` | int\|null | 是 | 溯源 | 来源任务 |
| `original_keyword` | string | 是 | 可选 | 生成关键词 |
| `keywords` | string/text | 是 | 推送 SEO | 关键词 |
| `meta_description` | string/text | 是 | 推送 SEO | 描述 |
| `status` | string | 是 | 可能变 `published` | `draft/published/private` |
| `review_status` | string | 是 | 通常需已通过 | 审核状态 |
| `view_count` | int | 0 | 本地统计 | 浏览量 |
| `is_ai_generated` | int | 1 | 可选透传 | AI 生成标记 |
| `is_hot` / `is_featured` | bool | 默认 | 可选 | 运营标记 |
| `published_at` | datetime\|null | 条件 | 本站发布时写入 | 发布时间 |
| `created_at` / `updated_at` | datetime | 自动 | 更新 | 时间戳 |
| `deleted_at` | datetime\|null | 否 | 删除同步时用 | 软删除 |

### 2.1 建议新增/约定的同步相关字段（文档契约）

> 当前主表未必都有独立列；实现时可落库到 `articles` 扩展字段，或先写入 `article_distributions.remote_meta` / 运营配置。  
> 对接时统一按以下逻辑字段处理：

| 逻辑字段 | 建议存储 | 说明 |
|----------|----------|------|
| `official_url` | `article_distributions.remote_url`（首选）或文章扩展字段 | 官网原文链接 |
| `official_remote_id` | `article_distributions.remote_id` | 官网侧文章 ID |
| `official_sync_status` | `article_distributions.status` | `queued/sending/synced/failed` |
| `official_synced_at` | distribution `updated_at` / meta | 最近成功同步时间 |
| `official_channel_id` | `distribution_channel_id` | 使用的官网渠道 |
| `source_link_appended` | content 标记或 meta | 是否已追加文末原文链接 |

---

## 3. 状态机

### 3.1 `status`

| 值 | 含义 |
|----|------|
| `draft` | 草稿 |
| `published` | 已发布（本地站可见） |
| `private` | 私有 |

### 3.2 `review_status`

| 值 | 含义 |
|----|------|
| `pending` | 待审核 |
| `approved` | 审核通过 |
| `rejected` | 驳回 |
| `auto_approved` | 自动通过 |

### 3.3 官网同步状态（`article_distributions.status`）

| 值 | 含义 | 后台按钮表现 |
|----|------|--------------|
| 无记录 | 未同步 | 显示「同步到官网」 |
| `queued` | 已入队 | 「同步中」 |
| `sending` | 发送中 | 「同步中」 |
| `synced` | 成功 | 「已同步 / 重新同步」+ 原文链接 |
| `failed` | 失败 | 「重试同步」+ 错误信息 |

### 3.4 建议的可同步门槛

默认满足以下条件才允许点「同步到官网」：

1. 文章未删除
2. `title`、`content` 非空
3. 风险扫描非 `blocked`（或已填写放行原因）
4. `review_status ∈ {approved, auto_approved}`（可配置是否强制）
5. 已配置有效官网渠道（路径 B）或允许本地发布（路径 A）

---

## 4. 官网 URL 规则

### 4.1 本地站（GEOFlow 前台）

```text
{SITE_URL}/article/{slug}
```

对应路由：`route('site.article', $article->slug)`  
配置来源：`config('geoflow.site_url')` / `SITE_URL`

示例：

```text
https://www.example.com/article/a1b2c3d4
```

### 4.2 外部官网（分发渠道）

以渠道回传为准：

```text
article_distributions.remote_url
```

示例：

```text
https://official.example.com/blog/from-0-to-1-fresh-retail
```

**文末标注必须优先使用 `remote_url`；没有 remote_url 时不要伪造链接。**

---

## 5. 文末「官网原文链接」规范

### 5.1 追加时机

在 **官网同步成功拿到最终 URL 后** 追加/更新，而不是同步前盲写。

### 5.2 推荐文案（中文）

```markdown
---

**原文链接：** [https://official.example.com/xxx](https://official.example.com/xxx)
```

可选更品牌化：

```markdown
---

> 本文同步发布于官网，查看原文：https://official.example.com/xxx
```

### 5.3 幂等规则（非常重要）

1. 使用固定锚点标记，避免重复追加：

```markdown
<!-- official-source-link:start -->
**原文链接：** [URL](URL)
<!-- official-source-link:end -->
```

2. 再次同步时：
   - 若锚点已存在 → **替换中间 URL**
   - 若锚点不存在 → 追加到文末
3. 不要每次 `content .= 链接` 直接拼接

### 5.4 同步给官网的 content 是否含链接？

两种策略，文档推荐 **策略 B**：

| 策略 | 行为 | 优缺点 |
|------|------|--------|
| A. 先带本地链接同步 | 同步前写本地 URL | 简单，但官网文末可能指向 GEOFlow 而非官网自身 |
| **B. 同步成功后回写** | 先同步正文，成功后用 `remote_url` 回写 GEOFlow 文末；可选再 `update` 一次官网 | 原文链接准确，多一次更新 |
| C. 仅 GEOFlow 文末标注 | 官网上不显示该段 | 适合官网自己有 canonical |

**推荐默认：策略 B**  
- GEOFlow 文章文末显示官网原文链接  
- 若官网也需要，再触发一次 `action=update` 分发

---

## 6. 后台「同步到官网」按钮设计

### 6.1 入口位置

1. **文章编辑页**（主入口）  
   - 按钮文案：`同步到官网`
   - 次要操作：`查看官网原文`（有 URL 时）
2. **文章列表页**  
   - 单行操作：同步  
   - 批量操作：批量同步到官网
3. （可选）任务详情页：对该任务下已审文章批量同步

### 6.2 按钮状态

| 条件 | 按钮 |
|------|------|
| 未同步且可同步 | 主按钮「同步到官网」 |
| 同步中 | 禁用「同步中…」 |
| 已同步 | 「重新同步」+ 链接「打开原文」 |
| 失败 | 「重试同步」+ 错误摘要 |
| 不满足门槛 | 禁用，并提示原因（待审/风险/无渠道） |

### 6.3 建议路由

```text
POST   /geo_admin/articles/{articleId}/sync-official
GET    /geo_admin/articles/{articleId}/official-sync-status
POST   /geo_admin/articles/batch/sync-official
```

路由名建议：

- `admin.articles.sync-official`
- `admin.articles.sync-official.status`
- `admin.articles.batch.sync-official`

### 6.4 控制器职责（Admin\ArticleController 或独立 Service）

`syncOfficial(Article $article)` 伪流程：

```text
1. 校验文章可同步门槛
2. 解析官网目标：
   - 配置的 official_channel_id，或
   - 任务绑定的 distributionChannels 中标记为官网的渠道，或
   - local 模式（本站发布）
3. local 模式：
   - 审核通过后发布 status=published
   - official_url = site.article URL
   - 追加文末链接
4. remote 模式：
   - DistributionOrchestrator::enqueueForArticle($article, 'publish'|'update')
   - 异步等待/轮询 distribution 状态
   - synced 后取 remote_url
   - 追加/更新文末链接
   - 可选二次 update 推送到官网
5. 返回结果给前端 toast
```

### 6.5 前端交互

- 点击后立即 toast「已开始同步」
- 2–3s 轮询 status 接口
- 成功：展示原文链接，刷新编辑页 content 预览
- 失败：展示 `last_error_message`

---

## 7. 同步请求/响应契约

### 7.1 后台提交

```http
POST /geo_admin/articles/123/sync-official
Content-Type: application/json

{
  "channel_id": 5,
  "force": false,
  "append_source_link": true
}
```

| 字段 | 说明 |
|------|------|
| `channel_id` | 可选；不传则用默认官网渠道 |
| `force` | 是否忽略“已同步且内容未变” |
| `append_source_link` | 是否写文末原文链接，默认 true |

### 7.2 状态响应

```json
{
  "article_id": 123,
  "sync_status": "synced",
  "official_url": "https://official.example.com/blog/xxx",
  "remote_id": "456",
  "channel_id": 5,
  "channel_name": "品牌官网",
  "source_link_appended": true,
  "last_error_message": null,
  "updated_at": "2026-07-30T12:10:00+08:00"
}
```

### 7.3 推送到官网的文章载荷（建议）

复用现有分发 payload 思路，最少包含：

```json
{
  "title": "……",
  "slug": "a1b2c3d4",
  "content": "Markdown/HTML",
  "excerpt": "……",
  "keywords": "……",
  "meta_description": "……",
  "status": "publish",
  "author_name": "……",
  "category_name": "……",
  "source": {
    "system": "geoflow",
    "article_id": 123,
    "task_id": 88,
    "smart_import_job_id": 1,
    "jiey_project_id": 51
  }
}
```

官网需返回：

```json
{
  "remote_id": "456",
  "remote_url": "https://official.example.com/blog/xxx"
}
```

---

## 8. 关联对象字段

### 8.1 配图

- `article_images.position`
- `images.file_path` / `original_name`

同步官网时按渠道能力决定：

- 上传媒体库后替换正文图片 URL，或
- 直接推带绝对地址的图片链接

### 8.2 分发记录 `article_distributions`

| 字段 | 同步用途 |
|------|----------|
| `article_id` | 关联文章 |
| `distribution_channel_id` | 官网渠道 |
| `action` | `publish` / `update` / `delete` |
| `status` | 同步状态 |
| `remote_id` | 官网文章 ID |
| `remote_url` | **原文链接（核心）** |
| `last_error_message` | 失败原因 |
| `attempt_count` | 重试次数 |
| `payload_hash` | 内容变更检测 |
| `remote_meta` | 额外回传 |

### 8.3 任务执行 `task_runs`

生成阶段使用；同步官网不必强依赖，但可在 meta 中带 `task_run_id` 溯源。

---

## 9. 现有 API（可继续用于拉取文章）

### 9.1 文章详情

`GET /api/v1/articles/{id}`

```json
{
  "id": 123,
  "title": "……",
  "slug": "a1b2c3d4",
  "content": "Markdown 正文",
  "excerpt": "摘要",
  "keywords": "关键词",
  "meta_description": "SEO 描述",
  "status": "draft",
  "review_status": "pending",
  "task_id": 88,
  "task_name": "jiey IDE 项目推广 · 全栈开发者 - 鲜时达",
  "author_id": 2,
  "author_name": "张三",
  "category_id": 3,
  "category_name": "技术实践",
  "published_at": null,
  "created_at": "2026-07-30 12:00:00",
  "updated_at": "2026-07-30 12:00:00",
  "images": []
}
```

### 9.2 建议扩展返回（同步官网后）

在详情中增加：

```json
{
  "official_sync": {
    "status": "synced",
    "channel_id": 5,
    "channel_name": "品牌官网",
    "remote_id": "456",
    "official_url": "https://official.example.com/blog/xxx",
    "synced_at": "2026-07-30 12:10:00",
    "source_link_appended": true
  }
}
```

---

## 10. Callback / Webhook 事件（对接官网与中台）

### 10.1 事件列表

| event | 触发点 |
|-------|--------|
| `article.generated` | Worker 生成草稿成功 |
| `article.generation_failed` | 生成失败 |
| `article.reviewed` | 审核通过/驳回 |
| `article.published` | 本地发布 |
| `article.official_sync_queued` | 点击同步到官网并入队 |
| `article.official_synced` | 官网同步成功，已有 `official_url` |
| `article.official_sync_failed` | 官网同步失败 |
| `article.source_link_appended` | 文末原文链接已写入 |
| `article.distributed` | 其他分发渠道成功 |
| `smart_import.completed` | 导入素材+任务完成（无文章） |

### 10.2 `article.official_synced` 推荐 Payload

```json
{
  "event": "article.official_synced",
  "event_id": "evt_01JXXXX",
  "occurred_at": "2026-07-30T12:10:00+08:00",
  "data": {
    "article": {
      "id": 123,
      "title": "……",
      "slug": "a1b2c3d4",
      "status": "published",
      "review_status": "approved",
      "content": "……（已含文末原文链接）……",
      "excerpt": "……",
      "keywords": "……",
      "meta_description": "……"
    },
    "official": {
      "channel_id": 5,
      "channel_name": "品牌官网",
      "remote_id": "456",
      "official_url": "https://official.example.com/blog/xxx",
      "action": "publish",
      "synced_at": "2026-07-30T12:10:00+08:00"
    },
    "relations": {
      "task_id": 88,
      "task_name": "jiey IDE 项目推广 · 全栈开发者 - 鲜时达",
      "author_name": "……",
      "category_name": "……",
      "images": []
    },
    "source": {
      "type": "jiey_flow",
      "smart_import_job_id": 1,
      "jiey_project_id": 51,
      "prompt_name": "Jiey项目·全栈开发者视角",
      "role_label": "全栈开发者"
    }
  }
}
```

### 10.3 最小字段（官网/中台最少要接）

1. `article.id`
2. `article.title`
3. `article.content`（或更新后的 content）
4. `official.official_url`
5. `official.remote_id`
6. `official.status/synced_at`
7. `relations.task_id`
8. 溯源：`source.jiey_project_id` / `smart_import_job_id`（如有）

---

## 11. 后台按钮实现清单（给研发）

### 11.1 必做

- [ ] 配置「官网渠道」识别方式  
  - 环境变量 `GEOFLOW_OFFICIAL_CHANNEL_ID`，或  
  - 渠道标记 `is_official=true`
- [ ] 文章编辑页增加「同步到官网」按钮
- [ ] 列表页增加单行/批量同步
- [ ] 同步服务：门槛校验 → 入队分发 → 回写状态
- [ ] `remote_url` 成功后追加/更新文末原文链接（锚点幂等）
- [ ] 状态接口供前端轮询
- [ ] 失败展示 `last_error_message`

### 11.2 建议做

- [ ] 同步前预览将推送的标题/摘要
- [ ] 内容未变化时提示“已同步且无变更”
- [ ] 同步成功后站内通知
- [ ] API 详情返回 `official_sync` 对象
- [ ] webhook：`article.official_synced`

### 11.3 配置项建议

```env
# 官网渠道（外部站）
GEOFLOW_OFFICIAL_CHANNEL_ID=5

# 若官网就是本系统前台
GEOFLOW_OFFICIAL_MODE=local   # local | distribution
SITE_URL=https://www.example.com

# 文末链接
GEOFLOW_OFFICIAL_SOURCE_LINK_ENABLED=true
GEOFLOW_OFFICIAL_SOURCE_LINK_TEMPLATE="**原文链接：** [{url}]({url})"

# 可选 webhook
GEOFLOW_ARTICLE_WEBHOOK_URL=
GEOFLOW_ARTICLE_WEBHOOK_SECRET=
GEOFLOW_ARTICLE_WEBHOOK_EVENTS=article.official_synced,article.generated
```

---

## 12. 运营操作手册（后台怎么点）

1. 生成/导入任务，等待文章草稿产生  
2. 进入 **文章管理** → 打开文章  
3. 确认审核状态与风险扫描通过  
4. 点击 **同步到官网**  
5. 等待状态变为「已同步」  
6. 点击 **打开原文** 验收官网页面  
7. 回到编辑页确认文末已有：

```markdown
**原文链接：** https://official.example.com/...
```

---

## 13. 字段分层（给产品/对接）

### A. 内容核心（必同步到官网）

`title, slug, content, excerpt, keywords, meta_description`

### B. 归属溯源（建议同步/callback）

`id, task_id, task_name, author_*, category_*, is_ai_generated, jiey_project_id, prompt/role`

### C. 官网同步结果（必回写）

`official_url, remote_id, sync_status, synced_at, channel_id`

### D. 媒体

`images[]`（按渠道能力转换）

### E. 本站状态

`status, review_status, published_at`

---

## 14. 常见误区

1. **导入完成就当已同步官网** — 还没有文章，更没有 remote_url  
2. **文末先写死本地链接** — 官网原文应以官网 URL 为准  
3. **每次同步都追加一段链接** — 必须幂等替换  
4. **未审核/风险阻断仍强制同步** — 会把问题内容推到官网  
5. **任务 `publish_scope=local_only` 却期望外发** — 不会走分发，需改任务范围或专用同步入口  
6. **只存 remote_url，不回写 content** — 后台/导出/二次分发看不到文末原文链接  

---

## 15. 无 Webhook 时的过渡方案

1. 后台/API 创建任务并生成文章  
2. 审核通过后调用同步接口或点按钮  
3. 轮询：
   - `GET /api/v1/articles/{id}`
   - 或后台 status 接口  
4. 直到 `official_sync.status=synced` 且 `official_url` 有值  
5. 将 `official_url` 与最终 `content` 同步到你们中台  

---

## 16. 一页总览

**生成字段：**  
`id title slug excerpt content category_id author_id task_id original_keyword keywords meta_description status review_status view_count is_ai_generated is_hot is_featured published_at timestamps`

**同步官网后新增关键结果：**  
`official_url / remote_id / sync_status / synced_at / channel_id + 文末原文链接`

**后台必加：**  
文章编辑页/列表页「同步到官网」按钮 + 状态展示 + 打开原文

**文末格式：**

```markdown
<!-- official-source-link:start -->
**原文链接：** [https://official.example.com/xxx](https://official.example.com/xxx)
<!-- official-source-link:end -->
```

---

## 17. 下一步实现顺序（建议）

1. 定官网模式：`local` 或 `distribution`  
2. 加后台按钮与 status 接口  
3. 复用 `DistributionOrchestrator` 完成同步  
4. 成功回写文末原文链接（锚点幂等）  
5. 扩展文章详情 `official_sync`  
6. 补 `article.official_synced` webhook  

如需继续落地代码，按第 17 节顺序从后台按钮和同步服务开始即可。
