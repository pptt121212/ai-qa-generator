<?php
class QA_Creator {
    public function create_qa_posts($qa_pairs, $source_post, $target_settings) {
        $created_posts = array();

        // 解析目标设置
        $target_post_type = isset($target_settings['post_type']) ? $target_settings['post_type'] : 'post';
        $target_taxonomy = isset($target_settings['taxonomy']) ? $target_settings['taxonomy'] : '';

        // 获取原文章的所有分类法术语
        $source_taxonomies = get_object_taxonomies($source_post->post_type);
        $source_terms = array();
        
        foreach ($source_taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($source_post->ID, $taxonomy, array('fields' => 'names'));
            if (!is_wp_error($terms) && !empty($terms)) {
                $source_terms = array_merge($source_terms, $terms);
            }
        }
        
        foreach ($qa_pairs as $qa_pair) {
            // 构建文章内容，添加更多上下文信息
            $source_post_type_obj = get_post_type_object($source_post->post_type);
            $source_type_label = $source_post_type_obj ? $source_post_type_obj->label : $source_post->post_type;
            
            $content = sprintf(
                "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->\n\n" .
                "<!-- wp:paragraph {\"className\":\"source-link\"} -->\n" .
                "<p class=\"source-link\">本答案来源于%s《<a href=\"%s\">%s</a>》</p>\n" .
                "<!-- /wp:paragraph -->",
                wpautop($qa_pair['answer']),
                $source_type_label,
                get_permalink($source_post->ID),
                esc_html($source_post->post_title)
            );

            $post_data = array(
                'post_title' => wp_strip_all_tags($qa_pair['question']),
                'post_content' => $content,
                'post_status' => 'draft', // 先创建为草稿
                'post_author' => $source_post->post_author,
                'post_type' => $target_post_type,
                'post_excerpt' => wp_trim_words($qa_pair['answer'], 55, '...')
            );

            // 创建新文章
            $post_id = wp_insert_post($post_data);

            if ($post_id) {
                // 设置目标分类法
                if (!empty($target_taxonomy)) {
                    $this->set_post_taxonomy($post_id, $target_taxonomy);
                }
                
                // 复制原文章的自定义字段
                $this->copy_custom_fields($source_post->ID, $post_id);
                
                // 添加来源文章ID和其他元数据
                update_post_meta($post_id, 'source_post_id', $source_post->ID);
                update_post_meta($post_id, 'source_post_type', $source_post->post_type);
                update_post_meta($post_id, 'qa_generated_time', current_time('mysql'));
                update_post_meta($post_id, 'qa_draft_status', 'pending_publish'); // 标记为待发布
                
                // 复制原文章的分类法术语（如果目标文章类型支持）
                $this->copy_taxonomies($source_post, $post_id, $target_post_type);
                
                $created_posts[] = $post_id;
            }
        }

        return $created_posts;
    }
    
    private function set_post_taxonomy($post_id, $taxonomy_term) {
        if (strpos($taxonomy_term, ':') !== false) {
            list($taxonomy, $term_id) = explode(':', $taxonomy_term, 2);
            wp_set_post_terms($post_id, array(intval($term_id)), $taxonomy);
        }
    }
    
    private function copy_taxonomies($source_post, $target_post_id, $target_post_type) {
        $source_taxonomies = get_object_taxonomies($source_post->post_type);
        $target_taxonomies = get_object_taxonomies($target_post_type);
        
        // 只复制两种文章类型都支持的分类法
        $common_taxonomies = array_intersect($source_taxonomies, $target_taxonomies);
        
        foreach ($common_taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($source_post->ID, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_post_terms($target_post_id, $terms, $taxonomy);
            }
        }
    }
    
    /**
     * 发布草稿状态的问答文章
     */
    public function publish_draft_qa_posts($post_ids) {
        $published_count = 0;
        $failed_posts = array();
        
        foreach ($post_ids as $post_id) {
            // 检查是否是待发布的草稿
            if (get_post_meta($post_id, 'qa_draft_status', true) === 'pending_publish') {
                $result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ));
                
                if (!is_wp_error($result) && $result) {
                    update_post_meta($post_id, 'qa_draft_status', 'published');
                    update_post_meta($post_id, 'qa_published_time', current_time('mysql'));
                    $published_count++;
                } else {
                    $failed_posts[] = $post_id;
                    error_log('发布问答文章失败: ' . $post_id . ' - ' . (is_wp_error($result) ? $result->get_error_message() : '未知错误'));
                }
            }
        }
        
        return array(
            'published_count' => $published_count,
            'failed_posts' => $failed_posts
        );
    }
    
    /**
     * 清理失败任务的草稿文章
     */
    public function cleanup_failed_drafts($post_ids) {
        $deleted_count = 0;
        
        foreach ($post_ids as $post_id) {
            // 只删除待发布状态的草稿
            if (get_post_meta($post_id, 'qa_draft_status', true) === 'pending_publish') {
                $result = wp_delete_post($post_id, true); // 彻底删除
                if ($result) {
                    $deleted_count++;
                }
            }
        }
        
        return $deleted_count;
    }

    private function copy_custom_fields($source_post_id, $target_post_id) {
        $custom_fields = get_post_custom($source_post_id);
        
        if ($custom_fields) {
            foreach ($custom_fields as $key => $values) {
                // 只复制非WordPress内部字段和非AI处理相关字段
                if (substr($key, 0, 1) !== '_' && 
                    !in_array($key, array('ai_processed', 'generated_qa_posts'))) {
                    foreach ($values as $value) {
                        add_post_meta($target_post_id, $key, $value);
                    }
                }
            }
        }
    }
}
