<?php
/**
 * 设置管理器类，负责处理插件设置页面和选项注册
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_QA_Generator_Settings_Manager {

    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        require AI_QA_GENERATOR_PLUGIN_DIR . 'admin/partials/admin-settings-display.php';
    }

    /**
     * 注册插件设置
     */
    public function register_settings() {
        register_setting('ai_qa_generator_settings', 'ai_qa_generator_settings');

        add_settings_section(
            'ai_qa_generator_main',
            '基本设置',
            array($this, 'settings_section_callback'),
            'ai_qa_generator_settings'
        );

        add_settings_field(
            'source_post_type',
            '源文章类型',
            array($this, 'source_post_type_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'source_taxonomy',
            '源分类/标签',
            array($this, 'source_taxonomy_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'target_post_type',
            '目标文章类型',
            array($this, 'target_post_type_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'target_taxonomy',
            '目标分类/标签',
            array($this, 'target_taxonomy_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'ai_api_key',
            'AI API密钥',
            array($this, 'ai_api_key_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'api_base_url',
            'API接口地址',
            array($this, 'api_base_url_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );

        add_settings_field(
            'ai_model',
            'AI模型',
            array($this, 'ai_model_callback'),
            'ai_qa_generator_settings',
            'ai_qa_generator_main'
        );
    }

    /**
     * 设置区域回调
     */
    public function settings_section_callback() {
        echo '<p>配置AI问答生成器的基本设置</p>';
    }

    /**
     * 源文章类型设置字段回调
     */
    public function source_post_type_callback() {
        $options = get_option('ai_qa_generator_settings');
        
        // 确保类已加载
        if (!class_exists('Post_Processor')) {
            echo '<p>错误：Post_Processor类未加载</p>';
            return;
        }
        
        $post_processor = new Post_Processor();
        $post_types = $post_processor->get_available_post_types();
        
        echo '<select name="ai_qa_generator_settings[source_post_type]" class="widefat" id="source-post-type">';
        echo '<option value="">请选择源文章类型</option>';
        foreach ($post_types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . 
                 selected(isset($options['source_post_type']) ? $options['source_post_type'] : '', $value, false) . '>' . 
                 esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">选择要处理的文章类型</p>';
    }

    /**
     * 源分类/标签设置字段回调
     */
    public function source_taxonomy_callback() {
        $options = get_option('ai_qa_generator_settings');
        $selected_post_type = isset($options['source_post_type']) ? $options['source_post_type'] : 'post';
        
        echo '<select name="ai_qa_generator_settings[source_taxonomy]" class="widefat" id="source-taxonomy">';
        echo '<option value="">全部（不限制分类）</option>';
        
        if (!empty($selected_post_type) && class_exists('Post_Processor')) {
            $post_processor = new Post_Processor();
            $taxonomies = $post_processor->get_taxonomies_for_post_type($selected_post_type);
            
            foreach ($taxonomies as $taxonomy => $taxonomy_label) {
                $terms = $post_processor->get_taxonomy_terms($taxonomy);
                if (!empty($terms)) {
                    echo '<optgroup label="' . esc_attr($taxonomy_label) . '">';
                    foreach ($terms as $term_value => $term_label) {
                        echo '<option value="' . esc_attr($term_value) . '" ' . 
                             selected(isset($options['source_taxonomy']) ? $options['source_taxonomy'] : '', $term_value, false) . '>' . 
                             esc_html($term_label) . '</option>';
                    }
                    echo '</optgroup>';
                }
            }
        }
        echo '</select>';
        echo '<p class="description">选择特定的分类或标签，留空表示处理所有文章</p>';
    }

    /**
     * 目标文章类型设置字段回调
     */
    public function target_post_type_callback() {
        $options = get_option('ai_qa_generator_settings');
        
        if (!class_exists('Post_Processor')) {
            echo '<p>错误：Post_Processor类未加载</p>';
            return;
        }
        
        $post_processor = new Post_Processor();
        $post_types = $post_processor->get_available_post_types();
        
        echo '<select name="ai_qa_generator_settings[target_post_type]" class="widefat" id="target-post-type">';
        foreach ($post_types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . 
                 selected(isset($options['target_post_type']) ? $options['target_post_type'] : 'post', $value, false) . '>' . 
                 esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">生成的问答文章将保存为此类型</p>';
    }

    /**
     * 目标分类/标签设置字段回调
     */
    public function target_taxonomy_callback() {
        $options = get_option('ai_qa_generator_settings');
        $selected_post_type = isset($options['target_post_type']) ? $options['target_post_type'] : 'post';
        
        echo '<select name="ai_qa_generator_settings[target_taxonomy]" class="widefat" id="target-taxonomy">';
        echo '<option value="">不设置分类</option>';
        
        if (!empty($selected_post_type) && class_exists('Post_Processor')) {
            $post_processor = new Post_Processor();
            $taxonomies = $post_processor->get_taxonomies_for_post_type($selected_post_type);
            
            foreach ($taxonomies as $taxonomy => $taxonomy_label) {
                $terms = $post_processor->get_taxonomy_terms($taxonomy, false);
                if (!empty($terms)) {
                    echo '<optgroup label="' . esc_attr($taxonomy_label) . '">';
                    foreach ($terms as $term_value => $term_label) {
                        echo '<option value="' . esc_attr($term_value) . '" ' . 
                             selected(isset($options['target_taxonomy']) ? $options['target_taxonomy'] : '', $term_value, false) . '>' . 
                             esc_html($term_label) . '</option>';
                    }
                    echo '</optgroup>';
                }
            }
        }
        echo '</select>';
        echo '<p class="description">生成的问答文章将归类到此分类下</p>';
    }

    /**
     * AI API密钥设置字段回调
     */
    public function ai_api_key_callback() {
        $options = get_option('ai_qa_generator_settings');
        echo '<input type="text" class="widefat" name="ai_qa_generator_settings[ai_api_key]" value="' . 
             esc_attr(isset($options['ai_api_key']) ? $options['ai_api_key'] : '') . '">';
        echo '<p class="description">请输入您的AI API密钥</p>';
    }

    /**
     * API接口地址设置字段回调
     */
    public function api_base_url_callback() {
        $options = get_option('ai_qa_generator_settings');
        $default_url = 'https://api.siliconflow.cn/v1/chat/completions';
        echo '<input type="text" class="widefat" name="ai_qa_generator_settings[api_base_url]" value="' . 
             esc_attr(isset($options['api_base_url']) ? $options['api_base_url'] : $default_url) . '">';
        echo '<p class="description">默认硅基流动API</p>';
    }

    /**
     * AI模型设置字段回调
     */
    public function ai_model_callback() {
        $options = get_option('ai_qa_generator_settings');
        $current_model = isset($options['ai_model']) ? $options['ai_model'] : 'deepseek-ai/DeepSeek-V3';
        
        echo '<input type="text" class="widefat" name="ai_qa_generator_settings[ai_model]" 
              placeholder="输入AI模型名称，如：deepseek-ai/DeepSeek-V3" 
              value="' . esc_attr($current_model) . '">';
        echo '<p class="description">默认 deepseek-ai/DeepSeek-V3</p>';
        
        // 添加文章类型变化时更新分类的JavaScript
        echo '<script>
        jQuery(document).ready(function($) {
            function updateTaxonomyOptions(postTypeSelect, taxonomySelect) {
                var postType = postTypeSelect.val();
                if (!postType) return;
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "get_taxonomy_terms_for_post_type",
                        post_type: postType,
                        nonce: "' . wp_create_nonce('ai_qa_generator_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            taxonomySelect.html(response.data);
                            console.log("Taxonomy data updated:", response.data);
                        } else {
                            console.error("Failed to get taxonomy data:", response);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX request failed:", error);
                    }
                });
            }
            
            $("#source-post-type").change(function() {
                updateTaxonomyOptions($(this), $("#source-taxonomy"));
            });
            
            $("#target-post-type").change(function() {
                updateTaxonomyOptions($(this), $("#target-taxonomy"));
            });
        });
        </script>';
    }
}
?>