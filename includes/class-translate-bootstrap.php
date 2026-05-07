<?php
/**
 * Translate Module Bootstrap.
 *
 * Loads and initializes the bundled GML Translate subsystem. The translate
 * module lives in includes/translate/ and retains its original class names
 * (GML_*) and option keys (gml_*, wp_gml_index, wp_gml_queue) so data
 * migrated from the standalone GML Translate plugin works without changes.
 *
 * The standalone GML Translate plugin (plugins/gml-translate) is detected
 * and disabled at activation to avoid duplicate hooks and class collisions.
 *
 * @package GML_SEO
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GML_SEO_Translate_Bootstrap {

    const SUBDIR = 'includes/translate/';

    /** @var bool Whether the translate module successfully loaded. */
    private static $loaded = false;

    public static function load() {
        if ( self::$loaded ) return;

        // CRITICAL: If the standalone GML Translate plugin is still active,
        // DO NOT load the bundled module — it would cause fatal class
        // redeclaration errors (GML_Translator, GML_Installer, etc. would
        // be defined twice). We wait for the user to deactivate it.
        if ( self::standalone_is_active() ) {
            self::$loaded = false;
            add_action( 'admin_notices', [ __CLASS__, 'show_conflict_notice' ] );
            return;
        }

        // GML Translate expects these constants. We mirror them so the bundled
        // code runs unchanged.
        if ( ! defined( 'GML_VERSION' ) ) {
            define( 'GML_VERSION', GML_SEO_VER . '-translate' );
        }
        if ( ! defined( 'GML_PLUGIN_DIR' ) ) {
            define( 'GML_PLUGIN_DIR', GML_SEO_DIR . self::SUBDIR );
        }
        if ( ! defined( 'GML_PLUGIN_URL' ) ) {
            define( 'GML_PLUGIN_URL', GML_SEO_URL . self::SUBDIR );
        }
        if ( ! defined( 'GML_PLUGIN_FILE' ) ) {
            define( 'GML_PLUGIN_FILE', GML_SEO_DIR . 'gml-seo.php' );
        }

        // Register autoloader BEFORE any class is touched
        self::register_autoloader();

        self::$loaded = true;
    }

    /**
     * Check whether the standalone GML Translate plugin is currently active.
     * Uses the active_plugins option directly — safe to call before
     * wp-admin/includes/plugin.php is loaded.
     */
    public static function standalone_is_active() {
        $active = (array) get_option( 'active_plugins', [] );
        if ( in_array( 'gml-translate/gml-translate.php', $active, true ) ) {
            return true;
        }
        // Also check network-activated plugins (multisite)
        if ( is_multisite() ) {
            $network = (array) get_site_option( 'active_sitewide_plugins', [] );
            if ( isset( $network['gml-translate/gml-translate.php'] ) ) {
                return true;
            }
        }
        // Defensive: check if classes are already defined (plugin loaded earlier)
        if ( class_exists( 'GML_Translate', false ) || class_exists( 'GML_Installer', false ) ) {
            return true;
        }
        return false;
    }

    public static function show_conflict_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $deactivate_url = wp_nonce_url(
            admin_url( 'plugins.php?action=deactivate&plugin=gml-translate/gml-translate.php' ),
            'deactivate-plugin_gml-translate/gml-translate.php'
        );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>⚠️ GML AI SEO:</strong>
                检测到独立的 <strong>GML Translate</strong> 插件正在运行。
                从 v1.6.0 起，翻译功能已内置于本插件。请先停用独立的 GML Translate 插件以避免冲突。
                翻译数据（数据库表、语言配置、翻译记忆库）在停用后依然保留并会被本插件无缝复用。
            </p>
            <p>
                <a href="<?php echo esc_url( $deactivate_url ); ?>" class="button button-primary">
                    停用独立 GML Translate 插件
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Spl autoloader that resolves GML_* classes to files inside
     * includes/translate/ (and includes/translate/admin/).
     */
    private static function register_autoloader() {
        spl_autoload_register( function( $class ) {
            // Only handle GML_* from the bundled translate module.
            // GML_SEO_* classes use their own require_once at plugin boot.
            if ( strpos( $class, 'GML_' ) !== 0 ) return;
            if ( strpos( $class, 'GML_SEO_' ) === 0 ) return;

            $name = substr( $class, 4 );
            $file = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

            $dirs = [
                GML_SEO_DIR . self::SUBDIR,
                GML_SEO_DIR . self::SUBDIR . 'admin/',
            ];

            foreach ( $dirs as $d ) {
                $path = $d . $file;
                if ( file_exists( $path ) ) {
                    require_once $path;
                    return;
                }
            }
        } );
    }

    /**
     * Initialize all translate components. Called from GML_SEO::boot().
     * Mirrors the original gml-translate.php init_components() flow.
     */
    public static function init() {
        if ( ! self::$loaded ) self::load();
        if ( ! self::$loaded ) return; // conflict with standalone — skip

        // If no target languages configured, only load admin settings
        // (so user can configure it) and skip the heavy stuff.
        $languages = get_option( 'gml_languages', [] );
        $has_langs = ! empty( $languages );

        // Admin settings (always)
        if ( is_admin() ) {
            if ( class_exists( 'GML_Admin_Settings' ) ) new GML_Admin_Settings();
            if ( class_exists( 'GML_Translation_Editor' ) ) new GML_Translation_Editor();
        }

        if ( ! $has_langs ) return;

        // Translation enabled toggle
        $enabled = (bool) get_option( 'gml_translation_enabled', false );
        if ( ! $enabled ) return;

        // Frontend components
        if ( ! is_admin() ) {
            if ( class_exists( 'GML_Output_Buffer' ) )        new GML_Output_Buffer();
            if ( class_exists( 'GML_Language_Switcher' ) )    new GML_Language_Switcher();
            if ( class_exists( 'GML_Nav_Menu_Switcher' ) )    new GML_Nav_Menu_Switcher();
            if ( class_exists( 'GML_Translate_Hreflang' ) ) new GML_Translate_Hreflang();
            if ( class_exists( 'GML_Translate_Router' ) )   new GML_Translate_Router();
            if ( class_exists( 'GML_Sitemap' ) )              new GML_Sitemap();
            if ( class_exists( 'GML_Language_Detector' ) )    new GML_Language_Detector();
            if ( class_exists( 'GML_Gettext_Filter' ) )       new GML_Gettext_Filter();
        }

        // Cron-driven components (both contexts)
        if ( class_exists( 'GML_Queue_Processor' ) )      new GML_Queue_Processor();
        if ( class_exists( 'GML_Content_Crawler' ) )      new GML_Content_Crawler();
        if ( class_exists( 'GML_Translation_Plan_Manager' ) ) new GML_Translation_Plan_Manager();

        // Register language switcher widget
        add_action( 'widgets_init', function() {
            if ( class_exists( 'GML_Language_Switcher_Widget' ) ) {
                register_widget( 'GML_Language_Switcher_Widget' );
            }
        } );
    }

    /**
     * Run installer on plugin activation — creates DB tables and seeds
     * default options. Safe to call multiple times (uses dbDelta).
     *
     * If GML Translate standalone plugin previously ran, the tables and
     * options already exist — this becomes a no-op and data is preserved.
     */
    public static function install() {
        if ( ! self::$loaded ) self::load();
        if ( ! self::$loaded ) return; // conflict with standalone — skip
        if ( class_exists( 'GML_Installer' ) ) {
            GML_Installer::activate();
        }
    }

    /**
     * Deactivate translate module — clears its cron events but preserves
     * the translation memory so users don't lose work.
     */
    public static function deactivate() {
        if ( ! self::$loaded ) return;
        if ( class_exists( 'GML_Installer' ) ) {
            GML_Installer::deactivate();
        }
    }

    /**
     * Attempt to auto-deactivate the standalone GML Translate plugin on
     * activation, so both don't run simultaneously.
     */
    public static function maybe_deactivate_standalone() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $standalone = 'gml-translate/gml-translate.php';
        if ( is_plugin_active( $standalone ) ) {
            deactivate_plugins( $standalone, true );
            set_transient( 'gml_seo_translate_migrated', 1, HOUR_IN_SECONDS );
        }
    }

    /**
     * Show an admin notice after merging with standalone GML Translate.
     */
    public static function maybe_show_migration_notice() {
        if ( get_transient( 'gml_seo_translate_migrated' ) ) {
            delete_transient( 'gml_seo_translate_migrated' );
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo '<strong>✅ GML Translate 已合并到 GML AI SEO。</strong> ';
                echo '原插件已自动停用，所有翻译数据（数据库、设置、语言配置）无缝保留。';
                echo '请在 <a href="' . esc_url( admin_url( 'admin.php?page=gml-seo&tab=translate' ) ) . '">GML AI SEO → 🌐 Translation</a> 查看。';
                echo '</p></div>';
            } );
        }
    }
}
