<?php
class AI_QA_Cache_Manager {
    private static $cache_group = 'ai_qa_generator';
    private static $cache_expiry = 7 * DAY_IN_SECONDS; // 7天
    
    public static function get_cached_result($content_hash, $model) {
        $cache_key = self::generate_cache_key($content_hash, $model);
        
        // 优先使用对象缓存
        if (function_exists('wp_cache_get')) {
            $result = wp_cache_get($cache_key, self::$cache_group);
            if ($result !== false) {
                return $result;
            }
        }
        
        // 回退到transient
        return get_transient($cache_key);
    }
    
    public static function cache_result($content_hash, $model, $result) {
        $cache_key = self::generate_cache_key($content_hash, $model);
        
        // 同时使用对象缓存和transient
        if (function_exists('wp_cache_set')) {
            wp_cache_set($cache_key, $result, self::$cache_group, self::$cache_expiry);
        }
        
        set_transient($cache_key, $result, self::$cache_expiry);
    }
    
    private static function generate_cache_key($content_hash, $model) {
        return 'ai_qa_' . md5($content_hash . $model);
    }
    
    public static function clear_cache() {
        global $wpdb;
        
        // 清除transient缓存
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_ai_qa_%'
            )
        );
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_ai_qa_%'
            )
        );
        
        // 如果使用对象缓存，清除对应缓存组
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::$cache_group);
        }
    }
    
    public static function get_cache_stats() {
        global $wpdb;
        
        $cache_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_ai_qa_%'
            )
        );
        
        return array(
            'cached_items' => intval($cache_count),
            'cache_expiry_hours' => self::$cache_expiry / HOUR_IN_SECONDS
        );
    }
}