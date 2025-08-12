<div class="wrap">
    <h1>AI问答生成器 - 文章处理</h1>

    <!-- 文章处理部分 -->
    <div class="ai-qa-generator-process">

        <?php if (empty($posts)): ?>
            <p>没有找到可处理的文章。请确保已选择源分类，且分类下有未处理的文章。</p>
        <?php else: ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <button id="generate-qa" class="button button-primary">生成问答</button>
                    <button id="test-ajax" class="button" style="margin-left: 10px;">测试连接</button>
                    <span id="selected-count-wrapper">
                        已选择: <span id="selected-count">0</span> 篇
                    </span>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column column-cb check-column">
                            <input type="checkbox" id="select-all-posts">
                        </th>
                        <th class="manage-column">标题</th>
                        <th class="manage-column">类型</th>
                        <th class="manage-column">分类/标签</th>
                        <th class="manage-column">处理状态</th>
                        <th class="manage-column">日期</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // 获取正在队列中的文章ID
                    $posts_in_queue = array();
                    if (class_exists('AI_QA_Background_Processor')) {
                        $posts_in_queue = AI_QA_Background_Processor::get_posts_in_queue();
                    }
                    
                    foreach ($posts as $post): 
                        $is_processed = get_post_meta($post->ID, 'ai_processed', true);
                        $is_in_queue = in_array($post->ID, $posts_in_queue);
                        $generated_qa_posts = get_post_meta($post->ID, 'generated_qa_posts', true);
                        $qa_count = is_array($generated_qa_posts) ? count($generated_qa_posts) : 0;
                        
                        // 获取详细的处理状态
                        if (class_exists('AI_QA_Background_Processor')) {
                            $detailed_status = AI_QA_Background_Processor::get_post_processing_status($post->ID);
                        } else {
                            $detailed_status = $is_processed ? 'completed' : ($is_in_queue ? 'processing' : 'pending');
                        }
                        
                        $row_class = '';
                        if ($detailed_status === 'completed') {
                            $row_class = 'processed-post';
                        } elseif ($detailed_status === 'processing') {
                            $row_class = 'in-queue-post';
                        } elseif ($detailed_status === 'failed') {
                            $row_class = 'failed-post';
                        }
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td class="check-column">
                                <input type="checkbox" 
                                       class="post-checkbox" 
                                       value="<?php echo esc_attr($post->ID); ?>"
                                       <?php 
                                       if ($detailed_status === 'completed') {
                                           echo 'disabled title="此文章已处理完成"';
                                       } elseif ($detailed_status === 'processing') {
                                           echo 'disabled title="此文章正在处理中"';
                                       } elseif ($detailed_status === 'failed') {
                                           echo 'title="此文章处理失败，可重新选择处理"';
                                       } else {
                                           echo 'title="选择此文章进行处理"';
                                       }
                                       ?>>
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">
                                        <?php echo esc_html($post->post_title); ?>
                                    </a>
                                </strong>
                                <?php 
                                $batch_id = get_post_meta($post->ID, 'ai_qa_batch_id', true);
                                if ($batch_id) {
                                    echo '<br><small style="color: #777;">批次ID: ' . esc_html(substr($batch_id, -8)) . '</small>';
                                }
                                ?>
                                <?php if ($is_processed): ?>
                                    <span class="processed-badge" style="background: #46b450; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 8px;">已处理</span>
                                <?php elseif ($is_in_queue): ?>
                                    <span class="in-queue-badge" style="background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 8px;">队列中</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $post_type_obj = get_post_type_object($post->post_type);
                                echo esc_html($post_type_obj ? $post_type_obj->label : $post->post_type);
                                ?>
                            </td>
                            <td>
                                <?php
                                $taxonomies = get_object_taxonomies($post->post_type);
                                $all_terms = array();
                                foreach ($taxonomies as $taxonomy) {
                                    $terms = wp_get_post_terms($post->ID, $taxonomy);
                                    if (!is_wp_error($terms) && !empty($terms)) {
                                        foreach ($terms as $term) {
                                            $all_terms[] = $term->name;
                                        }
                                    }
                                }
                                echo esc_html(implode(', ', array_slice($all_terms, 0, 3)));
                                if (count($all_terms) > 3) {
                                    echo '...';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                // 获取详细的处理状态
                                if (class_exists('AI_QA_Background_Processor')) {
                                    $detailed_status = AI_QA_Background_Processor::get_post_processing_status($post->ID);
                                } else {
                                    $detailed_status = $is_processed ? 'completed' : ($is_in_queue ? 'processing' : 'pending');
                                }
                                
                                switch ($detailed_status) {
                                    case 'completed':
                                        echo '<span style="color: #46b450; font-weight: bold;">✅ 已完成</span>';
                                        if ($qa_count > 0) {
                                            echo '<br><small>生成 ' . $qa_count . ' 个问答</small>';
                                            echo '<br><small>' . date('Y-m-d H:i', strtotime($is_processed)) . '</small>';
                                        }
                                        break;
                                    case 'processing':
                                        echo '<span style="color: #0073aa; font-weight: bold;">🔄 处理中</span>';
                                        $start_time = get_post_meta($post->ID, 'ai_processing_start_time', true);
                                        if ($start_time) {
                                            echo '<br><small>开始: ' . date('H:i:s', strtotime($start_time)) . '</small>';
                                        } else {
                                            echo '<br><small>等待后台处理</small>';
                                        }
                                        break;
                                    case 'failed':
                                        echo '<span style="color: #d63638; font-weight: bold;">❌ 处理失败</span>';
                                        $fail_count = get_post_meta($post->ID, 'ai_fail_count', true);
                                        if ($fail_count > 1) {
                                            echo '<br><small style="color: #d63638;">失败 ' . $fail_count . ' 次</small>';
                                        }
                                        $error_message = get_post_meta($post->ID, 'ai_processing_error', true);
                                        if ($error_message) {
                                            echo '<br><small style="color: #d63638;" title="' . esc_attr($error_message) . '">查看错误</small>';
                                        }
                                        break;
                                    default: // pending
                                        echo '<span style="color: #999;">⏳ 待处理</span>';
                                        break;
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo get_the_date('Y-m-d H:i:s', $post); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
            </div>
        <?php endif; ?>
        

        
        <!-- 后台任务监控 -->
        <div id="batch-monitor" style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-radius: 4px; border-left: 4px solid #0073aa;">
            <h3>🔄 后台任务监控</h3>
            <div id="batch-status">
                <p>正在检查后台任务状态...</p>
            </div>
            <button id="refresh-batch-status" class="button button-small">刷新状态</button>
        </div>

        <!-- 快速统计 -->
        <?php 
        if (class_exists('AI_QA_History_Manager')) {
            $quick_stats = AI_QA_History_Manager::get_statistics();
        } else {
            $quick_stats = array('total_processed' => 0);
        }
        if ($quick_stats['total_processed'] > 0): 
        ?>
        <div class="quick-stats" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
            <h3>📊 处理统计</h3>
            <p>
                已处理 <strong><?php echo $quick_stats['total_processed']; ?></strong> 篇文章，
                生成 <strong><?php echo $quick_stats['total_qa_created']; ?></strong> 个问答，
                最近30天处理 <strong><?php echo $quick_stats['last_30_days']; ?></strong> 篇
            </p>
            <p><a href="<?php echo admin_url('admin.php?page=ai-qa-history'); ?>">查看详细历史 →</a></p>
        </div>
        <?php endif; ?>
    </div>
</div>
