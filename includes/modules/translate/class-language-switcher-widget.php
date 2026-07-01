<?php
/**
 * Language Switcher Widget
 *
 * @package GML_Translate
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GML Language Switcher Widget class
 */
class GML_Language_Switcher_Widget extends WP_Widget {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(
            'gml_language_switcher',
            __('GML Language Switcher', 'gml-translate'),
            [
                'description' => __('Display language switcher for GML Translate', 'gml-translate'),
                'classname' => 'gml-language-switcher-widget',
            ]
        );
    }
    
    /**
     * Front-end display of widget
     *
     * @param array $args Widget arguments
     * @param array $instance Saved values from database
     */
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        // Widget title
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        // Get settings
        $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';
        $show_flags = !empty($instance['show_flags']) ? true : false;
        $show_names = !empty($instance['show_names']) ? true : false;
        
        // Display language switcher
        if (class_exists('GML_Language_Switcher')) {
            $switcher = new GML_Language_Switcher();
            echo $switcher->render([
                'style' => $style,
                'show_flags' => $show_flags,
                'show_names' => $show_names,
            ]);
        }
        
        echo $args['after_widget'];
    }
    
    /**
     * Back-end widget form
     *
     * @param array $instance Previously saved values from database
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('Languages', 'gml-translate');
        $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';
        $show_flags = !empty($instance['show_flags']) ? true : false;
        $show_names = !empty($instance['show_names']) ? true : false;
        ?>
        
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Title:', 'gml-translate'); ?>
            </label>
            <input 
                class="widefat" 
                id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                type="text" 
                value="<?php echo esc_attr($title); ?>"
            >
        </p>
        
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>">
                <?php _e('Display Style:', 'gml-translate'); ?>
            </label>
            <select 
                class="widefat" 
                id="<?php echo esc_attr($this->get_field_id('style')); ?>" 
                name="<?php echo esc_attr($this->get_field_name('style')); ?>"
            >
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>>
                    <?php _e('Dropdown', 'gml-translate'); ?>
                </option>
                <option value="links" <?php selected($style, 'links'); ?>>
                    <?php _e('Links', 'gml-translate'); ?>
                </option>
                <option value="flags" <?php selected($style, 'flags'); ?>>
                    <?php _e('Flags', 'gml-translate'); ?>
                </option>
                <option value="buttons" <?php selected($style, 'buttons'); ?>>
                    <?php _e('Buttons', 'gml-translate'); ?>
                </option>
            </select>
        </p>
        
        <p>
            <input 
                class="checkbox" 
                type="checkbox" 
                id="<?php echo esc_attr($this->get_field_id('show_flags')); ?>" 
                name="<?php echo esc_attr($this->get_field_name('show_flags')); ?>" 
                <?php checked($show_flags, true); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_flags')); ?>">
                <?php _e('Show Flags', 'gml-translate'); ?>
            </label>
        </p>
        
        <p>
            <input 
                class="checkbox" 
                type="checkbox" 
                id="<?php echo esc_attr($this->get_field_id('show_names')); ?>" 
                name="<?php echo esc_attr($this->get_field_name('show_names')); ?>" 
                <?php checked($show_names, true); ?>
            >
            <label for="<?php echo esc_attr($this->get_field_id('show_names')); ?>">
                <?php _e('Show Language Names', 'gml-translate'); ?>
            </label>
        </p>
        
        <?php
    }
    
    /**
     * Sanitize widget form values as they are saved
     *
     * @param array $new_instance Values just sent to be saved
     * @param array $old_instance Previously saved values from database
     * @return array Updated safe values to be saved
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        
        $instance['title'] = !empty($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
        $instance['style'] = !empty($new_instance['style']) ? sanitize_text_field($new_instance['style']) : 'dropdown';
        $instance['show_flags'] = !empty($new_instance['show_flags']) ? true : false;
        $instance['show_names'] = !empty($new_instance['show_names']) ? true : false;
        
        return $instance;
    }
}

/**
 * Register widget
 */
function gml_register_language_switcher_widget() {
    register_widget('GML_Language_Switcher_Widget');
}
add_action('widgets_init', 'gml_register_language_switcher_widget');
