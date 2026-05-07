<?php
/**
 * Language Switcher class - UI component for language selection
 *
 * @package GML_Translate
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GML Language Switcher class
 */
class GML_Language_Switcher {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register shortcode
        add_shortcode('gml_language_switcher', [$this, 'render_shortcode']);
        
        // Register widget
        add_action('widgets_init', [$this, 'register_widget']);
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Automatic position hooks
        $this->register_auto_position();
    }

    /**
     * Register automatic position hooks based on admin setting.
     */
    private function register_auto_position() {
        $position = get_option('gml_switcher_position', 'none');
        if ($position === 'none' || empty($position)) {
            return;
        }

        switch ($position) {
            case 'header_left':
            case 'header_center':
            case 'header_right':
                add_action('wp_head', [$this, 'render_auto_position_inline'], 5);
                break;
            case 'menu_before':
                add_filter('wp_nav_menu_items', [$this, 'prepend_to_menu'], 10, 2);
                break;
            case 'menu_after':
                add_filter('wp_nav_menu_items', [$this, 'append_to_menu'], 10, 2);
                break;
            case 'footer_left':
            case 'footer_center':
            case 'footer_right':
                add_action('wp_footer', [$this, 'render_auto_position_footer'], 5);
                break;
        }
    }

    /**
     * Render switcher as a fixed overlay for header positions.
     */
    public function render_auto_position_inline() {
        $position = get_option('gml_switcher_position', 'none');
        $align_map = [
            'header_left'   => 'left:20px',
            'header_center' => 'left:50%;transform:translateX(-50%)',
            'header_right'  => 'right:20px',
        ];
        $align = $align_map[$position] ?? 'right:20px';
        echo '<div class="gml-auto-switcher" style="position:fixed;top:10px;' . $align . ';z-index:99999;">';
        echo $this->render();
        echo '</div>';
    }

    /**
     * Render switcher as a fixed overlay for footer positions.
     */
    public function render_auto_position_footer() {
        $position = get_option('gml_switcher_position', 'none');
        $align_map = [
            'footer_left'   => 'left:20px',
            'footer_center' => 'left:50%;transform:translateX(-50%)',
            'footer_right'  => 'right:20px',
        ];
        $align = $align_map[$position] ?? 'right:20px';
        echo '<div class="gml-auto-switcher" style="position:fixed;bottom:20px;' . $align . ';z-index:99999;">';
        echo $this->render();
        echo '</div>';
    }

    /**
     * Prepend switcher to nav menu.
     * Only injects once — into the first non-footer nav menu on the page.
     */
    public function prepend_to_menu($items, $args) {
        if ( ! $this->should_inject_into_menu( $args ) ) {
            return $items;
        }
        return '<li class="menu-item gml-menu-item">' . $this->render(['menu_context' => true]) . '</li>' . $items;
    }

    /**
     * Append switcher to nav menu.
     * Only injects once — into the first non-footer nav menu on the page.
     */
    public function append_to_menu($items, $args) {
        if ( ! $this->should_inject_into_menu( $args ) ) {
            return $items;
        }
        return $items . '<li class="menu-item gml-menu-item">' . $this->render(['menu_context' => true]) . '</li>';
    }

    /**
     * Decide whether to inject the switcher into this menu call.
     *
     * Priority:
     * 1. theme_location matches known primary names → inject.
     * 2. theme_location matches known footer/secondary names → skip.
     * 3. Menu name contains footer keywords → skip.
     * 4. Otherwise inject only ONCE per page (static flag) to avoid duplicates.
     */
    private function should_inject_into_menu( $args ) {
        static $injected = false;

        $primary_locations = [ 'primary', 'main', 'main-menu', 'primary-menu', 'header-menu', 'top-menu', 'nav-menu', 'navigation' ];
        $footer_locations  = [ 'footer', 'footer-menu', 'footer-nav', 'secondary', 'bottom', 'bottom-menu' ];

        $location = $args->theme_location ?? '';

        if ( $location && in_array( $location, $primary_locations, true ) ) {
            $injected = true;
            return true;
        }

        if ( $location && in_array( $location, $footer_locations, true ) ) {
            return false;
        }

        // Check menu name for footer keywords
        $menu_name = '';
        if ( ! empty( $args->menu ) ) {
            if ( is_object( $args->menu ) ) {
                $menu_name = strtolower( $args->menu->name ?? '' );
            } elseif ( is_string( $args->menu ) ) {
                $menu_name = strtolower( $args->menu );
            }
        }
        foreach ( [ 'footer', 'bottom', 'secondary' ] as $kw ) {
            if ( strpos( $menu_name, $kw ) !== false ) {
                return false;
            }
        }

        // Unknown location — inject only once per page to avoid duplicates
        if ( $injected ) {
            return false;
        }
        $injected = true;
        return true;
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'gml-language-switcher',
            GML_PLUGIN_URL . 'assets/css/language-switcher.css',
            [],
            GML_VERSION
        );

        wp_enqueue_script(
            'gml-language-switcher',
            GML_PLUGIN_URL . 'assets/js/language-switcher.js',
            [],
            GML_VERSION,
            true
        );

        // Pass data for link rewriting
        $languages_raw = get_option('gml_languages', []);
        $lang_codes    = array_values(array_filter(array_column($languages_raw, 'code')));
        $source_lang   = get_option('gml_source_lang', substr(get_locale(), 0, 2));
        // Include source lang so the regex covers all known prefixes
        $all_codes     = array_unique(array_merge([$source_lang], $lang_codes));

        wp_localize_script('gml-language-switcher', 'gmlData', [
            'currentLang' => $this->get_current_language(),
            'sourceLang'  => $source_lang,
            'languages'   => $all_codes,
            'homeUrl'     => home_url('/'),
        ]);
    }
    
    /**
     * Render shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'is_dropdown'  => null,
            'show_flags'   => null,
            'flag_type'    => null,
            'show_names'   => null,
            'use_fullname' => null,
            // legacy
            'style'        => null,
        ], $atts);

        // Remove null values so render() falls back to saved admin settings
        $args = array_filter($atts, fn($v) => $v !== null);

        // Map legacy 'yes'/'no' to 'true'/'false' for filter_var
        foreach (['show_flags', 'show_names', 'is_dropdown', 'use_fullname'] as $key) {
            if (isset($args[$key])) {
                $args[$key] = in_array(strtolower($args[$key]), ['yes', '1', 'true']) ? 'true' : 'false';
            }
        }

        // Legacy style= compat
        if (isset($args['style']) && !isset($args['is_dropdown'])) {
            $args['is_dropdown'] = $args['style'] === 'dropdown' ? 'true' : 'false';
        }

        return $this->render($args);
    }
    
    /**
     * Render language switcher
     *
     * @param array $args Arguments
     * @return string HTML output
     */
    /**
     * Get flag HTML based on flag type setting.
     *
     * @param string $lang_code   Language code
     * @param string $flag_type   rectangle|circle|square|emoji
     * @param string $native_name Native language name (for alt text)
     * @param string $country     ISO 3166-1 alpha-2 country code (overrides internal map)
     */
    private function get_flag_html($lang_code, $flag_type, $native_name, $country = '') {
        // Use provided country, or fall back to internal map
        if ( ! $country ) {
            $country_codes = [
                'en' => 'us', 'zh' => 'cn', 'ja' => 'jp', 'fr' => 'fr',
                'de' => 'de', 'es' => 'es', 'pt' => 'pt', 'ru' => 'ru',
                'ko' => 'kr', 'ar' => 'sa', 'it' => 'it', 'nl' => 'nl',
                'pl' => 'pl', 'tr' => 'tr', 'vi' => 'vn', 'th' => 'th',
                'id' => 'id', 'ms' => 'my', 'hi' => 'in', 'sv' => 'se',
                'da' => 'dk', 'fi' => 'fi', 'nb' => 'no', 'cs' => 'cz',
                'sk' => 'sk', 'hu' => 'hu', 'ro' => 'ro', 'bg' => 'bg',
                'hr' => 'hr', 'uk' => 'ua', 'el' => 'gr', 'he' => 'il',
            ];
            $country = $country_codes[$lang_code] ?? $lang_code;
        }

        if ($flag_type === 'emoji') {
            $emojis = [
                'en' => '🇬🇧', 'zh' => '🇨🇳', 'ja' => '🇯🇵', 'fr' => '🇫🇷',
                'de' => '🇩🇪', 'es' => '🇪🇸', 'pt' => '🇵🇹', 'ru' => '🇷🇺',
                'ko' => '🇰🇷', 'ar' => '🇸🇦', 'it' => '🇮🇹', 'nl' => '🇳🇱',
                'pl' => '🇵🇱', 'tr' => '🇹🇷', 'vi' => '🇻🇳',
            ];
            $emoji = $emojis[$lang_code] ?? '🏴';
            return '<span class="gml-flag gml-flag-emoji" aria-label="' . esc_attr($native_name) . '">' . $emoji . '</span>';
        }

        // Use flagcdn.com image for all other types
        $css_class = 'gml-flag gml-flag-img gml-flag-' . esc_attr($flag_type);
        $img_url = 'https://flagcdn.com/w40/' . esc_attr($country) . '.png';
        return '<img class="' . $css_class . '" src="' . $img_url . '" alt="' . esc_attr($native_name) . '" loading="lazy">';
    }

    public function render($args = []) {
        // Read saved admin settings as defaults
        $saved_is_dropdown = get_option('gml_switcher_is_dropdown', true);
        $saved_show_flags  = get_option('gml_switcher_show_flags', true);
        $saved_flag_type   = get_option('gml_switcher_flag_type', 'rectangle');
        $saved_show_names  = get_option('gml_switcher_show_names', true);
        $saved_use_fullname = get_option('gml_switcher_use_fullname', true);

        $defaults = [
            'is_dropdown'  => $saved_is_dropdown ? 'true' : 'false',
            'show_flags'   => $saved_show_flags  ? 'true' : 'false',
            'flag_type'    => $saved_flag_type,
            'show_names'   => $saved_show_names  ? 'true' : 'false',
            'use_fullname' => $saved_use_fullname ? 'true' : 'false',
            // legacy compat
            'style'        => $saved_is_dropdown ? 'dropdown' : 'buttons',
        ];

        $args = wp_parse_args($args, $defaults);

        // Normalize booleans (accept 'yes'/'no'/'true'/'false'/bool)
        $is_dropdown  = filter_var($args['is_dropdown'],  FILTER_VALIDATE_BOOLEAN);
        $show_flags   = filter_var($args['show_flags'],   FILTER_VALIDATE_BOOLEAN);
        $show_names   = filter_var($args['show_names'],   FILTER_VALIDATE_BOOLEAN);
        $use_fullname = filter_var($args['use_fullname'], FILTER_VALIDATE_BOOLEAN);
        $flag_type    = sanitize_text_field($args['flag_type']); // rectangle|circle|square|emoji

        // Get source language
        $wp_locale   = get_locale();
        $source_lang = get_option('gml_source_lang', substr($wp_locale, 0, 2));

        // Derive source country from actual WordPress locale (e.g. en_US → us, en_GB → gb)
        $source_country = GML_Admin_Settings::get_country_from_locale($source_lang, $wp_locale);

        // Get configured languages
        $languages = get_option('gml_languages', []);

        // Build all languages array (source + enabled targets)
        $all_languages = [$source_lang];
        foreach ($languages as $lang) {
            if ($lang['enabled'] ?? true) {
                $all_languages[] = $lang['code'];
            }
        }
        $all_languages = array_unique($all_languages);

        // Build country map: lang_code => ISO country code
        // Source language uses locale-derived country; targets use stored country field
        $lang_countries = [ $source_lang => $source_country ];
        foreach ( $languages as $lang ) {
            if ( $lang['enabled'] ?? true ) {
                // Prefer stored country, then derive from lang code using internal map
                if ( ! empty( $lang['country'] ) ) {
                    $lang_countries[ $lang['code'] ] = $lang['country'];
                } else {
                    $country_map = [
                        'en' => 'us', 'zh' => 'cn', 'ja' => 'jp', 'fr' => 'fr',
                        'de' => 'de', 'es' => 'es', 'pt' => 'pt', 'ru' => 'ru',
                        'ko' => 'kr', 'ar' => 'sa', 'it' => 'it', 'nl' => 'nl',
                        'pl' => 'pl', 'tr' => 'tr', 'vi' => 'vn', 'th' => 'th',
                        'id' => 'id', 'ms' => 'my', 'hi' => 'in', 'sv' => 'se',
                        'da' => 'dk', 'fi' => 'fi', 'nb' => 'no', 'cs' => 'cz',
                        'sk' => 'sk', 'hu' => 'hu', 'ro' => 'ro', 'bg' => 'bg',
                        'hr' => 'hr', 'uk' => 'ua', 'el' => 'gr', 'he' => 'il',
                        'sr' => 'rs', 'sl' => 'si', 'lt' => 'lt', 'lv' => 'lv',
                        'et' => 'ee', 'ca' => 'es', 'fa' => 'ir', 'ur' => 'pk',
                        'bn' => 'bd', 'ta' => 'in', 'te' => 'in', 'sw' => 'ke',
                        'af' => 'za', 'ka' => 'ge', 'hy' => 'am', 'az' => 'az',
                        'kk' => 'kz', 'uz' => 'uz', 'mn' => 'mn', 'km' => 'kh',
                        'my' => 'mm', 'lo' => 'la', 'ne' => 'np', 'tl' => 'ph',
                    ];
                    $lang_countries[ $lang['code'] ] = $country_map[ $lang['code'] ] ?? $lang['code'];
                }
            }
        }

        // Get current language and URLs
        $current_lang  = $this->get_current_language();
        $language_urls = GML_Translate_Router::get_language_urls();

        // Language info map
        $language_info = [
            'en' => ['name' => 'English',      'native' => 'English',          'code_upper' => 'EN'],
            'zh' => ['name' => 'Chinese',      'native' => '中文',              'code_upper' => 'ZH'],
            'es' => ['name' => 'Spanish',      'native' => 'Español',          'code_upper' => 'ES'],
            'fr' => ['name' => 'French',       'native' => 'Français',         'code_upper' => 'FR'],
            'de' => ['name' => 'German',       'native' => 'Deutsch',          'code_upper' => 'DE'],
            'ja' => ['name' => 'Japanese',     'native' => '日本語',            'code_upper' => 'JA'],
            'ko' => ['name' => 'Korean',       'native' => '한국어',            'code_upper' => 'KO'],
            'pt' => ['name' => 'Portuguese',   'native' => 'Português',        'code_upper' => 'PT'],
            'ru' => ['name' => 'Russian',      'native' => 'Русский',          'code_upper' => 'RU'],
            'ar' => ['name' => 'Arabic',       'native' => 'العربية',          'code_upper' => 'AR'],
            'hi' => ['name' => 'Hindi',        'native' => 'हिन्दी',            'code_upper' => 'HI'],
            'it' => ['name' => 'Italian',      'native' => 'Italiano',         'code_upper' => 'IT'],
            'nl' => ['name' => 'Dutch',        'native' => 'Nederlands',       'code_upper' => 'NL'],
            'pl' => ['name' => 'Polish',       'native' => 'Polski',           'code_upper' => 'PL'],
            'tr' => ['name' => 'Turkish',      'native' => 'Türkçe',           'code_upper' => 'TR'],
            'vi' => ['name' => 'Vietnamese',   'native' => 'Tiếng Việt',       'code_upper' => 'VI'],
            'th' => ['name' => 'Thai',         'native' => 'ไทย',              'code_upper' => 'TH'],
            'id' => ['name' => 'Indonesian',   'native' => 'Bahasa Indonesia', 'code_upper' => 'ID'],
            'ms' => ['name' => 'Malay',        'native' => 'Bahasa Melayu',    'code_upper' => 'MS'],
            'tl' => ['name' => 'Filipino',     'native' => 'Filipino',         'code_upper' => 'TL'],
            'sv' => ['name' => 'Swedish',      'native' => 'Svenska',          'code_upper' => 'SV'],
            'da' => ['name' => 'Danish',       'native' => 'Dansk',            'code_upper' => 'DA'],
            'nb' => ['name' => 'Norwegian',    'native' => 'Norsk',            'code_upper' => 'NB'],
            'fi' => ['name' => 'Finnish',      'native' => 'Suomi',            'code_upper' => 'FI'],
            'cs' => ['name' => 'Czech',        'native' => 'Čeština',          'code_upper' => 'CS'],
            'sk' => ['name' => 'Slovak',       'native' => 'Slovenčina',       'code_upper' => 'SK'],
            'hu' => ['name' => 'Hungarian',    'native' => 'Magyar',           'code_upper' => 'HU'],
            'ro' => ['name' => 'Romanian',     'native' => 'Română',           'code_upper' => 'RO'],
            'bg' => ['name' => 'Bulgarian',    'native' => 'Български',        'code_upper' => 'BG'],
            'hr' => ['name' => 'Croatian',     'native' => 'Hrvatski',         'code_upper' => 'HR'],
            'sr' => ['name' => 'Serbian',      'native' => 'Српски',           'code_upper' => 'SR'],
            'sl' => ['name' => 'Slovenian',    'native' => 'Slovenščina',      'code_upper' => 'SL'],
            'uk' => ['name' => 'Ukrainian',    'native' => 'Українська',       'code_upper' => 'UK'],
            'el' => ['name' => 'Greek',        'native' => 'Ελληνικά',         'code_upper' => 'EL'],
            'he' => ['name' => 'Hebrew',       'native' => 'עברית',            'code_upper' => 'HE'],
            'lt' => ['name' => 'Lithuanian',   'native' => 'Lietuvių',         'code_upper' => 'LT'],
            'lv' => ['name' => 'Latvian',      'native' => 'Latviešu',         'code_upper' => 'LV'],
            'et' => ['name' => 'Estonian',      'native' => 'Eesti',            'code_upper' => 'ET'],
            'ca' => ['name' => 'Catalan',      'native' => 'Català',           'code_upper' => 'CA'],
            'fa' => ['name' => 'Persian',      'native' => 'فارسی',            'code_upper' => 'FA'],
            'ur' => ['name' => 'Urdu',         'native' => 'اردو',             'code_upper' => 'UR'],
            'bn' => ['name' => 'Bengali',      'native' => 'বাংলা',             'code_upper' => 'BN'],
            'ta' => ['name' => 'Tamil',        'native' => 'தமிழ்',             'code_upper' => 'TA'],
            'te' => ['name' => 'Telugu',       'native' => 'తెలుగు',            'code_upper' => 'TE'],
            'sw' => ['name' => 'Swahili',      'native' => 'Kiswahili',        'code_upper' => 'SW'],
            'af' => ['name' => 'Afrikaans',    'native' => 'Afrikaans',        'code_upper' => 'AF'],
            'ka' => ['name' => 'Georgian',     'native' => 'ქართული',          'code_upper' => 'KA'],
            'hy' => ['name' => 'Armenian',     'native' => 'Հայերեն',          'code_upper' => 'HY'],
            'az' => ['name' => 'Azerbaijani',  'native' => 'Azərbaycan',       'code_upper' => 'AZ'],
            'kk' => ['name' => 'Kazakh',       'native' => 'Қазақ',            'code_upper' => 'KK'],
            'uz' => ['name' => 'Uzbek',        'native' => 'Oʻzbek',           'code_upper' => 'UZ'],
            'mn' => ['name' => 'Mongolian',    'native' => 'Монгол',           'code_upper' => 'MN'],
            'km' => ['name' => 'Khmer',        'native' => 'ខ្មែរ',              'code_upper' => 'KM'],
            'my' => ['name' => 'Myanmar',      'native' => 'မြန်မာ',            'code_upper' => 'MY'],
            'lo' => ['name' => 'Lao',          'native' => 'ລາວ',              'code_upper' => 'LO'],
            'ne' => ['name' => 'Nepali',       'native' => 'नेपाली',            'code_upper' => 'NE'],
        ];

        $menu_context = ! empty( $args['menu_context'] );

        ob_start();
        $wrapper_class = 'gml-language-switcher ' . ($is_dropdown ? 'gml-style-dropdown' : 'gml-style-buttons');
        if ( $menu_context ) {
            $wrapper_class .= ' gml-in-menu';
        }
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>" translate="no">
        <?php if ($is_dropdown): ?>
            <!-- Weglot-style custom dropdown -->
            <div class="gml-dropdown" tabindex="0">
                <?php
                $cur_data  = $language_info[$current_lang] ?? ['name' => $current_lang, 'native' => strtoupper($current_lang), 'code_upper' => strtoupper($current_lang)];
                $cur_label = $use_fullname ? $cur_data['native'] : $cur_data['code_upper'];
                ?>
                <button type="button" class="gml-dropdown-btn" aria-haspopup="listbox" aria-expanded="false">
                    <?php if ($show_flags): echo $this->get_flag_html($current_lang, $flag_type, $cur_data['native'], $lang_countries[$current_lang] ?? ''); endif; ?>
                    <?php if ($show_names): ?><span class="gml-lang-label"><?php echo esc_html($cur_label); ?></span><?php endif; ?>
                    <span class="gml-dropdown-arrow">▼</span>
                </button>
                <ul class="gml-dropdown-menu" role="listbox">
                    <?php foreach ($all_languages as $lang):
                        // Skip current language — it's already shown in the trigger button
                        if ( $lang === $current_lang ) continue;
                        $d     = $language_info[$lang] ?? ['name' => $lang, 'native' => strtoupper($lang), 'code_upper' => strtoupper($lang)];
                        $label = $use_fullname ? $d['native'] : $d['code_upper'];
                        $url   = esc_url($language_urls[$lang] ?? '#');
                    ?>
                    <li role="option">
                        <a href="<?php echo $url; ?>" class="gml-dropdown-item">
                            <?php if ($show_flags): echo $this->get_flag_html($lang, $flag_type, $d['native'], $lang_countries[$lang] ?? ''); endif; ?>
                            <?php if ($show_names): ?><span class="gml-lang-label"><?php echo esc_html($label); ?></span><?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <!-- Button/link style -->
            <div class="gml-language-buttons">
                <?php foreach ($all_languages as $lang):
                    $d     = $language_info[$lang] ?? ['name' => $lang, 'native' => strtoupper($lang), 'code_upper' => strtoupper($lang)];
                    $label = $use_fullname ? $d['native'] : $d['code_upper'];
                    $url   = esc_url($language_urls[$lang] ?? '#');
                    $active = $current_lang === $lang ? ' gml-active' : '';
                ?>
                <a href="<?php echo $url; ?>" class="gml-lang-button<?php echo $active; ?>">
                    <?php if ($show_flags): echo $this->get_flag_html($lang, $flag_type, $d['native'], $lang_countries[$lang] ?? ''); endif; ?>
                    <?php if ($show_names): ?><span class="gml-lang-label"><?php echo esc_html($label); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get current language for UI highlighting.
     * URL prefix is authoritative; falls back to source language.
     * (Cookie is no longer used — URL is the single source of truth.)
     */
    private function get_current_language() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtok( $request_uri, '?' );
        if ( preg_match( '#^/([a-z]{2})(/|$)#', $path, $matches ) ) {
            $lang = $matches[1];
            // Validate against enabled languages to avoid showing unknown codes
            $configured = get_option( 'gml_languages', [] );
            foreach ( $configured as $l ) {
                if ( ( $l['enabled'] ?? true ) && $l['code'] === $lang ) {
                    return $lang;
                }
            }
        }
        $wp_locale = get_locale();
        return get_option( 'gml_source_lang', substr( $wp_locale, 0, 2 ) );
    }
    
    /**
     * Register widget
     */
    public function register_widget() {
        register_widget('GML_Language_Switcher_Widget');
    }
}

