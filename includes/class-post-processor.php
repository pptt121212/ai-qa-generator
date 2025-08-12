<?php
class Post_Processor {
    
    /**
     * 获取所有可用的文章类型
     */
    public function get_available_post_types() {
        $post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true
        ), 'objects');
        
        // 排除一些不适合的文章类型
        $excluded_types = array('attachment', 'revision', 'nav_menu_item');
        
        $available_types = array();
        foreach ($post_types as $post_type) {
            if (!in_array($post_type->name, $excluded_types)) {
                $available_types[$post_type->name] = $post_type->label;
            }
        }
        
        return $available_types;
    }
    
    /**
     * 根据文章类型和分类获取文章
     */
    public function get_posts_by_type_and_taxonomy($post_type = 'post', $taxonomy_term = null, $processed = false, $paged = 1, $posts_per_page = 100) {
        try {
            $meta_query = array();
            // 排除已处理的文章
            if (!$processed) {
                $meta_query[] = array(
                    'key' => 'ai_processed',
                    'compare' => 'NOT EXISTS'
                );
            }

            $args = array(
                'posts_per_page' => 100,  // 固定为 100
                'post_type' => $post_type,
                'post_status' => 'publish', 
                'meta_query' => $meta_query,
                'orderby' => 'date',
                'order' => 'DESC'
            );

            // 如果指定了分类术语，添加税务查询
            if (!empty($taxonomy_term)) {
                $taxonomy_data = $this->parse_taxonomy_term($taxonomy_term, $post_type);
                if ($taxonomy_data) {
                    $args['tax_query'] = array(
                        array(
                            'taxonomy' => $taxonomy_data['taxonomy'],
                            'field' => 'term_id',
                            'terms' => $taxonomy_data['term_id']
                        )
                    );
                    error_log("添加分类查询: " . print_r($args['tax_query'], true));
                } else {
                    error_log("无法解析分类术语: " . $taxonomy_term);
                }
            }

            $query = new WP_Query($args);

            return array(
                'posts' => $query->posts,
                'total' => $query->found_posts,
                'total_pages' => ceil($query->found_posts / $posts_per_page)
            );
            
        } catch (Exception $e) {
            error_log('获取文章失败: ' . $e->getMessage());
            throw new Exception('获取文章列表失败：' . $e->getMessage());
        }
    }
    
    /**
     * 兼容旧方法
     */
    public function get_category_posts($category_id, $processed = false, $paged = 1, $posts_per_page = 100) {
        return $this->get_posts_by_type_and_taxonomy('post', $category_id, $processed, $paged, $posts_per_page);
    }
    
    /**
     * 解析分类术语
     */
    private function parse_taxonomy_term($taxonomy_term, $post_type) {
        if (empty($taxonomy_term)) {
            error_log("[Post_Processor] taxonomy_term is empty");
            return null;
        }
        
        error_log("[Post_Processor] Parsing taxonomy term: " . print_r($taxonomy_term, true) . " (post_type: " . $post_type . ")");
        
        
        
        // 如果是字符串格式 "taxonomy:term_id"
        if (strpos($taxonomy_term, ':') !== false) {
            list($taxonomy, $term_id) = explode(':', $taxonomy_term, 2);
            error_log("[Post_Processor] Parsed as taxonomy:id format - " . $taxonomy . ":" . $term_id);
            
            // 验证分类法和术语是否存在且可用
            if (!empty($taxonomy) && !empty($term_id)) {
                if (taxonomy_exists($taxonomy)) {
                    $term = get_term(intval($term_id), $taxonomy);
                    if (!is_wp_error($term) && $term) {
                        error_log("[Post_Processor] Found valid term: " . $term->name . " in taxonomy: " . $taxonomy);
                        return array(
                            'taxonomy' => $taxonomy,
                            'term_id' => intval($term_id)
                        );
                    } else {
                        error_log("[Post_Processor] Term not found: " . $term_id . " in taxonomy: " . $taxonomy);
                    }
                } else {
                    error_log("[Post_Processor] Taxonomy does not exist: " . $taxonomy);
                }
            } else {
                error_log("[Post_Processor] Empty taxonomy or term_id");
            }
        } else {
            error_log("[Post_Processor] Unrecognized taxonomy format");
        }
        
        return null;
    }
    
    /**
     * 获取文章类型的可用分类法
     */
    public function get_taxonomies_for_post_type($post_type) {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $available_taxonomies = array();
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public && $taxonomy->show_ui) {
                $available_taxonomies[$taxonomy->name] = $taxonomy->label;
            }
        }
        
        return $available_taxonomies;
    }
    
    /**
     * 获取分类法的术语列表
     */
    public function get_taxonomy_terms($taxonomy, $hide_empty = true) {
        error_log("[Post_Processor] Getting terms for taxonomy: " . $taxonomy);
        
        if (!taxonomy_exists($taxonomy)) {
            error_log("[Post_Processor] Taxonomy does not exist: " . $taxonomy);
            return array();
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => $hide_empty,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (is_wp_error($terms)) {
            error_log("[Post_Processor] Error getting terms: " . $terms->get_error_message());
            return array();
        }
        
        $term_options = array();
        foreach ($terms as $term) {
            $term_key = $taxonomy . ':' . $term->term_id;
            $term_value = $term->name . ' (' . $term->count . ')';
            $term_options[$term_key] = $term_value;
            error_log("[Post_Processor] Added term option: " . $term_key . " => " . $term_value);
        }
        
        error_log("[Post_Processor] Total terms found: " . count($term_options));
        return $term_options;
    }

    public function extract_post_content($post) {
        try {
            if (!is_object($post)) {
                throw new Exception('无效的文章对象');
            }

            // 获取完整的文章内容，包括格式化后的内容
            $formatted_content = apply_filters('the_content', $post->post_content);
            $formatted_content = wp_strip_all_tags($formatted_content); // 移除HTML标签

            // 获取文章的所有分类法术语
            $taxonomies = get_object_taxonomies($post->post_type);
            $all_terms = array();
            $categories = array();
            $tags = array();
            
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post->ID, $taxonomy);
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $all_terms[] = $term->name;
                        
                        // 为了向后兼容，仍然区分categories和tags
                        if ($taxonomy === 'category') {
                            $categories[] = $term->name;
                        } elseif ($taxonomy === 'post_tag') {
                            $tags[] = $term->name;
                        }
                    }
                }
            }

            // 获取文章摘要
            $excerpt = $post->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = wp_trim_words($formatted_content, 55, '...');
            }

            // 获取文章类型信息
            $post_type_obj = get_post_type_object($post->post_type);
            $post_type_label = $post_type_obj ? $post_type_obj->label : $post->post_type;

            // 返回结构化的文章信息
            $content = array(
                'title' => $post->post_title,
                'post_type' => $post->post_type,
                'post_type_label' => $post_type_label,
                'categories' => $categories, // 向后兼容
                'tags' => $tags, // 向后兼容
                'all_terms' => $all_terms, // 所有分类法术语
                'excerpt' => $excerpt,
                'formatted_content' => $formatted_content
            );

            error_log('提取的文章内容: ' . print_r($content, true));
            return $content;

        } catch (Exception $e) {
            error_log('提取文章内容失败: ' . $e->getMessage());
            throw new Exception('提取文章内容失败：' . $e->getMessage());
        }
    }
}
