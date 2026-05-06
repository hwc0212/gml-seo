# Changelog

All notable changes to GML AI SEO will be documented in this file.

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
