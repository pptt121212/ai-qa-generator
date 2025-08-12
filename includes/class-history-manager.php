<?php
class AI_QA_History_Manager {
    
    public static function add_history_record($source_post_id, $created_posts, $settings_used) {
        $history = get_option('ai_qa_processing_history', array());
        
        $record = array(
            'id' => uniqid(),
            'source_post_id' => $source_post_id,
            'source_post_title' => get_the_title($source_post_id),
            'created_posts' => $created_posts,
            'created_count' => count($created_posts),
            'processing_time' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'settings_used' => array(
                'ai_model' => $settings_used['ai_model']
            )
        );
        
        array_unshift($history, $record);
        
        // 只保留最近100条记录
        $history = array_slice($history, 0, 100);
        
        update_option('ai_qa_processing_history', $history);
        
        return $record['id'];
    }
    
    public static function get_history($limit = 100) {
        $history = get_option('ai_qa_processing_history', array());
        return array_slice($history, 0, $limit);
    }
    
    public static function delete_generated_posts($record_id) {
        $history = get_option('ai_qa_processing_history', array());
        
        foreach ($history as $key => $record) {
            if ($record['id'] === $record_id) {
                // 删除生成的文章
                foreach ($record['created_posts'] as $post_id) {
                    wp_delete_post($post_id, true);
                }
                
                // 移除原文章的处理标记
                delete_post_meta($record['source_post_id'], 'ai_processed');
                delete_post_meta($record['source_post_id'], 'generated_qa_posts');
                
                // 从历史记录中移除
                unset($history[$key]);
                update_option('ai_qa_processing_history', array_values($history));
                
                return true;
            }
        }
        
        return false;
    }
    
    public static function get_statistics() {
        $history = get_option('ai_qa_processing_history', array());
        
        $stats = array(
            'total_processed' => count($history),
            'total_qa_created' => 0,
            'last_30_days' => 0,
            'most_used_model' => '',
            'model_usage' => array()
        );
        
        $thirty_days_ago = strtotime('-30 days');
        $model_count = array();
        
        foreach ($history as $record) {
            $stats['total_qa_created'] += $record['created_count'];
            
            if (strtotime($record['processing_time']) > $thirty_days_ago) {
                $stats['last_30_days']++;
            }
            
            $model = $record['settings_used']['ai_model'];
            $model_count[$model] = isset($model_count[$model]) ? $model_count[$model] + 1 : 1;
        }
        
        if (!empty($model_count)) {
            arsort($model_count);
            $stats['most_used_model'] = key($model_count);
            $stats['model_usage'] = $model_count;
        }
        
        return $stats;
    }
}