<?php
defined('ABSPATH') || exit;

final class WM_Order_Detail_Fix {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes'], 101);
    }

    public static function routes(): void {
        register_rest_route('woo-manager/v1', '/orders/(?P<order_id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'detail'],
            'permission_callback' => [WM_REST::class, 'can_manage'],
        ], true);
    }

    public static function detail(WP_REST_Request $request) {
        try {
            return WM_REST::order_detail($request);
        } catch (Throwable $e) {
            if (!function_exists('wc_get_order')) {
                return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست.', ['status' => 503]);
            }
            $order = wc_get_order(absint($request['order_id']));
            if (!$order) {
                return new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
            }
            return rest_ensure_response(self::fallback($order, $e));
        }
    }

    private static function fallback(WC_Order $order, Throwable $error): array {
        $items = [];
        try {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $items[] = [
                    'id' => (int) $item->get_id(),
                    'product_id' => (int) $item->get_product_id(),
                    'variation_id' => (int) $item->get_variation_id(),
                    'name' => (string) $item->get_name(),
                    'quantity' => (int) $item->get_quantity(),
                    'total' => (string) $item->get_total(),
                    'sku' => $product ? (string) $product->get_sku() : '',
                    'image' => ($product && $product->get_image_id()) ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : null,
                    'meta' => [],
                ];
            }
        } catch (Throwable $ignored) {}

        $billing = [
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name' => (string) $order->get_billing_last_name(),
            'phone' => (string) $order->get_billing_phone(),
            'email' => (string) $order->get_billing_email(),
            'state' => (string) $order->get_billing_state(),
            'city' => (string) $order->get_billing_city(),
            'address_1' => (string) $order->get_billing_address_1(),
            'address_2' => (string) $order->get_billing_address_2(),
            'postcode' => (string) $order->get_billing_postcode(),
        ];
        $shipping = [
            'first_name' => (string) $order->get_shipping_first_name(),
            'last_name' => (string) $order->get_shipping_last_name(),
            'phone' => method_exists($order, 'get_shipping_phone') ? (string) $order->get_shipping_phone() : '',
            'state' => (string) $order->get_shipping_state(),
            'city' => (string) $order->get_shipping_city(),
            'address_1' => (string) $order->get_shipping_address_1(),
            'address_2' => (string) $order->get_shipping_address_2(),
            'postcode' => (string) $order->get_shipping_postcode(),
        ];

        $remaining = 0;
        try { $remaining = (float) $order->get_remaining_refund_amount(); } catch (Throwable $ignored) {}

        return [
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'status_options' => array_values(array_map(static function($key, $label) {
                return ['value' => str_replace('wc-', '', (string) $key), 'label' => wp_strip_all_tags((string) $label)];
            }, array_keys(wc_get_order_statuses()), array_values(wc_get_order_statuses()))),
            'currency' => (string) $order->get_currency(),
            'total' => (string) $order->get_total(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'discount_total' => (string) $order->get_discount_total(),
            'total_refunded' => (string) $order->get_total_refunded(),
            'refundable_amount' => $remaining,
            'payment_method' => (string) $order->get_payment_method_title(),
            'billing' => $billing,
            'shipping' => $shipping,
            'shipping_method' => (string) $order->get_shipping_method(),
            'items' => $items,
            'refunds' => [],
            'history' => [],
            'shipment' => [
                'tapin_order_id' => $order->get_meta('_tapin_order_id') ?: null,
                'tracking_number' => $order->get_meta('_tracking_number') ?: null,
            ],
            '_safe_fallback' => true,
            '_detail_warning' => 'جزئیات سفارش با حالت سازگار بارگذاری شد.',
            '_detail_error' => sanitize_text_field($error->getMessage()),
        ];
    }
}
