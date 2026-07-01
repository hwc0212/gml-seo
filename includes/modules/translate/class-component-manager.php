<?php
/**
 * Component Manager Class
 * 
 * Identifies and manages reusable components (header, footer, navigation, etc.)
 * 
 * @package GML_Translate
 * @since 2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class GML_Component_Manager {
    
    /**
     * Component selectors
     */
    private $components = [
        'header'     => ['#masthead', '.site-header', 'header', '.header'],
        'footer'     => ['#colophon', '.site-footer', 'footer', '.footer'],
        'navigation' => ['.main-navigation', '#site-navigation', 'nav', '.nav-menu'],
        'sidebar'    => ['#secondary', '.sidebar', 'aside', '.widget-area'],
        'widget'     => ['.widget'],
    ];
    
    /**
     * Identify components in DOM
     */
    public function identify_components($dom) {
        $identified = [];
        
        foreach ($this->components as $type => $selectors) {
            $nodes = $this->find_nodes($dom, $selectors);
            
            foreach ($nodes as $node) {
                // Generate component fingerprint
                $fingerprint = $this->generate_fingerprint($node);
                
                $identified[] = [
                    'type' => $type,
                    'node' => $node,
                    'fingerprint' => $fingerprint,
                    'html' => $dom->saveHTML($node),
                ];
            }
        }
        
        return $identified;
    }
    
    /**
     * Generate component fingerprint
     */
    private function generate_fingerprint($node) {
        // Extract text content
        $text = $this->extract_text($node);
        
        // Extract structure features
        $structure = $this->extract_structure($node);
        
        // Combine and hash
        return md5($text . '|' . $structure);
    }
    
    /**
     * Extract text content
     */
    private function extract_text($node) {
        $text = '';
        
        if ($node->nodeType === XML_TEXT_NODE) {
            $text .= trim($node->nodeValue);
        }
        
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $text .= $this->extract_text($child);
            }
        }
        
        return $text;
    }
    
    /**
     * Extract structure features
     */
    private function extract_structure($node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        
        $structure = $node->nodeName;
        
        // Add important attributes
        if ($node->hasAttribute('id')) {
            $structure .= '#' . $node->getAttribute('id');
        }
        
        if ($node->hasAttribute('class')) {
            $structure .= '.' . str_replace(' ', '.', $node->getAttribute('class'));
        }
        
        return $structure;
    }
    
    /**
     * Find nodes by selectors
     */
    private function find_nodes($dom, $selectors) {
        $xpath = new DOMXPath($dom);
        $nodes = [];
        
        foreach ($selectors as $selector) {
            $xpath_query = $this->selector_to_xpath($selector);
            $result = @$xpath->query($xpath_query);
            
            if ($result) {
                foreach ($result as $node) {
                    $nodes[] = $node;
                }
            }
        }
        
        return $nodes;
    }
    
    /**
     * Convert selector to XPath
     */
    private function selector_to_xpath($selector) {
        // #id
        if (strpos($selector, '#') === 0) {
            $id = substr($selector, 1);
            return "//*[@id='$id']";
        }
        
        // .class
        if (strpos($selector, '.') === 0) {
            $class = substr($selector, 1);
            return "//*[contains(@class, '$class')]";
        }
        
        // tag
        return "//{$selector}";
    }
    
    /**
     * Get component cache
     */
    public function get_component_cache($fingerprint, $target_lang) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gml_components';
        $source_lang = get_option('gml_source_lang', 'zh');
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_html FROM $table 
             WHERE fingerprint = %s AND source_lang = %s AND target_lang = %s",
            $fingerprint, $source_lang, $target_lang
        ));
        
        return $result;
    }
    
    /**
     * Save component cache
     */
    public function save_component_cache($component, $translated_html, $target_lang) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gml_components';
        $source_lang = get_option('gml_source_lang', 'zh');
        
        $wpdb->replace($table, [
            'component_type' => $component['type'],
            'fingerprint' => $component['fingerprint'],
            'source_html' => $component['html'],
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_html' => $translated_html,
            'usage_count' => 1,
            'last_used' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }
    
    /**
     * Update component usage
     */
    public function update_component_usage($fingerprint, $target_lang) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'gml_components';
        $source_lang = get_option('gml_source_lang', 'zh');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE $table 
             SET usage_count = usage_count + 1, last_used = %s
             WHERE fingerprint = %s AND source_lang = %s AND target_lang = %s",
            current_time('mysql'), $fingerprint, $source_lang, $target_lang
        ));
    }
}
