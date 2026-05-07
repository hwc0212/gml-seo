# GML AI SEO

**持续运行的 AI SEO 自动化 + 多语言翻译一体化插件。** 不是一次性工具 —— AI 定时扫描全站、自动重新优化、实时推送 Google/Bing，翻译时按目标语言用户搜索习惯做 transcreation 而非直译。

专为 2025 AI Overviews / AI Mode / Helpful Content System（HCS）时代设计。

## 核心理念

**SEO 不是发布时填一次就完事的事情 —— 它是一份持续的维护工作。**

传统 SEO 插件只给你手动旋钮。这个插件不一样：

- AI **每周**自动扫描全站，识别 5 类问题并按优先级排队重新优化
- 内容发布/更新时**秒级**通知 Google Indexing API + IndexNow（Bing / Yandex / Seznam / Naver）
- **BLUF (Bottom Line Up Front)** 机制让内容进入 AI Overviews 引用池
- **Schema 智能识别**：AI 判断文章是 Article / HowTo / Recipe / Review / Event / VideoObject / Course 并输出对应 Rich Result
- **多语言合一**：翻译共用 SEO 的 AI Key 和上下文，目标语言用户搜什么就生成什么（而不是机械翻译）

目标：**比一般 SEO 专员做得更好。**

## 主要功能

### 🗓 Health Monitor — 定时 SEO 扫描（核心差异化）

每周/每天/每月（可选）自动全站扫描，5 个信号决定优先级：

| 信号 | 优先级 | 描述 |
|---|---|---|
| 从未分析 | 90 | 新内容首次优化 |
| 缺失标题/描述 | 80 | 分析过但数据残缺 |
| 内容变更 | 70 | Content hash 对比出不一致 |
| SEO 分数 &lt; 60 | 60 | 上次低分，值得重做 |
| 新鲜度超标 | 40 | 内容过于陈旧 |

**类别感知的新鲜度阈值：**
- 新闻 / 公告类：30 天
- 教程 / guide 类：90 天
- 普通文章：180 天
- 页面（page）：365 天

可通过 `gml_seo_freshness_threshold_days` 过滤器自定义。

命中阈值的页面自动进入队列，按优先级分批处理（每批 3 篇，间隔 5 分钟，避免 API 限流）。所有扫描运行都有日志，保留最近 50 条。

### 🚀 Real-time Indexing — 内容秒级推送

**IndexNow 协议（Bing / Yandex / Seznam / Naver）**
- 自动生成 32 位验证密钥文件
- 通过 rewrite rule + fallback 路由提供验证端点
- Publish / update / delete / unpublish 事件触发，非阻塞（fire-and-forget）

**Google Indexing API**
- 粘贴服务账号 JSON 即用
- 自动 JWT RS256 签名获取 access token
- Transient 缓存 45 分钟
- URL_UPDATED / URL_DELETED 事件类型

### 🤖 AI SEO Master Engine

