<?php
/**
 * 历史页面渲染器类，负责渲染统计与历史页面
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_History_Page_Renderer {

    /**
     * 渲染历史与统计页面
     */
    public function render_history_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 检查必要的类是否已加载
        $missing_classes = array();
        if (!class_exists('AI_QA_History_Manager')) $missing_classes[] = 'AI_QA_History_Manager';
        if (!class_exists('AI_QA_Database_Manager')) $missing_classes[] = 'AI_QA_Database_Manager';
        if (!class_exists('AI_QA_Cache_Manager')) $missing_classes[] = 'AI_QA_Cache_Manager';
        
        if (!empty($missing_classes)) {
            echo '<div class="error"><p>错误：以下类未加载：' . implode(', ', $missing_classes) . '</p></div>';
            return;
        }
        
        // 获取统计数据
        $stats = AI_QA_History_Manager::get_statistics();
        $db_stats = AI_QA_Database_Manager::get_processing_stats();
        $cache_stats = AI_QA_Cache_Manager::get_cache_stats();
        
        // 获取处理历史
        $history = AI_QA_History_Manager::get_history(50);
        ?>
        <div class="wrap">
            <h1>统计与历史</h1>
            
            <!-- 统计信息卡片 -->
            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top: 0; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 10px;">📊 总体统计</h3>
                    <p><strong>总处理文章：</strong><?php echo $stats['total_processed']; ?> 篇</p>
                    <p><strong>生成问答：</strong><?php echo $stats['total_qa_created']; ?> 个</p>
                    <p><strong>最近30天：</strong><?php echo $stats['last_30_days']; ?> 篇</p>
                    <p><small style="color: #666;">平均每篇生成 <?php echo $stats['total_processed'] > 0 ? round($stats['total_qa_created'] / $stats['total_processed'], 1) : 0; ?> 个问答</small></p>
                </div>
                
                <div class="stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top: 0; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 10px;">⚡ 性能统计</h3>
                    <p><strong>成功率：</strong><?php echo $db_stats ? round(($db_stats['successful'] / max($db_stats['total_processed'], 1)) * 100, 1) : 0; ?>%</p>
                    <p><strong>平均问答数：</strong><?php echo $db_stats ? round($db_stats['avg_qa_per_post'], 1) : 0; ?></p>
                    <p><strong>缓存命中：</strong><?php echo $cache_stats['cached_items']; ?> 项</p>
                    <p><small style="color: #666;">缓存节省了 <?php echo $cache_stats['cached_items']; ?> 次API调用</small></p>
                </div>
                
                <div class="stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top: 0; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 10px;">🤖 模型使用</h3>
                    <p><strong>最常用：</strong><?php echo esc_html($stats['most_used_model']); ?></p>
                    <?php if (!empty($stats['model_usage'])): ?>
                        <?php foreach (array_slice($stats['model_usage'], 0, 3) as $model => $count): ?>
                            <p><small style="color: #666;"><?php echo esc_html($model); ?>: <?php echo $count; ?> 次</small></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php if (class_exists('AI_QA_Background_Processor')): ?>
                    <?php $queue_stats = AI_QA_Background_Processor::get_queue_stats(); ?>
                    <div class="stats-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h3 style="margin-top: 0; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 10px;">🔄 队列状态</h3>
                        <p><strong>待处理批次：</strong><?php echo $queue_stats['pending_batches']; ?> 个</p>
                        <p><strong>已完成批次：</strong><?php echo $queue_stats['completed_batches']; ?> 个</p>
                        <p><strong>待处理文章：</strong><?php echo $queue_stats['total_posts'] - $queue_stats['processed_posts']; ?> 篇</p>
                        <p><small style="color: #666;">总共处理了 <?php echo $queue_stats['processed_posts']; ?> 篇文章</small></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- 处理历史列表 -->
            <h2>📋 文章处理状态</h2>
            
            <?php
            // 获取所有相关文章的状态
            $all_posts = array();
            
            // 从历史记录中获取文章
            foreach ($history as $record) {
                $post_id = $record['source_post_id'];
                if (!isset($all_posts[$post_id])) {
                    $all_posts[$post_id] = array(
                        'post_id' => $post_id,
                        'post_title' => $record['source_post_title'],
                        'post_type' => $record['source_post_type'] ?? 'post',
                        'processing_time' => $record['processing_time'],
                        'created_count' => $record['created_count'],
                        'model' => $record['settings_used']['ai_model']
                    );
                }
            }
            
            // 获取失败的文章
            if (class_exists('AI_QA_Background_Processor')) {
                $failed_posts = AI_QA_Background_Processor::get_failed_posts(20);
                foreach ($failed_posts as $failed_post) {
                    $post_id = $failed_post->ID;
                    if (!isset($all_posts[$post_id])) {
                        $all_posts[$post_id] = array(
                            'post_id' => $post_id,
                            'post_title' => $failed_post->post_title,
                            'post_type' => $failed_post->post_type,
                            'processing_time' => $failed_post->failed_time,
                            'created_count' => 0,
                            'model' => '',
                            'error_message' => $failed_post->error_message,
                            'fail_count' => $failed_post->fail_count ?? 1
                        );
                    }
                }
            }
            
            // 获取正在队列中的文章
            if (class_exists('AI_QA_Background_Processor')) {
                $posts_in_queue = AI_QA_Background_Processor::get_posts_in_queue();
                foreach ($posts_in_queue as $queue_post_id) {
                    if (!isset($all_posts[$queue_post_id])) {
                        $post = get_post($queue_post_id);
                        if ($post) {
                            $all_posts[$queue_post_id] = array(
                                'post_id' => $queue_post_id,
                                'post_title' => $post->post_title,
                                'post_type' => $post->post_type,
                                'processing_time' => '',
                                'created_count' => 0,
                                'model' => ''
                            );
                        }
                    }
                }
            }
            ?>
            
            <?php if (empty($all_posts)): ?>
                <div style="background: #f9f9f9; padding: 20px; border-radius: 4px; text-align: center; color: #666;">
                    <p>暂无处理记录</p>
                    <p><small>开始处理文章后，状态记录将显示在这里</small></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 25%;">文章信息</th>
                            <th style="width: 15%;">处理状态</th>
                            <th style="width: 15%;">生成结果</th>
                            <th style="width: 20%;">处理时间</th>
                            <th style="width: 15%;">使用模型</th>
                            <th style="width: 10%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_posts as $post_data): 
                            $post_id = $post_data['post_id'];
                            $status = AI_QA_Background_Processor::get_post_processing_status($post_id);
                            $error_message = get_post_meta($post_id, 'ai_processing_error', true);
                            $fail_count = get_post_meta($post_id, 'ai_fail_count', true);
                        ?>
                            <tr data-post-id="<?php echo $post_id; ?>" class="post-status-<?php echo $status; ?>">
                                <td>
                                    <strong><?php echo esc_html($post_data['post_title']); ?></strong>
                                    <?php 
                                    $batch_id = get_post_meta($post_id, 'ai_qa_batch_id', true);
                                    if ($batch_id) {
                                        echo '<br><small style="color: #777;">批次ID: ' . esc_html(substr($batch_id, -8)) . '</small>';
                                    }
                                    ?>
                                    <br>
                                    <small style="color: #666;">
                                        ID: <?php echo $post_id; ?>
                                        <?php if ($post_data['post_type'] !== 'post'): ?>
                                            | 类型: <?php echo esc_html($post_data['post_type']); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php
                                    switch ($status) {
                                        case 'pending':
                                            echo '<span class="status-badge status-pending">⏳ 等待处理</span>';
                                            break;
                                        case 'processing':
                                            echo '<span class="status-badge status-processing">🔄 处理中</span>';
                                            $start_time = get_post_meta($post_id, 'ai_processing_start_time', true);
                                            if ($start_time) {
                                                echo '<br><small style="color: #666;">开始: ' . date('H:i:s', strtotime($start_time)) . '</small>';
                                            }
                                            break;
                                        case 'completed':
                                            echo '<span class="status-badge status-completed">✅ 已完成</span>';
                                            break;
                                        case 'failed':
                                            echo '<span class="status-badge status-failed">❌ 处理失败</span>';
                                            if ($fail_count > 1) {
                                                echo '<br><small style="color: #d63638;">失败次数: ' . $fail_count . '</small>';
                                            }
                                            break;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($status === 'completed'): ?>
                                        <strong style="color: #46b450;"><?php echo $post_data['created_count']; ?></strong> 个问答
                                        <br><small style="color: #46b450;">✅ 成功生成</small>
                                    <?php elseif ($status === 'failed'): ?>
                                        <span style="color: #d63638;">0 个问答</span>
                                        <br><small style="color: #d63638;">❌ 生成失败</small>
                                    <?php elseif ($status === 'processing'): ?>
                                        <span style="color: #ffb900;">处理中...</span>
                                        <br><small style="color: #666;">请等待</small>
                                    <?php else: ?>
                                        <span style="color: #999;">待处理</span>
                                        <br><small style="color: #666;">等待开始</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($post_data['processing_time'])): ?>
                                        <?php echo date('Y-m-d H:i:s', strtotime($post_data['processing_time'])); ?>
                                        <br><small style="color: #666;">
                                            <?php echo human_time_diff(strtotime($post_data['processing_time']), current_time('timestamp')); ?>前
                                        </small>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($post_data['model'])): ?>
                                        <?php 
                                        $model_name = $post_data['model'];
                                        if (strpos($model_name, 'deepseek') !== false) {
                                            echo '🧠 DeepSeek';
                                        } elseif (strpos($model_name, 'qwen') !== false) {
                                            echo '🤖 Qwen';
                                        } elseif (strpos($model_name, 'llama') !== false) {
                                            echo '🦙 Llama';
                                        } else {
                                            echo '🤖 ' . esc_html(substr($model_name, 0, 15));
                                        }
                                        ?>
                                        <br><small style="color: #666;"><?php echo esc_html(substr($model_name, 0, 25)); ?></small>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($status === 'failed'): ?>
                                        <button class="button button-small retry-post" 
                                                data-post-id="<?php echo $post_id; ?>"
                                                title="重新处理此文章">
                                            🔄 重试
                                        </button>
                                        <?php if (!empty($error_message)): ?>
                                            <br><small style="color: #d63638; cursor: help;" 
                                                     title="<?php echo esc_attr($error_message); ?>">
                                                查看错误
                                            </small>
                                        <?php endif; ?>
                                    <?php elseif ($status === 'completed'): ?>
                                        <span style="color: #46b450; font-size: 12px;">✓ 完成</span>
                                    <?php elseif ($status === 'processing'): ?>
                                        <span style="color: #ffb900; font-size: 12px;">⏳ 处理中</span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">⏳ 等待</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0;">状态说明：</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px;">
                        <div><span class="status-badge status-pending">⏳ 等待处理</span> - 文章在队列中等待</div>
                        <div><span class="status-badge status-processing">🔄 处理中</span> - 正在生成问答</div>
                        <div><span class="status-badge status-completed">✅ 已完成</span> - 成功生成问答</div>
                        <div><span class="status-badge status-failed">❌ 处理失败</span> - 多次重试后失败，可点击重试</div>
                    </div>
                </div>
                
                <?php if (count($all_posts) >= 50): ?>
                    <p style="text-align: center; margin-top: 20px; color: #666;">
                        <small>显示最近50条记录</small>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <style>
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }
        
        .status-pending {
            background: #f0f0f0;
            color: #666;
        }
        
        .status-processing {
            background: #fff3cd;
            color: #856404;
            animation: pulse 2s infinite;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .post-status-processing {
            background-color: #fff8e1;
        }
        
        .post-status-failed {
            background-color: #ffebee;
        }
        
        .post-status-completed {
            background-color: #f1f8e9;
        }
        
        .retry-post {
            background: #0073aa;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }
        
        .retry-post:hover {
            background: #005a87;
        }
        
        .retry-post:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // 重试失败的文章
            $('.retry-post').on('click', function() {
                var $button = $(this);
                var postId = $button.data('post-id');
                
                if (!confirm('确定要重试处理这篇文章吗？')) {
                    return;
                }
                
                $button.prop('disabled', true).text('重试中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ai_qa_retry_failed_post',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('ai_qa_generator_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data);
                            
                            // 更新行状态
                            var $row = $button.closest('tr');
                            $row.removeClass('post-status-failed').addClass('post-status-processing');
                            $row.find('.status-badge').removeClass('status-failed').addClass('status-processing')
                                .html('🔄 处理中');
                            $row.find('td:nth-child(3)').html('<span style="color: #ffb900;">处理中...</span><br><small style="color: #666;">请等待</small>');
                            $button.closest('td').html('<span style="color: #ffb900; font-size: 12px;">⏳ 处理中</span>');
                            
                            // 5秒后刷新页面查看最新状态
                            setTimeout(function() {
                                location.reload();
                            }, 5000);
                        } else {
                            alert('❌ 重试失败：' + response.data);
                            $button.prop('disabled', false).text('🔄 重试');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('重试请求失败:', {status: xhr.status, error: error});
                        alert('❌ 请求失败：' + error);
                        $button.prop('disabled', false).text('🔄 重试');
                    }
                });
            });
            
            // 每30秒自动刷新状态（如果有处理中的文章）
            if ($('.status-processing').length > 0) {
                setTimeout(function() {
                    location.reload();
                }, 30000);
            }
        });
        </script>
        <?php
    }
}
?>