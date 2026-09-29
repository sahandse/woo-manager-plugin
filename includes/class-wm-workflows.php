<?php
defined('ABSPATH') || exit;

final class WM_Workflows {
    private const PREFS = 'woo_manager_app_preferences';
    private const QUEUE = 'woo_manager_retry_queue';

    public static function install(): void {
        add_role('woo_manager_warehouse','انباردار فروشگاه',['read'=>true,'manage_woocommerce'=>true]);
        add_role('woo_manager_support','پشتیبانی فروشگاه',['read'=>true,'manage_woocommerce'=>true]);
        if (!wp_next_scheduled('woo_manager_retry_queue')) wp_schedule_event(time() + 300, 'hourly', 'woo_manager_retry_queue');
    }

    public static function register_types(): void {
        register_post_type('woo_manager_return',['public'=>false,'show_ui'=>false,'supports'=>['title','editor'],'capability_type'=>'post']);
    }

    public static function boot(): void {
        add_action('init', [self::class, 'register_types']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('woocommerce_new_order', [self::class, 'order_event'], 10, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'status_event'], 10, 4);
        add_action('woocommerce_product_set_stock_status', [self::class, 'stock_event'], 10, 3);
        add_action('woo_manager_retry_queue', [self::class, 'process_queue']);
    }