AI 严格遵循以下 Google 官方指南：
- [Search Essentials](https://developers.google.com/search/docs/essentials)
- [SEO Starter Guide](https://developers.google.com/search/docs/fundamentals/seo-starter-guide)
- [Succeeding in AI Search (May 2025)](https://developers.google.com/search/blog/2025/05/succeeding-in-ai-search)

每次分析产出：

- **SEO 标题**（≤ 60 字符，关键词自然前置，不堆砌）
- **Meta 描述**（120–155 字符，广告文案式写法）
- **Open Graph 标题/描述**（独立优化，更具情感驱动力）
- **焦点关键词**（主关键词 + 3-5 个次要关键词）
- **搜索意图分类**（信息型 / 交易型 / 导航型 / 商业型）
- **BLUF / TL;DR**（1-2 句直接答案，≤ 280 字符，供 AI Overviews 引用）
- **SEO 评分**（0-100）+ **E-E-A-T 评分** + **AI-search 评分**
- **SEO 审计**（按 Google "people-first content" 标准，至少 5 条具体 issue）
- **内链建议**（使用描述性锚文本）
- **图片 alt 文本**（AI 生成并自动填充）
- **URL Slug 建议**（24 小时内的新文章自动应用）
- **FAQ**（3-5 组 People Also Ask 风格 Q&A）
- **Schema 类型识别**（Article / HowTo / Recipe / Review / Event / VideoObject / Course）

### 🔗 Auto Internal Linking — AI 自动内链

Google 官方重点：**"Links help Google discover pages. Use descriptive anchor text."**

- AI 扫描站内已优化页面，为每篇新文章挑 3-5 个语义最相关的目标
- 生成**描述性锚文本**（绝不使用 "click here" / "read more" 这类通用词）
- 通过 `the_content` 过滤器注入，**不修改数据库原文**，随时可回滚
- 安全注入：跳过 `<a>`、`<h1-6>`、`<code>`、`<script>`、`<style>` 保护区
- 每篇最多 5 条；AI 判断无合适匹配时返回空数组（不强制内链）
- 帖子删除/下线时自动从候选索引移除
- 编辑器级关闭开关

### ❓ FAQ Schema — 最易获得的 Google Rich Result

- AI 基于文章内容生成 3-5 组 Q&A（内容驱动，绝不编造）
- 自动在文章末尾渲染可访问的 `<details>` 交互式 FAQ section
- 自动输出合法的 `FAQPage` JSON-LD
- 每篇文章级关闭开关（schema 与可见区块独立控制）

### 📋 Smart Schema — AI 识别 + 输出

**默认输出：**
- `WebSite` + SearchAction（首页）
- `Article`（文章）
- `WebPage`（页面）
- `Product` + Offer + AggregateRating（WooCommerce 产品）
- `BreadcrumbList`（所有页面）
- `Speakable`（BLUF 区块，辅助语音搜索 / AI Overviews）

**AI 按内容类型动态扩展：**
- `HowTo`（带 step 列表）
- `Recipe`（带 ingredients + instructions）
- `Review`（带 reviewRating）
- `Event`（带 startDate + location）
- `VideoObject`
- `Course`

严格遵循 Google "结构化数据必须匹配可见内容"的铁律 —— 只在页面实际包含对应元素时输出。

### 🌐 Translation — 多语言一体化（v1.6+ 合并自 GML Translate）

从 v1.6.0 起，GML Translate 全部功能已并入本插件。翻译和 SEO 共用同一个 AI Key 和引擎。

- **动态 AI 翻译**（Gemini / DeepSeek）+ 基于 hash 的翻译记忆库
- **异步队列** + cron 处理器，大站点也流畅
- **可视化翻译编辑器**（搜索、分页、手动修正）
- **术语表**（Glossary）强制翻译规则
- **排除规则**（选择器 / 路径级）
- **语言切换器**（下拉、内联、国旗、语言名可配）
- **Nav Menu 切换器**（独立菜单项）
- **语言自动检测** + cookie 记忆
- **多语言 sitemap**（gml-sitemap.xml）+ **hreflang 标签**
- **内容爬虫**（后台批量预翻译）
- **Gettext filter**（翻译主题 / 插件内 i18n 字符串）
- **Weglot 配置自动导入**

**无缝迁移：** 如果原来装了独立 GML Translate，本插件激活时会自动停用它，数据库表（`wp_gml_index` / `wp_gml_queue`）和所有配置原样保留 —— **零重复翻译成本**。

### ⚡ 性能优化（Core Web Vitals 自动）

Google 2021 年起将 Core Web Vitals（LCP / INP / CLS）作为排名信号。内置全部安全的自动优化，无需 Perfmatters / WP Rocket：

**WordPress 瘦身**
- 移除 Emoji 脚本（~10KB）、Dashicons CSS（~46KB）、oEmbed（~6KB）
- 移除 RSD / WLW / Shortlink / REST / oEmbed 发现链接
- 隐藏 WP 版本号、禁用 XML-RPC、禁用自我 Pingback
- 移除未使用的 Gutenberg 全局样式

**JavaScript**
- 非关键 JS 自动 `defer`
- 安全跳过 jQuery 等关键脚本

**图片**
- 首屏前 2 张图正常加载，其余自动 `loading="lazy"`
- 自动补全缺失 `width` / `height`（防止 CLS）
- 第一张内容图 `fetchpriority="high"`（LCP 优化）
- 特色图片 `<link rel="preload">`
- iframe（YouTube / Google Maps）自动 lazy

**资源提示**
- 自动 preconnect Google Fonts / GA / GTM
- Gravatar 等 dns-prefetch

**不做过度优化**（遵循 Google 官方建议）
- ❌ 不移除未使用的 CSS
- ❌ 不延迟所有 JS
- ❌ 不禁用 Google Fonts
- ❌ 不内联关键 CSS

### 💉 Code Injection

一站式管理追踪代码：
- **GA4** / **GTM** / **AdSense** 填 ID 即可
- 自定义 `<head>` / `<body>` 开头 / `</body>` 前注入点

### 🚀 Bulk Optimize

- 一键分析所有未优化的已发布页面
- 强制模式：对已优化页面全量重跑（升级后回填新功能）
- 进度条实时显示，随时暂停
- 自动间隔防 API 限流

### 📊 Dashboard + Automation

- **Dashboard**：已优化 / 总数 / 覆盖率 / FAQ 数 / 自动内链数 / 候选索引大小
- **Automation**：下次扫描倒计时、队列状态、扫描报告、彩色终端风格运行日志、手动扫描 / 处理按钮

## 和传统 SEO 插件的区别

| 维度 | 传统 SEO 插件（Yoast / Rank Math / SEOPress） | GML AI SEO |
|---|---|---|
| SEO 标题 / 描述 | 用户手动填 | AI 按 Google 指南自动生成 |
| 关键词研究 | 用户自己做 | AI 识别真实搜索查询 |
| 内容审计 | 基于规则的静态检查 | AI 按 Google E-E-A-T 深度分析 |
| 图片 alt | 用户手动 | AI 自动生成并填充 |
| 内链 | 无或 Pro 版 | AI 选目标 + 描述性锚文本 + 安全注入 |
| FAQ Schema | 手动填 | AI 自动基于内容生成 |
| 结构化数据 | 固定几种 | AI 智能识别 HowTo / Recipe / Review 等 |
| AI Overviews 优化 | 无 | BLUF + Speakable schema |
| 定时重新优化 | 无 | 每周全站扫描 + 优先级队列 |
| 实时索引推送 | 无或需另装插件 | IndexNow + Google Indexing API 内置 |
| Core Web Vitals | 另装 Perfmatters / WP Rocket | 内置自动优化 |
| 多语言 | 另装 WPML / Polylang / Weglot | 内置（翻译 = transcreation，不是直译） |
| 配置复杂度 | 几十个设置页 | 填 API Key 即可 |

## 安装

1. 上传 `gml-seo` 目录到 `/wp-content/plugins/`（或 WP admin 上传 zip）
2. 激活插件
3. 进入 **GML AI SEO → ⚙️ Settings**
4. 选择 AI 引擎（Gemini 或 DeepSeek）并填入 API Key：
   - Gemini：[Google AI Studio](https://aistudio.google.com/apikey)
   - DeepSeek：[DeepSeek 开放平台](https://platform.deepseek.com/api_keys)（中国大陆推荐）
5. 进入 **🤖 Automation** 确认定时扫描已安排
6. （可选）配置 Google Indexing API：粘贴服务账号 JSON

完成。新发布的文章会自动被 AI 优化；已有文章通过 **🚀 Bulk Optimize** 一键回填。

## 升级路径

### 从 v1.3.x 升级到 v1.4+
1. 进 **Dashboard** → 点 **🔄 重建索引**
2. 进 **Bulk Optimize** → 勾选 **强制重新分析** → 运行
3. 旧文章会获得 FAQ + 自动内链

### 从独立 GML Translate 升级到 v1.6+
1. 先升级 GML AI SEO 到 1.6.1+
2. 停用独立的 `gml-translate` 插件（插件会显示一键停用按钮）
3. 数据自动保留，无需重新翻译
4. 卸载独立插件（保留数据选项）

## 钩子 / 过滤器

| 名称 | 用途 |
|---|---|
| `gml_seo_freshness_threshold_days` | 自定义内容新鲜度阈值 |
| `gml_seo_faq_heading` | 自定义 FAQ section 标题 |

## 要求

- WordPress 6.0+
- PHP 7.4+
- AI API Key（Gemini 或 DeepSeek，二选一）

## 版本

1.7.2 — 完整更新日志见 [CHANGELOG.md](CHANGELOG.md)

## License

GPLv2 or later
