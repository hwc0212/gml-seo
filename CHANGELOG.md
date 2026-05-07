# Changelog

All notable changes to GML AI SEO will be documented in this file.

## [1.7.3] - 2026-04-16

### Fixed
- 🐛 **卸载独立 GML Translate 后仍持续显示"独立插件正在运行"警告**
  - 根因：`standalone_is_active()` 除了检查 `active_plugins` option，还有一层"防御性"的 `class_exists('GML_Translate') || class_exists('GML_Installer')` 检测
  - 但我们自己的 bundled 模块里也有 `GML_Installer` 类（位于 `includes/translate/class-installer.php`），DB 自动升级时会调用 `GML_Installer::activate()` 导致该类被加载
  - `active_plugins` 已经显示独立插件不在激活列表里，但 `class_exists` 检测返回 true，导致误报
  - 修复：删除 class_exists 检测，只保留 `active_plugins` + `active_sitewide_plugins`（multisite）两个权威来源

## [1.7.2] - 2026-04-16

### Fixed
- 🐛 **严重回归 — 翻译页面丢失主内容** — 我在 v1.6.0 合并 GML Translate 时写错了 bootstrap 的 init 门控逻辑，独立版本原本一切正常
  - 独立 GML Translate 的做法：只要配了 API Key 就注册所有前端模块（Output Buffer、SEO Router、Hreflang、Sitemap、Gettext Filter、Language Switcher、Language Detector）。`gml_translation_enabled` option 只作为 Output Buffer 内部 `should_skip()` 的开关
  - 我的错误做法：把 `gml_translation_enabled` 和 `has_langs` 当成 gate，没通过就**跳过所有前端模块**。结果：
    - Router 不注册 → 语言 URL 路由不工作
    - Hreflang 不输出
    - Gettext Filter 不运行 → 主题字符串不翻译
    - 极端情况下 Output Buffer 也不跑 → 翻译页渲染出各种残缺形态
  - 修复：Bootstrap init 改为 1:1 镜像独立版的逻辑
    - 只要 API Key 存在（Translate 自己的加密 key 或 SEO 的共享 key），所有前端模块都初始化
    - 去掉"语言数组非空"和"translation_enabled"的门控
    - 新增 DOING_CRON 分支（只加载 Queue Processor + Content Crawler，减少第三方插件副作用）
    - 新增 DB 版本自动升级检测（独立版本有但合并时漏掉）
- 🔄 **撤销 v1.7.1 的 `fix_front_page_flags`** — 那个修复方向错了（独立版本没这个 hook 也一切正常），根本原因不在 `is_front_page`，而是上面说的 router 根本没被初始化

### Important
**升级到 1.7.2 后必须清缓存**（Translation → Clear Cache + 任何缓存插件 + 浏览器 hard reload）。翻译页面应该立即完全恢复。

## [1.7.1] - 2026-04-16

### Fixed
- 🐛 **翻译版首页主内容（hero/page builder section）丢失** — `/ru/`、`/es/`、`/fr/`、`/de/`、`/it/` 等语言首页打开后，header 和 footer 正常翻译，但中间 Oxygen Builder / Elementor / Divi 渲染的 hero 区块和首页专属内容整个消失，只剩一个简单的联系方式块
  - 根因：路由把 `/ru/` 映射成 `page_id=front_page_id` 让 WordPress 服务首页内容，但 `WP_Query::parse_query()` 只会根据 `REQUEST_URI == '/'` 来设置 `is_front_page = true`。`REQUEST_URI` 是 `/ru/` 时，`is_front_page()` 返回 `false`。页面构建器（Oxygen、Elementor、Divi、几乎所有主流主题）都依赖 `is_front_page()` 来决定是否渲染"首页模板"，条件为假时就跳过 hero，只渲染"普通页面"
  - 修复：`GML_Translate_Router` 新增 `fix_front_page_flags()`，挂 `parse_query` 优先级 99，在语言首页 URL 上主动把 `$wp_query->is_front_page = true`
  - 同时处理"最新文章"首页模式（`show_on_front != page`）：置 `is_home=true, is_front_page=true, is_page=false, is_singular=false`
  - 只针对语言根路径（`/ru/`、`/ru`）生效，子页面 `/ru/about/` 不受影响

### Important
此 bug 是 v1.6.0 合并时带进来的（独立 GML Translate 之前也可能有同样问题，但没被注意到）。升级到 1.7.1 后清一次页面缓存（Translation → Clear Cache 或任何缓存插件），翻译首页就会恢复完整。

