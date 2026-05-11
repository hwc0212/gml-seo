=== GML AI SEO ===
Contributors: huwencai
Tags: seo, ai, sitemap, multilingual, translate
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

All-in-one AI SEO automation + multilingual translation with destination-language SEO awareness. Built for 2025 AI Overviews and HCS.

== Description ==

GML AI SEO is a scheduled-worker SEO automation plugin — not a one-off tool. AI audits your entire site weekly, detects content drift and stale pages, re-optimizes what needs attention, and notifies Google + Bing in real time via IndexNow and Google Indexing API. Follows Google's 2025 guidance for AI Overviews, AI Mode, and the Helpful Content System (HCS) now baked into core ranking.

= What makes it different =

Traditional SEO plugins give you manual knobs to turn. This plugin does the SEO work itself, continuously:

* **Scheduled weekly audit** scans every post, scores its health across 5 signals (never analyzed, missing data, content drift, low score, staleness), and queues at-risk pages for re-optimization
* **Real-time indexing** pushes publish/update/delete events to Bing, Yandex, Seznam, Naver (via IndexNow — zero config) and to Google (via Indexing API with your service account)
* **AI Overviews ready** — generates BLUF (Bottom Line Up Front) summaries marked with Speakable schema so your content has a better chance of being quoted in AI search results
* **Smart schema detection** — AI identifies whether a page is an Article, HowTo, Recipe, Review, Event, Course, or VideoObject and outputs the matching structured data

= AI SEO Master Engine =

The AI engine (Google Gemini or DeepSeek) analyzes every published page and automatically generates:

* SEO title (≤ 60 chars, keyword-optimized, no stuffing)
* Meta description (120–155 chars, compelling ad-copy style)
* Open Graph title & description for social sharing
* Focus keywords (primary + 3–5 secondary)
* Search intent classification
* SEO score (0–100, A+ to F grade)
* Content quality audit based on Google E-E-A-T criteria
* Internal linking suggestions with descriptive anchor text
* URL slug optimization suggestions
* Image alt text auto-generation and filling
* FAQ generation with FAQPage schema for Google rich results
* Automatic internal linking across the entire site

= Built-in Performance Optimization =

Automatic Core Web Vitals optimizations — no extra plugins needed:

* Remove emoji scripts, Dashicons CSS, oEmbed scripts
* Defer non-critical JavaScript (safely skips jQuery)
* Lazy-load images and iframes
* Auto-fill missing width/height attributes (prevents CLS)
* First content image fetchpriority="high" (LCP boost)
* Featured image preload
* Preconnect & DNS prefetch for external resources

= Complete SEO Infrastructure =

* Meta tags — title, description, canonical, robots
* Open Graph + Twitter Card tags
* JSON-LD structured data (WebSite, Article, WebPage, Product, BreadcrumbList)
* XML Sitemap — index + per-post-type and taxonomy sub-sitemaps
* Virtual robots.txt with best-practice rules
* Code injection — GA4, GTM, AdSense, custom head/body/footer code
* Bulk optimization with progress tracking
* Dashboard with optimization coverage stats

= Dual AI Engine =

* Google Gemini — recommended for international sites
* DeepSeek — recommended for sites in mainland China where Google API is inaccessible

== Installation ==

