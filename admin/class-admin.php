<?php
/**
 * 管理界面类
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once AI_QA_GENERATOR_PLUGIN_DIR . 'includes/class-error-handler.php';

// 引入新的模块类
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-assets-loader.php';
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-settings-manager.php';
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-history-page-renderer.php';
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-monitor-page-renderer.php';
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-main-page-renderer.php';
require_once AI_QA_GENERATOR_PLUGIN_DIR . 'admin/class-ajax-handler.php';

class AI_QA_Generator_Admin {
    private static $instance = null;
    
    // 用于保存各个模块实例的属性
    private $settings_manager;
    private $main_page_renderer;
    private $history_page_renderer;
    private $monitor_page_renderer;
    private $assets_loader;
    private $ajax_handler;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 私有构造函数，防止直接创建实例
        // 初始化各个模块实例
        $this->settings_manager = new AI_QA_Generator_Settings_Manager();
        $this->main_page_renderer = new AI_QA_Generator_Main_Page_Renderer();
        $this->history_page_renderer = new AI_QA_Generator_History_Page_Renderer();
        $this->monitor_page_renderer = new AI_QA_Generator_Monitor_Page_Renderer();
        $this->assets_loader = new AI_QA_Generator_Assets_Loader();
        $this->ajax_handler = new AI_QA_Generator_Ajax_Handler();
    }

    public function init() {
        error_log('正在初始化Admin类...', 0);
        
        // 注册AJAX处理函数（无论是否在管理区域都需要注册）
        add_action('wp_ajax_get_batch_status', array($this->ajax_handler, 'ajax_get_batch_status'));
        add_action('wp_ajax_start_background_batch', array($this->ajax_handler, 'ajax_start_background_batch'));
        add_action('wp_ajax_ai_qa_clear_cache', array($this->ajax_handler, 'ajax_clear_cache'));
        add_action('wp_ajax_ai_qa_cleanup_logs', array($this->ajax_handler, 'ajax_cleanup_logs'));
        add_action('wp_ajax_ai_qa_cleanup_drafts', array($this->ajax_handler, 'ajax_cleanup_drafts'));
        add_action('wp_ajax_get_taxonomy_terms_for_post_type', array($this->ajax_handler, 'get_taxonomy_terms_for_post_type_callback'));
        add_action('wp_ajax_get_batch_progress', array($this->ajax_handler, 'ajax_get_batch_progress'));
        add_action('wp_ajax_ai_qa_system_status', array($this->ajax_handler, 'ajax_get_system_status'));
        add_action('wp_ajax_ai_qa_trigger_processing', array($this->ajax_handler, 'ajax_trigger_processing'));
        add_action('wp_ajax_ai_qa_reschedule_tasks', array($this->ajax_handler, 'ajax_reschedule_tasks'));
        add_action('wp_ajax_ai_qa_refresh_post_status', array($this->ajax_handler, 'ajax_refresh_post_status'));
        add_action('wp_ajax_ai_qa_force_start_processing', array($this->ajax_handler, 'ajax_force_start_processing'));
        add_action('wp_ajax_ai_qa_retry_failed_post', array($this->ajax_handler, 'ajax_retry_failed_post'));
        
        // 添加一个测试AJAX处理函数
        add_action('wp_ajax_ai_qa_test', array($this->ajax_handler, 'ajax_test'));
        
        error_log('AJAX处理函数已注册', 0);
        
        // 只在管理区域注册管理界面相关的钩子
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            // 直接注册设置
            add_action('admin_init', array($this->settings_manager, 'register_settings'));
            
            // 使用新的资源加载类来加载脚本和样式
            add_action('admin_enqueue_scripts', array($this->assets_loader, 'enqueue_admin_scripts'));
            
            error_log('管理界面钩子已注册', 0);
        }
        
        error_log('Admin类初始化完成', 0);
    }

    public function add_admin_menu() {
        add_menu_page(
            'AI问答生成器',           // 页面标题
            'AI问答生成器',           // 菜单标题
            'manage_options',         // 所需权限
            'ai-qa-generator',        // 菜单slug
            array($this->main_page_renderer, 'render_admin_page'), // 回调函数指向新的渲染器
            'dashicons-format-chat'   // 图标
        );

        add_submenu_page(
            'ai-qa-generator',
            '文章处理', // 子菜单标题
            '文章处理', // 子菜单标题
            'manage_options',
            'ai-qa-generator', // 与父菜单相同的slug
            array($this->main_page_renderer, 'render_admin_page') // 回调函数指向新的渲染器
        );
        
        add_submenu_page(
            'ai-qa-generator',
            '统计与历史',
            '统计与历史',
            'manage_options',
            'ai-qa-history',
            array($this->history_page_renderer, 'render_history_page') // 回调函数指向新的渲染器
        );
        
        add_submenu_page(
            'ai-qa-generator',
            '系统监控',
            '系统监控',
            'manage_options',
            'ai-qa-monitor',
            array($this->monitor_page_renderer, 'render_monitor_page') // 回调函数指向新的渲染器
        );

        add_submenu_page(
            'ai-qa-generator',
            '设置',
            '设置',
            'manage_options',
            'ai-qa-settings',
            array($this->settings_manager, 'render_settings_page') // 回调函数指向新的管理器
        );
    }
}
