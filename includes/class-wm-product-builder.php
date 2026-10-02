<?php
defined('ABSPATH') || exit;

final class WM_Product_Builder {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes'], 30);
    }

    public static function routes(): void {
        $auth = [WM_REST::class, 'can_manage'];
        register_rest_route('woo-manager/v1', '/products/create-advanced', [
            'methods' => 'POST',
            'callback' => [self::class, 'create'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('woo-manager/v1', '/products/(?P<product_id>\d+)/duplicate', [
            'methods' => 'POST',
            'callback' => [self::class, 'duplicate'],
            'permission_callback' => $auth,
        ]);
    }

    private static function decimal($value): string { return wc_format_decimal($value ?? ''); }
    private static function ids($value): array { return array_values(array_filter(array_map('absint', (array) $value))); }

    public static function create(WP_REST_Request $request) {
        if (!class_exists('WC_Product')) return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست.', ['status' => 503]);
        $p = (array) $request->get_json_params();
        $name = sanitize_text_field((string) ($p['name'] ?? ''));
        if ($name === '') return new WP_Error('product_name_required', 'نام محصول الزامی است.', ['status' => 422]);

        $type = ($p['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';
        $product = $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
        $product->set_name($name);
        $status = sanitize_key((string) ($p['status'] ?? 'draft'));
        $product->set_status(in_array($status, ['publish','draft','pending','private'], true) ? $status : 'draft');
        $product->set_catalog_visibility('visible');
        $product->set_description(wp_kses_post((string) ($p['description'] ?? '')));
        $product->set_short_description(wp_kses_post((string) ($p['short_description'] ?? '')));
        $product->set_featured(rest_sanitize_boolean($p['featured'] ?? false));

        $sku = wc_clean((string) ($p['sku'] ?? ''));
        if ($sku !== '') {
            try { $product->set_sku($sku); }
            catch (Exception $e) { return new WP_Error('invalid_sku', $e->getMessage(), ['status' => 422]); }
        }

        if ($type === 'simple') {
            $product->set_regular_price(self::decimal($p['regular_price'] ?? ''));
            $product->set_sale_price(self::decimal($p['sale_price'] ?? ''));
        }

        $manage = rest_sanitize_boolean($p['manage_stock'] ?? false);
        $product->set_manage_stock($manage);
        if ($manage) {
            $product->set_stock_quantity(max(0, (int) ($p['stock_quantity'] ?? 0)));
            $low = isset($p['low_stock_amount']) ? absint($p['low_stock_amount']) : null;
            if ($low !== null) $product->set_low_stock_amount($low);
        }
        $stock_status = sanitize_key((string) ($p['stock_status'] ?? 'instock'));
        $product->set_stock_status(in_array($stock_status, ['instock','outofstock','onbackorder'], true) ? $stock_status : 'instock');
        $backorders = sanitize_key((string) ($p['backorders'] ?? 'no'));
        $product->set_backorders(in_array($backorders, ['no','notify','yes'], true) ? $backorders : 'no');

        foreach (['weight','length','width','height'] as $field) {
            $value = self::decimal($p[$field] ?? '');
            if ($value !== '') {
                $setter = 'set_'.$field;
                $product->$setter($value);
            }
        }
        $shipping_class_id = absint($p['shipping_class_id'] ?? 0);
        if ($shipping_class_id) $product->set_shipping_class_id($shipping_class_id);
        $product->set_category_ids(self::ids($p['category_ids'] ?? []));
        $product->set_tag_ids(self::ids($p['tag_ids'] ?? []));

        $image_id = absint($p['image_id'] ?? 0);
        if ($image_id && get_post_type($image_id) === 'attachment') $product->set_image_id($image_id);
        $gallery = array_values(array_filter(self::ids($p['gallery_image_ids'] ?? []), static fn($id) => get_post_type($id) === 'attachment'));
        if ($gallery) $product->set_gallery_image_ids($gallery);

        if ($type === 'variable') {
            $attributes = [];
            foreach ((array) ($p['attributes'] ?? []) as $index => $raw) {
                if (!is_array($raw)) continue;
                $label = sanitize_text_field((string) ($raw['name'] ?? ''));
                $options = array_values(array_filter(array_map('sanitize_text_field', (array) ($raw['options'] ?? []))));
                if ($label === '' || !$options) continue;
                $attribute = new WC_Product_Attribute();
                $attribute->set_id(0);
                $attribute->set_name($label);
                $attribute->set_options($options);
                $attribute->set_position((int) $index);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);
        }

        try { $id = $product->save(); }
        catch (Throwable $e) { return new WP_Error('product_create_failed', $e->getMessage(), ['status' => 500]); }

        $brand = sanitize_text_field((string) ($p['brand'] ?? ''));
        if ($brand !== '') update_post_meta($id, '_woo_manager_brand', $brand);
        $barcode = sanitize_text_field((string) ($p['barcode'] ?? ''));
        if ($barcode !== '') update_post_meta($id, '_woo_manager_barcode', $barcode);

        $variations_created = 0;
        if ($type === 'variable' && !empty($p['variations']) && is_array($p['variations'])) {
            foreach ($p['variations'] as $v) {
                if (!is_array($v) || empty($v['attributes'])) continue;
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($id);
                $variation->set_status('publish');
                $attrs = [];
                foreach ((array) $v['attributes'] as $key => $value) {
                    $attrs[sanitize_title((string) $key)] = sanitize_text_field((string) $value);
                }
                $variation->set_attributes($attrs);
                $variation->set_regular_price(self::decimal($v['regular_price'] ?? $p['regular_price'] ?? ''));
                $variation->set_sale_price(self::decimal($v['sale_price'] ?? ''));
                $variation->set_manage_stock(rest_sanitize_boolean($v['manage_stock'] ?? $manage));
                if ($variation->get_manage_stock()) $variation->set_stock_quantity(max(0, (int) ($v['stock_quantity'] ?? $p['stock_quantity'] ?? 0)));
                $variation->set_stock_status('instock');
                try { $variation->save(); $variations_created++; } catch (Throwable $e) { /* keep product creation successful */ }
            }
            WC_Product_Variable::sync($id);
        }

        WM_Logs::add('product_created', 'محصول حرفه‌ای از اپ ساخته شد.', ['product_id' => $id, 'type' => $type]);
        return new WP_REST_Response(['id' => $id, 'name' => $product->get_name(), 'status' => $product->get_status(), 'type' => $type, 'variations_created' => $variations_created], 201);
    }

    public static function duplicate(WP_REST_Request $request) {
        $source = wc_get_product(absint($request['product_id']));
        if (!$source) return new WP_Error('product_not_found', 'محصول پیدا نشد.', ['status' => 404]);
        if (!class_exists('WC_Admin_Duplicate_Product')) {
            include_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
        }
        try {
            $duplicator = new WC_Admin_Duplicate_Product();
            $copy = $duplicator->product_duplicate($source);
            if (!$copy) throw new Exception('Duplicate failed');
            $copy->set_name($source->get_name().' - کپی');
            $copy->set_status('draft');
            $copy->save();
            WM_Logs::add('product_duplicated', 'محصول از اپ کپی شد.', ['source_id' => $source->get_id(), 'product_id' => $copy->get_id()]);
            return rest_ensure_response(['id' => $copy->get_id(), 'name' => $copy->get_name(), 'status' => 'draft']);
        } catch (Throwable $e) {
            return new WP_Error('product_duplicate_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}
