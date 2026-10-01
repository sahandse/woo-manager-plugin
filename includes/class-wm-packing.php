<?php
defined('ABSPATH') || exit;

final class WM_Packing {
    private const META_PROGRESS = '_woo_manager_packing_progress';
    private const META_PACKED_AT = '_woo_manager_packed_at';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes'], 20);
    }

    public static function routes(): void {
        register_rest_route('woo-manager/v1', '/packing/(?P<order_id>\d+)', [
            ['methods' => 'GET', 'callback' => [self::class, 'get'], 'permission_callback' => [WM_REST::class, 'can_manage']],
        ]);
        register_rest_route('woo-manager/v1', '/packing/(?P<order_id>\d+)/scan', [
            ['methods' => 'POST', 'callback' => [self::class, 'scan'], 'permission_callback' => [WM_REST::class, 'can_manage']],
        ]);
        register_rest_route('woo-manager/v1', '/packing/(?P<order_id>\d+)/complete', [
            ['methods' => 'POST', 'callback' => [self::class, 'complete'], 'permission_callback' => [WM_REST::class, 'can_manage']],
        ]);
    }

    private static function order(WP_REST_Request $request) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست.', ['status' => 503]);
        }
        $order = wc_get_order(absint($request['order_id']));
        return $order ?: new WP_Error('order_not_found', 'سفارش پیدا نشد.', ['status' => 404]);
    }

    private static function progress(WC_Order $order): array {
        $raw = $order->get_meta(self::META_PROGRESS, true);
        return is_array($raw) ? $raw : [];
    }

    private static function item_meta($item): array {
        return array_values(array_map(static function ($meta): array {
            return [
                'key' => wp_strip_all_tags((string) $meta->display_key),
                'value' => wp_strip_all_tags((string) $meta->display_value),
            ];
        }, $item->get_formatted_meta_data('')));
    }

    private static function item_codes($item, $product): array {
        $codes = [
            (string) $item->get_id(),
            (string) $item->get_product_id(),
            (string) $item->get_variation_id(),
        ];
        if ($product) {
            $codes[] = (string) $product->get_sku();
            if (method_exists($product, 'get_global_unique_id')) {
                $codes[] = (string) $product->get_global_unique_id();
            }
            foreach (['_barcode', 'barcode', '_gtin', 'gtin', '_ean', 'ean'] as $key) {
                $codes[] = (string) $product->get_meta($key, true);
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $codes), static fn($v) => $v !== '')));
    }

    private static function payload(WC_Order $order): array {
        $progress = self::progress($order);
        $items = [];
        $complete = true;
        foreach ($order->get_items() as $item) {
            $item_id = (int) $item->get_id();
            $required = max(0, (int) $item->get_quantity());
            $scanned = min($required, max(0, (int) ($progress[$item_id] ?? 0)));
            if ($scanned < $required) $complete = false;
            $product = $item->get_product();
            $items[] = [
                'id' => $item_id,
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'name' => (string) $item->get_name(),
                'sku' => $product ? (string) $product->get_sku() : '',
                'required' => $required,
                'scanned' => $scanned,
                'meta' => self::item_meta($item),
            ];
        }
        return [
            'order_id' => $order->get_id(),
            'items' => $items,
            'complete' => $complete && !empty($items),
            'packed_at' => $order->get_meta(self::META_PACKED_AT, true) ?: null,
        ];
    }

    public static function get(WP_REST_Request $request) {
        $order = self::order($request);
        return is_wp_error($order) ? $order : rest_ensure_response(self::payload($order));
    }

    public static function scan(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $params = (array) $request->get_json_params();
        $code = trim(sanitize_text_field((string) ($params['code'] ?? '')));
        if ($code === '') return new WP_Error('packing_code_required', 'بارکد، SKU یا شناسه کالا الزامی است.', ['status' => 422]);

        $progress = self::progress($order);
        foreach ($order->get_items() as $item) {
            $item_id = (int) $item->get_id();
            $required = max(0, (int) $item->get_quantity());
            $scanned = max(0, (int) ($progress[$item_id] ?? 0));
            if ($scanned >= $required) continue;
            if (!in_array($code, self::item_codes($item, $item->get_product()), true)) continue;

            $progress[$item_id] = $scanned + 1;
            $order->update_meta_data(self::META_PROGRESS, $progress);
            $order->delete_meta_data(self::META_PACKED_AT);
            $order->save();
            return rest_ensure_response(self::payload($order));
        }

        return new WP_Error('packing_item_not_matched', 'این کد با هیچ کالای باقی‌مانده در سفارش تطبیق ندارد.', ['status' => 422]);
    }

    public static function complete(WP_REST_Request $request) {
        $order = self::order($request);
        if (is_wp_error($order)) return $order;
        $payload = self::payload($order);
        if (!$payload['complete']) {
            return new WP_Error('packing_incomplete', 'ابتدا همه کالاهای سفارش را اسکن و تطبیق دهید.', ['status' => 409]);
        }
        if (!$order->get_meta(self::META_PACKED_AT, true)) {
            $packed_at = current_time(DATE_ATOM);
            $order->update_meta_data(self::META_PACKED_AT, $packed_at);
            $order->add_order_note('بسته‌بندی سفارش از اپ Woo Manager تکمیل شد.');
            $order->save();
        }
        WM_Logs::add('order_packing_completed', 'بسته‌بندی سفارش تکمیل شد.', ['order_id' => $order->get_id()]);
        return rest_ensure_response(self::payload($order));
    }
}
