<?php
defined('ABSPATH') || exit;

final class WM_REST {
    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); add_action('woocommerce_order_status_processing',[self::class,'auto_register']); }
    public static function routes(): void {
        register_rest_route('woo-manager/v1', '/pair', ['methods'=>'POST','callback'=>[self::class,'pair'],'permission_callback'=>'__return_true']);
        register_rest_route('woo-manager/v1', '/health', ['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/orders', ['methods'=>'GET','callback'=>[self::class,'orders'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/tapin/register/(?P<order_id>\d+)', ['methods'=>'POST','callback'=>[self::class,'tapin_register'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/tapin/label', ['methods'=>'POST','callback'=>[self::class,'tapin_label'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/tapin/invoice', ['methods'=>'POST','callback'=>[self::class,'tapin_invoice'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/tapin/pdf', ['methods'=>'POST','callback'=>[self::class,'tapin_pdf'],'permission_callback'=>[self::class,'can_manage']]);
    }
    public static function can_manage(WP_REST_Request $request): bool { return current_user_can('manage_woocommerce') || WM_Devices::authenticate($request); }
    public static function pair(WP_REST_Request $request) {
        $p=(array)$request->get_json_params();
        if (!preg_match('/^\d{6}$/',(string)($p['code']??'')) || empty($p['device_id'])) return new WP_Error('invalid_pair_request','کد شش‌رقمی و شناسه دستگاه الزامی است.',['status'=>422]);
        return WM_Devices::exchange((string)$p['code'],sanitize_text_field($p['device_id']),sanitize_text_field($p['device_name']??'Mobile'));
    }
    public static function health(): WP_REST_Response {
        return rest_ensure_response(['ok'=>true,'version'=>WOO_MANAGER_VERSION,'woocommerce'=>class_exists('WooCommerce'),'tapin'=>WM_Tapin::health(),'sms_provider'=>WM_Settings::get('sms_provider','melipayamak')]);
    }
    public static function orders(WP_REST_Request $request) {
        if (!function_exists('wc_get_orders')) return new WP_Error('woocommerce_missing','ووکامرس فعال نیست.',['status'=>503]);
        $orders = wc_get_orders(['limit'=>min(50,max(1,(int)$request->get_param('limit'))),'orderby'=>'date','order'=>'DESC']);
        return rest_ensure_response(array_map(static function($order){
            $created = $order->get_date_created();
            return ['id'=>$order->get_id(),'status'=>$order->get_status(),'total'=>$order->get_total(),'currency'=>$order->get_currency(),'customer'=>$order->get_formatted_billing_full_name(),'date'=>$created ? $created->date(DATE_ATOM) : null,'tapin_order_id'=>$order->get_meta('_tapin_order_id')?:null,'tapin_uuid'=>$order->get_meta('_tapin_uuid')?:null,'tracking_number'=>$order->get_meta('_tracking_number')?:null];
        }, $orders));
    }
    public static function tapin_register(WP_REST_Request $request) {
        $wc_id=absint($request['order_id']); $order=wc_get_order($wc_id);
        if (!$order) return new WP_Error('order_not_found','سفارش پیدا نشد.',['status'=>404]);
        $mapped=WM_Order_Mapper::from_order($order,(array)$request->get_json_params());
        if (isset($mapped['error'])) return $mapped['error'];
        $result=WM_Tapin::register($mapped['payload']);
        if (!is_wp_error($result)) {
            $entries=$result['entries']??[];
            if (!empty($entries['order_id'])) { $detail=WM_Tapin::detail((int)$entries['order_id']); if(!is_wp_error($detail) && !empty($detail['entries'])) $entries=array_merge($entries,$detail['entries']); }
            $result['entries']=$entries;
            $order->update_meta_data('_woo_manager_tapin',$entries); $order->update_meta_data('_tapin_order_id',$entries['order_id']??''); $order->update_meta_data('_tapin_uuid',$entries['id']??''); $order->update_meta_data('_tracking_number',$entries['barcode']??''); $order->save();
            if (!empty($entries['barcode'])) self::send_tracking_sms($order,(string)$entries['barcode']);
        }
        return $result;
    }
    private static function send_tracking_sms(WC_Order $order,string $code): void {
        $message=str_replace(['{tracking_code}','{order_id}'],[$code,(string)$order->get_id()],WM_Settings::get('tracking_message','سفارش شما ارسال شد. کد رهگیری: {tracking_code}'));
        $provider=WM_Settings::get('sms_provider','melipayamak')==='farazsms'?new WM_FarazSMS():new WM_Melipayamak();
        $sent=$provider->send($order->get_billing_phone(),$message); $order->add_order_note(is_wp_error($sent)?'خطا در ارسال پیامک رهگیری: '.$sent->get_error_message():'پیامک کد رهگیری ارسال شد.');
    }
    public static function auto_register(int $order_id): void {
        if(WM_Settings::get('tapin_auto_register','no')!=='yes')return; $order=wc_get_order($order_id); if(!$order||$order->get_meta('_tapin_order_id'))return;
        $mapped=WM_Order_Mapper::from_order($order,[]); if(isset($mapped['error'])){$order->add_order_note('ثبت خودکار تاپین انجام نشد: '.$mapped['error']->get_error_message());return;}
        $result=WM_Tapin::register($mapped['payload']); if(is_wp_error($result)){$order->add_order_note('خطای تاپین: '.$result->get_error_message());return;}
        $entries=$result['entries']??[]; if(!empty($entries['order_id'])){$detail=WM_Tapin::detail((int)$entries['order_id']);if(!is_wp_error($detail)&&!empty($detail['entries']))$entries=array_merge($entries,$detail['entries']);}
        $order->update_meta_data('_tapin_order_id',$entries['order_id']??'');$order->update_meta_data('_tapin_uuid',$entries['id']??'');$order->update_meta_data('_tracking_number',$entries['barcode']??'');$order->save();if(!empty($entries['barcode']))self::send_tracking_sms($order,(string)$entries['barcode']);
    }
    public static function tapin_label(WP_REST_Request $r) { $params=(array)$r->get_json_params(); $ids=(array)($params['ids']??[]); if(!$ids)return new WP_Error('missing_ids','شناسه مرسوله‌ها الزامی است.',['status'=>422]); $html=WM_Tapin::label($ids); return is_wp_error($html)?$html:new WP_REST_Response($html,200,['Content-Type'=>'text/html; charset=utf-8']); }
    public static function tapin_invoice(WP_REST_Request $r) { $params=(array)$r->get_json_params(); $ids=(array)($params['ids']??[]); if(!$ids)return new WP_Error('missing_ids','شناسه سفارش‌های تاپین الزامی است.',['status'=>422]); $html=WM_Tapin::invoice($ids); return is_wp_error($html)?$html:new WP_REST_Response($html,200,['Content-Type'=>'text/html; charset=utf-8']); }
    public static function tapin_pdf(WP_REST_Request $r) { $p=(array)$r->get_json_params();$ids=(array)($p['ids']??[]);$type=($p['type']??'invoice')==='label'?'label':'invoice';if(!$ids)return new WP_Error('missing_ids','شناسه مرسوله‌ها الزامی است.',['status'=>422]);$html=$type==='label'?WM_Tapin::label($ids):WM_Tapin::invoice($ids);if(is_wp_error($html))return $html;return WM_PDF::from_html($html,'tapin-'.$type.'-'.gmdate('Ymd-His').'.pdf');}
}
