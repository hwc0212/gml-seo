# Changelog

All notable changes to GML AI SEO will be documented in this file.

## [1.6.0] - 2026-04-16

### 🔀 GML Translate 已合并进本插件

这是一次架构级合并：**GML Translate 作为独立插件从此进入 deprecated 状态**，其全部功能（22 个类、动态翻译引擎、翻译记忆库、语言切换器、hreflang、多语言 sitemap、内容爬虫、翻译编辑器、术语表、Weglot 迁移工具）现在内置于本插件中，共享同一个 AI Key 和引擎配置。

合并的核心动机：**翻译的内容也需要做 SEO**。直接翻译往往不是目标语言用户会搜索的说法——英语用户搜 "best espresso machine"，而日语用户搜「家庭用 エスプレッソ マシン おすすめ」。这不是翻译问题，是**搜索习惯本地化**问题。只有翻译和 SEO 共享一个 AI 上下文，才能做到真正意义上的多语言 SEO。

### Added

#### 🌐 Translation 模块（完整内建）
- 全部原 GML Translate 功能无缝可用：
  - Gemini / DeepSeek 双引擎动态翻译（共享 SEO 的 AI Key 配置）
  - 基于 hash 的翻译记忆库（`wp_gml_index` 表）
  - 异步翻译队列（`wp_gml_queue` 表 + cron 处理器）
  - 可视化翻译编辑器（手动修正、搜索、分页）
  - 术语表（Glossary）强制翻译规则
  - 排除规则（Exclusion Rules）跳过特定选择器/路径
  - 语言切换器（下拉/内联，支持国旗/语言名）
  - Nav Menu 语言切换器（单独菜单项）
  - 语言自动检测 + cookie 记忆
  - 多语言 sitemap（gml-sitemap.xml）+ hreflang 标签
  - 内容爬虫（Content Crawler）后台批量预翻译
  - Gettext filter（翻译主题/插件内的 i18n 字符串）
  - Weglot 配置自动导入（从 Weglot 迁移无缝）
- 新增 **🌐 Translation** admin tab（GML AI SEO 主菜单下），保留原 5 个子 tab（Settings / Switcher / Translations / Exclusions / Glossary）

#### 🔄 从独立 GML Translate 无缝迁移
- 合并插件激活时：
  1. 自动检测并停用独立的 GML Translate 插件
  2. **数据表 wp_gml_index / wp_gml_queue 保持不变**——翻译记忆库 100% 保留
  3. `gml_languages`、`gml_source_lang`、`gml_translation_enabled`、`gml_glossary_rules`、`gml_exclusion_rules` 等所有 option 无缝复用
  4. Admin 通知提示用户合并完成 + 数据已保留
- 已翻译过的页面**零重复翻译成本**

#### 🔧 类名重构（避免冲突）
- `GML_SEO_Hreflang` → `GML_Translate_Hreflang`（因与本插件的 `GML_SEO_*` 前缀冲突）
- `GML_SEO_Router` → `GML_Translate_Router`
- 删除 orphan 类 `Gemini_Path_Guard`（已无人引用）
- 独立自动加载器（bootstrap 实现）路由 `GML_*` 类到 `includes/translate/` 子目录

### 后续规划（v1.7.0）
这一版是**架构合并**，数据保留但翻译流程未改动。v1.7.0 将引入：
- **SEO-aware 翻译**：翻译时带上主关键词/搜索意图上下文
- **目标语言 SEO 重写**：标题/描述按目标语言用户搜索习惯独立生成（不是直译）
- **目标语言 FAQ / BLUF 独立生成**：确保 AI Overviews 在每种语言都能引用
- **多语言 Health Monitor**：每种语言独立的定时扫描队列
- **IndexNow 推送翻译版**：每个语言 URL 单独通知搜索引擎

## [1.5.0] - 2026-04-16

### 🤖 SEO 从一次性任务升级为持续自动化工作流

本版本围绕一个核心理念：**SEO 不是发布时做一次就完事的事情**。插件会定时全站扫描，识别变质内容自动重新优化，并把变更实时推送给搜索引擎。目标是让 AI 做得比一般 SEO 人员更好。

### Added

