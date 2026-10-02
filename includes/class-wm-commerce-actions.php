<?php
defined('ABSPATH') || exit;

final class WM_Commerce_Actions {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes'], 20);
    }

    public static function routes(): void {
        $auth = [WM_REST::class, 'can_manage'];
        register_rest_route('woo-manager/v1', '/products', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_product'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\\d+)/message', [
            'methods' => 'POST',
            'callback' => [self::class, 'send_order_message'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\\d+)/message-templates', [
            'methods' => 'GET',
            'callback' => [self::class, 'message_templates'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\\d+)/shipping-ready', [
            'methods' => 'POST',
            'callback' => [self::class, 'shipping_ready'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/tapin/options/(?P<order_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'tapin_options'],
            'permission_callback' => $auth,
        ]);
    }

    private static function provider(): WM_SMS_Provider {
        return WM_Settings::get('sms_provider', 'melipayamak') === 'farazsms'
            ? new WM_FarazSMS()
            : new WM_Melipayamak();
    }

    public static function create_product(WP_REST_Request $request) {
        if (!class_exists('WC_Product_Simple')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست.', ['status' => 503]);
        }
        $p = (array) $request->get_json_params();
        $name = sanitize_text_field((string) ($p['name'] ?? ''));
        if ($name === '') return new WP_Error('product_name_required', 'نام محصول الزامی است.', ['status' => 422]);

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status(in_array(($p['status'] ?? 'draft'), ['publish','draft','pending','private'], true) ? $p['status'] : 'draft');
        $product->set_catalog_visibility('visible');
        $product->set_description(wp_kses_post((string) ($p['description'] ?? '')));
        $product->set_short_description(wp_kses_post((string) ($p['short_description'] ?? '')));
        $product->set_regular_price(wc_format_decimal($p['regular_price'] ?? ''));
        $product->set_sale_price(wc_format_decimal($p['sale_price'] ?? ''));
        $product->set_featured(rest_sanitize_boolean($p['featured'] ?? false));

        $sku = wc_clean((string) ($p['sku'] ?? ''));
        if ($sku !== '') {
            try { $product->set_sku($sku); }
            catch (Exception $e) { return new WP_Error('invalid_sku', $e->getMessage(), ['status' => 422]); }
        }

        $manage = rest_sanitize_boolean($p['manage_stock'] ?? false);
        $product->set_manage_stock($manage);
        if ($manage) $product->set_stock_quantity(max(0, (int) ($p['stock_quantity'] ?? 0)));
        $stock_status = sanitize_key((string) ($p['stock_status'] ?? 'instock'));
        if (!in_array($stock_status, ['instock','outofstock','onbackorder'], true)) $stock_status = 'instock';
        $product->set_stock_status($stock_status);

        $weight = wc_format_decimal($p['weight'] ?? '');
        if ($weight !== '') $product->set_weight($weight);

        $category_ids = array_values(array_filter(array_map('absint', (array) ($p['category_ids'] ?? []))));
        if ($category_ids) $product->set_category_ids($category_ids);
        $image_id = absint($p['image_id'] ?? 0);
        if ($image_id && get_post_type($image_id) === 'attachment') $product->set_image_id($image_id);

        try {
            $id = $product->save();
        } catch (Throwable $e) {
            return new WP_Error('product_create_failed', $e->getMessage(), ['status' => 500]);
        }
        WM_Logs::add('product_created', 'محصول جدید از اپ ساخته شد.', ['product_id' => $id]);
        return new WP_REST_Response(['id' => $id, 'name' => $product->get_name(), 'status' => $product->get_status()], 201);
    }

    public static function send_order_message(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        if (!$order) return new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        $p = (array) $request->get_json_params();
        $message = trim(sanitize_textarea_field((string) ($p['message'] ?? '')));
        if ($message === '') return new WP_Error('message_required', 'متن پیام الزامی است.', ['status' => 422]);
        $mobile = preg_replace('/\\D+/', '', (string) $order->get_billing_phone());
        if (strlen($mobile) < 10) return new WP_Error('invalid_mobile', 'شماره موبایل مشتری معتبر نیست.', ['status' => 422]);
        $provider = self::provider();
        if (!$provider->is_configured()) return new WP_Error('sms_not_configured', 'سرویس پیامک در افزونه تنظیم نشده است.', ['status' => 422]);
        $result = $provider->send($mobile, $message);
        if (is_wp_error($result)) return $result;
        $code = wp_remote_retrieve_response_code($result);
        if ($code < 200 || $code >= 300) return new WP_Error('sms_send_failed', 'ارسال پیامک ناموفق بود: '.$code, ['status' => 502]);
        $order->add_order_note('پیامک از اپ برای مشتری ارسال شد: '.$message);
        WM_Logs::add('order_sms_sent', 'پیامک سفارش ارسال شد.', ['order_id' => $order->get_id()]);
        return rest_ensure_response(['ok' => true, 'mobile' => substr($mobile, 0, 4).'***'.substr($mobile, -3)]);
    }

    public static function message_templates(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        if (!$order) return new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        $number = $order->get_order_number();
        $tracking = trim((string) $order->get_meta('_tracking_number'));
        $store = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $suffix = $store !== '' ? "\n".$store : '';
        $templates = [
            ['key' => 'received', 'status' => 'pending', 'title' => 'ثبت سفارش', 'message' => "سفارش #{$number} با موفقیت ثبت شد و در انتظار پرداخت/تأیید است.".$suffix],
            ['key' => 'processing', 'status' => 'processing', 'title' => 'در حال آماده‌سازی', 'message' => "سفارش #{$number} در حال آماده‌سازی و بسته‌بندی است.".$suffix],
            ['key' => 'ready', 'status' => 'processing', 'title' => 'آماده برای ارسال', 'message' => "سفارش #{$number} آماده ارسال است و به‌زودی تحویل پست می‌شود.".$suffix],
            ['key' => 'post', 'status' => 'processing', 'title' => 'تحویل به پست', 'message' => "سفارش #{$number} تحویل پست شد.".($tracking !== '' ? " کد رهگیری: {$tracking}" : '').$suffix],
            ['key' => 'tracking', 'status' => 'processing', 'title' => 'کد رهگیری', 'message' => "سفارش #{$number} ارسال شد.".($tracking !== '' ? " کد رهگیری: {$tracking}" : ' کد رهگیری پس از ثبت مرسوله ارسال می‌شود.').$suffix],
            ['key' => 'completed', 'status' => 'completed', 'title' => 'تکمیل سفارش', 'message' => "سفارش #{$number} تکمیل شد. از خرید شما سپاسگزاریم.".$suffix],
            ['key' => 'address', 'status' => '', 'title' => 'نیاز به اصلاح آدرس', 'message' => "برای ارسال سفارش #{$number}، آدرس یا کدپستی نیاز به بررسی دارد. لطفاً با فروشگاه تماس بگیرید.".$suffix],
        ];
        return rest_ensure_response([
            'order_id' => $order->get_id(),
            'order_number' => $number,
            'current_status' => $order->get_status(),
            'tracking_number' => $tracking !== '' ? $tracking : null,
            'items' => $templates,
        ]);
    }

    public static function shipping_ready(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        if (!$order) return new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        $p = (array) $request->get_json_params();
        $ready = rest_sanitize_boolean($p['ready'] ?? true);
        $current = (bool) $order->get_meta('_woo_manager_shipping_ready');
        if ($current !== $ready) {
            $order->update_meta_data('_woo_manager_shipping_ready', $ready ? '1' : '');
            $order->add_order_note($ready ? 'وضعیت «آماده برای ارسال» از اپ فعال شد.' : 'وضعیت «آماده برای ارسال» از اپ برداشته شد.');
            $order->save();
            WM_Logs::add('shipping_ready_changed', $ready ? 'سفارش آماده ارسال شد.' : 'آماده ارسال لغو شد.', ['order_id' => $order->get_id(), 'ready' => $ready]);
        }
        return rest_ensure_response(['ok' => true, 'ready' => $ready, 'order_id' => $order->get_id()]);
    }

    public static function tapin_options(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        if (!$order) return new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
        $shipment = (array) $order->get_meta('_woo_manager_tapin', true);
        $product_weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $grams = wc_get_weight((float) $product->get_weight(), 'g');
            if ($grams > 0) $product_weight += (int) round($grams * max(1, (int) $item->get_quantity()));
        }
        $configured_weight = absint(WM_Settings::get('tapin_package_weight', '100'));
        $package_weight = max(100, $product_weight, $configured_weight);
        $default_box = absint(WM_Settings::get('tapin_box_id'));
        $box_options = [];
        if ($default_box > 0) {
            $box_options[] = ['id' => $default_box, 'label' => 'جعبه پیش‌فرض فروشگاه (#'.$default_box.')'];
        }
        return rest_ensure_response([
            'configured' => WM_Tapin::health(),
            'shipping_ready' => (bool) $order->get_meta('_woo_manager_shipping_ready'),
            'order_status' => $order->get_status(),
            'defaults' => [
                'province_code' => absint($order->get_meta('_tapin_province_code')),
                'city_code' => absint($order->get_meta('_tapin_city_code')),
                'package_weight' => $package_weight,
                'products_weight' => $product_weight,
                'pay_type' => 1,
                'order_type' => absint(WM_Settings::get('tapin_order_type', '1')) ?: 1,
                'box_id' => $default_box,
                'content_type' => 1,
            ],
            'box_options' => $box_options,
            'destination' => [
                'province' => $order->get_shipping_state() ?: $order->get_billing_state(),
                'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
                'postal_code' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                'address' => trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()).' '.($order->get_shipping_address_2() ?: $order->get_billing_address_2())),
            ],
            'shipment' => $shipment,
            'registered' => (bool) $order->get_meta('_tapin_order_id'),
            'tapin_order_id' => $order->get_meta('_tapin_order_id') ?: null,
            'tracking_number' => $order->get_meta('_tracking_number') ?: null,
        ]);
    }
}
