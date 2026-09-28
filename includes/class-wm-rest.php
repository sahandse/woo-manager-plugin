<?php
defined('ABSPATH') || exit;

final class WM_REST {
    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); }
    public static function routes(): void {
        register_rest_route('woo-manager/v1', '/health', ['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/orders', ['methods'=>'GET','callback'=>[self::class,'orders'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/tapin/register', ['methods'=>'POST','callback'=>[self::class,'tapin_register'],'permission_callback'=>[self::class,'can_manage']]);
    }
    public static function can_manage(): bool { return current_user_can('manage_woocommerce'); }
    public static function health(): WP_REST_Response {
        return rest_ensure_response(['ok'=>true,'version'=>WOO_MANAGER_VERSION,'woocommerce'=>class_exists('WooCommerce'),'tapin'=>WM_Tapin::health(),'sms_provider'=>WM_Settings::get('sms_provider','melipayamak')]);
    }
    public static function orders(WP_REST_Request $request) {
        if (!function_exists('wc_get_orders')) return new WP_Error('woocommerce_missing','ووکامرس فعال نیست.',['status'=>503]);
        $orders = wc_get_orders(['limit'=>min(50,max(1,(int)$request->get_param('limit'))),'orderby'=>'date','order'=>'DESC']);
        return rest_ensure_response(array_map(static function($order){
            $created = $order->get_date_created();
            return ['id'=>$order->get_id(),'status'=>$order->get_status(),'total'=>$order->get_total(),'currency'=>$order->get_currency(),'customer'=>$order->get_formatted_billing_full_name(),'date'=>$created ? $created->date(DATE_ATOM) : null];
        }, $orders));
    }
    public static function tapin_register(WP_REST_Request $request) {
        $payload = (array)$request->get_json_params();
        if (empty($payload['order_id'])) return new WP_Error('missing_order_id','شناسه سفارش الزامی است.',['status'=>422]);
        $result = WM_Tapin::register($payload);
        if (!is_wp_error($result)) update_post_meta(absint($payload['order_id']), '_woo_manager_tapin', wp_json_encode($result));
        return $result;
    }
}
