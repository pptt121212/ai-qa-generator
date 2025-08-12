<?php
class AI_Processor {
    private $api_key;
    private $model;
    private $api_base_url;

    public function __construct() {
        $settings = get_option('ai_qa_generator_settings');
        error_log('AI设置: ' . print_r($settings, true));
        
        $this->api_key = isset($settings['ai_api_key']) ? trim($settings['ai_api_key']) : '';
        
        // 处理自定义API地址
        $this->api_base_url = isset($settings['api_base_url']) && !empty($settings['api_base_url']) 
            ? $settings['api_base_url'] 
            : 'https://api.siliconflow.cn/v1/chat/completions';
        
        // 处理AI模型设置
        $this->model = isset($settings['ai_model']) && !empty($settings['ai_model']) 
            ? $settings['ai_model'] 
            : 'deepseek-ai/DeepSeek-V3';
        
        // 检查并清理API密钥
        if (empty($this->api_key)) {
            throw new Exception('AI API密钥未设置，请在插件设置中配置API密钥');
        }
        
        // 移除可能存在的Bearer前缀
        if (strpos($this->api_key, 'Bearer ') === 0) {
            $this->api_key = substr($this->api_key, 7);
        }
        
        // 去除空白字符
        $this->api_key = trim($this->api_key);
        
        if (strlen($this->api_key) < 32) {
            error_log('API密钥长度异常: ' . strlen($this->api_key));
            throw new Exception('API密钥格式无效，请确保输入了完整的API密钥');
        }
        
        error_log('API密钥长度: ' . strlen($this->api_key));
        error_log('选择的模型: ' . $this->model);
    }

    public function process_content($content) {
        try {
            if (empty($content) || !is_array($content)) {
                throw new Exception('内容格式无效');
            }

            // 优化内存使用：减少日志记录
            $content_hash = md5(serialize($content));
            
            // 检查缓存
            $cached_result = AI_QA_Cache_Manager::get_cached_result($content_hash, $this->model);
            if ($cached_result !== false) {
                // 减少日志输出，节省内存
                error_log('使用缓存结果，跳过API调用');
                return $cached_result;
            }

            // 强制垃圾回收，释放内存
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // 构建API请求内容
            $prompt = $this->build_prompt($content);
            
            // 调用硅基流动 API
            $response = $this->call_api($prompt);
            
            // 解析返回的问答对
            $qa_pairs = $this->parse_qa_response($response);
            
            // 缓存结果
            AI_QA_Cache_Manager::cache_result($content_hash, $this->model, $qa_pairs);
            
            // 清理大变量，释放内存
            unset($prompt, $response, $content);
            
            // 再次强制垃圾回收
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            error_log('AI处理完成，生成 ' . count($qa_pairs) . ' 个问答对');
            
            return $qa_pairs;
        } catch (Exception $e) {
            error_log("AI处理错误: " . $e->getMessage());
            // 清理可能的大变量
            if (isset($prompt)) unset($prompt);
            if (isset($response)) unset($response);
            if (isset($content)) unset($content);
            throw new Exception('AI处理失败：' . $e->getMessage());
        }
    }

    private function build_prompt($content) {
        // 格式化内容
        $title = isset($content['title']) ? $content['title'] : '';
        $categories = isset($content['categories']) ? $this->format_array($content['categories']) : '无';
        $tags = isset($content['tags']) ? $this->format_array($content['tags']) : '无';
        $excerpt = isset($content['excerpt']) ? $content['excerpt'] : '';
        $formatted_content = isset($content['formatted_content']) ? $content['formatted_content'] : '';

        // 计算内容长度来调整问答数量
        $content_length = mb_strlen($formatted_content, 'UTF-8');
        $qa_count = $content_length > 2000 ? '4-6' : ($content_length > 1000 ? '3-5' : '2-4');

        // 优化的提示词模板
        $prompt = <<<EOT
你是一位专业的知识整理专家，擅长将复杂内容转化为易于理解的问答形式。请基于以下文章内容，创建高质量的问答对。

## 文章信息
**标题**: {$title}
**分类**: {$categories}
**标签**: {$tags}
**摘要**: {$excerpt}

## 文章正文
{$formatted_content}

## 任务要求
1. **问答数量**: 生成 {$qa_count} 个问答对
2. **问题设计**: 
   - 问题要具体明确，，聚焦文章主体，避免过于宽泛
   - 涵盖文章的核心观点、关键概念、实用方法
   - 问题应该是读者真正关心的内容
3. **答案质量**:
   - 答案要准确、完整，基于文章内容
   - 保持逻辑清晰，结构合理
   - 适当补充背景信息，增强可读性
   - 每个答案控制在150-400字之间
4. **内容覆盖**: 确保问答对能够覆盖文章的主要知识点

## 撰写要求
• 使用基本 HTML 标签（如 h2, p, ul, li 等）格式化内容，不使用 h1和title标签
• 约300-500字
• 纯HTML输出（不含CSS/style/Markdown/```html```/代码块）
• 专业且通俗易懂
• 直接返回结果不附加说明

## 输出格式
请严格按照以下JSON格式输出，不要添加任何其他内容：

{
  "qa_pairs": [
    {
      "question": "具体明确的问题",
      "answer": "详细准确的答案"
    }
  ]
}
EOT;

        error_log('生成的提示词: ' . $prompt);
        return $prompt;
    }

