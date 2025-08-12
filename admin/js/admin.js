jQuery(document).ready(function ($) {
    // 标签页切换
    $('.nav-tab-wrapper a').click(function (e) {
        e.preventDefault();
        var target = $(this).attr('href').substring(1);

        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.tab-content').hide();
        $('#' + target).show();

        // 如果切换到处理页面，自动更新选中数量
        if (target === 'process') {
            updateSelectedCount();
        }
    });

    // 全选/取消全选
    $('#select-all-posts').on('change', function () {
        $('.post-checkbox:not(:disabled)').prop('checked', $(this).prop('checked'));
        updateSelectedCount();
    });

    // 单个复选框变化
    $(document).on('change', '.post-checkbox', function () {
        updateSelectedCount();
        // 如果取消某个复选框，也要更新全选框状态
        updateSelectAllState();
    });

    // 更新全选框状态
    function updateSelectAllState() {
        var totalCheckboxes = $('.post-checkbox:not(:disabled)').length;
        var checkedCheckboxes = $('.post-checkbox:checked').length;
        $('#select-all-posts').prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
    }

    // 更新选中数量
    function updateSelectedCount() {
        var count = $('.post-checkbox:checked').length;
        $('#selected-count').text(count);
        $('#generate-qa').prop('disabled', count === 0);
    }

    // 页面加载时初始化状态
    updateSelectedCount();
    updateSelectAllState();

    // 处理选中的文章
    $('#generate-qa').on('click', function (e) {
        e.preventDefault();

        var selectedPosts = [];
        $('.post-checkbox:checked').each(function () {
            selectedPosts.push($(this).val());
        });

        if (selectedPosts.length === 0) {
            alert('请先选择要处理的文章');
            return;
        }

        // 统一使用后台队列处理所有文章
        var message = '检测到 ' + selectedPosts.length + ' 篇文章处理任务\n\n';
        message += '文章将使用后台队列处理：\n';
        message += '✅ 固定资源消耗，不会导致服务器过载\n';
        message += '✅ 支持大批量处理（100+ 篇文章）\n';
        message += '✅ 关闭页面后继续在后台运行\n';
        message += '✅ 每2分钟处理1篇，保持系统稳定\n\n';
        message += '预计完成时间：约 ' + Math.ceil(selectedPosts.length * 2 / 60) + ' 小时';
        
        if (confirm(message + '\n\n点击确定启动后端处理')) {
            startBackgroundProcessing(selectedPosts);
            return;
        }
    });

    // 后台批量处理
    function startBackgroundProcessing(selectedPosts) {
        var $button = $('#generate-qa');
        $button.prop('disabled', true).text('启动中...');
        
        $.ajax({
            url: aiQaGeneratorAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'start_background_batch',
                post_ids: selectedPosts,
                nonce: aiQaGeneratorAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    var message = '✅ 后端队列处理已启动！\n\n';
                    message += '📊 处理统计：\n';
                    message += '• 有效文章：' + response.data.valid_posts + ' 篇\n';
                    message += '• 总选择：' + response.data.total_posts + ' 篇\n';
                    message += '• 预计时间：' + response.data.estimated_time + '\n';
                    message += '• 批次ID：' + response.data.batch_id.substring(response.data.batch_id.length - 8) + '\n';
                    
                    // 显示过滤信息
                    if (response.data.filtered_info) {
                        var filtered = response.data.filtered_info;
                        message += '\n🔍 过滤统计：\n';
                        if (filtered.already_processed > 0) {
                            message += '• 已处理：' + filtered.already_processed + ' 篇\n';
                        }
                        if (filtered.in_queue > 0) {
                            message += '• 队列中：' + filtered.in_queue + ' 篇\n';
                        }
                        if (filtered.invalid_posts > 0) {
                            message += '• 无效文章：' + filtered.invalid_posts + ' 篇\n';
                        }
                    }
                    
                    message += '\n🔧 系统特性：\n';
                    message += '• 固定资源消耗，每次处理1篇文章\n';
                    message += '• 每2分钟自动处理下一篇\n';
                    message += '• 关闭页面后继续在后台运行\n';
                    message += '• 避免502错误和服务器过载\n';
                    message += '• 自动过滤重复和已处理文章\n\n';
                    message += '📈 您可以在下方"后台任务监控"查看实时进度';
                    
                    alert(message);
                    
                    // 清空选择并刷新监控
                    $('.post-checkbox').prop('checked', false);
                    $('#select-all-posts').prop('checked', false);
                    updateSelectedCount();
                    
                    // 立即更新监控状态
                    if ($('#batch-monitor').length > 0) {
                        setTimeout(updateBatchStatus, 1000);
                    }
                } else {
                    alert('❌ 启动批量处理失败：\n' + response.data);
                }
            },
            error: function (xhr, status, error) {
                console.error('启动后台处理失败:', {status: xhr.status, error: error});
                alert('❌ 请求失败：' + error + '\n状态码：' + xhr.status);
            },
            complete: function() {
                $button.prop('disabled', false).text('生成问答');
            }
        });
    }

    // 移除旧的前台处理相关的函数

    // 测试AJAX连接
    $('#test-ajax').on('click', function () {
        var $button = $(this);
        $button.prop('disabled', true).text('测试中...');

        $.ajax({
            url: aiQaGeneratorAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'ai_qa_test',
                nonce: aiQaGeneratorAdmin.nonce
            },
            success: function (response) {
                console.log('测试响应:', response);
                if (response.success) {
                    alert('✅ AJAX连接正常！\n' + JSON.stringify(response.data, null, 2));
                } else {
                    alert('❌ AJAX连接失败：' + JSON.stringify(response));
                }
            },
            error: function (xhr, status, error) {
                console.error('测试失败:', xhr, status, error);
                alert('❌ AJAX测试失败：' + xhr.status + ' ' + error);
            },
            complete: function () {
                $button.prop('disabled', false).text('测试连接');
            }
        });
    });

    // 后台任务监控
    function updateBatchStatus() {
        $.ajax({
            url: aiQaGeneratorAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'get_batch_progress',
                nonce: aiQaGeneratorAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayBatchStatus(response.data);
                } else {
                    $('#batch-status').html('<p>❌ 获取状态失败: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#batch-status').html('<p>❌ 网络错误，无法获取状态</p>');
            }
        });
    }
    
    function displayBatchStatus(batches) {
        var html = '';
        var hasPending = false;
        
        if (Object.keys(batches).length === 0) {
            html = '<p>✅ 当前没有后台任务在运行</p>';
        } else {
            html = '<div class="batch-list">';
            
            for (var batchId in batches) {
                var batch = batches[batchId];
                var statusIcon = '';
                var statusText = '';
                
                switch (batch.status) {
                    case 'pending':
                        statusIcon = '🔄';
                        statusText = '处理中';
                        hasPending = true;
                        break;
                    case 'completed':
                        statusIcon = '✅';
                        statusText = '已完成';
                        break;
                    case 'failed':
                        statusIcon = '❌';
                        statusText = '失败';
                        break;
                    default:
                        statusIcon = '❓';
                        statusText = batch.status;
                }
                
                html += '<div class="batch-item" style="margin: 10px 0; padding: 10px; background: white; border-radius: 4px; border: 1px solid #ddd;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center;">';
                html += '<div>';
                html += '<strong>' + statusIcon + ' 批次 ' + batchId.substring(batchId.length - 8) + '</strong> (' + statusText + ')';
                html += '<br><small>进度: ' + batch.progress + ' (' + batch.progress_percent + '%)</small>';
                if (batch.failed_count > 0) {
                    html += '<br><small style="color: #d63638;">失败: ' + batch.failed_count + ' 篇</small>';
                }
                html += '</div>';
                html += '<div class="progress-bar-mini" style="width: 100px; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden;">';
                html += '<div style="width: ' + batch.progress_percent + '%; height: 100%; background: #0073aa; transition: width 0.3s;"></div>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            }
            
            html += '</div>';
        }
        
        $('#batch-status').html(html);
        
        // 如果有正在处理的任务，5秒后自动刷新
        if (hasPending) {
            setTimeout(updateBatchStatus, 5000);
        }
    }
    
    // 页面加载时检查状态
    if ($('#batch-monitor').length > 0) {
        updateBatchStatus();
    }
    
    // 手动刷新按钮
    $('#refresh-batch-status').on('click', function() {
        $(this).prop('disabled', true).text('刷新中...');
        updateBatchStatus();
        setTimeout(function() {
            $('#refresh-batch-status').prop('disabled', false).text('刷新状态');
        }, 1000);
    });
    
    // 定期刷新文章状态
    function refreshPostStatus() {
        var postIds = [];
        $('.post-checkbox').each(function() {
            postIds.push($(this).val());
        });
        
        if (postIds.length === 0) return;
        
        $.ajax({
            url: aiQaGeneratorAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'ai_qa_refresh_post_status',
                post_ids: postIds,
                nonce: aiQaGeneratorAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    updatePostStatusDisplay(response.data);
                }
            },
            error: function() {
                // 静默失败，不影响用户体验
            }
        });
    }
    
    function updatePostStatusDisplay(statusData) {
        for (var postId in statusData) {
            var statusInfo = statusData[postId];
            var $row = $('.post-checkbox[value="' + postId + '"]').closest('tr');
            var $checkbox = $('.post-checkbox[value="' + postId + '"]');
            var $statusCell = $row.find('td:nth-child(5)');

            // 移除并根据新状态更新行class
            $row.removeClass('processed-post in-queue-post failed-post');

            switch (statusInfo.status) {
                case 'completed':
                    $row.addClass('processed-post');
                    $checkbox.prop('disabled', true).attr('title', '此文章已处理完成');
                    $statusCell.html('<span style="color: #46b450; font-weight: bold;">✅ 已完成</span>');
                    break;
                
                case 'processing':
                    $row.addClass('in-queue-post');
                    $checkbox.prop('disabled', true).attr('title', '此文章正在处理中');
                    $statusCell.html('<span style="color: #0073aa; font-weight: bold;">🔄 处理中</span><br><small>等待后台处理</small>');
                    break;

                case 'failed':
                    $row.addClass('failed-post');
                    $checkbox.prop('disabled', false).attr('title', '此文章处理失败，可重新选择处理');
                    
                    var failHtml = '<span style="color: #d63638; font-weight: bold;">❌ 处理失败</span>';
                    if (statusInfo.fail_count > 1) {
                        failHtml += '<br><small style="color: #d63638;">失败 ' + statusInfo.fail_count + ' 次</small>';
                    }
                    if (statusInfo.error_message) {
                        // 使用jQuery来创建元素并设置属性，可以避免手动处理HTML实体编码
                        var $errorLink = $('<small style="color: #d63638;"></small>')
                                            .attr('title', statusInfo.error_message)
                                            .text('查看错误');
                        failHtml += '<br>' + $('<div>').append($errorLink).html();
                    }
                    $statusCell.html(failHtml);
                    break;

                case 'pending':
                default:
                    $checkbox.prop('disabled', false).removeAttr('title');
                    $statusCell.html('<span style="color: #999;">⏳ 待处理</span>');
                    break;
            }
        }

        // 更新选中数量和全选状态
        updateSelectedCount();
        updateSelectAllState();
    }
    
    // 每30秒刷新一次文章状态
    if ($('.post-checkbox').length > 0) {
        setInterval(refreshPostStatus, 30000);
    }

    // 分页功能
    $(document).on('click', '.pagination-link', function (e) {
        e.preventDefault();
        var page = $(this).data('page');
        $('#current-page').val(page);
        $('#posts-filter').submit();
    });
});
