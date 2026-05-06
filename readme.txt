=== GML AI SEO ===
Contributors: huwencai
Tags: seo, ai, sitemap, meta tags, performance
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-config AI-powered SEO & Performance. Gemini / DeepSeek auto-optimizes titles, descriptions, schema, sitemaps, and page speed.

== Description ==

GML AI SEO lets anyone achieve expert-level SEO without any SEO knowledge. Install the plugin, add your API key, and AI handles everything — strictly following Google's official SEO guidelines.

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

= 1.4.0 =
Adds automatic internal linking and FAQ schema — two of the highest-impact SEO wins. After upgrading, run Bulk Optimize with "force re-analyze" to backfill existing posts.

= 1.3.0 =
Fixes sub-sitemap 404 errors, robots.txt duplicate lines, and adds manual SEO field editing without AI dependency. Recommended update for all users.
