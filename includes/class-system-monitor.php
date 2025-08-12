<?php
/**
 * 系统监控类 - 监控后台任务和系统状态
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_System_Monitor {
    
    /**
     * 检查系统状态
     */
    public static function check_system_status() {
        $status = array(
            'cron_enabled' => self::is_cron_enabled(),
            'scheduled_tasks' => self::get_scheduled_tasks(),
            'queue_status' => self::get_queue_status(),
            'resource_usage' => self::get_resource_usage(),
            'last_processing' => self::get_last_processing_info(),
            'system_health' => 'good'
        );
        
        // 评估系统健康状态
        if (!$status['cron_enabled']) {
            $status['system_health'] = 'critical';
        } elseif (empty($status['scheduled_tasks'])) {
            $status['system_health'] = 'warning';
        }
        
        return $status;
    }
    
    /**
     * 检查WordPress Cron是否启用
     */
    public static function is_cron_enabled() {
        return !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
    }
    
    /**
     * 获取已调度的任务
     */
    public static function get_scheduled_tasks() {
        $tasks = array();
        
        $ai_tasks = array(
            'ai_qa_process_batch' => 'AI问答处理任务',
            'ai_qa_cleanup_batches' => '批次清理任务',
            'ai_qa_cleanup_drafts' => '草稿清理任务'
        );
        
        foreach ($ai_tasks as $hook => $name) {
            $next_run = wp_next_scheduled($hook);
            $tasks[$hook] = array(
                'name' => $name,
                'scheduled' => $next_run !== false,
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : '未调度',
                'time_until' => $next_run ? human_time_diff(time(), $next_run) : '未知'
            );
        }
        
        return $tasks;
    }
    
    /**
     * 获取队列状态
     */
    public static function get_queue_status() {
        if (!class_exists('AI_QA_Background_Processor')) {
            return array('error' => '后台处理器类未加载');
        }
        
        return AI_QA_Background_Processor::get_queue_stats();
    }
    
    /**
     * 获取资源使用情况
     */
    public static function get_resource_usage() {
        return array(
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . '秒',
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version')
        );
    }
    
    /**
     * 获取最后处理信息
     */
    public static function get_last_processing_info() {
        $processing_lock = get_option('ai_qa_batch_processing', false);
        $last_batch_time = get_option('ai_qa_last_batch_time', 0);
        
        return array(
            'is_processing' => $processing_lock !== false,
            'processing_since' => $processing_lock ? date('Y-m-d H:i:s', $processing_lock) : null,
            'last_batch_time' => $last_batch_time ? date('Y-m-d H:i:s', $last_batch_time) : '从未运行',
            'time_since_last' => $last_batch_time ? human_time_diff($last_batch_time, time()) . '前' : '未知'
        );
    }
    
    /**
     * 强制触发后台处理（用于测试）
     */
    public static function trigger_background_processing() {
        if (!class_exists('AI_QA_Background_Processor')) {
            return array('success' => false, 'message' => '后台处理器类未加载');
        }
        
        try {
            // 记录触发时间
            update_option('ai_qa_last_manual_trigger', time());
            
            // 强制启动处理
            AI_QA_Background_Processor::force_start_processing();
            
            return array(
                'success' => true, 
                'message' => '后台处理已强制启动，将在2秒后开始执行',
                'timestamp' => current_time('mysql')
            );
        } catch (Exception $e) {
            return array(
                'success' => false, 
                'message' => '触发失败: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * 重新调度所有任务
     */
    public static function reschedule_all_tasks() {
        $tasks = array(
            'ai_qa_process_batch' => 'ai_qa_every_2min',
            'ai_qa_cleanup_batches' => 'hourly',
            'ai_qa_cleanup_drafts' => 'twicedaily'
        );
        
        $results = array();
        
        foreach ($tasks as $hook => $recurrence) {
            // 清除现有调度
            wp_clear_scheduled_hook($hook);
            
            // 重新调度
            $scheduled = wp_schedule_event(time(), $recurrence, $hook);
            
            $results[$hook] = array(
                'success' => $scheduled !== false,
                'next_run' => wp_next_scheduled($hook)
            );
        }
        
        return $results;
    }
    
    /**
     * 获取处理性能统计
     */
    public static function get_performance_stats() {
        global $wpdb;
        
        // 获取最近24小时的处理统计
        $stats = array(
            'last_24h_processed' => 0,
            'last_24h_failed' => 0,
            'average_processing_time' => 0,
            'success_rate' => 0
        );
        
        try {
            if (class_exists('AI_QA_Database_Manager')) {
                $table_name = $wpdb->prefix . 'ai_qa_processing_log';
                
                // 检查表是否存在
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                    $cutoff_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
                    
                    $results = $wpdb->get_results($wpdb->prepare("
                        SELECT 
                            status,
                            COUNT(*) as count,
                            AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_time
                        FROM $table_name 
                        WHERE created_at >= %s 
                        GROUP BY status
                    ", $cutoff_time));
                    
                    $total_processed = 0;
                    $total_failed = 0;
                    $total_time = 0;
                    
                    foreach ($results as $result) {
                        if ($result->status === 'completed') {
                            $stats['last_24h_processed'] = intval($result->count);
                            $total_processed = intval($result->count);
                            $total_time = floatval($result->avg_time);
                        } elseif ($result->status === 'failed') {
                            $stats['last_24h_failed'] = intval($result->count);
                            $total_failed = intval($result->count);
                        }
                    }
                    
                    $stats['average_processing_time'] = round($total_time, 1);
                    $total = $total_processed + $total_failed;
                    $stats['success_rate'] = $total > 0 ? round(($total_processed / $total) * 100, 1) : 0;
                }
            }
        } catch (Exception $e) {
            error_log('获取性能统计失败: ' . $e->getMessage());
        }
        
        return $stats;
    }
}