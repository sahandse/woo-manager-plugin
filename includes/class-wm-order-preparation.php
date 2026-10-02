<?php
defined('ABSPATH') || exit;

final class WM_Order_Preparation {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes'], 40);
    }

    public static function routes(): void {
        $auth = [WM_REST::class, 'can_manage'];
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/preparation', [
            'methods' => 'GET',
            'callback' => [self::class, 'state'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/preparation/review', [
            'methods' => 'POST',
            'callback' => [self::class, 'save_review'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/manual-shipment', [
            'methods' => 'POST',
            'callback' => [self::class, 'manual_shipment'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)/preparation/pdf', [
            'methods' => 'POST',
            'callback' => [self::class, 'pdf'],
            'permission_callback' => $auth,
        ]);
    }

    private static function order(WP_REST_Request $request) {
        $order = wc_get_order(absint($request['order_id']));
        return $order ?: new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
    }

    private static function review(WC_Order $order): array {
        $stored = (array) $order->get_meta('_woo_manager_preparation_review', true);
        $billing = [
            'phone' => (string) $order->get_billing_phone(),
            'postcode' => (string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode()),
            'address' => trim((string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1())),
        ];
        return [
            'items_checked' => !empty($stored['items_checked']),
            'address_checked' => !empty($stored['address_checked']),
            'phone_checked' => !empty($stored['phone_checked']),
            'postcode_checked' => !empty($stored['postcode_checked']),
            'payment_checked' => !empty($stored['payment_checked']),
            'notes_checked' => !empty($stored['notes_checked']),
            'complete' => !empty($stored['complete']),
            'auto' => [
                'has_items' => count($order->get_items()) > 0,
                'has_address' => $billing['address'] !== '',
                'has_phone' => $billing['phone'] !== '',
                'has_postcode' => $billing['postcode'] !== '',
                'is_paid' => $order->is_paid(),
            ],
        ];
    }

    public static function state(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $shipping = (array) $order->get_meta('_woo_manager_manual_shipment', true);
        return rest_ensure_response([
            'review' => self::review($order),
            'shipping' => $shipping,
            'shipping_ready' => (bool) $order->get_meta('_woo_manager_shipping_ready'),
            'tracking_number' => $order->get_meta('_tracking_number') ?: null,
            'carrier' => $order->get_meta('_woo_manager_shipping_carrier') ?: ($order->get_meta('_tapin_order_id') ? 'post' : null),
            'order_status' => $order->get_status(),
        ]);
    }

    public static function save_review(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $p = (array) $request->get_json_params();
        $keys = ['items_checked','address_checked','phone_checked','postcode_checked','payment_checked','notes_checked'];
        $review = [];
        foreach ($keys as $key) $review[$key] = rest_sanitize_boolean($p[$key] ?? false);
        $review['complete'] = !in_array(false, array_values($review), true);
        $review['updated_at'] = current_time('mysql');
        $order->update_meta_data('_woo_manager_preparation_review', $review);
        $order->add_order_note($review['complete'] ? 'مرحله بررسی سفارش در اپ تکمیل شد.' : 'چک‌لیست بررسی سفارش در اپ به‌روزرسانی شد.');
        $order->save();
        return rest_ensure_response(['ok' => true, 'review' => self::review($order)]);
    }

    public static function manual_shipment(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $p = (array) $request->get_json_params();
        $carrier = sanitize_key((string) ($p['carrier'] ?? 'tipax'));
        if (!in_array($carrier, ['tipax','post'], true)) $carrier = 'tipax';
        $shipment = [
            'carrier' => $carrier,
            'weight' => max(1, absint($p['weight'] ?? 0)),
            'box' => sanitize_text_field((string) ($p['box'] ?? '')),
            'pay_type' => sanitize_key((string) ($p['pay_type'] ?? 'cod')),
            'content_type' => sanitize_key((string) ($p['content_type'] ?? 'normal')),
            'tracking_number' => sanitize_text_field((string) ($p['tracking_number'] ?? '')),
            'registered_at' => current_time('mysql'),
        ];
        $order->update_meta_data('_woo_manager_manual_shipment', $shipment);
        $order->update_meta_data('_woo_manager_shipping_carrier', $carrier);
        $order->update_meta_data('_woo_manager_shipping_ready', '1');
        if ($shipment['tracking_number'] !== '') $order->update_meta_data('_tracking_number', $shipment['tracking_number']);
        $order->add_order_note(sprintf('اطلاعات ارسال %s از اپ ثبت شد. وزن: %d گرم، جعبه: %s، پرداخت: %s%s', $carrier === 'tipax' ? 'تیپاکس' : 'پست', $shipment['weight'], $shipment['box'] ?: '-', $shipment['pay_type'], $shipment['tracking_number'] ? '، رهگیری: '.$shipment['tracking_number'] : ''));
        $order->save();
        WM_Logs::add('manual_shipment_saved', 'اطلاعات ارسال دستی سفارش ذخیره شد.', ['order_id' => $order->get_id(), 'carrier' => $carrier]);
        return rest_ensure_response(['ok' => true, 'shipment' => $shipment]);
    }

    private static function order_html(WC_Order $order, string $type): string {
        $shipment = (array) $order->get_meta('_woo_manager_manual_shipment', true);
        $carrier = (string) ($order->get_meta('_woo_manager_shipping_carrier') ?: ($order->get_meta('_tapin_order_id') ? 'post' : ''));
        $tracking = (string) $order->get_meta('_tracking_number');
        $name = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name()) ?: trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $address = trim(($order->get_shipping_state() ?: $order->get_billing_state()).'، '.($order->get_shipping_city() ?: $order->get_billing_city()).'، '.($order->get_shipping_address_1() ?: $order->get_billing_address_1()).' '.($order->get_shipping_address_2() ?: $order->get_billing_address_2()));
        $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $phone = method_exists($order, 'get_shipping_phone') && $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
        $html = '<h2>'.esc_html($type === 'label' ? 'لیبل ارسال' : 'فاکتور سفارش').' #'.esc_html($order->get_order_number()).'</h2>';
        $html .= '<p><strong>گیرنده:</strong> '.esc_html($name).'<br><strong>موبایل:</strong> '.esc_html($phone).'<br><strong>کدپستی:</strong> '.esc_html($postcode).'<br><strong>آدرس:</strong> '.esc_html($address).'</p>';
        $html .= '<p><strong>روش ارسال:</strong> '.esc_html($carrier === 'tipax' ? 'تیپاکس' : 'پست').'<br><strong>وزن:</strong> '.esc_html((string) ($shipment['weight'] ?? '-')).' گرم<br><strong>جعبه:</strong> '.esc_html((string) ($shipment['box'] ?? '-')).'<br><strong>رهگیری:</strong> '.esc_html($tracking ?: '-').'</p>';
        if ($type !== 'label') {
            $html .= '<table width="100%" cellspacing="0" cellpadding="6" border="1"><tr><th>محصول</th><th>تعداد</th><th>مبلغ</th></tr>';
            foreach ($order->get_items() as $item) $html .= '<tr><td>'.esc_html($item->get_name()).'</td><td>'.esc_html((string) $item->get_quantity()).'</td><td>'.wp_kses_post(wc_price($item->get_total(), ['currency' => $order->get_currency()])).'</td></tr>';
            $html .= '</table><h3>مبلغ کل: '.wp_kses_post(wc_price($order->get_total(), ['currency' => $order->get_currency()])).'</h3>';
        }
        return $html;
    }

    public static function pdf(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $p = (array) $request->get_json_params();
        $type = ($p['type'] ?? 'invoice') === 'label' ? 'label' : 'invoice';
        $paper = $type === 'label' ? 'A6' : 'A4';
        $result = WM_PDF::from_html(self::order_html($order, $type), 'order-'.$order->get_id().'-'.$type.'.pdf', $paper);
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }
}