#### 🗓 SEO Health Monitor — 定时自动化引擎（核心）
- **每周全站扫描**（频率可调：每天/每周/每月/禁用），根据多信号识别需要重新优化的页面：
  - 从未分析 → 优先级 90
  - 缺失标题/描述 → 优先级 80
  - 内容变更但未重新分析 → 优先级 70（content hash 对比）
  - SEO 分数 &lt; 60 → 优先级 60
  - 超过新鲜度阈值 → 优先级 40（新闻 30 天、教程 90 天、普通 180 天、页面 365 天）
- 自动按优先级排队 + 分批处理（每批 3 篇，间隔 5 分钟避免 API 限流）
- 类别感知的新鲜度阈值：自动识别 news/announcement/教程/guide 分类
- 可过滤：`gml_seo_freshness_threshold_days` hook 允许自定义阈值
- 扫描报告：healthy / stale / changed / missing / low_score 统计
- 运行日志（保留最近 50 条）

#### 🚀 Real-time Indexing — 内容变更秒级推送
- **IndexNow** 协议支持（Bing / Yandex / Seznam / Naver 免配置）：
  - 自动生成 32 位验证密钥并通过 rewrite rule + fallback 路由提供
  - 发布、更新、下线、删除时自动推送 URL
  - Fire-and-forget 非阻塞请求，不影响保存体验
- **Google Indexing API** 支持：
  - 服务账号 JSON 粘贴即用
  - 自动 JWT 签名获取 access token（transient 缓存 45 分钟）
  - 支持 URL_UPDATED 和 URL_DELETED 事件

#### 🎯 AI-Search Optimization — 抢占 AI Overviews 引用
- **BLUF / TL;DR 自动生成** — AI 提取 1-2 句直接答案（≤280 字符），自动注入文章开头
- **Speakable schema** 标记 BLUF 区块，提示 Google 用于语音搜索和 AI Overviews
- **AI-search score** — 单独的 0-100 分，评估页面在 AI Overviews 中被引用的可能性
- **E-E-A-T score** — 单独评分，衡量 Experience / Expertise / Authoritativeness / Trust

#### 📋 Schema 智能扩展
AI 自动识别内容类型，输出对应的富结构化数据：
- **HowTo** — 教程类，带 `step` 列表
- **Recipe** — 食谱，带 `recipeIngredient` + `recipeInstructions`
- **Review** — 评测，带 `reviewRating`
- **Event** — 活动页，带 `startDate` + `location`
- **VideoObject** — 含视频页面
- **Course** — 教育课程

只在 AI 明确识别出匹配类型时才输出（遵循 Google "结构化数据必须匹配可见内容" 指南）。

#### 📊 Automation Dashboard — 新建 Admin 页
- 下次扫描倒计时
- 队列状态 / 最近扫描时间 / 索引协议状态
- "立即扫描" / "立即处理队列" 手动按钮
- 彩色终端风格运行日志

### Changed

#### AI Prompt 升级到 Google 2025 官方指南
- 纳入 May 2025 "Top ways to ensure your content performs well in Google's AI experiences on Search" 官方指南
- 纳入 HCS（Helpful Content System）已并入核心排名算法的事实
- BLUF（Bottom Line Up Front）作为必选字段
- 提示词明确要求：独特/非商品化内容、第一手经验证据、非显而易见的洞察
- INP 替代 FID 的 Core Web Vitals 信号说明

#### 插件描述重写
强调"定时自动化"和"为 AI Overviews 时代而生"的定位。

## [1.4.1] - 2026-04-16

### Fixed
- 🐛 **AI 自动内链 = 0** — v1.4.0 升级后批量优化完成但 Dashboard 显示"AI 自动内链: 0"
  - 根因 1：候选索引是增量构建的，批量优化时处理第 1 篇时索引为空，无候选可选；处理第 2 篇只有 1 个候选，且代码要求 `< 2` 时跳过，导致前几十篇都失败
  - 根因 2：anchor 验证用的是 `collect_page_data()` 裁剪过的 3000 字内容，而 AI 拿到的是 2500 字片段；两者不一致导致"anchor 不在原文"误判
  - 根因 3：prompt 里把 `existing_seo_title`（SEO 标题）当作 primary keyword 传给 AI，误导语义匹配
  - 修复：
    1. 新增 `GML_SEO_Auto_Link::rebuild_index()` 方法，从所有已优化页面一次性构建索引
    2. 批量优化第一次请求时自动触发 rebuild，保证所有帖子都有完整候选池
    3. Dashboard 新增"🔄 重建索引"按钮，显示当前索引收录数
    4. Anchor 验证改用原文完整内容（不再用裁剪版）
    5. Prompt 改用正确的 `_gml_seo_primary_kw` 作为 primary keyword
    6. 最低候选数从 `< 2` 改为 `empty()`
    7. 失败时写入 `error_log`，记录拒绝原因（便于排查）
