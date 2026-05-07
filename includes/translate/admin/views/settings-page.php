<?php
/**
 * Admin Settings Page View
 *
 * @package GML_Translate
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php settings_errors(); ?>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('gml_translate_settings');
        do_settings_sections('gml-translate');
        submit_button();
        ?>
    </form>
    
    <hr>
    
    <h2><?php _e('Quick Start Guide', 'gml-translate'); ?></h2>
    <div class="gml-quick-start">
        <ol>
            <li><?php _e('Get your Gemini API Key from', 'gml-translate'); ?> <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a></li>
            <li><?php _e('Enter your API Key above and save settings', 'gml-translate'); ?></li>
            <li><?php _e('Select source language and target languages', 'gml-translate'); ?></li>
            <li><?php _e('Add language switcher to your site using shortcode:', 'gml-translate'); ?> <code>[gml_language_switcher]</code></li>
            <li><?php _e('Visit your site with language prefix, e.g.:', 'gml-translate'); ?> <code><?php echo home_url('/en/'); ?></code></li>
        </ol>
    </div>
    
    <hr>
    
    <h2><?php _e('Language Switcher Shortcodes', 'gml-translate'); ?></h2>
    <div class="gml-shortcodes">
        <h3><?php _e('Basic Usage', 'gml-translate'); ?></h3>
        <pre><code>[gml_language_switcher]</code></pre>
        
        <h3><?php _e('With Style', 'gml-translate'); ?></h3>
        <pre><code>[gml_language_switcher style="dropdown"]</code></pre>
        <pre><code>[gml_language_switcher style="links"]</code></pre>
        <pre><code>[gml_language_switcher style="flags"]</code></pre>
        <pre><code>[gml_language_switcher style="buttons"]</code></pre>
        
        <h3><?php _e('With Options', 'gml-translate'); ?></h3>
        <pre><code>[gml_language_switcher style="dropdown" show_flags="yes" show_names="yes"]</code></pre>
    </div>
    
    <hr>
    
    <h2><?php _e('System Information', 'gml-translate'); ?></h2>
    <table class="widefat">
        <tbody>
            <tr>
                <td><strong><?php _e('Plugin Version', 'gml-translate'); ?></strong></td>
                <td><?php echo GML_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('WordPress Version', 'gml-translate'); ?></strong></td>
                <td><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('PHP Version', 'gml-translate'); ?></strong></td>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Database Tables', 'gml-translate'); ?></strong></td>
                <td>
                    <?php
                    global $wpdb;
                    $tables = [
                        $wpdb->prefix . 'gml_index',
                        $wpdb->prefix . 'gml_queue',
                        $wpdb->prefix . 'gml_plans',
                        $wpdb->prefix . 'gml_plan_items',
                    ];
                    $existing = 0;
                    foreach ($tables as $table) {
                        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                            $existing++;
                        }
                    }
                    echo $existing . ' / ' . count($tables) . ' ' . __('tables exist', 'gml-translate');
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php _e('Cache Directory', 'gml-translate'); ?></strong></td>
                <td>
                    <?php
                    $upload_dir = wp_upload_dir();
                    $cache_dir = $upload_dir['basedir'] . '/gml-cache';
                    echo is_writable($cache_dir) ? 
                        '<span style="color: green;">✓ ' . __('Writable', 'gml-translate') . '</span>' : 
                        '<span style="color: red;">✗ ' . __('Not writable', 'gml-translate') . '</span>';
                    ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<style>
.gml-quick-start {
    background: #f0f0f1;
    padding: 20px;
    border-left: 4px solid #2271b1;
    margin: 20px 0;
}

.gml-quick-start ol {
    margin: 0;
    padding-left: 20px;
}

.gml-quick-start li {
    margin: 10px 0;
}

.gml-shortcodes {
    background: #fff;
    padding: 20px;
    border: 1px solid #c3c4c7;
}

.gml-shortcodes h3 {
    margin-top: 20px;
    margin-bottom: 10px;
}

.gml-shortcodes pre {
    background: #f6f7f7;
    padding: 10px;
    border-left: 3px solid #2271b1;
    overflow-x: auto;
}

.gml-shortcodes code {
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
}
</style>