1. Upload the `gml-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **GML AI SEO → Settings**.
4. Choose your AI engine (Gemini or DeepSeek) and enter the API key.
5. Done! New posts are optimized automatically on publish. Use **Bulk Optimize** for existing content.

= Getting an API Key =

* **Gemini**: Visit [Google AI Studio](https://aistudio.google.com/apikey) and create a free API key.
* **DeepSeek**: Visit [DeepSeek Platform](https://platform.deepseek.com/api_keys) and create an API key.

== Frequently Asked Questions ==

= Do I need any SEO knowledge to use this plugin? =

No. The AI follows Google's official SEO guidelines automatically. Just install, add your API key, and the plugin handles everything.

= Can I manually edit SEO titles and descriptions? =

Yes. Every post editor includes a GML AI SEO metabox with editable fields for SEO title, meta description, keywords, OG title, and OG description. You can edit them with or without running the AI analysis.

= Does this replace Yoast / Rank Math / SEOPress? =

Yes. GML AI SEO covers meta tags, sitemaps, schema, robots.txt, performance optimization, and code injection. It is designed as a complete, all-in-one SEO solution.

= Will the performance optimizations break my site? =

The plugin only applies safe optimizations that follow Google's Core Web Vitals best practices. It skips jQuery and other critical scripts when deferring JS, and does not remove CSS or disable Google Fonts.

= What happens if the AI returns an error? =

The plugin automatically retries once on JSON parse failures. Network or API errors are logged to the WordPress debug log for troubleshooting.

= Is it compatible with WooCommerce? =

Yes. The plugin generates Product schema with price and rating data, and the robots.txt automatically blocks cart, checkout, and account pages from crawling.

= Does it work with multilingual plugins? =

Yes. It is tested and compatible with GML Translate. The meta tags module respects the active language context.

== Screenshots ==

1. AI SEO analysis report in the post editor with score, audit, and suggestions.
2. Editable SEO fields with Google search preview.
3. Bulk optimization with real-time progress.
4. Dashboard showing optimization coverage.
5. Settings page with dual AI engine support.

== Changelog ==

= 1.9.1 =
* Added: **Performance tab 现在是完整表单**，每项优化独立开关，共 18 项分成 6 组（WordPress 瘦身 / JS / 字体 / 图片与 iframe / 资源提示 / HTML 与 REST）。默认全部启用，主题或插件冲突时可按项关闭。
* Added: Settings 页新增"退出观察期"按钮（迁移完成后自动进入观察期时才显示）。
* Fixed: `sanitize()` 之前会把未在当前表单里出现的字段（如 `gradual_mode` / `conflict_notice_dismissed` / `perf_*`）误清空。改为基于现有 option 增量覆盖，各 tab 通过 `__*_submitted` 隐藏字段声明自己在操作哪组字段。

= 1.9.0 =
* Added: **SEO Plugin Migration** — safely take over sites that already run Yoast SEO / Rank Math / SEOPress / AIOSEO / The SEO Framework. Three layers of protection:
  * **Conflict Detector** (read-only) identifies competing SEO plugins and suppresses GML's frontend meta output so no duplicate `<title>`, description or canonical tags are emitted.
  * **Migration wizard** (Scan → Preview → Execute → Progress) moves hand-tuned meta into GML's `_gml_seo_*` namespace. WP-Cron-backed batching (100 posts per batch). Source data is never deleted.
  * **Anti-penalty observation period** (Gradual Mode) auto-engages on completion. AI results are routed to `_gml_seo_suggestion_*` instead of overwriting frontend meta, Metabox shows a "migrated vs AI suggestion" side-by-side view, Bulk Optimize is blocked by both UI and AJAX checks. Exit anytime from Settings.
* Follows [Google SEO Starter Guide](https://developers.google.com/search/docs/fundamentals/seo-starter-guide) and [Google Search Essentials](https://developers.google.com/search/docs/essentials).
* New option `gml_seo_migration_state` tracks migration status. No existing `gml_seo` option fields are modified.

= 1.8.0 =
* Added: 4 safe performance optimizations — Google Fonts font-display: swap auto-applied, HTML output minification (skips pre/textarea/script/style/code), HTTP Link: preload headers for HTTP/2 Early Hints, disable rarely-used oEmbed REST endpoints.
* Performance tab updated to show the 4 new optimizations with 🆕 marker.

= 1.7.3 =
* Fixed: "Standalone GML Translate is running" warning persisted even after uninstalling the standalone plugin. Caused by a defensive class_exists check that triggered on our own bundled GML_Installer class. Removed that check — active_plugins option is the authoritative source.

= 1.7.2 =
* Fixed: Critical regression introduced in v1.6.0 merge — translated pages were rendering with missing main content. Root cause: bootstrap init gated frontend modules (router, hreflang, output buffer, gettext filter, language switcher) behind gml_translation_enabled AND non-empty languages array. Standalone GML Translate never had this gate. Bootstrap now mirrors standalone's exact init logic: any API key configured → initialize all frontend modules. gml_translation_enabled remains as internal output-buffer gate only.
* Reverted: The v1.7.1 fix_front_page_flags attempt — it was targeting the wrong root cause. Standalone never needed it.

= 1.7.1 =
* Fixed: Critical — translated language homepages (/ru/, /es/, /fr/, etc.) lost their hero / page-builder content, only showing a basic page layout. Root cause: WordPress's is_front_page() returned false when the URL had a language prefix, so Oxygen / Elementor / Divi / most themes skipped frontpage-specific rendering. Fix: Router now explicitly sets is_front_page=true on language root URLs via parse_query hook.

= 1.7.0 =
* Added: Unified API key — SEO and Translation now share the same Gemini/DeepSeek API configuration. Set it once in GML AI SEO → Settings, translation module inherits automatically.
* Added: Language switcher style adaptation — CSS uses pure inheritance (font/color/size inherited from parent), JS auto-copies the computed styles of a sibling link so the switcher blends seamlessly into any nav menu, header, footer, or sidebar context.
* Added: Menu switcher dedup — when a language switcher menu item is added via Appearance → Menus, the auto-position menu_before/menu_after injection is suppressed automatically.
* Removed: Translation Engine dropdown and API key fields from Translation tab (managed under unified Settings).

= 1.6.1 =
* Fixed: Critical — site would white-screen when the standalone GML Translate plugin was still active after upgrading to 1.6.0. Bootstrap now detects the standalone plugin BEFORE defining any shared constants.
* Added: Persistent admin notice with one-click "Deactivate standalone" button when both plugins coexist.

= 1.6.0 =
* Merged: GML Translate plugin fully absorbed. Dynamic AI translation, hash-based translation memory, language switcher, hreflang, multilingual sitemap, translation editor, glossary, exclusion rules, content crawler, Weglot importer.
* Seamless migration: Standalone GML Translate auto-deactivated on activation; `wp_gml_index` / `wp_gml_queue` tables and all options preserved — zero re-translation cost.
* Renamed internal classes `GML_SEO_Hreflang` / `GML_SEO_Router` to `GML_Translate_*` to avoid collisions.
* Shared AI key and engine across SEO and translation.

= 1.5.0 =
* Added: Scheduled SEO Health Monitor — weekly full-site audit with 5-signal priority queue (never analyzed, missing data, content drift, low score, staleness). Category-aware freshness thresholds.
* Added: Real-time indexing via IndexNow (Bing/Yandex/Seznam/Naver, zero config) and Google Indexing API (service account).
* Added: BLUF/TL;DR auto-generation with Speakable schema for AI Overviews citation.
* Added: Smart schema detection — AI picks Article / HowTo / Recipe / Review / Event / VideoObject / Course based on content.
* Added: E-E-A-T score and AI-search score as separate sub-scores.
* Added: Automation dashboard tab with run log, queue status, manual triggers.
* Changed: AI prompt updated with Google's May 2025 "Succeeding in AI Search" guidance and HCS-as-core-ranking.

= 1.4.1 =
* Fixed: Auto-link count stays at 0 after bulk optimize — rebuilt the candidate index seeding so posts processed early in the run now have candidates to link to; added Dashboard "Rebuild Index" button for one-click repair.
* Fixed: Anchor-text verbatim check now uses full post content instead of truncated excerpt.
* Fixed: Dashboard stats grid layout using CSS Grid so labels no longer overlap.

= 1.4.0 =
* Added: Automatic internal linking — AI picks the most semantically relevant posts on your site and injects descriptive anchor text links via `the_content` filter (DB content is never modified, fully reversible per-post).
* Added: FAQ generation with FAQPage schema — AI generates 3-5 "People Also Ask" style Q&As grounded in page content, rendered as an accessible section plus JSON-LD for Google rich results.
* Added: Dashboard cards showing FAQ and auto-link coverage across the site.
* Added: Bulk optimize "force re-analyze" mode — backfill FAQ and auto-links for previously optimized posts.

= 1.3.0 =
* Fixed: Sub-sitemap 404 — auto flush rewrite rules on version upgrade; improved 404 status reset in sitemap renderer.
* Fixed: Duplicate gml-sitemap.xml in robots.txt when GML Translate is active.
* Fixed: Bulk optimize now auto-retries once on "Invalid JSON" parse failure.
* Fixed: Dashboard AI title column text overflow — added max-width and ellipsis truncation.
* Fixed: Metabox SEO fields are now always visible and editable without running AI analysis first.

= 1.2.0 =
* Added: DeepSeek AI engine support — alternative for mainland China where Google API is inaccessible.
* Fixed: sitemap.xml redirect conflict with WordPress core WP_Sitemaps (three-layer fix).

= 1.1.0 =
* Added: Built-in performance optimization module — Core Web Vitals optimizations with zero configuration.
* Added: Performance tab in admin showing all active optimizations.

= 1.0.0 =
* Initial release.
* AI SEO master engine with Gemini integration.
* Meta tags, Open Graph, Twitter Card, JSON-LD schema.
* XML Sitemap and virtual robots.txt.
* Code injection (GA4, GTM, AdSense, custom code).
* Bulk optimization and dashboard.
* Post editor metabox with full SEO report.

== Upgrade Notice ==

= 1.9.1 =
Performance tab 现在可以按项开关优化（默认全开，兼容 v1.9.0）。修复了一个 sanitize bug —— 原来保存设置时可能误清空 gradual_mode 等字段。

= 1.9.0 =
Safely migrate from Yoast / Rank Math / SEOPress / AIOSEO / The SEO Framework with a guided wizard. Conflict detection suppresses duplicate meta tags until migration is finished. An anti-penalty observation period engages automatically after migration to prevent Google Core Update surprises.

= 1.7.3 =
Fixes false-positive "standalone GML Translate is running" warning after uninstalling the standalone plugin.

= 1.7.2 =
Critical regression fix: translated pages missing main content. Upgrade and clear cache.

= 1.7.1 =
Critical fix: translated homepages were missing hero sections because is_front_page() returned false on /ru/, /es/, /fr/, etc. Upgrade and clear cache.

= 1.7.0 =
Unified API key across SEO and Translation (set once in Settings, done). Language switcher now adapts to its surroundings automatically. Menu dedup.

= 1.6.1 =
Critical fix for v1.6.0 fatal error when standalone GML Translate is still active. Upgrade immediately if you installed v1.6.0.

= 1.6.0 =
GML Translate is now part of GML AI SEO. Upgrade, then uninstall the standalone plugin — all translation memory and settings are preserved.

= 1.5.0 =
Major upgrade: SEO now runs as a scheduled worker. Weekly full-site audit, real-time indexing, AI Overviews optimization. Visit the new Automation tab after upgrade.

= 1.4.1 =
Fixes auto-link count stuck at 0 after bulk optimize. After upgrading, click "Rebuild Index" on Dashboard then re-run Bulk with force mode.

= 1.4.0 =
Adds automatic internal linking and FAQ schema — two of the highest-impact SEO wins. After upgrading, run Bulk Optimize with "force re-analyze" to backfill existing posts.

= 1.3.0 =
Fixes sub-sitemap 404 errors, robots.txt duplicate lines, and adds manual SEO field editing without AI dependency. Recommended update for all users.
