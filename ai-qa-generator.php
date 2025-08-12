<?php
/**
 * Plugin Name: AI QA Generator
 * Plugin URI: https://www.kdjingpai.com
 * Description: 一个强大的WordPress插件，可以将您的文章内容自动转换为问答形式。支持多种AI模型（如DeepSeek、Qwen等），可自定义源文章类型和分类，灵活设置目标文章类型和分类。具备后台批量处理、进度监控、失败重试、系统状态监控等功能，帮助您轻松创建大量高质量的问答内容，提升网站SEO效果和用户互动性。
 * Version: 1.1.0
 * Author: AI TOOL
 * Author URI: https://www.kdjingpai.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-qa-generator
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_QA_GENERATOR_VERSION', '1.1.0');
define('AI_QA_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_QA_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// 添加错误记录函数
if (!function_exists('ai_qa_log_error')) {
    function ai_qa_log_error($message, $data = array()) {
        $log_message = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($data)) {
            $log_message .= "\nData: " . print_r($data, true);
        }
        
        // 确保WP_CONTENT_DIR已定义
        $log_file = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/ai-qa-debug.log' : '/tmp/ai-qa-debug.log';
        error_log($log_message . "\n", 3, $log_file);
    }
}

// 加载依赖项和初始化功能会在下面的代码中定义
function ai_qa_generator_load_dependencies() {
    $required_files = array(
        'includes/class-error-handler.php',
        'includes/class-cache-manager.php',
        'includes/class-history-manager.php',
        'includes/class-background-processor.php',
        'includes/class-database-manager.php',
        'includes/class-post-processor.php',
        'includes/class-ai-processor.php',
        'includes/class-qa-creator.php',
        'includes/class-system-monitor.php',
        'admin/class-admin.php'
    );

    $loaded_files = array();
    $failed_files = array();

    foreach ($required_files as $file) {
        $file_path = AI_QA_GENERATOR_PLUGIN_DIR . $file;
        if (!file_exists($file_path)) {
            ai_qa_log_error('Required file not found: ' . $file_path);
            $failed_files[] = $file;
            continue;
        }
        
        try {
            require_once $file_path;
            $loaded_files[] = $file;
            ai_qa_log_error('Successfully loaded: ' . $file);
        } catch (Exception $e) {
            ai_qa_log_error('Error loading file: ' . $file, array(
                'error' => $e->getMessage(),
                'file' => $file_path
            ));
            $failed_files[] = $file;
        } catch (ParseError $e) {
            ai_qa_log_error('Parse error in file: ' . $file, array(
                'error' => $e->getMessage(),
                'file' => $file_path,
                'line' => $e->getLine()
            ));
            $failed_files[] = $file;
        }
    }
    
    // 记录加载结果
    ai_qa_log_error('File loading summary', array(
        'loaded' => count($loaded_files),
        'failed' => count($failed_files),
        'loaded_files' => $loaded_files,
        'failed_files' => $failed_files
    ));
    
    return count($failed_files) === 0;
}

// 初始化插件
function ai_qa_generator_init() {
    try {
        // 加载依赖项
        $load_success = ai_qa_generator_load_dependencies();
        
        if (!$load_success) {
            ai_qa_log_error('Failed to load all required dependencies');
            // 在管理界面显示错误通知
            if (is_admin()) {
                add_action('admin_notices', function() {
                    echo '<div class="error"><p>AI问答生成器插件：部分组件加载失败，请检查插件文件完整性。</p></div>';
                });
            }
            return;
        }
        
        // 初始化管理界面（无条件初始化以确保AJAX处理函数注册）
        if (class_exists('AI_QA_Generator_Admin')) {
            ai_qa_log_error('正在初始化Admin类...', array(
                'is_admin' => is_admin(),
                'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX
            ));
            $admin = AI_QA_Generator_Admin::get_instance();
            $admin->init();
            ai_qa_log_error('Admin interface initialized successfully');
        } else {
            ai_qa_log_error('Admin class not found');
        }
        
        // 注册自定义cron间隔
        add_filter('cron_schedules', function($schedules) {
            $schedules['ai_qa_every_2min'] = array(
                'interval' => 120, // 2分钟
                'display' => 'Every 2 Minutes'
            );
            return $schedules;
        });
        
        // 注册后台处理钩子（确保类存在）
        if (class_exists('AI_QA_Background_Processor')) {
            add_action('ai_qa_process_batch', array('AI_QA_Background_Processor', 'process_batch'));
            add_action('ai_qa_cleanup_batches', array('AI_QA_Background_Processor', 'cleanup_completed_batches'));
            add_action('ai_qa_cleanup_drafts', array('AI_QA_Background_Processor', 'cleanup_orphaned_drafts'));
            
            // 添加启动检查钩子
            add_action('ai_qa_check_and_start', array('AI_QA_Background_Processor', 'check_and_start_processing'));
        }
        
    } catch (Exception $e) {
        ai_qa_log_error('Error during plugin initialization', array(
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
        
        // 在管理界面显示错误通知
        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>AI问答生成器插件初始化失败：' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}
// 确保WordPress已完全加载后再初始化
add_action('init', 'ai_qa_generator_init');

// 激活插件时的处理
function ai_qa_generator_activate() {
    try {
        // 先加载依赖项
        ai_qa_generator_load_dependencies();
        
        // 创建必要的数据库表和选项
        add_option('ai_qa_generator_settings', array(
            'source_post_type' => 'post',
            'source_taxonomy' => '',
            'target_post_type' => 'post', 
            'target_taxonomy' => '',
            'ai_api_key' => '',
            'ai_model' => 'deepseek-ai/DeepSeek-V3'
        ));
        
        // 创建数据库表（确保类已加载）
        if (class_exists('AI_QA_Database_Manager')) {
            AI_QA_Database_Manager::create_tables();
        }
        
        // 注册后台处理任务 - 每2分钟检查一次队列
        if (!wp_next_scheduled('ai_qa_process_batch')) {
            wp_schedule_event(time(), 'ai_qa_every_2min', 'ai_qa_process_batch');
            ai_qa_log_error('已注册后台处理任务：ai_qa_process_batch，间隔2分钟');
        }
        
        // 注册清理任务
        if (!wp_next_scheduled('ai_qa_cleanup_batches')) {
            wp_schedule_event(time(), 'hourly', 'ai_qa_cleanup_batches');
            ai_qa_log_error('已注册清理任务：ai_qa_cleanup_batches，间隔1小时');
        }
        
        // 注册草稿清理任务
        if (!wp_next_scheduled('ai_qa_cleanup_drafts')) {
            wp_schedule_event(time(), 'twicedaily', 'ai_qa_cleanup_drafts');
            ai_qa_log_error('已注册草稿清理任务：ai_qa_cleanup_drafts，间隔12小时');
        }
        
        // 刷新重写规则
        flush_rewrite_rules();
        ai_qa_log_error('Plugin activated successfully');
    } catch (Exception $e) {
        ai_qa_log_error('Error during plugin activation', array(
            'error' => $e->getMessage()
        ));
    }
}
register_activation_hook(__FILE__, 'ai_qa_generator_activate');

// 停用插件时的处理
function ai_qa_generator_deactivate() {
    try {
        // 刷新重写规则
        flush_rewrite_rules();
        ai_qa_log_error('Plugin deactivated successfully');
    } catch (Exception $e) {
        ai_qa_log_error('Error during plugin deactivation', array(
            'error' => $e->getMessage()
        ));
    }
}
register_deactivation_hook(__FILE__, 'ai_qa_generator_deactivate');

// 卸载插件时的处理
function ai_qa_generator_uninstall() {
    try {
        // 先加载依赖项
        ai_qa_generator_load_dependencies();
        
        // 删除插件设置
        delete_option('ai_qa_generator_settings');
        delete_option('ai_qa_processing_history');
        delete_option('ai_qa_batch_queue');
        delete_option('ai_qa_batch_processing');
        
        // 删除所有文章的处理标记
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, array('meta_key' => 'ai_processed'));
        $wpdb->delete($wpdb->postmeta, array('meta_key' => 'generated_qa_posts'));
        
        // 删除数据库表（确保类已加载）
        if (class_exists('AI_QA_Database_Manager')) {
            AI_QA_Database_Manager::drop_tables();
        }
        
        // 清除缓存（确保类已加载）
        if (class_exists('AI_QA_Cache_Manager')) {
            AI_QA_Cache_Manager::clear_cache();
        }
        
        // 清除定时任务
        wp_clear_scheduled_hook('ai_qa_process_batch');
        wp_clear_scheduled_hook('ai_qa_cleanup_batches');
        wp_clear_scheduled_hook('ai_qa_cleanup_drafts');
        
        ai_qa_log_error('Plugin uninstalled successfully');
    } catch (Exception $e) {
        ai_qa_log_error('Error during plugin uninstall', array(
            'error' => $e->getMessage()
        ));
    }
}
register_uninstall_hook(__FILE__, 'ai_qa_generator_uninstall');
