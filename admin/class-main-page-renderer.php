<?php
/**
 * 主页面渲染器类，负责渲染文章处理主页面
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_Main_Page_Renderer {

    /**
     * 渲染主管理页面
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 保存设置
        if (isset($_POST['submit'])) {
            check_admin_referer('ai_qa_generator_settings');
            $settings = array(
                'source_post_type' => sanitize_text_field($_POST['ai_qa_generator_settings']['source_post_type']),
                'source_taxonomy' => sanitize_text_field($_POST['ai_qa_generator_settings']['source_taxonomy']),
                'target_post_type' => sanitize_text_field($_POST['ai_qa_generator_settings']['target_post_type']),
                'target_taxonomy' => sanitize_text_field($_POST['ai_qa_generator_settings']['target_taxonomy']),
                'ai_api_key' => sanitize_text_field($_POST['ai_qa_generator_settings']['ai_api_key']),
                'api_base_url' => sanitize_text_field($_POST['ai_qa_generator_settings']['api_base_url']),
                'ai_model' => sanitize_text_field($_POST['ai_qa_generator_settings']['ai_model'])
            );
            update_option('ai_qa_generator_settings', $settings);
            add_settings_error('ai_qa_generator_messages', 'ai_qa_generator_message', '设置已保存', 'updated');
        }

        $settings = get_option('ai_qa_generator_settings');
        
        // 确保类已加载
        if (!class_exists('Post_Processor')) {
            echo '<div class="error"><p>错误：Post_Processor类未加载，请检查插件文件。</p></div>';
            return;
        }
        
        $post_processor = new Post_Processor();
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $posts_per_page = 20;
        
        // 获取文章（支持多种文章类型）
        $source_post_type = isset($settings['source_post_type']) ? $settings['source_post_type'] : 'post';
        $source_taxonomy = isset($settings['source_taxonomy']) ? $settings['source_taxonomy'] : '';
        
        $result = $post_processor->get_posts_by_type_and_taxonomy(
            $source_post_type,
            $source_taxonomy,
            null, 
            $current_page, 
            $posts_per_page
        );
        
        $posts = $result['posts'];
        $total_pages = $result['total_pages'];

        // 显示设置错误/更新消息
        settings_errors('ai_qa_generator_messages');

        // 输出管理界面HTML
        require AI_QA_GENERATOR_PLUGIN_DIR . 'admin/partials/admin-display.php';
    }

    /**
     * 渲染分页链接
     *
     * @param int $current_page 当前页码
     * @param int $total_pages 总页数
     */
    private function render_pagination($current_page, $total_pages) {
        if ($total_pages <= 1) {
            return;
        }

        $output = '<div class="pagination-links">';
        
        // 上一页
        if ($current_page > 1) {
            $output .= '<a class="pagination-link prev-page" href="#" data-page="' . ($current_page - 1) . '">‹</a>';
        }

        // 页码
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $current_page) {
                $output .= '<span class="pagination-link current">' . $i . '</span>';
            } else {
                $output .= '<a class="pagination-link" href="#" data-page="' . $i . '">' . $i . '</a>';
            }
        }

        // 下一页
        if ($current_page < $total_pages) {
            $output .= '<a class="pagination-link next-page" href="#" data-page="' . ($current_page + 1) . '">›</a>';
        }

        $output .= '</div>';
        
        echo $output;
    }
}
?>