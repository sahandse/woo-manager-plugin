<?php
defined('ABSPATH') || exit;

final class WM_REST {
    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); add_action('woocommerce_order_status_processing',[self::class,'auto_register']); }
    public static function routes(): void {
        register_rest_route('woo-manager/v1', '/pair', ['methods'=>'POST','callback'=>[self::class,'pair'],'permission_callback'=>'__return_true']);
        register_rest_route('woo-manager/v1', '/health', ['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/orders', ['methods'=>'GET','callback'=>[self::class,'orders'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)', [
            ['methods'=>'GET','callback'=>[self::class,'order_detail'],'permission_callback'=>[self::class,'can_manage']],
            ['methods'=>'PATCH','callback'=>[self::class,'update_order'],'permission_callback'=>[self::class,'can_manage']],
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/notes', ['methods'=>'POST','callback'=>[self::class,'add_note'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/refunds', ['methods'=>'POST','callback'=>[self::class,'create_refund'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/products', ['methods'=>'GET','callback'=>[self::class,'products'],'permission_callback'=>[self::class,'can_manage']]);
        register_rest_route('woo-manager/v1', '/products/(?P<product_id>\d+)', [
            ['methods'=>'GET','callback'=>[self::class,'product_detail'],'permission_callback'=>[self::class,'can_manage']],
            ['methods'=>'PATCH','callback'=>[self::class,'update_product'],'permission_callback'=>[self::class,'can_manage']],
        ]);
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
    private static function get_order(WP_REST_Request $request) {
        if (!function_exists('wc_get_order')) return new WP_Error('woocommerce_missing','ووکامرس فعال نیست.',['status'=>503]);
        $order=wc_get_order(absint($request['order_id']));
        return $order ?: new WP_Error('order_not_found','سفارش پیدا نشد.',['status'=>404]);
    }
    private static function address(WC_Order $order,string $type): array {
        $shipping=$type==='shipping';
        $get=static function(string $field) use($order,$shipping){$method='get_'.($shipping?'shipping_':'billing_').$field;return method_exists($order,$method)?$order->{$method}():'';};
        return ['first_name'=>$get('first_name'),'last_name'=>$get('last_name'),'company'=>$get('company'),'address_1'=>$get('address_1'),'address_2'=>$get('address_2'),'city'=>$get('city'),'state'=>$get('state'),'postcode'=>$get('postcode'),'country'=>$get('country'),'phone'=>$shipping && method_exists($order,'get_shipping_phone')?$order->get_shipping_phone():$order->get_billing_phone(),'email'=>$order->get_billing_email()];
    }
    private static function serialize_order(WC_Order $order): array {
        $items=[];
        foreach($order->get_items() as $item){$product=$item->get_product();$items[]=['id'=>$item->get_id(),'product_id'=>$item->get_product_id(),'variation_id'=>$item->get_variation_id(),'name'=>$item->get_name(),'quantity'=>(int)$item->get_quantity(),'subtotal'=>$item->get_subtotal(),'total'=>$item->get_total(),'sku'=>$product?$product->get_sku():'','image'=>$product&&$product->get_image_id()?wp_get_attachment_image_url($product->get_image_id(),'thumbnail'):null,'meta'=>array_values(array_map(static function($meta){return ['key'=>$meta->display_key,'value'=>wp_strip_all_tags((string)$meta->display_value)];},$item->get_formatted_meta_data('')) )];}
        $notes=wc_get_order_notes(['order_id'=>$order->get_id(),'limit'=>100,'orderby'=>'date_created','order'=>'DESC']);
        $history=array_map(static function($note){return ['id'=>$note->id,'content'=>wp_strip_all_tags($note->content),'customer_note'=>(bool)$note->customer_note,'date'=>$note->date_created?$note->date_created->date(DATE_ATOM):null,'author'=>$note->added_by];},$notes);
        $refunds=array_map(static function($refund){$created=$refund->get_date_created();return ['id'=>$refund->get_id(),'amount'=>$refund->get_amount(),'reason'=>$refund->get_reason(),'date'=>$created?$created->date(DATE_ATOM):null];},$order->get_refunds());
        $created=$order->get_date_created();$updated=$order->get_date_modified();
        return ['id'=>$order->get_id(),'number'=>$order->get_order_number(),'status'=>$order->get_status(),'status_options'=>array_map(static fn($label,$key)=>['value'=>str_replace('wc-','',$key),'label'=>$label],wc_get_order_statuses(),array_keys(wc_get_order_statuses())),'currency'=>$order->get_currency(),'total'=>$order->get_total(),'subtotal'=>$order->get_subtotal(),'discount_total'=>$order->get_discount_total(),'shipping_total'=>$order->get_shipping_total(),'tax_total'=>$order->get_total_tax(),'total_refunded'=>$order->get_total_refunded(),'refundable_amount'=>$order->get_remaining_refund_amount(),'payment_method'=>$order->get_payment_method_title(),'transaction_id'=>$order->get_transaction_id(),'customer_note'=>$order->get_customer_note(),'created_at'=>$created?$created->date(DATE_ATOM):null,'updated_at'=>$updated?$updated->date(DATE_ATOM):null,'billing'=>self::address($order,'billing'),'shipping'=>self::address($order,'shipping'),'shipping_method'=>$order->get_shipping_method(),'items'=>$items,'refunds'=>$refunds,'history'=>$history,'shipment'=>['tapin_order_id'=>$order->get_meta('_tapin_order_id')?:null,'tapin_uuid'=>$order->get_meta('_tapin_uuid')?:null,'tracking_number'=>$order->get_meta('_tracking_number')?:null,'province_code'=>$order->get_meta('_tapin_province_code')?:null,'city_code'=>$order->get_meta('_tapin_city_code')?:null,'tapin'=>$order->get_meta('_woo_manager_tapin')?:null]];
    }
    public static function order_detail(WP_REST_Request $request){$order=self::get_order($request);return is_wp_error($order)?$order:rest_ensure_response(self::serialize_order($order));}
    public static function update_order(WP_REST_Request $request){
        $order=self::get_order($request);if(is_wp_error($order))return $order;$p=(array)$request->get_json_params();$status=sanitize_key((string)($p['status']??''));$allowed=array_map(static fn($key)=>str_replace('wc-','',$key),array_keys(wc_get_order_statuses()));if(!$status||!in_array($status,$allowed,true))return new WP_Error('invalid_status','وضعیت سفارش معتبر نیست.',['status'=>422]);
        try{$order->update_status($status,'وضعیت از اپ Woo Manager تغییر کرد.',true);return rest_ensure_response(self::serialize_order($order));}catch(Throwable $e){return new WP_Error('status_update_failed',$e->getMessage(),['status'=>500]);}
    }
    public static function add_note(WP_REST_Request $request){
        $order=self::get_order($request);if(is_wp_error($order))return $order;$p=(array)$request->get_json_params();$content=trim(sanitize_textarea_field((string)($p['content']??'')));if($content==='')return new WP_Error('empty_note','متن یادداشت الزامی است.',['status'=>422]);$customer_note=rest_sanitize_boolean($p['customer_note']??false);$id=$order->add_order_note($content,$customer_note,true);return new WP_REST_Response(['id'=>$id,'order'=>self::serialize_order($order)],201);
    }
    public static function create_refund(WP_REST_Request $request){
        $order=self::get_order($request);if(is_wp_error($order))return $order;$p=(array)$request->get_json_params();if(($p['confirm']??false)!==true)return new WP_Error('refund_confirmation_required','برای بازپرداخت، تأیید صریح الزامی است.',['status'=>409]);$amount=(float)wc_format_decimal($p['amount']??0);$remaining=(float)$order->get_remaining_refund_amount();if($amount<=0||$amount>$remaining)return new WP_Error('invalid_refund_amount','مبلغ بازپرداخت باید بیشتر از صفر و حداکثر مبلغ قابل بازپرداخت باشد.',['status'=>422]);
        $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>$amount,'reason'=>sanitize_text_field((string)($p['reason']??'')),'refund_payment'=>rest_sanitize_boolean($p['refund_payment']??false),'restock_items'=>rest_sanitize_boolean($p['restock_items']??false)]);if(is_wp_error($refund))return $refund;$order->add_order_note(sprintf('بازپرداخت مبلغ %s از اپ Woo Manager ثبت شد.',wc_price($amount,['currency'=>$order->get_currency()])));return new WP_REST_Response(['refund_id'=>$refund->get_id(),'order'=>self::serialize_order($order)],201);
    }
    private static function product_summary(WC_Product $product): array {
        $image_id=$product->get_image_id();
        return ['id'=>$product->get_id(),'name'=>$product->get_name(),'type'=>$product->get_type(),'status'=>$product->get_status(),'sku'=>$product->get_sku(),'price'=>$product->get_price(),'regular_price'=>$product->get_regular_price(),'sale_price'=>$product->get_sale_price(),'stock_status'=>$product->get_stock_status(),'stock_quantity'=>$product->get_stock_quantity(),'manage_stock'=>$product->get_manage_stock(),'featured'=>$product->get_featured(),'image'=>$image_id?wp_get_attachment_image_url($image_id,'woocommerce_thumbnail'):null];
    }
    private static function serialize_product(WC_Product $product): array {
        $data=self::product_summary($product);$images=[];
        foreach(array_filter(array_merge([$product->get_image_id()],$product->get_gallery_image_ids())) as $image_id){$images[]=['id'=>(int)$image_id,'src'=>wp_get_attachment_image_url($image_id,'large'),'thumbnail'=>wp_get_attachment_image_url($image_id,'woocommerce_thumbnail')];}
        $categories=array_map(static fn($term)=>['id'=>$term->term_id,'name'=>$term->name],wp_get_post_terms($product->get_id(),'product_cat'));
        $attributes=[];foreach($product->get_attributes() as $attribute){$attributes[]=['name'=>wc_attribute_label($attribute->get_name()),'options'=>$attribute->is_taxonomy()?wc_get_product_terms($product->get_id(),$attribute->get_name(),['fields'=>'names']):$attribute->get_options(),'variation'=>$attribute->get_variation(),'visible'=>$attribute->get_visible()];}
        $variations=[];if($product->is_type('variable')){foreach($product->get_children() as $variation_id){$variation=wc_get_product($variation_id);if($variation)$variations[]=array_merge(self::product_summary($variation),['attributes'=>$variation->get_attributes()]);}}
        return array_merge($data,['description'=>$product->get_description(),'short_description'=>$product->get_short_description(),'weight'=>$product->get_weight(),'dimensions'=>['length'=>$product->get_length(),'width'=>$product->get_width(),'height'=>$product->get_height()],'categories'=>$categories,'attributes'=>$attributes,'images'=>$images,'variations'=>$variations,'permalink'=>$product->get_permalink(),'date_modified'=>$product->get_date_modified()?$product->get_date_modified()->date(DATE_ATOM):null]);
    }
    public static function products(WP_REST_Request $request){
        if(!function_exists('wc_get_products'))return new WP_Error('woocommerce_missing','ووکامرس فعال نیست.',['status'=>503]);$limit=min(50,max(1,(int)($request->get_param('limit')?:30)));$page=max(1,(int)($request->get_param('page')?:1));$args=['limit'=>$limit,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC'];$search=trim(sanitize_text_field((string)$request->get_param('search')));if($search!=='')$args['s']=$search;$status=sanitize_key((string)$request->get_param('status'));if($status!=='')$args['status']=$status;$result=wc_get_products($args);return rest_ensure_response(['items'=>array_map([self::class,'product_summary'],$result->products),'total'=>(int)$result->total,'pages'=>(int)$result->max_num_pages,'page'=>$page]);
    }
    private static function get_product(WP_REST_Request $request){$product=wc_get_product(absint($request['product_id']));return $product?:new WP_Error('product_not_found','محصول پیدا نشد.',['status'=>404]);}
    public static function product_detail(WP_REST_Request $request){$product=self::get_product($request);return is_wp_error($product)?$product:rest_ensure_response(self::serialize_product($product));}
    public static function update_product(WP_REST_Request $request){
        $product=self::get_product($request);if(is_wp_error($product))return $product;$p=(array)$request->get_json_params();
        if(array_key_exists('name',$p))$product->set_name(sanitize_text_field((string)$p['name']));if(array_key_exists('sku',$p)){$sku=wc_clean((string)$p['sku']);try{$product->set_sku($sku);}catch(Exception $e){return new WP_Error('invalid_sku',$e->getMessage(),['status'=>422]);}}
        foreach(['regular_price','sale_price'] as $field){if(array_key_exists($field,$p)){$value=$p[$field]===''?'':wc_format_decimal($p[$field]);$method='set_'.$field;$product->{$method}($value);}}
        if(array_key_exists('manage_stock',$p))$product->set_manage_stock(rest_sanitize_boolean($p['manage_stock']));if(array_key_exists('stock_quantity',$p)&&$product->get_manage_stock())$product->set_stock_quantity(max(0,(int)$p['stock_quantity']));
        if(array_key_exists('stock_status',$p)){if(!in_array($p['stock_status'],['instock','outofstock','onbackorder'],true))return new WP_Error('invalid_stock_status','وضعیت موجودی معتبر نیست.',['status'=>422]);$product->set_stock_status($p['stock_status']);}
        if(array_key_exists('status',$p)){if(!in_array($p['status'],['publish','draft','pending','private'],true))return new WP_Error('invalid_product_status','وضعیت انتشار معتبر نیست.',['status'=>422]);$product->set_status($p['status']);}
        if(array_key_exists('featured',$p))$product->set_featured(rest_sanitize_boolean($p['featured']));if(array_key_exists('description',$p))$product->set_description(wp_kses_post((string)$p['description']));if(array_key_exists('short_description',$p))$product->set_short_description(wp_kses_post((string)$p['short_description']));
        try{$product->save();return rest_ensure_response(self::serialize_product($product));}catch(Throwable $e){return new WP_Error('product_update_failed',$e->getMessage(),['status'=>500]);}
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
