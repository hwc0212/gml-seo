# Changelog

All notable changes to GML AI SEO will be documented in this file.

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
