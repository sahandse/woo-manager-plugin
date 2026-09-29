<?php
defined('ABSPATH') || exit;
final class WM_Melipayamak implements WM_SMS_Provider {
    public function is_configured(): bool { return WM_Settings::get('melipayamak_username') !== '' && WM_Settings::get('melipayamak_password') !== '' && WM_Settings::get('melipayamak_from') !== ''; }
    public function send(string $mobile, string $message) {
        if (!$this->is_configured()) return new WP_Error('sms_not_configured', 'ملی‌پیامک تنظیم نشده است.');
        $response=wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', ['timeout'=>20,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['username'=>WM_Settings::get('melipayamak_username'),'password'=>WM_Settings::get('melipayamak_password'),'to'=>$mobile,'from'=>WM_Settings::get('melipayamak_from'),'text'=>$message,'isFlash'=>false])]);
        if(is_wp_error($response))return $response;
        $code=wp_remote_retrieve_response_code($response);
        if($code<200||$code>=300)return new WP_Error('melipayamak_http_error','ملی‌پیامک پاسخ ناموفق داد: '.$code);
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if(is_array($body)&&isset($body['RetStatus'])&&(int)$body['RetStatus']!==1)return new WP_Error('melipayamak_api_error',sanitize_text_field((string)($body['StrRetStatus']??'ارسال پیامک ناموفق بود.')));
        return $response;
    }
}
