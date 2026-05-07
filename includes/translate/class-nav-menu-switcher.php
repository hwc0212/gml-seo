<?php
/**
 * Nav Menu Language Switcher integration
 *
 * Adds a "GML Language Switcher" meta box to the WordPress Menus admin page
 * so users can drag-and-drop a language switcher into any nav menu position.
 *
 * @package GML_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GML_Nav_Menu_Switcher {

    /** Custom menu-item object type identifier */
    const ITEM_TYPE = 'gml_language_switcher';

    public function __construct() {
        // Admin: register meta box on Appearance → Menus
        add_action( 'admin_head-nav-menus.php', [ $this, 'add_meta_box' ] );

        // Frontend: replace placeholder menu item with real switcher HTML
        add_filter( 'wp_nav_menu_objects', [ $this, 'filter_menu_objects' ], 10, 2 );

        // Setup: populate label/url for our custom item type in admin
        add_filter( 'wp_setup_nav_menu_item', [ $this, 'setup_menu_item' ] );

        // Walker: render the switcher HTML instead of a normal <a> link
        add_filter( 'walker_nav_menu_start_el', [ $this, 'render_menu_item' ], 10, 4 );
    }

    /* ─── Admin meta box ─────────────────────────────────── */

    /**
     * Register the meta box on the Menus page.
     */
    public function add_meta_box() {
        add_meta_box(
            'gml-language-switcher-menu',
            __( 'GML Language Switcher', 'gml-translate' ),
            [ $this, 'render_meta_box' ],
            'nav-menus',
            'side',
            'default'
        );
    }

    /**
     * Render the meta box content — a single checkbox item the user can add.
     */
    public function render_meta_box() {
        global $_nav_menu_placeholder;
        $_nav_menu_placeholder = $_nav_menu_placeholder < -1 ? $_nav_menu_placeholder - 1 : -1;
        ?>
        <div id="gml-switcher-menu-item" class="posttypediv">
            <div class="tabs-panel tabs-panel-active">
                <ul class="categorychecklist form-no-clear">
                    <li>
                        <label class="menu-item-title">
                            <input type="checkbox"
                                   class="menu-item-checkbox"
                                   name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-object-id]"
                                   value="-1" />
                            <?php esc_html_e( 'Language Switcher', 'gml-translate' ); ?>
                        </label>
                        <input type="hidden"
                               class="menu-item-type"
                               name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-type]"
                               value="custom" />
                        <input type="hidden"
                               class="menu-item-title"
                               name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-title]"
                               value="<?php esc_attr_e( 'Language Switcher', 'gml-translate' ); ?>" />
                        <input type="hidden"
                               class="menu-item-url"
                               name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-url]"
                               value="#gml-language-switcher" />
                        <input type="hidden"
                               class="menu-item-classes"
                               name="menu-item[<?php echo (int) $_nav_menu_placeholder; ?>][menu-item-classes]"
                               value="gml-menu-item-switcher" />
                    </li>
                </ul>
            </div>
            <p class="button-controls wp-clearfix">
                <span class="add-to-menu">
                    <input type="submit"
                           class="button submit-add-to-menu right"
                           value="<?php esc_attr_e( 'Add to Menu', 'gml-translate' ); ?>"
                           name="add-gml-switcher-menu-item"
                           id="submit-gml-switcher-menu-item" />
                    <span class="spinner"></span>
                </span>
            </p>
        </div>
        <?php
    }

    /* ─── Frontend rendering ─────────────────────────────── */

    /**
     * Identify our custom menu items by their URL marker.
     * Set a flag so the walker can replace the output.
     */
    public function setup_menu_item( $menu_item ) {
        if ( is_object( $menu_item ) && isset( $menu_item->url ) && $menu_item->url === '#gml-language-switcher' ) {
            $menu_item->type       = self::ITEM_TYPE;
            $menu_item->type_label = __( 'GML Switcher', 'gml-translate' );
        }
        return $menu_item;
    }

    /**
     * Filter menu objects — keep the item but mark it so the walker replaces it.
     */
    public function filter_menu_objects( $sorted_items, $args ) {
        foreach ( $sorted_items as $item ) {
            if ( isset( $item->url ) && $item->url === '#gml-language-switcher' ) {
                $item->type = self::ITEM_TYPE;
                // Remove the # URL so it doesn't render as a link
                $item->url = '';
            }
        }
        return $sorted_items;
    }

    /**
     * Replace the walker output for our custom item type with the actual switcher.
     */
    public function render_menu_item( $item_output, $item, $depth, $args ) {
        if ( ! isset( $item->type ) || $item->type !== self::ITEM_TYPE ) {
            return $item_output;
        }

        // Don't render in admin (Customizer live preview is fine)
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $item_output;
        }

        $switcher = new GML_Language_Switcher();
        return $switcher->render( [ 'menu_context' => true ] );
    }
}
