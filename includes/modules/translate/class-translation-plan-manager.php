<?php
/**
 * Translation Plan Manager Class
 * 
 * Manages batch translation plans with progress tracking and deduplication
 * 
 * @package GML_Translate
 * @since 2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class GML_Translation_Plan_Manager {
    
    /**
     * Create translation plan
     */
    public function create_plan($plan_name, $target_lang, $options = []) {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'gml_plans';
        
        // Create plan
        $wpdb->insert($plan_table, [
            'plan_name' => $plan_name,
            'target_lang' => $target_lang,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
        ]);
        
        $plan_id = $wpdb->insert_id;
        
        // Collect items to translate
        $items = $this->collect_items($options);
        
        // Deduplicate (skip already translated)
        $items = $this->deduplicate_items($items, $target_lang);
        
        // Add items to plan
        $this->add_items_to_plan($plan_id, $items);
        
        // Update total count
        $wpdb->update($plan_table, [
            'total_items' => count($items),
        ], ['id' => $plan_id]);
        
        return $plan_id;
    }
    
    /**
     * Collect items to translate
     */
    private function collect_items($options) {
        $items = [];
        
        // 1. Collect posts
        if (!isset($options['include_posts']) || $options['include_posts']) {
            $post_types = $options['post_types'] ?? ['post', 'page'];
            $posts = get_posts([
                'post_type' => $post_types,
                'post_status' => 'publish',
                'numberposts' => -1,
            ]);
            
            foreach ($posts as $post) {
                $items[] = [
                    'type' => 'post',
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'fingerprint' => md5($post->post_content . $post->post_title),
                ];
            }
        }
        
        // 2. Collect terms
        if (!isset($options['include_terms']) || $options['include_terms']) {
            $taxonomies = $options['taxonomies'] ?? ['category', 'post_tag'];
            $terms = get_terms([
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
            ]);
            
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $items[] = [
                        'type' => 'term',
                        'id' => $term->term_id,
                        'title' => $term->name,
                        'fingerprint' => md5($term->name . $term->description),
                    ];
                }
            }
        }
        
        // 3. Collect common components
        if (!isset($options['include_components']) || $options['include_components']) {
            $components = $this->collect_common_components();
            $items = array_merge($items, $components);
        }
        
        return $items;
    }
    
    /**
     * Collect common components
     */
    private function collect_common_components() {
        $components = [];
        
        // Get homepage HTML
        $home_url = home_url('/');
        $response = wp_remote_get($home_url, ['timeout' => 15]);
        
        if (!is_wp_error($response)) {
            $html = wp_remote_retrieve_body($response);
            
            // Parse HTML
            libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            // Identify components
            $component_manager = new GML_Component_Manager();
            $identified = $component_manager->identify_components($dom);
            
            foreach ($identified as $component) {
                $components[] = [
                    'type' => 'component',
                    'id' => null,
                    'title' => ucfirst($component['type']),
                    'fingerprint' => $component['fingerprint'],
                ];
            }
        }
        
        return $components;
    }
    
    /**
     * Deduplicate items (skip already translated)
     */
    private function deduplicate_items($items, $target_lang) {
        global $wpdb;
        
        $source_lang = get_option('gml_source_lang', 'zh');
        $deduplicated = [];
        
        foreach ($items as $item) {
            $fingerprint = $item['fingerprint'];
            
            // Check if already translated
            if ($item['type'] === 'component') {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}gml_components 
                     WHERE fingerprint = %s AND source_lang = %s AND target_lang = %s",
                    $fingerprint, $source_lang, $target_lang
                ));
            } else {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}gml_index 
                     WHERE source_hash = %s AND source_lang = %s AND target_lang = %s",
                    $fingerprint, $source_lang, $target_lang
                ));
            }
            
            if (!$exists) {
                $deduplicated[] = $item;
            }
        }
        
        return $deduplicated;
    }
    
    /**
     * Add items to plan
     */
    private function add_items_to_plan($plan_id, $items) {
        global $wpdb;
        
        $item_table = $wpdb->prefix . 'gml_plan_items';
        
        foreach ($items as $item) {
            $wpdb->insert($item_table, [
                'plan_id' => $plan_id,
                'item_type' => $item['type'],
                'item_id' => $item['id'],
                'item_title' => $item['title'],
                'fingerprint' => $item['fingerprint'],
                'status' => 'pending',
            ]);
        }
    }
    
    /**
     * Execute translation plan
     */
    public function execute_plan($plan_id) {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'gml_plans';
        $item_table = $wpdb->prefix . 'gml_plan_items';
        
        // Update plan status
        $wpdb->update($plan_table, [
            'status' => 'running',
            'started_at' => current_time('mysql'),
        ], ['id' => $plan_id]);
        
        // Get pending items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $item_table WHERE plan_id = %d AND status = 'pending' LIMIT 10",
            $plan_id
        ));
        
        if (empty($items)) {
            // Mark plan as completed
            $wpdb->update($plan_table, [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ], ['id' => $plan_id]);
            return;
        }
        
        $completed = 0;
        $failed = 0;
        
        foreach ($items as $item) {
            try {
                // Update status to processing
                $wpdb->update($item_table, [
                    'status' => 'processing',
                ], ['id' => $item->id]);
                
                // Translate item
                $this->translate_item($item);
                
                // Mark as completed
                $wpdb->update($item_table, [
                    'status' => 'completed',
                    'processed_at' => current_time('mysql'),
                ], ['id' => $item->id]);
                
                $completed++;
                
            } catch (Exception $e) {
                // Mark as failed
                $wpdb->update($item_table, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'processed_at' => current_time('mysql'),
                ], ['id' => $item->id]);
                
                $failed++;
            }
            
            // Avoid API rate limiting
            sleep(1);
        }
        
        // Update progress
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM $item_table WHERE plan_id = %d",
            $plan_id
        ));
        
        $progress = $stats->total > 0 ? ($stats->completed / $stats->total * 100) : 0;
        
        $wpdb->update($plan_table, [
            'completed_items' => $stats->completed,
            'failed_items' => $stats->failed,
            'progress' => $progress,
        ], ['id' => $plan_id]);
    }
    
    /**
     * Translate single item
     */
    private function translate_item($item) {
        $plan = $this->get_plan($item->plan_id);
        $target_lang = $plan->target_lang;
        
        switch ($item->item_type) {
            case 'post':
                $this->translate_post($item->item_id, $target_lang);
                break;
                
            case 'term':
                $this->translate_term($item->item_id, $target_lang);
                break;
                
            case 'component':
                $this->translate_component_by_fingerprint($item->fingerprint, $target_lang);
                break;
        }
    }
    
    /**
     * Translate post
     */
    private function translate_post($post_id, $target_lang) {
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception('Post not found');
        }
        
        $api = new GML_Gemini_API();
        $source_lang = get_option('gml_source_lang', 'zh');
        
        // Translate title
        $translated_title = $api->translate($post->post_title, $source_lang, $target_lang);
        
        // Translate content
        $translated_content = $api->translate($post->post_content, $source_lang, $target_lang);
        
        // Save to cache
        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';
        
        $wpdb->replace($table, [
            'source_hash' => md5($post->post_title),
            'source_text' => $post->post_title,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_text' => $translated_title,
            'context_type' => 'text',
            'status' => 'auto',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        
        $wpdb->replace($table, [
            'source_hash' => md5($post->post_content),
            'source_text' => $post->post_content,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_text' => $translated_content,
            'context_type' => 'text',
            'status' => 'auto',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }
    
    /**
     * Translate term
     */
    private function translate_term($term_id, $target_lang) {
        $term = get_term($term_id);
        if (is_wp_error($term)) {
            throw new Exception('Term not found');
        }
        
        $api = new GML_Gemini_API();
        $source_lang = get_option('gml_source_lang', 'zh');
        
        // Translate name
        $translated_name = $api->translate($term->name, $source_lang, $target_lang);
        
        // Save to cache
        global $wpdb;
        $table = $wpdb->prefix . 'gml_index';
        
        $wpdb->replace($table, [
            'source_hash' => md5($term->name),
            'source_text' => $term->name,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_text' => $translated_name,
            'context_type' => 'text',
            'status' => 'auto',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }
    
    /**
     * Translate component by fingerprint
     */
    private function translate_component_by_fingerprint($fingerprint, $target_lang) {
        // Component translation is handled by component manager
        // This is a placeholder for future implementation
    }
    
    /**
     * Get plan
     */
    public function get_plan($plan_id) {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'gml_plans';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $plan_table WHERE id = %d",
            $plan_id
        ));
    }
    
    /**
     * Get plan progress
     */
    public function get_plan_progress($plan_id) {
        return $this->get_plan($plan_id);
    }
    
    /**
     * Get all plans
     */
    public function get_all_plans() {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'gml_plans';
        
        return $wpdb->get_results(
            "SELECT * FROM $plan_table ORDER BY created_at DESC"
        );
    }
    
    /**
     * Delete plan
     */
    public function delete_plan($plan_id) {
        global $wpdb;
        
        $plan_table = $wpdb->prefix . 'gml_plans';
        $item_table = $wpdb->prefix . 'gml_plan_items';
        
        // Delete items
        $wpdb->delete($item_table, ['plan_id' => $plan_id]);
        
        // Delete plan
        $wpdb->delete($plan_table, ['id' => $plan_id]);
    }
}
