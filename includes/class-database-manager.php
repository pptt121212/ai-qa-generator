<?php
class AI_QA_Database_Manager {
    private static $table_name = 'ai_qa_processing_log';
    
    public static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) NOT NULL,
            target_post_ids longtext NOT NULL,
            processing_time datetime DEFAULT CURRENT_TIMESTAMP,
            completion_time datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'processing',
            qa_count int(11) DEFAULT 0,
            ai_model varchar(100) NOT NULL,
            user_id bigint(20) NOT NULL,
            error_message text NULL,
            PRIMARY KEY (id),
            KEY source_post_id (source_post_id),
            KEY processing_time (processing_time),
            KEY status (status),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // 记录表版本
        update_option('ai_qa_db_version', '1.0');
    }
    
    public static function log_processing($source_post_id, $ai_model, $user_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->insert(
            $table_name,
            array(
                'source_post_id' => $source_post_id,
                'target_post_ids' => '',
                'ai_model' => $ai_model,
                'user_id' => $user_id,
                'status' => 'processing'
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );
    }
    
    public static function update_processing_result($source_post_id, $target_post_ids, $qa_count, $status = 'completed', $error_message = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $update_data = array(
            'target_post_ids' => json_encode($target_post_ids),
            'completion_time' => current_time('mysql'),
            'qa_count' => $qa_count,
            'status' => $status
        );
        
        if ($error_message) {
            $update_data['error_message'] = $error_message;
        }
        
        return $wpdb->update(
            $table_name,
            $update_data,
            array('source_post_id' => $source_post_id, 'status' => 'processing'),
            array('%s', '%s', '%d', '%s', '%s'),
            array('%d', '%s')
        );
    }
    
    public static function get_processing_stats($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_processed,
                SUM(qa_count) as total_qa_created,
                AVG(qa_count) as avg_qa_per_post,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
            FROM $table_name 
            WHERE processing_time >= %s
        ", $date_limit), ARRAY_A);
        
        return $stats;
    }
    
    public static function get_recent_processing($limit = 20) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                p.post_title,
                l.processing_time,
                l.completion_time,
                l.qa_count,
                l.status,
                l.ai_model,
                l.error_message
            FROM $table_name l
            LEFT JOIN {$wpdb->posts} p ON l.source_post_id = p.ID
            ORDER BY l.processing_time DESC
            LIMIT %d
        ", $limit), ARRAY_A);
    }
    
    public static function cleanup_old_logs($days = 90) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name 
            WHERE processing_time < %s
        ", $date_limit));
    }
    
    public static function drop_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        delete_option('ai_qa_db_version');
    }
}