    private function format_array($array) {
        if (empty($array)) {
            return '无';
        }
        if (is_string($array)) {
            return $array;
        }
        return implode('、', $array);
    }

    private function call_api_with_retry($args, $max_retries = 3) {
        $attempt = 1;
        $last_error = null;

        while ($attempt <= $max_retries) {
            error_log("API请求尝试 {$attempt}/{$max_retries}");
            
            $response = wp_remote_post($this->api_base_url, $args);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 200) {
                    return $response;
                }
                $last_error = '状态码: ' . $status_code;
            } else {
                $last_error = $response->get_error_message();
            }
            
            error_log("第{$attempt}次尝试失败: " . $last_error);
            
            if ($attempt < $max_retries) {
                $wait_time = $attempt * 5; // 递增等待时间
                error_log("等待 {$wait_time} 秒后重试...");
                sleep($wait_time);
            }
            
            $attempt++;
        }
        
        throw new Exception('API请求失败（重试' . $max_retries . '次）: ' . $last_error);
    }

    private function call_api($prompt) {
        $max_retries = 3;
        $attempt = 0;
        $last_error = '';
        
        while ($attempt < $max_retries) {
            try {
                // 确保API密钥格式正确
                $api_key = $this->api_key;
                if (strpos($api_key, 'Bearer ') !== 0) {
                    $api_key = 'Bearer ' . $api_key;
                }
                
                // 准备请求头
                $headers = array(
                    'Authorization' => $api_key,
                    'Content-Type' => 'application/json'
                );

            // 准备请求数据
            $data = array(
                'model' => $this->model, // 使用配置的模型
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 2048,
                'temperature' => 0.7,
                'top_p' => 0.7
            );
            
            error_log('API请求数据: ' . print_r($data, true));
            error_log('使用的API密钥(部分): ' . substr($this->api_key, 0, 10) . '...');

            error_log('API请求数据: ' . print_r($data, true));

            // 优化的请求参数，减少资源占用
            $args = array(
                'method' => 'POST',
                'timeout' => 90, // 减少超时时间，避免长时间占用连接
                'headers' => $headers,
                'body' => json_encode($data),
                'data_format' => 'body',
                'decompress' => false,
                'httpversion' => '1.1',
                'blocking' => true,
                'sslverify' => true,
                'redirection' => 2, // 进一步减少重定向
                'user-agent' => 'AI-QA-Generator/1.0', // 添加用户代理
                'compress' => true, // 启用压缩以减少传输时间
                'limit_response_size' => 1048576 // 限制响应大小为1MB
            );

            error_log('发送API请求，参数: ' . print_r($args, true));
            
            $response = wp_remote_post($this->api_base_url, $args);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API请求失败: ' . $error_message);
                throw new Exception('API请求失败: ' . $error_message);
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);
            
            error_log('API响应状态码: ' . $status_code);
            error_log('API响应头: ' . print_r($response_headers, true));
            error_log('API响应体: ' . $response_body);
            
            // 针对401错误提供更具体的错误信息
            if ($status_code === 401) {
                $error_data = json_decode($response_body, true);
                $error_message = '认证失败，请检查API密钥是否正确设置';
                
                if ($error_data && isset($error_data['error'])) {
                    if (is_string($error_data['error'])) {
                        $error_message = $error_data['error'];
                    } elseif (is_array($error_data['error'])) {
                        if (isset($error_data['error']['message'])) {
                            $error_message = $error_data['error']['message'];
                        }
                        if (isset($error_data['error']['code'])) {
                            $error_message .= ' (错误代码: ' . $error_data['error']['code'] . ')';
                        }
                    }
                }
                
                $debug_info = array(
                    'error' => $error_message,
                    'status_code' => $status_code,
                    'response_body' => $response_body
                );
                error_log('API调试信息: ' . print_r($debug_info, true));
                
                throw new Exception('API认证失败: ' . $error_message, 401);
            }
            
            error_log('API响应状态码: ' . $status_code);
            error_log('API响应头: ' . print_r($response_headers, true));
            error_log('API响应体: ' . $response_body);
            
            if ($status_code !== 200) {
                $error_data = json_decode($response_body, true);
                $error_message = '';
                
                if (isset($error_data['error'])) {
                    if (is_array($error_data['error'])) {
                        $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : '';
                        if (isset($error_data['error']['code'])) {
                            $error_message .= ' (错误代码: ' . $error_data['error']['code'] . ')';
                        }
                    } else {
                        $error_message = $error_data['error'];
                    }
                }
                
                if (empty($error_message)) {
                    $error_message = '未知错误，请检查API密钥是否正确设置';
                }
                
                // 检测API限速错误
                if ($status_code === 429 || 
                    strpos(strtolower($error_message), 'rate limit') !== false ||
                    strpos(strtolower($error_message), 'too many requests') !== false) {
                    throw new Exception('API请求频率限制，请稍后重试（RPM: 1000/分钟）', 429);
                }
                
                throw new Exception('API返回错误状态码: ' . $status_code . ', 错误信息: ' . $error_message);
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!$result) {
                throw new Exception('API响应解析失败: ' . json_last_error_msg());
            }

            if (isset($result['error'])) {
                throw new Exception('API错误: ' . $result['error']['message']);
            }

            if (!isset($result['choices']) || 
                !isset($result['choices'][0]) || 
                !isset($result['choices'][0]['message']) || 
                !isset($result['choices'][0]['message']['content'])) {
                error_log('无效的API响应格式: ' . print_r($result, true));
                throw new Exception('API响应格式无效，请检查API密钥是否正确');
            }

                            error_log('API响应成功: ' . print_r($result, true));
                
                // 返回文本内容
                $content = $result['choices'][0]['message']['content'];
                
                // 记录token使用情况
                if (isset($result['usage'])) {
                    error_log('Token使用情况: ' . print_r($result['usage'], true));
                }
                
                return $content;
                
            } catch (Exception $e) {
                $attempt++;
                $last_error = $e->getMessage();
                error_log("第 {$attempt} 次尝试失败: {$last_error}");
                
                if ($attempt < $max_retries) {
                    // 针对不同错误类型使用不同的等待时间
                    if (strpos($last_error, 'rate limit') !== false || strpos($last_error, '429') !== false) {
                        // API限速错误：等待65秒
                        $wait_time = 65;
                        error_log("检测到API限速，等待 {$wait_time} 秒后重试...");
                    } else {
                        // 其他错误：指数退避
                        $wait_time = min(pow(2, $attempt), 10);
                        error_log("等待 {$wait_time} 秒后重试...");
                    }
                    
                    sleep($wait_time);
                    continue;
                }
                
                // 根据错误类型提供更具体的错误信息
                if (strpos($last_error, 'rate limit') !== false || strpos($last_error, '429') !== false) {
                    throw new Exception("API请求频率限制（RPM: 1000/分钟），请稍后重试或增加处理间隔");
                } elseif (strpos($last_error, '502') !== false || strpos($last_error, '503') !== false) {
                    throw new Exception("服务器暂时不可用，请稍后重试（已重试{$max_retries}次）");
                } elseif (strpos($last_error, 'timeout') !== false || strpos($last_error, 'timed out') !== false) {
                    throw new Exception("API请求超时，请检查网络连接或稍后重试（已重试{$max_retries}次）");
                } else {
                    throw new Exception("API调用失败（已重试{$max_retries}次）: {$last_error}");
                }
            }
        }
        
        throw new Exception("API调用失败：所有重试都失败了");
    }

    private function parse_qa_response($response) {
        try {
            error_log('解析API响应: ' . $response);
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON解析失败: ' . json_last_error_msg());
            }
            
            if (!isset($data['qa_pairs']) || !is_array($data['qa_pairs'])) {
                throw new Exception('响应格式无效：缺少qa_pairs数组');
            }

            foreach ($data['qa_pairs'] as $pair) {
                if (!isset($pair['question']) || !isset($pair['answer'])) {
                    throw new Exception('问答对格式无效');
                }
            }

            return $data['qa_pairs'];
        } catch (Exception $e) {
            error_log('响应解析错误: ' . $e->getMessage());
            throw new Exception('解析AI响应失败：' . $e->getMessage());
        }
    }
}
