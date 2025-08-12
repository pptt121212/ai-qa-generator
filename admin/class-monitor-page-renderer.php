<?php
/**
 * 监控页面渲染器类，负责渲染系统监控页面
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_Monitor_Page_Renderer {

    /**
     * 渲染系统监控页面
     */
    public function render_monitor_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!class_exists('AI_QA_System_Monitor')) {
            echo '<div class="error"><p>错误：AI_QA_System_Monitor类未加载</p></div>';
            return;
        }
        
        $system_status = AI_QA_System_Monitor::check_system_status();
        $performance_stats = AI_QA_System_Monitor::get_performance_stats();
        ?>
        <div class="wrap">
            <h1>系统监控</h1>
            
            <!-- 系统健康状态 -->
            <div class="system-health" style="margin-bottom: 20px;">
                <?php
                $health_color = array(
                    'good' => '#46b450',
                    'warning' => '#ffb900', 
                    'critical' => '#dc3232'
                );
                $health_icon = array(
                    'good' => '✅',
                    'warning' => '⚠️',
                    'critical' => '❌'
                );
                ?>
                <div style="padding: 15px; background: <?php echo $health_color[$system_status['system_health']]; ?>; color: white; border-radius: 4px;">
                    <h2 style="margin: 0; color: white;">
                        <?php echo $health_icon[$system_status['system_health']]; ?> 
                        系统状态: <?php echo ucfirst($system_status['system_health']); ?>
                    </h2>
                </div>
            </div>
            
            <!-- WordPress Cron状态 -->
            <div class="cron-status" style="margin-bottom: 20px;">
                <h3>🕐 WordPress Cron状态</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <p><strong>Cron启用状态:</strong> 
                        <?php echo $system_status['cron_enabled'] ? '✅ 已启用' : '❌ 已禁用'; ?>
                    </p>
                    
                    <h4>已调度的任务:</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>任务名称</th>
                                <th>调度状态</th>
                                <th>下次运行</th>
                                <th>剩余时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_status['scheduled_tasks'] as $hook => $task): ?>
                                <tr>
                                    <td><?php echo esc_html($task['name']); ?></td>
                                    <td><?php echo $task['scheduled'] ? '✅ 已调度' : '❌ 未调度'; ?></td>
                                    <td><?php echo esc_html($task['next_run']); ?></td>
                                    <td><?php echo esc_html($task['time_until']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php
                    // 获取处理器状态
                    $processor_status = array();
                    if (class_exists('AI_QA_Background_Processor')) {
                        $processor_status = AI_QA_Background_Processor::get_processor_status();
                    }
                    ?>
                    
                    <?php if (!empty($processor_status)): ?>
                    <h4>处理器状态:</h4>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; margin: 10px 0;">
                        <p><strong>当前状态:</strong> 
                            <?php echo $processor_status['is_processing'] ? '🔄 正在处理' : '⏸️ 空闲'; ?>
                        </p>
                        <?php if ($processor_status['is_processing']): ?>
                            <p><strong>处理开始时间:</strong> <?php echo $processor_status['processing_since']; ?></p>
                        <?php endif; ?>
                        <p><strong>下次调度时间:</strong> 
                            <?php echo $processor_status['next_scheduled'] ? $processor_status['next_scheduled'] : '未调度'; ?>
                        </p>
                        <p><strong>最后处理时间:</strong> 
                            <?php echo $processor_status['last_batch_time'] ? $processor_status['last_batch_time'] : '从未运行'; ?>
                        </p>
                        <?php if ($processor_status['time_since_last']): ?>
                            <p><strong>距离上次处理:</strong> 
                                <?php 
                                $minutes = floor($processor_status['time_since_last'] / 60);
                                echo $minutes . ' 分钟前';
                                if ($minutes > 5) {
                                    echo ' <span style="color: #d63638;">⚠️ 可能存在问题</span>';
                                }
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 15px;">
                        <button id="reschedule-tasks" class="button button-primary">重新调度所有任务</button>
                        <button id="trigger-processing" class="button">手动触发处理</button>
                        <button id="force-start-processing" class="button button-secondary">强制启动处理</button>
                        <button id="refresh-status" class="button">刷新状态</button>
                    </div>
                </div>
            </div>
            
            <!-- 队列状态 -->
            <div class="queue-status" style="margin-bottom: 20px;">
                <h3>📋 队列状态</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <?php if (isset($system_status['queue_status']['error'])): ?>
                        <p style="color: #dc3232;">❌ <?php echo esc_html($system_status['queue_status']['error']); ?></p>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div>
                                <strong>总批次数:</strong> <?php echo $system_status['queue_status']['total_batches']; ?>
                            </div>
                            <div>
                                <strong>待处理批次:</strong> <?php echo $system_status['queue_status']['pending_batches']; ?>
                            </div>
                            <div>
                                <strong>已完成批次:</strong> <?php echo $system_status['queue_status']['completed_batches']; ?>
                            </div>
                            <div>
                                <strong>失败批次:</strong> <?php echo $system_status['queue_status']['failed_batches']; ?>
                            </div>
                            <div>
                                <strong>总文章数:</strong> <?php echo $system_status['queue_status']['total_posts']; ?>
                            </div>
                            <div>
                                <strong>已处理文章:</strong> <?php echo $system_status['queue_status']['processed_posts']; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 性能统计 -->
            <div class="performance-stats" style="margin-bottom: 20px;">
                <h3>📊 性能统计（最近24小时）</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <strong>处理成功:</strong> <?php echo $performance_stats['last_24h_processed']; ?> 篇
                        </div>
                        <div>
                            <strong>处理失败:</strong> <?php echo $performance_stats['last_24h_failed']; ?> 篇
                        </div>
                        <div>
                            <strong>成功率:</strong> <?php echo $performance_stats['success_rate']; ?>%
                        </div>
                        <div>
                            <strong>平均处理时间:</strong> <?php echo $performance_stats['average_processing_time']; ?> 秒
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 资源使用情况 -->
            <div class="resource-usage" style="margin-bottom: 20px;">
                <h3>💻 资源使用情况</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <strong>当前内存使用:</strong> <?php echo $system_status['resource_usage']['memory_usage']; ?>
                        </div>
                        <div>
                            <strong>内存限制:</strong> <?php echo $system_status['resource_usage']['memory_limit']; ?>
                        </div>
                        <div>
                            <strong>最大执行时间:</strong> <?php echo $system_status['resource_usage']['max_execution_time']; ?>
                        </div>
                        <div>
                            <strong>PHP版本:</strong> <?php echo $system_status['resource_usage']['php_version']; ?>
                        </div>
                        <div>
                            <strong>WordPress版本:</strong> <?php echo $system_status['resource_usage']['wordpress_version']; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 最后处理信息 -->
            <div class="last-processing" style="margin-bottom: 20px;">
                <h3>⏰ 最后处理信息</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <p><strong>当前处理状态:</strong> 
                        <?php echo $system_status['last_processing']['is_processing'] ? '🔄 正在处理' : '⏸️ 空闲'; ?>
                    </p>
                    <?php if ($system_status['last_processing']['is_processing']): ?>
                        <p><strong>处理开始时间:</strong> <?php echo $system_status['last_processing']['processing_since']; ?></p>
                    <?php endif; ?>
                    <p><strong>最后批次时间:</strong> <?php echo $system_status['last_processing']['last_batch_time']; ?></p>
                    <p><strong>距离上次处理:</strong> <?php echo $system_status['last_processing']['time_since_last']; ?></p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#reschedule-tasks').click(function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('重新调度中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ai_qa_reschedule_tasks',
                        nonce: '<?php echo wp_create_nonce('ai_qa_generator_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ 所有任务已重新调度');
                            location.reload();
                        } else {
                            alert('❌ 重新调度失败: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('❌ 请求失败');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('重新调度所有任务');
                    }
                });
            });
            
            $('#trigger-processing').click(function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('触发中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ai_qa_trigger_processing',
                        nonce: '<?php echo wp_create_nonce('ai_qa_generator_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data);
                        } else {
                            alert('❌ ' + response.data);
                        }
                    },
                    error: function() {
                        alert('❌ 请求失败');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('手动触发处理');
                    }
                });
            });
            
            $('#force-start-processing').click(function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('强制启动中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ai_qa_force_start_processing',
                        nonce: '<?php echo wp_create_nonce('ai_qa_generator_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data);
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            alert('❌ ' + response.data);
                        }
                    },
                    error: function() {
                        alert('❌ 请求失败');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('强制启动处理');
                    }
                });
            });
            
            $('#refresh-status').click(function() {
                location.reload();
            });
        });
        </script>
        <?php
    }
}
?>