<?php
defined('ABSPATH') || exit;
final class WM_Melipayamak implements WM_SMS_Provider {
    public function is_configured(): bool { return WM_Settings::get('melipayamak_username') !== '' && WM_Settings::get('melipayamak_password') !== ''; }
    public function send(string $mobile, string $message) {
        if (!$this->is_configured()) return new WP_Error('sms_not_configured', 'ملی‌پیامک تنظیم نشده است.');
        return wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', ['timeout'=>20,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['username'=>WM_Settings::get('melipayamak_username'),'password'=>WM_Settings::get('melipayamak_password'),'to'=>$mobile,'from'=>WM_Settings::get('melipayamak_from'),'text'=>$message,'isFlash'=>false])]);
    }
}
