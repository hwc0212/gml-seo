# GML AI SEO

零配置 AI SEO 插件。Gemini 以 SEO 大师的方式自动优化你的网站，替代 SEOPress / Yoast / Rank Math。

## 核心理念

**SEO 小白也能做到 SEO 大师的效果。** 安装插件 → 填 API Key → 完成。AI 严格按照 Google 官方 SEO 指南自动处理一切。

## 功能

### 🤖 AI SEO 大师（核心）

Gemini 严格遵循 [Google Search Essentials](https://developers.google.com/search/docs/essentials) 和 [SEO Starter Guide](https://developers.google.com/search/docs/fundamentals/seo-starter-guide) 的官方指导，对每个页面做全面深度分析：

- **搜索意图分析** — 识别用户实际会搜索的查询词（不是关键词堆砌），分类为信息型/交易型/导航型/商业型
- **标题优化** — 遵循 Google 指南："清晰、简洁、准确描述页面内容"。≤60 字符避免截断，自然融入搜索词，不堆砌关键词（Google 会重写堆砌的标题）
- **描述优化** — 遵循 Google 指南："简洁的一两句话总结页面最相关的要点"。120-155 字符，作为搜索结果中的"电梯演讲"，包含价值主张和行动号召
- **社交分享优化** — OG 标题/描述独立优化，更具情感驱动力，适合社交媒体信息流
- **内容质量审计** — 按 Google 的"以人为本的内容"标准评估：是否有用、可靠、原创、易读、有专业性
- **图片 alt 文本** — 遵循 Google 指南："描述图片与内容之间关系的简短文字"。自动检测缺失 alt 的图片，AI 生成描述性 alt 文本并自动填充
- **内链分析** — 遵循 Google 指南："链接是连接用户和搜索引擎到网站其他部分的好方法"。建议使用描述性锚文本（不是"点击这里"）
- **URL Slug 优化** — 遵循 Google 指南："在 URL 中包含对用户有用的词"。检测无意义的 ID 型 slug，建议描述性替代方案
- **SEO 评分** — 0-100 分 + A+~F 等级，基于 Google 官方标准诚实评估
- **FAQ 自动生成** — AI 基于文章内容生成 3-5 组 "People Also Ask" 风格 Q&A，自动输出 FAQPage schema（Google rich result）+ 可视化 FAQ section
- **自动内链（Auto Internal Linking）** — AI 扫描站内已优化页面，为当前文章挑选 3-5 个语义最相关的内链目标，使用描述性锚文本，通过 `the_content` 过滤器注入（不修改数据库原文，随时可回滚）

**Google 明确说不重要的东西，我们不浪费精力：**
- ❌ meta keywords 标签（Google 明确不使用）
- ❌ 标题标签的语义顺序（Google 说不影响排名）
- ❌ 最低/最高字数要求（Google 说没有魔法字数）
- ❌ 域名中的关键词（Google 说几乎没有排名效果）

### ⚡ 自动性能优化（Core Web Vitals）

Google 2021 年起将 Core Web Vitals（LCP、INP、CLS）作为排名信号。本插件自动执行所有安全的性能优化，无需配置，无需安装 Perfmatters / WP Rocket 等额外插件：

**WordPress 瘦身（自动）：**
- 移除 Emoji 脚本和样式（~10KB）
- 移除 Dashicons CSS（未登录用户，~46KB）
- 移除 oEmbed 嵌入脚本（~6KB）
- 移除 RSD、WLW、Shortlink、REST API、oEmbed 发现链接
- 隐藏 WordPress 版本号
- 禁用 XML-RPC（安全 + 性能）
- 禁用自我 Pingback
- 移除 Gutenberg 未使用的全局样式

**JavaScript 优化（自动）：**
- 非关键 JS 自动添加 `defer` 属性（消除渲染阻塞）
- 安全跳过 jQuery 等关键脚本（不会破坏功能）

**图片优化（自动）：**
- 首屏前 2 张图片正常加载，其余自动 `loading="lazy"`
- 自动补全缺失的 `width` / `height` 属性（防止 CLS 布局偏移）
- 第一张内容图片自动添加 `fetchpriority="high"`（加速 LCP）
- 特色图片自动 `<link rel="preload">`（提前加载 LCP 候选）
- iframe（YouTube、Google Maps）自动 `loading="lazy"`

**资源提示（自动）：**
- 自动检测 Google Fonts → preconnect
- GA/GTM → preconnect
- Gravatar → dns-prefetch

**不做过度优化（遵循 Google 指南）：**
- ❌ 不移除未使用的 CSS（可能破坏动态内容样式）
- ❌ 不延迟所有 JS（可能破坏交互功能）
- ❌ 不禁用 Google Fonts（可能影响品牌视觉）
- ❌ 不内联关键 CSS（对有缓存的回访用户反而更慢）

### 📊 完整 SEO 基础设施

无需任何配置，开箱即用：

- **Meta 标签** — title、description、canonical、robots 自动输出到 `<head>`
- **Open Graph** — og:title、og:description、og:image、og:type、og:url、og:locale
- **Twitter Card** — summary_large_image 卡片，自动使用特色图片
- **JSON-LD 结构化数据** — 自动生成：
  - `WebSite` + SearchAction（首页）
  - `Article`（文章）
  - `WebPage`（页面）
  - `Product` + Offer + AggregateRating（WooCommerce 产品）
  - `BreadcrumbList`（所有页面）
- **XML Sitemap** — `/sitemap.xml` 索引 + 按 post type 和 taxonomy 分子站点地图
- **robots.txt** — 自动生成完整的虚拟 robots.txt：
  - 屏蔽 wp-admin、wp-includes、wp-login、xmlrpc
  - 屏蔽搜索结果、feed、trackback 等低价值页面
  - 屏蔽 WooCommerce 购物车、结账、我的账户
  - 屏蔽查询参数爬取（?s=、?p=、?replytocom=）
  - 自动添加 Sitemap 地址
- **Robots meta** — 搜索结果页、分页归档自动 noindex

### 💉 代码注入

一站式管理所有追踪代码和自定义脚本：

- **Google Analytics 4** — 填入 G-XXXXXXX 即可，自动注入 gtag.js
- **Google Tag Manager** — 填入 GTM-XXXXXXX，自动注入 head + body noscript
- **Google AdSense** — 填入 ca-pub-XXXXXXX，自动注入广告脚本
- **自定义代码** — 三个注入点：
  - `<head>` 内（适合：Search Console 验证、Facebook Pixel、自定义 CSS）
  - `<body>` 开头（适合：GTM noscript 备用、聊天插件）
  - `</body>` 前（适合：统计脚本、延迟加载 JS）

### 🚀 批量优化

- 一键分析所有未优化的已发布页面
- 进度条实时显示，支持随时暂停
- 每篇间隔 800ms，不会触发 API 限流

### 📊 Dashboard

- 优化覆盖率统计（已优化 / 总发布数）
- 最近优化的页面列表 + AI 生成的标题预览

## 安装

1. 上传 `gml-seo` 到 `/wp-content/plugins/`
2. 激活插件
3. 进入 **GML AI SEO** → Settings
4. 填入 Gemini API Key（从 [Google AI Studio](https://aistudio.google.com/apikey) 获取）
5. 完成！

新发布的文章会自动被 AI 优化。已有文章可以通过「批量优化」一键处理。

## 与 SEOPress / Yoast / Rank Math 的区别

| 功能 | 传统 SEO 插件 | GML AI SEO |
|------|-------------|------------|
| SEO 标题/描述 | 用户手动填写 | AI 按 Google 指南自动生成 |
| 关键词研究 | 用户自己做 | AI 分析搜索意图，识别真实搜索查询 |
| 内容审计 | 基于规则的简单检查 | AI 按 Google "以人为本"标准深度分析 |
| 图片 alt 文本 | 用户手动填写 | AI 自动生成描述性 alt 并填充 |
| 内链建议 | 无或需要 Pro 版 | AI 建议描述性锚文本（遵循 Google 指南） |
| 性能优化 | 需要额外安装 Perfmatters/WP Rocket | 内置自动优化，零配置 |
| 配置复杂度 | 几十个设置页面 | 只需填 API Key |
| 遵循标准 | 自定义规则 | 严格遵循 Google 官方 SEO 指南 |

## 要求

- WordPress 6.0+
- PHP 7.4+
- Google Gemini API Key 或 DeepSeek API Key（二选一）

## 版本

1.4.0 — 完整更新日志见 [CHANGELOG.md](CHANGELOG.md)