/**
 * Language Switcher Widget
 */
class GML_Language_Switcher_Widget extends WP_Widget {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(
            'gml_language_switcher',
            __('GML Language Switcher', 'gml-translate'),
            ['description' => __('Display language switcher', 'gml-translate')]
        );
    }
    
    /**
     * Widget output
     */
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . esc_html($instance['title']) . $args['after_title'];
        }
        
        $switcher = new GML_Language_Switcher();
        echo $switcher->render([
            'is_dropdown'  => ($instance['style'] ?? 'dropdown') === 'dropdown' ? 'true' : 'false',
            'show_flags'   => ($instance['show_flags'] ?? 'yes') === 'yes' ? 'true' : 'false',
            'show_names'   => ($instance['show_names'] ?? 'yes') === 'yes' ? 'true' : 'false',
        ]);
        
        echo $args['after_widget'];
    }
    
    /**
     * Widget form
     */
    public function form($instance) {
        $title = $instance['title'] ?? '';
        $style = $instance['style'] ?? 'dropdown';
        $show_flags = $instance['show_flags'] ?? 'yes';
        $show_names = $instance['show_names'] ?? 'yes';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Title:', 'gml-translate'); ?>
            </label>
            <input class="widefat" 
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" 
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                <?php _e('Style:', 'gml-translate'); ?>
            </label>
            <select class="widefat" 
                    id="<?php echo esc_attr($this->get_field_id('style')); ?>" 
                    name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>>Dropdown</option>
                <option value="links" <?php selected($style, 'links'); ?>>Links</option>
                <option value="flags" <?php selected($style, 'flags'); ?>>Flags</option>
                <option value="buttons" <?php selected($style, 'buttons'); ?>>Buttons</option>
            </select>
        </p>
        <p>
            <input type="checkbox" 
                   id="<?php echo esc_attr($this->get_field_id('show_flags')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('show_flags')); ?>" 
                   value="yes" 
                   <?php checked($show_flags, 'yes'); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_flags')); ?>">
                <?php _e('Show Flags', 'gml-translate'); ?>
            </label>
        </p>
        <p>
            <input type="checkbox" 
                   id="<?php echo esc_attr($this->get_field_id('show_names')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('show_names')); ?>" 
                   value="yes" 
                   <?php checked($show_names, 'yes'); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_names')); ?>">
                <?php _e('Show Names', 'gml-translate'); ?>
            </label>
        </p>
        <?php
    }
    
    /**
     * Update widget
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['style'] = sanitize_text_field($new_instance['style'] ?? 'dropdown');
        $instance['show_flags'] = isset($new_instance['show_flags']) ? 'yes' : 'no';
        $instance['show_names'] = isset($new_instance['show_names']) ? 'yes' : 'no';
        return $instance;
    }
}

/**
 * Helper function for templates
 *
 * @param array $args Arguments
 */
function gml_language_switcher($args = []) {
    $switcher = new GML_Language_Switcher();
    echo $switcher->render($args);
}