## [1.7.0] - 2026-04-16

### Added

#### 🔑 统一 API Key —— 一次设置，到处生效
- **SEO 和翻译共用同一个 AI Key 配置**。只需在 **GML AI SEO → Settings** 里填一次，翻译模块自动复用
- Translate tab 顶部显示一个蓝色通知卡片：显示当前引擎（Gemini / DeepSeek）和配置状态，一键跳到 Settings
- 引擎选择、Gemini Key、DeepSeek Key、DeepSeek Model、DeepSeek Base URL 这 5 个字段从 Translate 设置页彻底移除
- `GML_Gemini_API::get_api_key()` 增加回退逻辑：优先用 Translate 自己的加密 key（旧数据），为空时自动回落到 GML SEO 的 key
- `GML_Output_Buffer::should_skip()` 的 API Key 检测也同时认 Translate 和 SEO 两个来源，避免新安装时"已配置 key 却无法翻译"的误判

#### 🎨 语言切换器样式自适应（核心 UX 改进）
- **纯继承设计** — CSS 不再写死字号/字重/颜色/padding，默认全部 `font: inherit; color: inherit`
- **CSS 自定义属性** — 提供 `--gml-gap`、`--gml-hover-opacity`、`--gml-panel-bg` 等变量，主题或用户可轻松覆盖
- **JS 样式同步** — `initMenuStyleSync()` 升级为 `syncSwitcherToContext()`，能力扩展：
  - 不仅限于 `<ul>/<ol>` 里的 `<li>`（菜单），还支持 header / footer / nav / aside / .widget 等语义容器
  - 自动找附近的 `<a>` 链接，把它的 computed styles 复制到切换器（字体族、字号、字重、字体样式、颜色、行高、字距、text-transform、text-decoration）
  - 镜像参考 `<li>` 的 padding / margin，保证垂直对齐
  - 镜像参考 `<a>` 的 padding，保证点击面积一致
  - 结束后给切换器加 `gml-style-synced` class，CSS 可用它做更精细的适配
- **副作用**：切换器放在任何位置（header 导航、footer 链接、sidebar 小工具、shortcode）都会视觉上"消失"，看起来像原生菜单项

#### 🔀 菜单切换器去重
- `GML_Nav_Menu_Switcher::$rendered_in_menu` 静态标志：当 Appearance → Menus 里加了语言切换器菜单项时，自动抑制 `menu_before`/`menu_after` 的二次注入
- 避免用户同时配置两种机制时出现重复切换器

### Removed
- Translate settings 页的 Translation Engine 下拉
- Translate settings 页的 Gemini API Key 输入框
- Translate settings 页的 DeepSeek API Key 输入框
- Translate settings 页的 DeepSeek Model 输入框
- Translate settings 页的 DeepSeek API Base URL 输入框

（全部由 GML AI SEO → Settings 统一管理）

### Compatibility
- **向后兼容**：旧站点如果已经用 Translate 自己的加密 key 工作，继续有效
- 新安装或升级后填一次 SEO Key 就够，无需再配置 Translate 的

## [1.6.1] - 2026-04-16

### Fixed
- 🐛 **Fatal error — 独立 GML Translate 仍激活时站点崩溃**
  - 根因：v1.6.0 的 bootstrap 在定义 `GML_VERSION`/`GML_PLUGIN_DIR` 等常量时没先检测独立 GML Translate 插件是否还在运行。独立插件随后读取这些常量，尝试加载 `GML_PLUGIN_DIR . 'includes/class-autoloader.php'`，路径被我们覆盖成了 `gml-seo/includes/translate/includes/class-autoloader.php`（多了一层 includes），文件找不到 → fatal
  - 修复：`GML_SEO_Translate_Bootstrap::load()` **第一步**就检查 `active_plugins`，如果独立 GML Translate 仍在列表里，直接跳过常量定义、自动加载器注册、所有 init 流程
  - 新增 `standalone_is_active()` 方法，检查 `active_plugins`、`active_sitewide_plugins`（multisite）、以及 class_exists 三重验证
  - 在 admin 显示持续可见的警告横幅，带一键"停用独立插件"按钮
  - Translate tab 在独立插件激活时显示迁移引导而非报错
- **重要**：v1.6.0 用户如果遇到白屏，用 FTP/SSH 删除 `wp-content/plugins/gml-translate/` 即可恢复。然后上传 v1.6.1 替代。

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
