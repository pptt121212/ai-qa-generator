<?php
/**
 * 资源加载器类，负责加载管理后台的CSS和JavaScript文件
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_Assets_Loader {

    /**
     * 加载管理后台所需的CSS和JavaScript文件
     *
     * @param string $hook The current admin page hook suffix.
     */
    public function enqueue_admin_scripts($hook) {
        // 指定需要加载脚本的页面
        $allowed_hooks = array(
            'toplevel_page_ai-qa-generator', // 主页面
            'ai-qa-generator_page_ai-qa-history', // 历史页面
            'ai-qa-generator_page_ai-qa-monitor', // 监控页面
            'ai-qa-generator_page_ai-qa-settings'  // 设置页面
        );

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        wp_enqueue_style('ai-qa-generator-admin', AI_QA_GENERATOR_PLUGIN_URL . 'admin/css/admin.css', array(), AI_QA_GENERATOR_VERSION);
        wp_enqueue_script('ai-qa-generator-admin', AI_QA_GENERATOR_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), AI_QA_GENERATOR_VERSION, true);
        
        wp_localize_script('ai-qa-generator-admin', 'aiQaGeneratorAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_qa_generator_nonce')
        ));
    }
}