- 🐛 **Dashboard 统计卡排版错位** — 4 张卡片在某些宽度下 label 文字被压缩到一行导致遮挡
  - 修复：改用 CSS Grid + `auto-fit minmax(180px,1fr)`，自适应且 label 字号固定

## [1.4.0] - 2026-04-16

### Added
- 🔗 **AI 自动内链（Auto Internal Linking）** — Google SEO 官方推荐的核心优化项
  - AI 分析文章内容，从站内已发布页面中挑选 3-5 个语义最相关的目标
  - 生成描述性锚文本（遵循 Google 指南，绝不使用"点击这里"、"阅读更多"）
  - 通过 `the_content` 过滤器注入，**不修改数据库原文**，关闭即回滚
  - 自动维护候选索引（`gml_seo_link_index` option），AI 每次优化后增量更新
  - 安全注入：跳过 `<a>`、`<h1-6>`、`<code>`、`<script>` 等保护区域，只替换首次出现的锚文本
  - 通用锚文本（click here、read more 等）自动过滤
  - 每篇文章最多 5 条，AI 判断无合适匹配时返回空数组（不强制内链）
  - 支持每篇文章级关闭：编辑器 metabox 勾选即可隐藏该文章的自动内链
  - 帖子删除/下线时自动从候选索引移除
- ❓ **FAQ Schema 自动生成** — 最易获得的 Google Rich Result
  - AI 基于文章内容生成 3-5 组"People Also Ask"风格的 Q&A
  - 答案必须基于实际内容（不凭空编造），单答 40-150 字
  - 自动在文章末尾追加可视化 FAQ section（accessible `<details>`，内置样式）
  - 自动输出 `FAQPage` JSON-LD schema（Google rich result 必需）
  - 支持每篇文章级隐藏可见区块（schema 仍保留）
  - 瘦站内容时 AI 会返回空数组，不强制生成
- 📊 **Dashboard 新增统计卡** — FAQ rich result 数 + AI 自动内链数
- 🔄 **批量优化支持"强制重新分析"模式** — 升级到 v1.4.0 后可一键为所有旧文章补齐 FAQ + 自动内链

### Changed
- AI prompt 扩展：同一次调用新增 FAQ 生成字段，零额外成本
- Critical rule 新增对 FAQ 准确性的约束（禁止编造事实）

## [1.3.0] - 2026-04-16

### Fixed
- 🐛 **子 sitemap 404** — `sitemap-post.xml`、`sitemap-page.xml` 等子 sitemap 返回 404。原因是插件更新后 rewrite rules 未自动刷新。修复：新增版本升级自动检测，升级时自动 `flush_rewrite_rules()`；`render()` 中增加 `nocache_headers()` 确保 404 状态完全重置
- 🐛 **robots.txt 中 gml-sitemap.xml 重复** — GML SEO 的 `robots_txt()` 输出了 GML Translate 的 `gml-sitemap.xml`，GML Translate 自身又追加一次导致重复。修复：GML SEO 不再输出 `gml-sitemap.xml`，由 GML Translate 自行管理其 sitemap 声明
- 🐛 **批量优化 "Invalid JSON" 失败无重试** — AI 返回非法 JSON 时直接标记失败。修复：`call_json()` 新增自动重试 1 次机制，解析失败后重新调用 API
- 🐛 **Dashboard AI 标题列文字遮挡** — AI 标题列无宽度限制导致文字溢出。修复：添加 `max-width: 280px` + `text-overflow: ellipsis`，hover 显示完整标题
- 🐛 **编辑器 metabox 缺少手动编辑入口** — SEO 字段仅在 AI 生成报告后才显示，用户无法手动填写。修复：SEO 标题/描述/关键词/OG 字段始终显示为可编辑状态，无需依赖 AI；保存功能独立于 AI Key 配置

### Changed
- 版本号升级至 1.3.0

## [1.2.0] - 2026-04-16

