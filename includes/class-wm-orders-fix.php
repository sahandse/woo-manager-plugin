<?php
defined('ABSPATH') || exit;

/**
 * Compatibility layer for the orders endpoint.
 *
 * WooCommerce order queries are stricter on some HPOS / WooCommerce versions
 * about the status format they receive. The public API keeps returning plain
 * statuses (processing, completed, ...), while the query itself uses the
 * canonical wc-* keys.
 */
final class WM_Orders_Fix {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_route'], 99);
    }

    public static function register_route(): void {
        register_rest_route('woo-manager/v1', '/orders', [
            'methods' => 'GET',
            'callback' => [self::class, 'orders'],
            'permission_callback' => [WM_REST::class, 'can_manage'],
        ], true);
    }

    public static function orders(WP_REST_Request $request) {
        if (!function_exists('wc_get_orders')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست.', ['status' => 503]);
        }

        $limit = min(100, max(1, (int) ($request->get_param('limit') ?: 50)));
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $requested_status = sanitize_key((string) $request->get_param('status'));

        $status_labels = wc_get_order_statuses();
        $query_statuses = array_keys($status_labels); // canonical wc-* values
        $public_statuses = array_map(static fn($key) => str_replace('wc-', '', $key), $query_statuses);

        if ($requested_status !== '' && !in_array($requested_status, $public_statuses, true)) {
            return new WP_Error('invalid_order_status', 'وضعیت سفارش معتبر نیست.', ['status' => 422]);
        }

        $args = [
            'limit' => $limit,
            'page' => $page,
            'paginate' => true,
            'return' => 'objects',
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => $requested_status === '' ? $query_statuses : ['wc-' . $requested_status],
        ];

        try {
            $query = wc_get_orders($args);
        } catch (Throwable $e) {
            return new WP_Error('orders_query_failed', 'خواندن سفارش‌های ووکامرس انجام نشد: ' . $e->getMessage(), ['status' => 500]);
        }

        $orders = is_object($query) && isset($query->orders) ? $query->orders : (array) $query;
        $items = [];
        foreach ($orders as $order) {
            if (!is_a($order, 'WC_Order')) continue;
            $created = $order->get_date_created();
            $customer = trim((string) $order->get_formatted_billing_full_name());
            $items[] = [
                'id' => (int) $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'status' => (string) $order->get_status(),
                'total' => (string) $order->get_total(),
                'currency' => (string) $order->get_currency(),
                'customer' => $customer,
                'date' => $created ? $created->date(DATE_ATOM) : null,
                'tapin_order_id' => $order->get_meta('_tapin_order_id') ?: null,
                'tapin_uuid' => $order->get_meta('_tapin_uuid') ?: null,
                'tracking_number' => $order->get_meta('_tracking_number') ?: null,
            ];
        }

        if (!$request->get_param('envelope')) {
            return rest_ensure_response($items);
        }

        $counts = [];
        $store_total = 0;
        foreach ($public_statuses as $status) {
            $count = (int) wc_orders_count($status);
            $counts[$status] = $count;
            $store_total += $count;
        }

        $total = is_object($query) && isset($query->total) ? (int) $query->total : count($items);
        $pages = is_object($query) && isset($query->max_num_pages)
            ? (int) $query->max_num_pages
            : max(1, (int) ceil($total / $limit));

        return rest_ensure_response([
            'items' => $items,
            'total' => $total,
            'store_total' => $store_total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'status' => $requested_status,
            'counts' => $counts,
            'storage' => class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
                && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
                    ? 'hpos'
                    : 'posts',
            'plugin_version' => WOO_MANAGER_VERSION,
        ]);
    }
}
