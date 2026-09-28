<?php
defined('ABSPATH') || exit;
final class WM_FarazSMS implements WM_SMS_Provider {
    public function is_configured(): bool { return WM_Settings::get('faraz_api_key') !== ''; }
    public function send(string $mobile, string $message) {
        if (!$this->is_configured()) return new WP_Error('sms_not_configured', 'فراز SMS تنظیم نشده است.');
        return wp_remote_post('https://edge.ippanel.com/v1/api/send', ['timeout'=>20,'headers'=>['Authorization'=>WM_Settings::get('faraz_api_key'),'Content-Type'=>'application/json'],'body'=>wp_json_encode(['sending_type'=>'webservice','from_number'=>WM_Settings::get('faraz_from'),'message'=>$message,'params'=>['recipients'=>[$mobile]]])]);
    }
}
