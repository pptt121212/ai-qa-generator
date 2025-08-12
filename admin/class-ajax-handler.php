<?php
/**
 * AJAX处理器类，负责处理所有管理后台的AJAX请求
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_Ajax_Handler {

    // --- 与文章处理和状态相关的AJAX方法 ---
    
    /**
     * 获取批次状态
     */
    public function ajax_get_batch_status() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            $batch_id = sanitize_text_field($_POST['batch_id']);
            $status = AI_QA_Background_Processor::get_batch_status($batch_id);
            
            wp_send_json_success($status);
        } catch (Exception $e) {
            wp_send_json_error('获取状态失败：' . $e->getMessage());
        }
    }

    /**
     * 启动后台批量处理
     */
    public function ajax_start_background_batch() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $post_ids = array_map('intval', $_POST['post_ids']);
            $settings = get_option('ai_qa_generator_settings');
            
            $new_post_ids = array();
            $retry_post_ids = array();
            $already_processed_count = 0;
            $in_queue_count = 0;

            // 分类文章：新任务、重试任务、已处理/队列中
            if (class_exists('AI_QA_Background_Processor')) {
                foreach ($post_ids as $post_id) {
                    if (get_post_meta($post_id, 'ai_processed', true)) {
                        $already_processed_count++;
                        continue;
                    }
                    if (AI_QA_Background_Processor::is_post_in_queue($post_id)) {
                        $in_queue_count++;
                        continue;
                    }
                    if (get_post_meta($post_id, 'ai_processing_status', true) === 'failed') {
                        $retry_post_ids[] = $post_id;
                    } else {
                        $new_post_ids[] = $post_id;
                    }
                }
            }

            $retried_count = 0;
            // 处理重试任务
            if (!empty($retry_post_ids)) {
                foreach ($retry_post_ids as $post_id) {
                    $result = AI_QA_Background_Processor::retry_failed_post($post_id);
                    if ($result['success']) {
                        $retried_count++;
                    }
                }
            }

            // 处理新任务
            $new_batch_id = null;
            $new_posts_count = 0;
            if (!empty($new_post_ids)) {
                $new_batch_id = AI_QA_Background_Processor::add_batch($new_post_ids, $settings);
                if ($new_batch_id) {
                    $new_posts_count = count($new_post_ids);
                }
            }
            
            $total_processed = $retried_count + $new_posts_count;
            if ($total_processed === 0) {
                $error_message = '没有可处理的文章。';
                if($already_processed_count > 0) $error_message .= " {$already_processed_count}篇已处理。";
                if($in_queue_count > 0) $error_message .= " {$in_queue_count}篇在队列中。";
                wp_send_json_error($error_message);
                return;
            }

            // 为了与现有JS弹窗兼容，我们构建一个类似旧的响应
            wp_send_json_success(array(
                'valid_posts' => $total_processed,
                'total_posts' => count($post_ids),
                'estimated_time' => $total_processed * 2 . ' 分钟',
                'batch_id' => $new_batch_id ?? 'multiple_batches', // 如果有重试，可能涉及多个批次
                'filtered_info' => array(
                    'already_processed' => $already_processed_count,
                    'in_queue' => $in_queue_count,
                    'retried' => $retried_count, // 在弹窗中告知用户有多少文章被重试
                    'newly_added' => $new_posts_count
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error('启动批量处理失败：' . $e->getMessage());
        }
    }

    /**
     * 刷新文章状态
     */
    public function ajax_refresh_post_status() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $post_ids = array_map('intval', $_POST['post_ids']);
            $status_data = array();

            if (!class_exists('AI_QA_Background_Processor')) {
                wp_send_json_error('后台处理器类未加载');
                return;
            }
            
            foreach ($post_ids as $post_id) {
                $status = AI_QA_Background_Processor::get_post_processing_status($post_id);
                
                $data = array('status' => $status);

                if ($status === 'failed') {
                    $data['fail_count'] = get_post_meta($post_id, 'ai_fail_count', true);
                    $data['error_message'] = get_post_meta($post_id, 'ai_processing_error', true);
                }
                
                $status_data[$post_id] = $data;
            }
            
            wp_send_json_success($status_data);
        } catch (Exception $e) {
            wp_send_json_error('刷新状态失败：' . $e->getMessage());
        }
    }

    /**
     * 获取批次进度
     */
    public function ajax_get_batch_progress() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
            
            if ($batch_id) {
                $status = AI_QA_Background_Processor::get_batch_status($batch_id);
                wp_send_json_success($status);
            } else {
                $all_batches = AI_QA_Background_Processor::get_batch_status();
                wp_send_json_success($all_batches);
            }
        } catch (Exception $e) {
            wp_send_json_error('获取进度失败：' . $e->getMessage());
        }
    }
    
    /**
     * 重试失败的文章
     */
    public function ajax_retry_failed_post() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $post_id = intval($_POST['post_id']);
            if (!$post_id) {
                wp_send_json_error('无效的文章ID');
                return;
            }

            if (!class_exists('AI_QA_Background_Processor')) {
                wp_send_json_error('后台处理器类未加载');
                return;
            }

            $result = AI_QA_Background_Processor::retry_failed_post($post_id);
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        } catch (Exception $e) {
            wp_send_json_error('重试失败：' . $e->getMessage());
        }
    }

    // --- 与系统维护和清理相关的AJAX方法 ---

    /**
     * 清除缓存
     */
    public function ajax_clear_cache() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            AI_QA_Cache_Manager::clear_cache();
            wp_send_json_success('缓存已清除');
        } catch (Exception $e) {
            wp_send_json_error('清除缓存失败：' . $e->getMessage());
        }
    }

    /**
     * 清理旧日志
     */
    public function ajax_cleanup_logs() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $deleted = AI_QA_Database_Manager::cleanup_old_logs(90);
            wp_send_json_success('已清理 ' . $deleted . ' 条旧日志');
        } catch (Exception $e) {
            wp_send_json_error('清理日志失败：' . $e->getMessage());
        }
    }

    /**
     * 清理孤儿草稿
     */
    public function ajax_cleanup_drafts() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $deleted = AI_QA_Background_Processor::cleanup_orphaned_drafts();
            wp_send_json_success('已清理 ' . $deleted . ' 个孤儿草稿文章');
        } catch (Exception $e) {
            wp_send_json_error('清理草稿失败：' . $e->getMessage());
        }
    }

    // --- 与设置和分类相关的AJAX方法 ---

    /**
     * 获取文章类型的分类项 (用于设置页面)
     */
    public function get_taxonomy_terms_for_post_type_callback() {
        check_ajax_referer('ai_qa_generator_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        if (empty($post_type)) {
            wp_send_json_error('未提供文章类型');
            return;
        }
        
        error_log("[Admin] Getting taxonomies for post type: " . $post_type);
        
        if (!class_exists('Post_Processor')) {
            wp_send_json_error('Post_Processor类未加载');
            return;
        }
        
        $post_processor = new Post_Processor();
        $taxonomies = $post_processor->get_taxonomies_for_post_type($post_type);
        
        $options_html = '<option value="">不设置分类</option>';
        foreach ($taxonomies as $taxonomy => $taxonomy_label) {
            $terms = $post_processor->get_taxonomy_terms($taxonomy, false);
            if (!empty($terms)) {
                $options_html .= '<optgroup label="' . esc_attr($taxonomy_label) . '">';
                foreach ($terms as $term_value => $term_label) {
                    $options_html .= '<option value="' . esc_attr($term_value) . '">' . 
                                   esc_html($term_label) . '</option>';
                }
                $options_html .= '</optgroup>';
            }
        }
        
        error_log("[Admin] Generated taxonomy options HTML: " . substr($options_html, 0, 200) . '...');
        wp_send_json_success($options_html);
    }

    /**
     * 获取文章类型的分类项 (用于主页面筛选)
     */
    public function ajax_get_taxonomy_terms_for_post_type() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            $post_type = sanitize_text_field($_POST['post_type']);
            
            if (!class_exists('Post_Processor')) {
                wp_send_json_error('Post_Processor类未加载');
                return;
            }
            
            $post_processor = new Post_Processor();
            
            $html = '<option value="">全部（不限制分类）</option>';
            
            $taxonomies = $post_processor->get_taxonomies_for_post_type($post_type);
            
            foreach ($taxonomies as $taxonomy => $taxonomy_label) {
                $terms = $post_processor->get_taxonomy_terms($taxonomy);
                if (!empty($terms)) {
                    $html .= '<optgroup label="' . esc_attr($taxonomy_label) . '">';
                    foreach ($terms as $term_value => $term_label) {
                        $html .= '<option value="' . esc_attr($term_value) . '">' . esc_html($term_label) . '</option>';
                    }
                    $html .= '</optgroup>';
                }
            }
            
            wp_send_json_success($html);
        } catch (Exception $e) {
            wp_send_json_error('获取分类失败：' . $e->getMessage());
        }
    }

    // --- 与系统监控相关的AJAX方法 ---

    /**
     * 获取系统状态
     */
    public function ajax_get_system_status() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            if (!class_exists('AI_QA_System_Monitor')) {
                wp_send_json_error('系统监控类未加载');
                return;
            }

            $status = AI_QA_System_Monitor::check_system_status();
            wp_send_json_success($status);
        } catch (Exception $e) {
            wp_send_json_error('获取系统状态失败：' . $e->getMessage());
        }
    }
    
    /**
     * 触发后台处理
     */
    public function ajax_trigger_processing() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            if (!class_exists('AI_QA_System_Monitor')) {
                wp_send_json_error('系统监控类未加载');
                return;
            }

            $result = AI_QA_System_Monitor::trigger_background_processing();
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        } catch (Exception $e) {
            wp_send_json_error('触发处理失败：' . $e->getMessage());
        }
    }
    
    /**
     * 重新调度所有任务
     */
    public function ajax_reschedule_tasks() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            if (!class_exists('AI_QA_System_Monitor')) {
                wp_send_json_error('系统监控类未加载');
                return;
            }

            $results = AI_QA_System_Monitor::reschedule_all_tasks();
            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error('重新调度失败：' . $e->getMessage());
        }
    }
    
    /**
     * 强制启动处理
     */
    public function ajax_force_start_processing() {
        try {
            if (!check_ajax_referer('ai_qa_generator_nonce', 'nonce', false)) {
                wp_send_json_error('安全验证失败');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('权限不足');
                return;
            }

            if (!class_exists('AI_QA_Background_Processor')) {
                wp_send_json_error('后台处理器类未加载');
                return;
            }

            $result = AI_QA_Background_Processor::force_start_processing();
            
            if ($result) {
                wp_send_json_success('后台处理已强制启动，将在2秒后开始执行');
            } else {
                wp_send_json_error('强制启动失败');
            }
        } catch (Exception $e) {
            wp_send_json_error('强制启动失败：' . $e->getMessage());
        }
    }
    
    // --- 测试方法 ---

    /**
     * 测试AJAX处理函数
     */
    public function ajax_test() {
        error_log('🧪 测试AJAX处理函数被调用', 0);
        wp_send_json_success(array(
            'message' => 'AJAX处理正常工作',
            'is_admin' => is_admin(),
            'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        ));
    }
}
?>