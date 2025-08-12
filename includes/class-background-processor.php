<?php
class AI_QA_Background_Processor {
    private static $batch_option = 'ai_qa_batch_queue';
    private static $processing_option = 'ai_qa_batch_processing';
    
    public static function add_batch($post_ids, $settings) {
        // 过滤掉已经在队列中的文章
        $filtered_post_ids = self::filter_posts_not_in_queue($post_ids);
        
        if (empty($filtered_post_ids)) {
            error_log("所有文章都已在队列中或已处理，跳过批次创建");
            return false;
        }
        
        // 记录被过滤掉的文章
        $filtered_count = count($post_ids) - count($filtered_post_ids);
        if ($filtered_count > 0) {
            error_log("过滤掉 {$filtered_count} 篇已在队列中的文章，实际添加 " . count($filtered_post_ids) . " 篇");
        }
        
        $batch_id = uniqid('batch_');

        // 将批次ID关联到每篇文章
        foreach ($filtered_post_ids as $post_id) {
            update_post_meta($post_id, 'ai_qa_batch_id', $batch_id);
        }
        $batch_data = array(
            'id' => $batch_id,
            'post_ids' => $filtered_post_ids,
            'settings' => $settings,
            'created_time' => current_time('mysql'),
            'status' => 'pending',
            'processed_count' => 0,
            'failed_count' => 0,
            'total_count' => count($filtered_post_ids),
            'current_post_index' => 0,
            'estimated_completion' => date('Y-m-d H:i:s', time() + (count($filtered_post_ids) * 120)), // 每篇2分钟估算
            'priority' => 'normal',
            'processing_stats' => array(),
            'original_count' => count($post_ids), // 记录原始数量
            'filtered_count' => $filtered_count   // 记录过滤数量
        );
        
        $queue = get_option(self::$batch_option, array());
        $queue[$batch_id] = $batch_data;
        update_option(self::$batch_option, $queue);
        
        error_log("创建新批次 {$batch_id}: " . count($filtered_post_ids) . " 篇文章，预计完成时间: " . $batch_data['estimated_completion']);
        
        // 自动启动处理
        self::schedule_next_run();
        
        // 额外调度一个检查任务，确保处理能够启动
        if (!wp_next_scheduled('ai_qa_check_and_start')) {
            wp_schedule_single_event(time() + 10, 'ai_qa_check_and_start');
        }
        
        return $batch_id;
    }
    
    /**
     * 过滤掉已经在队列中或已处理的文章
     */
    public static function filter_posts_not_in_queue($post_ids) {
        $queue = get_option(self::$batch_option, array());
        $posts_in_queue = array();
        
        // 收集所有正在队列中的文章ID
        foreach ($queue as $batch) {
            if ($batch['status'] === 'pending') {
                // 获取未处理的文章ID
                $remaining_posts = array_slice(
                    $batch['post_ids'], 
                    $batch['current_post_index']
                );
                $posts_in_queue = array_merge($posts_in_queue, $remaining_posts);
            }
        }
        
        // 过滤掉已在队列中的文章
        $filtered_posts = array();
        foreach ($post_ids as $post_id) {
            // 检查文章是否存在
            if (!get_post($post_id)) {
                continue;
            }
            
            // 检查是否已成功处理（失败的文章允许重新处理）
            if (get_post_meta($post_id, 'ai_processed', true)) {
                continue;
            }
            
            // 检查是否在队列中
            if (in_array($post_id, $posts_in_queue)) {
                continue;
            }
            
            $filtered_posts[] = $post_id;
        }
        
        return $filtered_posts;
    }
    
