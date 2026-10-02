<?php
defined('ABSPATH') || exit;

final class WM_Fulfillment {
    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes'], 35); }

    public static function routes(): void {
        $auth = [WM_REST::class, 'can_manage'];
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/fulfillment', [
            ['methods' => 'GET', 'callback' => [self::class, 'get'], 'permission_callback' => $auth],
            ['methods' => 'POST', 'callback' => [self::class, 'save'], 'permission_callback' => $auth],
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/fulfillment/pdf', [
            'methods' => 'POST', 'callback' => [self::class, 'pdf'], 'permission_callback' => $auth,
        ]);
    }

    private static function order(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        return $order ?: new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
    }

    private static function meta($order): array {
        $data = $order->get_meta('_woo_manager_fulfillment', true);
        return is_array($data) ? $data : [];
    }

    private static function checklist($order): array {
        $shipping = [
            'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'last_name' => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'address_1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
            'state' => $order->get_shipping_state() ?: $order->get_billing_state(),
            'postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
        ];
        $phone = preg_replace('/\D+/', '', (string) $order->get_billing_phone());
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
            ];
        }
        return [
            'payment' => ['ok' => $order->is_paid() || in_array($order->get_status(), ['processing','completed'], true), 'label' => 'وضعیت پرداخت'],
            'items' => ['ok' => count($items) > 0, 'label' => 'اقلام سفارش'],
            'address' => ['ok' => trim($shipping['address_1']) !== '' && trim($shipping['city']) !== '', 'label' => 'آدرس و شهر'],
            'postcode' => ['ok' => strlen(preg_replace('/\D+/', '', (string) $shipping['postcode'])) >= 10, 'label' => 'کدپستی'],
            'phone' => ['ok' => strlen($phone) >= 10, 'label' => 'شماره موبایل'],
            'items_data' => $items,
            'shipping' => $shipping,
            'phone_masked' => strlen($phone) >= 7 ? substr($phone,0,4).'***'.substr($phone,-3) : $phone,
        ];
    }

    public static function get(WP_REST_Request $request) {
        $order = self::order($request); if (is_wp_error($order)) return $order;
        $meta = self::meta($order);
        return rest_ensure_response([
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'checklist' => self::checklist($order),
            'fulfillment' => $meta,
            'tapin' => [
                'registered' => (bool) $order->get_meta('_tapin_order_id'),
                'order_id' => $order->get_meta('_tapin_order_id') ?: null,
                'tracking_number' => $order->get_meta('_tracking_number') ?: null,
            ],
        ]);
    }

    public static function save(WP_REST_Request $request) {
        $order = self::order($request); if (is_wp_error($order)) return $order;
        $p = (array) $request->get_json_params();
        $old = self::meta($order);
        $carrier = sanitize_key((string) ($p['carrier'] ?? ($old['carrier'] ?? 'post')));
        if (!in_array($carrier, ['post','tipax'], true)) $carrier = 'post';
        $data = array_merge($old, [
            'carrier' => $carrier,
            'service' => sanitize_text_field((string) ($p['service'] ?? ($old['service'] ?? ''))),
            'weight' => max(0, absint($p['weight'] ?? ($old['weight'] ?? 0))),
            'box_id' => absint($p['box_id'] ?? ($old['box_id'] ?? 0)),
            'box_label' => sanitize_text_field((string) ($p['box_label'] ?? ($old['box_label'] ?? ''))),
            'cod' => rest_sanitize_boolean($p['cod'] ?? ($old['cod'] ?? false)),
            'content_type' => sanitize_text_field((string) ($p['content_type'] ?? ($old['content_type'] ?? 'normal'))),
            'tracking_number' => sanitize_text_field((string) ($p['tracking_number'] ?? ($old['tracking_number'] ?? ''))),
            'ready' => rest_sanitize_boolean($p['ready'] ?? ($old['ready'] ?? false)),
            'step' => min(3, max(1, absint($p['step'] ?? ($old['step'] ?? 1)))),
            'updated_at' => current_time('mysql'),
        ]);
        if (isset($p['checks']) && is_array($p['checks'])) {
            $data['checks'] = array_map('rest_sanitize_boolean', $p['checks']);
        }
        $order->update_meta_data('_woo_manager_fulfillment', $data);
        if ($data['tracking_number'] !== '') $order->update_meta_data('_tracking_number', $data['tracking_number']);
        if ($data['ready']) $order->update_meta_data('_woo_manager_shipping_ready', '1');
        $order->save();
        WM_Logs::add('fulfillment_saved', 'فرآیند آماده‌سازی سفارش ذخیره شد.', ['order_id'=>$order->get_id(),'carrier'=>$carrier,'step'=>$data['step']]);
        return self::get($request);
    }

    public static function pdf(WP_REST_Request $request) {
        $order = self::order($request); if (is_wp_error($order)) return $order;
        $f = self::meta($order); $c = self::checklist($order); $s = $c['shipping'];
        $carrier = ($f['carrier'] ?? 'post') === 'tipax' ? 'تیپاکس' : 'پست';
        $rows = '';
        foreach ($c['items_data'] as $item) {
            $rows .= '<tr><td>'.esc_html($item['name']).'</td><td>'.esc_html((string)$item['quantity']).'</td></tr>';
        }
        $html = '<h2>برگه آماده‌سازی سفارش #'.esc_html((string)$order->get_order_number()).'</h2>'
            .'<p><strong>روش ارسال:</strong> '.esc_html($carrier).'</p>'
            .'<p><strong>سرویس:</strong> '.esc_html((string)($f['service'] ?? '-')).'</p>'
            .'<p><strong>وزن:</strong> '.esc_html((string)($f['weight'] ?? 0)).' گرم</p>'
            .'<p><strong>جعبه:</strong> '.esc_html((string)($f['box_label'] ?? ($f['box_id'] ?? '-'))).'</p>'
            .'<p><strong>نوع پرداخت:</strong> '.(!empty($f['cod']) ? 'پس‌کرایه / پرداخت در مقصد' : 'پیش‌کرایه').'</p>'
            .'<p><strong>کد رهگیری:</strong> '.esc_html((string)($f['tracking_number'] ?? '-')).'</p>'
            .'<hr><p><strong>گیرنده:</strong> '.esc_html(trim($s['first_name'].' '.$s['last_name'])).'</p>'
            .'<p><strong>آدرس:</strong> '.esc_html(trim($s['state'].' '.$s['city'].' '.$s['address_1'])).'</p>'
            .'<p><strong>کدپستی:</strong> '.esc_html((string)$s['postcode']).'</p>'
            .'<table width="100%" border="1" cellspacing="0" cellpadding="6"><tr><th>محصول</th><th>تعداد</th></tr>'.$rows.'</table>';
        return rest_ensure_response(WM_PDF::from_html($html, 'fulfillment-'.$order->get_id().'.pdf'));
    }
}
