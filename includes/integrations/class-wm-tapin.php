<?php
defined('ABSPATH') || exit;

final class WM_Tapin {
    private const BASE = 'https://api.tapin.ir/api/v2/public/';

    private static function request(string $method, string $path, array $body = []) {
        $token = WM_Settings::get('tapin_token');
        if ($token === '') return new WP_Error('tapin_not_configured', 'توکن تاپین تنظیم نشده است.', ['status' => 422]);
        $args = ['method' => $method, 'timeout' => 30, 'headers' => ['Authorization' => $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
        if ($body) $args['body'] = wp_json_encode($body);
        $response = wp_remote_request(self::BASE . ltrim($path, '/'), $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) return new WP_Error('tapin_api_error', sanitize_text_field($data['message'] ?? 'خطا در ارتباط با تاپین'), ['status' => $code, 'response' => $data]);
        return is_array($data) ? $data : [];
    }

    public static function register(array $payload) {
        $payload['shop_id'] = $payload['shop_id'] ?? WM_Settings::get('tapin_shop_id');
        return self::request('POST', 'order/post/register/', $payload);
    }

    public static function health() {
        return WM_Settings::get('tapin_token') !== '' && WM_Settings::get('tapin_shop_id') !== '';
    }
}