    /**
     * 检查文章是否正在队列中处理
     */
    public static function is_post_in_queue($post_id) {
        $queue = get_option(self::$batch_option, array());
        
        foreach ($queue as $batch) {
            if ($batch['status'] === 'pending') {
                // 检查主队列中未处理的部分
                $remaining_posts = array_slice(
                    $batch['post_ids'], 
                    $batch['current_post_index']
                );
                if (in_array($post_id, $remaining_posts)) {
                    return true;
                }

                // 检查重试队列
                if (!empty($batch['retry_queue']) && in_array($post_id, $batch['retry_queue'])) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 获取正在队列中的所有文章ID
     */
    public static function get_posts_in_queue() {
        $queue = get_option(self::$batch_option, array());
        $posts_in_queue = array();
        
        foreach ($queue as $batch) {
            if ($batch['status'] === 'pending') {
                // 获取未处理的文章ID
                $remaining_posts = array_slice(
                    $batch['post_ids'], 
                    $batch['current_post_index']
                );
                $posts_in_queue = array_merge($posts_in_queue, $remaining_posts);
            }
        }
        
        return array_unique($posts_in_queue);
    }
    
    /**
     * 获取队列统计信息
     */
    public static function get_queue_stats() {
        $queue = get_option(self::$batch_option, array());
        $stats = array(
            'total_batches' => 0,
            'pending_batches' => 0,
            'completed_batches' => 0,
            'failed_batches' => 0,
            'total_posts' => 0,
            'processed_posts' => 0,
            'failed_posts' => 0
        );
        
        foreach ($queue as $batch) {
            $stats['total_batches']++;
            $stats['total_posts'] += $batch['total_count'];
            $stats['processed_posts'] += $batch['processed_count'];
            $stats['failed_posts'] += isset($batch['failed_count']) ? $batch['failed_count'] : 0;
            
            switch ($batch['status']) {
                case 'pending':
                    $stats['pending_batches']++;
                    break;
                case 'completed':
                    $stats['completed_batches']++;
                    break;
                case 'failed':
                    $stats['failed_batches']++;
                    break;
            }
        }
        
        return $stats;
    }
    
    public static function process_batch() {
        $current_time = time();
        $processing_lock = get_option(self::$processing_option, false);

        if ($processing_lock && $processing_lock < $current_time) {
            error_log('检测到过期的处理锁，自动释放');
            delete_option(self::$processing_option);
            $processing_lock = false;
        }

        if ($processing_lock) {
            error_log('后台处理器已在运行，跳过此次执行');
            return;
        }

        update_option(self::$processing_option, $current_time + 300);

        try {
            @set_time_limit(120);
            @ini_set('memory_limit', '128M');

            $start_time = microtime(true);
            $processed_in_run = 0;
            $max_process_per_run = 1;

            error_log('后台处理器启动: ' . date('Y-m-d H:i:s'));

            $queue = get_option(self::$batch_option, array());
            $has_pending_work = false;

            foreach ($queue as $batch) {
                if ($batch['status'] === 'pending') {
                    if (!empty($batch['retry_queue']) || $batch['current_post_index'] < $batch['total_count']) {
                        $has_pending_work = true;
                        break;
                    }
                }
            }

            if (!$has_pending_work) {
                error_log('没有待处理的批次，退出处理器');
                return;
            }

            foreach ($queue as $batch_id => &$batch) {
                if ($batch['status'] !== 'pending' || $processed_in_run >= $max_process_per_run) {
                    continue;
                }

                $post_id = null;
                $is_retry = false;

                if (!empty($batch['retry_queue'])) {
                    $post_id = array_shift($batch['retry_queue']);
                    $is_retry = true;
                    error_log("开始处理批次 {$batch_id} 的重试队列: 文章 {$post_id}");
                } elseif ($batch['current_post_index'] < $batch['total_count']) {
                    $post_id = $batch['post_ids'][$batch['current_post_index']];
                    $progress = $batch['current_post_index'] + 1;
                    $total = $batch['total_count'];
                    error_log("开始处理批次 {$batch_id}: 文章 {$post_id} ({$progress}/{$total})");
                }

                if (!$post_id) {
                    if (empty($batch['retry_queue']) && $batch['current_post_index'] >= $batch['total_count']) {
                        $batch['status'] = 'completed';
                        $batch['completed_time'] = current_time('mysql');
                        error_log("批次 {$batch_id} 已完成所有文章处理");
                    }
                    continue;
                }

                try {
                    self::process_single_post($post_id, $batch['settings']);
                    $batch['processed_count']++;
                    error_log("文章 {$post_id} 处理成功");
                } catch (Exception $e) {
                    error_log("处理文章 {$post_id} 失败: " . $e->getMessage());
                    self::mark_post_as_failed($post_id, $e->getMessage());
                    $batch['failed_count'] = isset($batch['failed_count']) ? $batch['failed_count'] + 1 : 1;
                    if (!isset($batch['failed_posts'])) {
                        $batch['failed_posts'] = array();
                    }
                    $batch['failed_posts'][] = $post_id;
                }

                if (!$is_retry) {
                    $batch['current_post_index']++;
                }

                if (empty($batch['retry_queue']) && $batch['current_post_index'] >= $batch['total_count']) {
                    $batch['status'] = 'completed';
                    $batch['completed_time'] = current_time('mysql');
                    error_log("批次 {$batch_id} 已完成所有文章处理（含重试）");
                }

                $processed_in_run++;
                break; 
            }
            unset($batch);

            update_option(self::$batch_option, $queue);
            update_option('ai_qa_last_batch_time', $current_time);

            $execution_time = microtime(true) - $start_time;
            error_log(sprintf('后台处理器完成: 处理 %d 篇文章，耗时 %.2f 秒', $processed_in_run, $execution_time));

            $still_has_work = false;
            foreach ($queue as $batch) {
                if ($batch['status'] === 'pending') {
                     if (!empty($batch['retry_queue']) || $batch['current_post_index'] < $batch['total_count']) {
                        $still_has_work = true;
                        break;
                    }
                }
            }

            if ($still_has_work) {
                self::schedule_next_run();
            } else {
                error_log('所有批次处理完成，无需调度下次执行');
            }

        } catch (Exception $e) {
            error_log('后台处理器异常: ' . $e->getMessage());
            self::schedule_next_run();
        } finally {
            delete_option(self::$processing_option);
        }
    }
    
    /**
     * 调度下次执行
     */
    public static function schedule_next_run() {
        // 如果还没有调度下次执行，则调度一个
        if (!wp_next_scheduled('ai_qa_process_batch')) {
            wp_schedule_single_event(time() + 30, 'ai_qa_process_batch');
            error_log('已调度30秒后的下次处理');
        }
    }
    
    /**
     * 检查并启动处理（用于确保处理不会停滞）
     */
    public static function check_and_start_processing() {
        $queue = get_option(self::$batch_option, array());
        $has_pending = false;
        
        // 检查是否有待处理的批次
        foreach ($queue as $batch) {
            if ($batch['status'] === 'pending' && $batch['current_post_index'] < $batch['total_count']) {
                $has_pending = true;
                break;
            }
        }
        
        if ($has_pending) {
            // 检查是否已经有调度的处理任务
            if (!wp_next_scheduled('ai_qa_process_batch')) {
                // 立即调度一个处理任务
                wp_schedule_single_event(time() + 5, 'ai_qa_process_batch');
                error_log('检测到待处理任务，已调度立即处理');
            } else {
                error_log('检测到待处理任务，但已有调度任务在运行');
            }
        } else {
            error_log('检查完成：没有待处理的任务');
        }
    }
    
    /**
     * 强制启动处理（用于手动触发）
     */
    public static function force_start_processing() {
        // 清除现有的调度
        wp_clear_scheduled_hook('ai_qa_process_batch');
        
        // 立即调度处理
        wp_schedule_single_event(time() + 2, 'ai_qa_process_batch');
        
        error_log('强制启动处理：已清除现有调度并重新调度');
        
        return true;
    }
    
    /**
     * 获取处理器状态
     */
    public static function get_processor_status() {
        $processing_lock = get_option(self::$processing_option, false);
        $next_scheduled = wp_next_scheduled('ai_qa_process_batch');
        $last_batch_time = get_option('ai_qa_last_batch_time', 0);
        
        return array(
            'is_processing' => $processing_lock !== false,
            'processing_since' => $processing_lock ? date('Y-m-d H:i:s', $processing_lock - 300) : null,
            'next_scheduled' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
            'last_batch_time' => $last_batch_time ? date('Y-m-d H:i:s', $last_batch_time) : null,
            'time_since_last' => $last_batch_time ? time() - $last_batch_time : null
        );
    }
    
    private static function process_single_post($post_id, $settings) {
        $post = get_post($post_id);
        if (!$post || get_post_meta($post_id, 'ai_processed', true)) {
            return;
        }
        
        try {
            // 标记文章为处理中状态
            update_post_meta($post_id, 'ai_processing_status', 'processing');
            update_post_meta($post_id, 'ai_processing_start_time', current_time('mysql'));
            
            // 优化后端处理：设置资源限制，避免服务器过载
            @set_time_limit(300); // 5分钟
            @ini_set('memory_limit', '256M');
            
            // 强制垃圾回收，释放内存
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            error_log("后端处理开始: 文章ID {$post_id}");
            
            $post_processor = new Post_Processor();
            $ai_processor = new AI_Processor();
            $qa_creator = new QA_Creator();
            
            // 解析目标设置
            $target_post_type = isset($settings['target_post_type']) ? $settings['target_post_type'] : 'post';
            $target_taxonomy = isset($settings['target_taxonomy']) ? $settings['target_taxonomy'] : '';
            
            $content = $post_processor->extract_post_content($post);
            $qa_pairs = $ai_processor->process_content($content);
            
            // 第一阶段：创建草稿
            $target_settings_array = array(
                'post_type' => $target_post_type,
                'taxonomy' => $target_taxonomy
            );
            $created_posts = $qa_creator->create_qa_posts($qa_pairs, $post, $target_settings_array);
            
            if (empty($created_posts)) {
                throw new Exception('创建问答文章失败');
            }
            
            // 第二阶段：发布草稿
            $publish_result = $qa_creator->publish_draft_qa_posts($created_posts);
            if ($publish_result['published_count'] === 0) {
                $qa_creator->cleanup_failed_drafts($created_posts);
                throw new Exception('发布问答文章失败');
            }
            
            // 清理部分失败的草稿
            if (!empty($publish_result['failed_posts'])) {
                $qa_creator->cleanup_failed_drafts($publish_result['failed_posts']);
                $created_posts = array_diff($created_posts, $publish_result['failed_posts']);
            }
            
            update_post_meta($post_id, 'ai_processed', current_time('mysql'));
            update_post_meta($post_id, 'generated_qa_posts', $created_posts);
            
            // 清除处理状态，标记为完成
            delete_post_meta($post_id, 'ai_processing_status');
            delete_post_meta($post_id, 'ai_processing_start_time');
            delete_post_meta($post_id, 'ai_processing_error');
            delete_post_meta($post_id, 'ai_failed_time');
            delete_post_meta($post_id, 'ai_fail_count');
            
            // 记录到数据库
            AI_QA_Database_Manager::log_processing($post_id, $settings['ai_model'], 0); // 后台处理用户ID为0
            AI_QA_Database_Manager::update_processing_result($post_id, $created_posts, count($created_posts));
            
            // 添加到历史记录
            AI_QA_History_Manager::add_history_record($post_id, $created_posts, $settings);
            
            // 清理大变量，释放内存
            unset($content, $qa_pairs, $target_settings_array);
            
            // 强制垃圾回收
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            error_log("后端处理完成: 文章ID {$post_id}，生成 " . count($created_posts) . " 个问答");
            
        } catch (Exception $e) {
            // 清理可能创建的草稿
            if (isset($created_posts) && !empty($created_posts)) {
                try {
                    $qa_creator->cleanup_failed_drafts($created_posts);
                } catch (Exception $cleanup_error) {
                    error_log('后台处理清理草稿失败: ' . $cleanup_error->getMessage());
                }
            }
            
            // 记录失败状态
            if (isset($settings)) {
                try {
                    AI_QA_Database_Manager::update_processing_result($post_id, array(), 0, 'failed', $e->getMessage());
                } catch (Exception $db_error) {
                    error_log('后台处理记录失败状态出错: ' . $db_error->getMessage());
                }
            }
            
            error_log('后台处理文章失败: ' . $post_id . ' - ' . $e->getMessage());
            throw $e; // 重新抛出异常，让上层处理
        }
    }
    
    public static function get_batch_status($batch_id = null) {
        $queue = get_option(self::$batch_option, array());
        
        if ($batch_id) {
            $batch = isset($queue[$batch_id]) ? $queue[$batch_id] : null;
            if ($batch) {
                // 计算进度百分比
                $batch['progress_percent'] = $batch['total_count'] > 0 ? 
                    round(($batch['processed_count'] / $batch['total_count']) * 100, 1) : 0;
                
                // 计算剩余时间
                if ($batch['processed_count'] > 0 && $batch['status'] === 'pending') {
                    $remaining = $batch['total_count'] - $batch['processed_count'];
                    $batch['estimated_remaining_time'] = $remaining * 2 . ' 分钟'; // 每篇2分钟
                }
            }
            return $batch;
        }
        
        // 返回所有批次的摘要信息
        $summary = array();
        foreach ($queue as $id => $batch) {
            $summary[$id] = array(
                'id' => $id,
                'status' => $batch['status'],
                'progress' => $batch['processed_count'] . '/' . $batch['total_count'],
                'progress_percent' => $batch['total_count'] > 0 ? 
                    round(($batch['processed_count'] / $batch['total_count']) * 100, 1) : 0,
                'created_time' => $batch['created_time'],
                'failed_count' => isset($batch['failed_count']) ? $batch['failed_count'] : 0
            );
        }
        
        return $summary;
    }
    
    public static function cancel_batch($batch_id) {
        $queue = get_option(self::$batch_option, array());
        
        if (isset($queue[$batch_id])) {
            $queue[$batch_id]['status'] = 'cancelled';
            update_option(self::$batch_option, $queue);
            return true;
        }
        
        return false;
    }
    
    public static function cleanup_completed_batches() {
        $queue = get_option(self::$batch_option, array());
        $cutoff_time = strtotime('-7 days');
        
        foreach ($queue as $batch_id => $batch) {
            if (in_array($batch['status'], array('completed', 'cancelled')) && 
                strtotime($batch['created_time']) < $cutoff_time) {
                unset($queue[$batch_id]);
            }
        }
        
        update_option(self::$batch_option, $queue);
        
        // 同时清理可能遗留的孤儿草稿
        self::cleanup_orphaned_drafts();
    }
    
    /**
     * 清理孤儿草稿文章（超过1小时未发布的AI生成草稿）
     * 注意：会保护正在处理批次中的草稿，避免误删
     */
    public static function cleanup_orphaned_drafts() {
        global $wpdb;
        
        // 查找超过1小时的待发布草稿
        $cutoff_time = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        $orphaned_posts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, pm2.meta_value as source_post_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'source_post_id'
            WHERE p.post_status = 'draft'
            AND pm.meta_key = 'qa_draft_status'
            AND pm.meta_value = 'pending_publish'
            AND p.post_date < %s
        ", $cutoff_time));
        
        // 获取正在处理的批次中的文章ID
        $protected_posts = self::get_posts_in_active_batches();
        
        $deleted_count = 0;
        $protected_count = 0;
        
        foreach ($orphaned_posts as $post) {
            $source_post_id = intval($post->source_post_id);
            
            // 检查源文章是否在正在处理的批次中
            if (in_array($source_post_id, $protected_posts)) {
                $protected_count++;
                error_log("保护草稿文章 {$post->ID}，其源文章 {$source_post_id} 正在批次处理中");
                continue;
            }
            
            // 安全删除孤儿草稿
            if (wp_delete_post($post->ID, true)) {
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            error_log("清理了 {$deleted_count} 个孤儿草稿文章");
        }
        
        if ($protected_count > 0) {
            error_log("保护了 {$protected_count} 个正在处理中的草稿文章");
        }
        
        return array(
            'deleted' => $deleted_count,
            'protected' => $protected_count
        );
    }
    
    /**
     * 获取正在处理的批次中的所有文章ID
     */
    private static function get_posts_in_active_batches() {
        $queue = get_option(self::$batch_option, array());
        $active_posts = array();
        
        foreach ($queue as $batch) {
            // 只检查正在处理的批次
            if ($batch['status'] === 'pending') {
                // 获取所有文章ID（包括已处理和未处理的）
                $active_posts = array_merge($active_posts, $batch['post_ids']);
            }
        }
        
        return array_unique($active_posts);
    }
    
    /**
     * 标记文章为失败状态
     */
    private static function mark_post_as_failed($post_id, $error_message) {
        update_post_meta($post_id, 'ai_processing_status', 'failed');
        update_post_meta($post_id, 'ai_processing_error', $error_message);
        update_post_meta($post_id, 'ai_failed_time', current_time('mysql'));
        
        // 增加失败次数
        $fail_count = get_post_meta($post_id, 'ai_fail_count', true);
        update_post_meta($post_id, 'ai_fail_count', intval($fail_count) + 1);
        
        error_log("文章 {$post_id} 标记为失败状态: {$error_message}");
    }
    
    /**
     * 清理失败文章的草稿
     */
    private static function cleanup_failed_post_drafts($post_id) {
        global $wpdb;
        
        // 查找该文章对应的待发布草稿
        $drafts = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'source_post_id' AND pm1.meta_value = %s
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'qa_draft_status' AND pm2.meta_value = 'pending_publish'
            WHERE p.post_status = 'draft'
        ", $post_id));
        
        $deleted_count = 0;
        foreach ($drafts as $draft) {
            if (wp_delete_post($draft->ID, true)) {
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            error_log("清理了文章 {$post_id} 对应的 {$deleted_count} 个失败草稿");
        }
        
        return $deleted_count;
    }
    
    /**
     * 获取文章的处理状态
     */
    public static function get_post_processing_status($post_id) {
        // 检查是否已完成处理
        if (get_post_meta($post_id, 'ai_processed', true)) {
            return 'completed';
        }
        
        // 检查是否标记为失败
        if (get_post_meta($post_id, 'ai_processing_status', true) === 'failed') {
            return 'failed';
        }
        
        // 检查是否在队列中
        if (self::is_post_in_queue($post_id)) {
            return 'processing';
        }
        
        // 默认为等待处理
        return 'pending';
    }
    
    /**
     * 重试失败的文章
     */
    public static function retry_failed_post($post_id) {
        // 检查文章是否存在且为失败状态
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => '文章不存在');
        }

        $status = get_post_meta($post_id, 'ai_processing_status', true);
        if ($status !== 'failed') {
            return array('success' => false, 'message' => '文章不是失败状态，无需重试');
        }

        $queue = get_option(self::$batch_option, array());
        $original_batch_id = get_post_meta($post_id, 'ai_qa_batch_id', true);

        // 检查原始批次是否存在
        if ($original_batch_id && isset($queue[$original_batch_id])) {
            // 清除失败状态
            delete_post_meta($post_id, 'ai_processing_status');
            delete_post_meta($post_id, 'ai_processing_error');
            delete_post_meta($post_id, 'ai_failed_time');

            $batch = &$queue[$original_batch_id]; // Use reference

            // 将文章ID添加到批次的重试队列中
            if (!isset($batch['retry_queue'])) {
                $batch['retry_queue'] = array();
            }
            // 防止重复添加
            if (!in_array($post_id, $batch['retry_queue'])) {
                $batch['retry_queue'][] = $post_id;
            }
            
            // 如果批次已完成，则重新打开
            if ($batch['status'] === 'completed') {
                $batch['status'] = 'pending';
            }
            
            // 更新失败计数
            if (isset($batch['failed_count']) && $batch['failed_count'] > 0) {
                $batch['failed_count']--;
            }

            update_option(self::$batch_option, $queue);
            self::schedule_next_run();

            error_log("文章 {$post_id} 已添加回原始批次 {$original_batch_id} 的重试队列");
            return array(
                'success' => true,
                'message' => '文章已添加回原始批次进行重试',
                'batch_id' => $original_batch_id
            );
        } else {
            // 如果找不到原始批次，则创建新批次（旧逻辑）
            delete_post_meta($post_id, 'ai_processing_status');
            delete_post_meta($post_id, 'ai_processing_error');
            delete_post_meta($post_id, 'ai_failed_time');
            
            $settings = get_option('ai_qa_generator_settings');
            $batch_id = self::add_batch(array($post_id), $settings);

            if ($batch_id) {
                error_log("文章 {$post_id} 已添加到新的重试批次 {$batch_id}");
                return array(
                    'success' => true,
                    'message' => '文章已添加到新的重试队列',
                    'batch_id' => $batch_id
                );
            } else {
                return array('success' => false, 'message' => '添加到重试队列失败');
            }
        }
    }
    
    /**
     * 获取失败文章列表
     */
    public static function get_failed_posts($limit = 50) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type, pm1.meta_value as error_message, pm2.meta_value as failed_time, pm3.meta_value as fail_count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'ai_processing_error'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'ai_failed_time'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'ai_fail_count'
            INNER JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = 'ai_processing_status' AND pm4.meta_value = 'failed'
            ORDER BY pm2.meta_value DESC
            LIMIT %d
        ", $limit));
        
        return $results;
    }
}