### Added
- 🤖 **DeepSeek 翻译引擎支持** — 中国大陆无法访问 Google API，新增 DeepSeek 作为可选 AI 引擎：
  - Settings 页面新增「AI 引擎」下拉选择器（Google Gemini / DeepSeek）
  - 切换引擎时自动显示/隐藏对应的配置字段
  - DeepSeek 使用 OpenAI 兼容的 Chat Completions API 格式
  - 支持自定义 DeepSeek 模型（deepseek-chat / deepseek-reasoner）
  - 支持自定义 API Base URL（适用于代理/私有部署）
  - API Key 独立存储，切换引擎不丢失配置
  - 所有 AI 功能（SEO 分析、批量优化、编辑器面板）均支持 DeepSeek

### Fixed
- 🐛 **sitemap.xml 被 WordPress 核心重定向到 wp-sitemap.xml** — WordPress 5.5+ 内置的 WP_Sitemaps 在 `init` priority 0 注册了 `/sitemap.xml` → `/wp-sitemap.xml` 的重定向，GML SEO 的 rewrite rule 注册太晚被核心抢先
  - 修复（三层防护）：
    1. 插件构造函数（最早时机）注册 `wp_sitemaps_enabled = false`
    2. `remove_action` 移除 `WP_Sitemaps::init`，阻止核心注册 rewrite rules
    3. `render()` 新增 URL 直接检测 fallback，即使 rewrite rule 没生效也能通过解析 REQUEST_URI 响应 sitemap 请求

---

## [1.1.0] - 2026-04-15

### Added
- ⚡ **自动性能优化模块** — 内置 Core Web Vitals 优化，无需安装 Perfmatters / WP Rocket：
  - WordPress 瘦身：移除 Emoji 脚本（~10KB）、Dashicons CSS（~46KB）、oEmbed 脚本（~6KB）、RSD/WLW/Shortlink/REST/oEmbed 链接、WP 版本号、Gutenberg 全局样式
  - 禁用 XML-RPC（安全 + 性能）、禁用自我 Pingback
  - 非关键 JS 自动 `defer`（安全跳过 jQuery 等关键脚本）
  - 图片自动 `loading="lazy"`（首屏前 2 张除外）
  - 自动补全缺失的 `width` / `height` 属性（防止 CLS）
  - 第一张内容图片自动 `fetchpriority="high"`（LCP 优化）
  - 特色图片自动 `<link rel="preload">`（加速 LCP）
  - iframe 自动 `loading="lazy"`（YouTube、Google Maps 等）
  - 自动 Preconnect Google Fonts、GA、GTM
  - 自动 DNS Prefetch Gravatar 等外部域名
- 📋 **Performance 标签页** — 后台新增 ⚡ Performance 标签页，展示所有已启用的优化项及说明，解释为什么不做过度优化

### Changed
- 插件描述更新，体现 SEO + Performance 双重能力

---

## [1.0.0] - 2026-04-15

### Added
- 🤖 **AI SEO 大师引擎** — Gemini 严格遵循 Google 官方 SEO 指南，自动分析页面并生成：
  - SEO 标题（≤60 字符，关键词自然前置，不堆砌）
  - Meta 描述（120-155 字符，广告文案式写法）
  - Open Graph 标题和描述（社交分享优化）
  - 焦点关键词（主关键词 + 3-5 个次要关键词）
  - 搜索意图分类（信息型/交易型/导航型/商业型）
  - SEO 评分（0-100 + A+~F 等级）
  - 内容质量审计（按 Google "以人为本"标准）
  - 内链建议（描述性锚文本）
  - URL Slug 优化建议
  - 图片 alt 文本自动生成和填充
- 📊 **完整 SEO 基础设施**：
  - Meta 标签输出（title、description、canonical、robots）
  - Open Graph + Twitter Card 标签
  - JSON-LD 结构化数据（WebSite、Article、WebPage、Product、BreadcrumbList）
  - XML Sitemap（/sitemap.xml）+ 按 post type 和 taxonomy 分子站点地图
  - 虚拟 robots.txt（屏蔽 wp-admin、feed、search、WooCommerce 购物车等）
- 💉 **代码注入**：Google Analytics 4、Google Tag Manager、Google AdSense、自定义 head/body/footer 代码
- 🚀 **批量优化**：一键分析所有未优化的已发布页面
- 📊 **Dashboard**：优化覆盖率统计 + 最近优化页面列表
- 🖊️ **编辑器 Meta Box**：显示 AI 完整分析报告、SEO 评分、审计问题、Google 搜索预览、可编辑字段
