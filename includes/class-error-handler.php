<?php
class AI_QA_Error_Handler {
    public static function log_error($message, $context = array()) {
        $error_message = '[AI QA Generator] ' . $message;
        if (!empty($context)) {
            $error_message .= "\nContext: " . print_r($context, true);
        }
        error_log($error_message);
    }

    public static function send_error_response($message, $code = '', $context = array()) {
        self::log_error($message, $context);
        wp_send_json_error(array(
            'message' => $message,
            'code' => $code,
            'debug' => WP_DEBUG ? $context : null
        ));
    }
}