    public static function routes(): void {
        $auth = [WM_REST::class, 'can_manage'];
        register_rest_route('woo-manager/v1','/events',['methods'=>'GET','callback'=>[self::class,'events'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/packing/(?P<order_id>\\d+)',['methods'=>'GET','callback'=>[self::class,'packing'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/packing/(?P<order_id>\\d+)/scan',['methods'=>'POST','callback'=>[self::class,'packing_scan'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/packing/(?P<order_id>\\d+)/complete',['methods'=>'POST','callback'=>[self::class,'packing_complete'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/returns',[
            ['methods'=>'GET','callback'=>[self::class,'returns'],'permission_callback'=>$auth],
            ['methods'=>'POST','callback'=>[self::class,'create_return'],'permission_callback'=>$auth],
        ]);
        register_rest_route('woo-manager/v1','/returns/(?P<return_id>\\d+)',['methods'=>'PATCH','callback'=>[self::class,'update_return'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/tapin/quote/(?P<order_id>\\d+)',['methods'=>'POST','callback'=>[self::class,'tapin_quote'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/tapin/thermal',['methods'=>'POST','callback'=>[self::class,'thermal_pdf'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/retry-queue',[
            ['methods'=>'GET','callback'=>[self::class,'queue'],'permission_callback'=>$auth],
            ['methods'=>'POST','callback'=>[self::class,'retry_queue'],'permission_callback'=>$auth],
        ]);
        register_rest_route('woo-manager/v1','/reports/profit',['methods'=>'GET','callback'=>[self::class,'profit'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/team',['methods'=>'GET','callback'=>[self::class,'team'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/audit',['methods'=>'GET','callback'=>[self::class,'audit'],'permission_callback'=>$auth]);
        register_rest_route('woo-manager/v1','/preferences',[
            ['methods'=>'GET','callback'=>[self::class,'preferences'],'permission_callback'=>$auth],
            ['methods'=>'PATCH','callback'=>[self::class,'save_preferences'],'permission_callback'=>$auth],
        ]);
        register_rest_route('woo-manager/v1','/release',['methods'=>'GET','callback'=>[self::class,'release'],'permission_callback'=>$auth]);
    }

    private static function log(string $action,string $message,array $context=[]): void {
        $user=wp_get_current_user();
        $context['user_id']=$user->ID;
        $context['user_name']=$user->display_name;
        WM_Logs::add($action,$message,$context);
    }

    public static function order_event(int $order_id): void { self::log('order_created','سفارش جدید ثبت شد.',['order_id'=>$order_id,'severity'=>'info']); }
    public static function status_event(int $order_id,string $from,string $to): void { self::log('order_status_changed','وضعیت سفارش تغییر کرد.',['order_id'=>$order_id,'from'=>$from,'to'=>$to,'severity'=>'info']); }
    public static function stock_event(int $product_id,string $status,$product): void {
        if ($status==='outofstock') self::log('stock_empty','موجودی محصول تمام شد.',['product_id'=>$product_id,'product'=>$product?$product->get_name():'','severity'=>'error']);
    }

    public static function events(WP_REST_Request $r) {
        $since=max(0,absint($r->get_param('since')));
        $items=array_values(array_filter(WM_Logs::recent(200),static fn($row)=>(int)$row['id']>$since));
        return rest_ensure_response(['items'=>$items,'cursor'=>$items?(int)$items[0]['id']:$since,'server_time'=>gmdate(DATE_ATOM)]);
    }

    private static function order(int $id) {
        $order=wc_get_order($id);
        return $order ?: new WP_Error('order_not_found','سفارش پیدا نشد.',['status'=>404]);
    }

    private static function pack_state(WC_Order $order): array {
        $state=(array)$order->get_meta('_woo_manager_packing',true);
        $scanned=(array)($state['scanned']??[]);
        $items=[];
        foreach($order->get_items() as $item_id=>$item){
            $product=$item->get_product();
            $items[]=['item_id'=>(int)$item_id,'product_id'=>$item->get_product_id(),'variation_id'=>$item->get_variation_id(),'name'=>$item->get_name(),'sku'=>$product?$product->get_sku():'','required'=>(int)$item->get_quantity(),'scanned'=>(int)($scanned[$item_id]??0),'meta'=>array_values(array_map(static fn($m)=>['key'=>$m->display_key,'value'=>wp_strip_all_tags((string)$m->display_value)],$item->get_formatted_meta_data('')))];
        }
        $complete=!array_filter($items,static fn($i)=>$i['scanned']!==$i['required']);
        return ['order_id'=>$order->get_id(),'items'=>$items,'complete'=>$complete,'completed_at'=>$state['completed_at']??null,'completed_by'=>$state['completed_by']??null];
    }

    public static function packing(WP_REST_Request $r){$order=self::order(absint($r['order_id']));return is_wp_error($order)?$order:rest_ensure_response(self::pack_state($order));}
    public static function packing_scan(WP_REST_Request $r){
        $order=self::order(absint($r['order_id']));if(is_wp_error($order))return $order;
        $p=(array)$r->get_json_params();$code=sanitize_text_field((string)($p['code']??''));if($code==='')return new WP_Error('scan_required','بارکد یا SKU الزامی است.',['status'=>422]);
        $matched=null;
        foreach($order->get_items() as $item_id=>$item){$product=$item->get_product();if($product&&in_array($code,[$product->get_sku(),(string)$product->get_id(),(string)$item->get_variation_id()],true)){$matched=[$item_id,$item];break;}}
        if(!$matched)return new WP_Error('packing_mismatch','این کالا، رنگ یا مدل در سفارش وجود ندارد.',['status'=>409]);
        [$item_id,$item]=$matched;$state=(array)$order->get_meta('_woo_manager_packing',true);$state['scanned']=(array)($state['scanned']??[]);$current=(int)($state['scanned'][$item_id]??0);
        if($current>=(int)$item->get_quantity())return new WP_Error('packing_overflow','تعداد اسکن‌شده بیشتر از سفارش است.',['status'=>409]);
        $state['scanned'][$item_id]=$current+1;$state['updated_at']=gmdate(DATE_ATOM);$order->update_meta_data('_woo_manager_packing',$state);$order->save();
        self::log('packing_scan','کالا برای بسته‌بندی اسکن شد.',['order_id'=>$order->get_id(),'item_id'=>$item_id,'code'=>$code]);
        return rest_ensure_response(self::pack_state($order));
    }
    public static function packing_complete(WP_REST_Request $r){
        $order=self::order(absint($r['order_id']));if(is_wp_error($order))return $order;$state=self::pack_state($order);if(!$state['complete'])return new WP_Error('packing_incomplete','تعداد یا مدل بعضی کالاها هنوز تطبیق ندارد.',['status'=>409]);
        $user=wp_get_current_user();$meta=(array)$order->get_meta('_woo_manager_packing',true);$meta['completed_at']=gmdate(DATE_ATOM);$meta['completed_by']=$user->display_name;$order->update_meta_data('_woo_manager_packing',$meta);$order->add_order_note('بسته‌بندی با اسکن کامل شد. اپراتور: '.$user->display_name);$order->save();
        self::log('packing_completed','بسته‌بندی سفارش تکمیل شد.',['order_id'=>$order->get_id()]);return rest_ensure_response(self::pack_state($order));
    }

    private static function serialize_return(WP_Post $post): array {
        return ['id'=>$post->ID,'order_id'=>(int)get_post_meta($post->ID,'order_id',true),'status'=>get_post_meta($post->ID,'status',true),'reason'=>$post->post_content,'resolution'=>get_post_meta($post->ID,'resolution',true),'amount'=>get_post_meta($post->ID,'amount',true),'image_id'=>(int)get_post_meta($post->ID,'image_id',true),'created_at'=>$post->post_date_gmt];
    }
    public static function returns(){ $posts=get_posts(['post_type'=>'woo_manager_return','post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC']);return rest_ensure_response(array_map([self::class,'serialize_return'],$posts)); }
    public static function create_return(WP_REST_Request $r){
        $p=(array)$r->get_json_params();$order=self::order(absint($p['order_id']??0));if(is_wp_error($order))return $order;$reason=sanitize_textarea_field((string)($p['reason']??''));if($reason==='')return new WP_Error('reason_required','علت مرجوعی الزامی است.',['status'=>422]);
        $id=wp_insert_post(['post_type'=>'woo_manager_return','post_status'=>'private','post_title'=>'مرجوعی سفارش '.$order->get_id(),'post_content'=>$reason],true);if(is_wp_error($id))return $id;
        update_post_meta($id,'order_id',$order->get_id());update_post_meta($id,'status','requested');update_post_meta($id,'resolution','pending');update_post_meta($id,'amount',wc_format_decimal($p['amount']??0));update_post_meta($id,'image_id',absint($p['image_id']??0));
        $order->add_order_note('درخواست مرجوعی #'.$id.' ثبت شد: '.$reason);self::log('return_created','درخواست مرجوعی ثبت شد.',['return_id'=>$id,'order_id'=>$order->get_id()]);return new WP_REST_Response(self::serialize_return(get_post($id)),201);
    }
    public static function update_return(WP_REST_Request $r){
        $post=get_post(absint($r['return_id']));if(!$post||$post->post_type!=='woo_manager_return')return new WP_Error('return_not_found','درخواست مرجوعی پیدا نشد.',['status'=>404]);
        $p=(array)$r->get_json_params();$allowed=['requested','received','approved','rejected','refunded','exchanged'];$status=sanitize_key((string)($p['status']??''));if(!in_array($status,$allowed,true))return new WP_Error('invalid_return_status','وضعیت مرجوعی معتبر نیست.',['status'=>422]);
        update_post_meta($post->ID,'status',$status);if(isset($p['resolution']))update_post_meta($post->ID,'resolution',sanitize_text_field($p['resolution']));
        self::log('return_updated','وضعیت مرجوعی تغییر کرد.',['return_id'=>$post->ID,'status'=>$status]);return rest_ensure_response(self::serialize_return($post));
    }

    public static function tapin_quote(WP_REST_Request $r){
        $order=self::order(absint($r['order_id']));if(is_wp_error($order))return $order;$mapped=WM_Order_Mapper::from_order($order,(array)$r->get_json_params());if(isset($mapped['error']))return $mapped['error'];$result=WM_Tapin::check_price($mapped['payload']);if(!is_wp_error($result))self::log('tapin_quote','هزینه ارسال تاپین استعلام شد.',['order_id'=>$order->get_id()]);return $result;
    }
    public static function thermal_pdf(WP_REST_Request $r){
        $p=(array)$r->get_json_params();$ids=array_values((array)($p['ids']??[]));if(!$ids)return new WP_Error('missing_ids','شناسه مرسوله‌ها الزامی است.',['status'=>422]);$html=WM_Tapin::label($ids);if(is_wp_error($html))return $html;return WM_PDF::from_html($html,'tapin-label-10x15-'.gmdate('Ymd-His').'.pdf','A6');
    }

    public static function enqueue(string $type,array $payload,string $error): void {
        $queue=(array)get_option(self::QUEUE,[]);$queue[]=array_merge(['id'=>wp_generate_uuid4(),'type'=>$type,'attempts'=>0,'last_error'=>sanitize_text_field($error),'created_at'=>gmdate(DATE_ATOM)],$payload);update_option(self::QUEUE,array_slice($queue,-500),false);
    }
    public static function queue(){return rest_ensure_response(['items'=>array_values((array)get_option(self::QUEUE,[]))]);}
    public static function retry_queue(){self::process_queue();return self::queue();}
    public static function process_queue(): void {
        $queue=(array)get_option(self::QUEUE,[]);$remaining=[];
        foreach($queue as $job){$ok=false;if(($job['type']??'')==='tapin'&&!empty($job['order_id'])){$order=wc_get_order(absint($job['order_id']));if($order){$mapped=WM_Order_Mapper::from_order($order,(array)($job['options']??[]));if(!isset($mapped['error'])){$result=WM_Tapin::register($mapped['payload']);$ok=!is_wp_error($result);if(!$ok)$job['last_error']=$result->get_error_message();}}}$job['attempts']=(int)($job['attempts']??0)+1;if(!$ok&&$job['attempts']<10)$remaining[]=$job;}
        update_option(self::QUEUE,$remaining,false);
    }

    public static function profit(WP_REST_Request $r){
        $days=min(365,max(1,absint($r->get_param('days')?:30)));$orders=wc_get_orders(['limit'=>-1,'status'=>['wc-processing','wc-completed'],'date_created'=>'>'.(time()-$days*DAY_IN_SECONDS)]);
        $revenue=$cost=$shipping=$refund=0.0;
        foreach($orders as $order){$revenue+=(float)$order->get_total();$shipping+=(float)$order->get_shipping_total();$refund+=(float)$order->get_total_refunded();foreach($order->get_items() as $item){$product=$item->get_product();$unit=$product?(float)($product->get_meta('_woo_manager_cost')?:$product->get_meta('_wc_cog_cost')):0;$cost+=$unit*(int)$item->get_quantity();}}
        return rest_ensure_response(['days'=>$days,'currency'=>get_woocommerce_currency(),'revenue'=>wc_format_decimal($revenue,0),'product_cost'=>wc_format_decimal($cost,0),'shipping'=>wc_format_decimal($shipping,0),'refunds'=>wc_format_decimal($refund,0),'profit'=>wc_format_decimal($revenue-$cost-$shipping-$refund,0),'orders_count'=>count($orders)]);
    }
    public static function team(){ $users=get_users(['role__in'=>['administrator','shop_manager','woo_manager_warehouse','woo_manager_support']]);return rest_ensure_response(array_map(static fn($u)=>['id'=>$u->ID,'name'=>$u->display_name,'roles'=>$u->roles],$users)); }
    public static function audit(WP_REST_Request $r){return rest_ensure_response(WM_Logs::recent(min(200,max(1,absint($r->get_param('limit')?:100)))));}
    public static function preferences(){return rest_ensure_response(array_merge(['theme'=>'system','color'=>'emerald','thermal_size'=>'10x15','notifications'=>true],(array)get_option(self::PREFS,[])));}
    public static function save_preferences(WP_REST_Request $r){$p=(array)$r->get_json_params();$clean=['theme'=>in_array($p['theme']??'system',['system','light','dark'],true)?$p['theme']:'system','color'=>sanitize_key((string)($p['color']??'emerald')),'thermal_size'=>in_array($p['thermal_size']??'10x15',['10x15','A6'],true)?$p['thermal_size']:'10x15','notifications'=>!empty($p['notifications'])];update_option(self::PREFS,$clean,false);self::log('preferences_updated','تنظیمات ظاهری اپ تغییر کرد.');return rest_ensure_response($clean);}
    public static function release(){return rest_ensure_response(['plugin_version'=>WOO_MANAGER_VERSION,'api_version'=>2,'minimum_app_version'=>'1.1.0','download_url'=>apply_filters('woo_manager_app_download_url',''),'compatible'=>true]);}
}
