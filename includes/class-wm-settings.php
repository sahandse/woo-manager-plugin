<?php
defined('ABSPATH') || exit;

final class WM_Settings {
    private const OPTION = 'woo_manager_settings';
    private const SECRET_FIELDS = ['tapin_token', 'melipayamak_password', 'faraz_api_key'];

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
    }

    public static function menu(): void {
        add_menu_page('مدیر فروشگاه', 'مدیر فروشگاه', 'manage_woocommerce', 'woo-manager', [self::class, 'render'], 'dashicons-store', 56);
    }

    public static function register(): void {
        register_setting('woo_manager', self::OPTION, ['sanitize_callback' => [self::class, 'sanitize']]);
    }

    public static function sanitize($input): array {
        $old = self::all();
        $clean = [];
        foreach (['tapin_shop_id','tapin_box_id','tapin_order_type','tapin_package_weight','melipayamak_username','melipayamak_from','faraz_from','sms_provider','tracking_message'] as $key) {
            $clean[$key] = sanitize_text_field($input[$key] ?? '');
        }
        foreach (self::SECRET_FIELDS as $key) {
            $value = trim((string)($input[$key] ?? ''));
            $clean[$key] = $value === '' ? ($old[$key] ?? '') : WM_Crypto::encrypt($value);
        }
        $clean['tapin_auto_register'] = !empty($input['tapin_auto_register']) ? 'yes' : 'no';
        return $clean;
    }

    public static function all(): array { return (array)get_option(self::OPTION, []); }
    public static function get(string $key, string $default = ''): string {
        $value = (string)(self::all()[$key] ?? $default);
        return in_array($key, self::SECRET_FIELDS, true) ? WM_Crypto::decrypt($value) : $value;
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::all();
        ?>
        <div class="wrap" dir="rtl"><h1>مدیر فروشگاه</h1><p>توکن‌ها رمزنگاری می‌شوند و هرگز به اپ ارسال نخواهند شد.</p>
        <form method="post" action="options.php"><?php settings_fields('woo_manager'); ?>
        <h2>تاپین</h2><table class="form-table"><tr><th>شناسه فروشگاه</th><td><input name="woo_manager_settings[tapin_shop_id]" value="<?php echo esc_attr($s['tapin_shop_id'] ?? ''); ?>"></td></tr><tr><th>توکن وب‌سرویس</th><td><input type="password" autocomplete="new-password" name="woo_manager_settings[tapin_token]" placeholder="برای حفظ مقدار فعلی خالی بگذارید"></td></tr></table>
        <table class="form-table"><tr><th>شناسه بسته تاپین</th><td><input type="number" name="woo_manager_settings[tapin_box_id]" value="<?php echo esc_attr($s['tapin_box_id'] ?? ''); ?>"></td></tr><tr><th>سرویس پیش‌فرض</th><td><select name="woo_manager_settings[tapin_order_type]"><option value="1" <?php selected($s['tapin_order_type'] ?? '1','1'); ?>>پیشتاز</option><option value="3" <?php selected($s['tapin_order_type'] ?? '1','3'); ?>>ویژه</option><option value="5" <?php selected($s['tapin_order_type'] ?? '1','5'); ?>>اکسپرس</option></select></td></tr><tr><th>وزن بسته‌بندی (گرم)</th><td><input type="number" min="0" name="woo_manager_settings[tapin_package_weight]" value="<?php echo esc_attr($s['tapin_package_weight'] ?? '100'); ?>"></td></tr><tr><th>ثبت خودکار</th><td><label><input type="checkbox" name="woo_manager_settings[tapin_auto_register]" value="1" <?php checked($s['tapin_auto_register'] ?? 'no','yes'); ?>> پس از «در حال انجام» شدن سفارش، مرسوله واقعی ثبت شود</label></td></tr></table>
        <h2>پیامک</h2><table class="form-table"><tr><th>سرویس فعال</th><td><select name="woo_manager_settings[sms_provider]"><option value="melipayamak" <?php selected($s['sms_provider'] ?? '', 'melipayamak'); ?>>ملی‌پیامک</option><option value="farazsms" <?php selected($s['sms_provider'] ?? '', 'farazsms'); ?>>فراز SMS</option></select></td></tr><tr><th>نام کاربری ملی‌پیامک</th><td><input name="woo_manager_settings[melipayamak_username]" value="<?php echo esc_attr($s['melipayamak_username'] ?? ''); ?>"></td></tr><tr><th>رمز ملی‌پیامک</th><td><input type="password" name="woo_manager_settings[melipayamak_password]"></td></tr><tr><th>شماره ارسال ملی‌پیامک</th><td><input name="woo_manager_settings[melipayamak_from]" value="<?php echo esc_attr($s['melipayamak_from'] ?? ''); ?>"></td></tr><tr><th>کلید API فراز</th><td><input type="password" name="woo_manager_settings[faraz_api_key]"></td></tr><tr><th>شماره ارسال فراز</th><td><input name="woo_manager_settings[faraz_from]" value="<?php echo esc_attr($s['faraz_from'] ?? ''); ?>"></td></tr></table>
        <table class="form-table"><tr><th>متن رهگیری</th><td><textarea name="woo_manager_settings[tracking_message]" rows="3" cols="60"><?php echo esc_textarea($s['tracking_message'] ?? 'سفارش شما ارسال شد. کد رهگیری: {tracking_code}'); ?></textarea></td></tr></table>
        <?php submit_button('ذخیره تنظیمات'); ?></form><hr><h2>اتصال اپ</h2><p>QR فقط آدرس سایت را دارد؛ کد محرمانه جداگانه و یک‌بارمصرف است.</p><img width="180" height="180" alt="QR فروشگاه" src="<?php echo esc_url('https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='.rawurlencode(home_url('/'))); ?>"><?php $pair=get_transient('wm_pair_display_'.get_current_user_id()); if($pair): ?><div class="notice notice-success inline"><p>کد یک‌بارمصرف: <strong style="font-size:24px;letter-spacing:4px"><?php echo esc_html($pair); ?></strong> — اعتبار ۱۰ دقیقه</p></div><?php endif; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wm_pair_code"><?php wp_nonce_field('wm_pair_code'); submit_button('ساخت کد اتصال جدید','secondary'); ?></form></div><?php
    }
}
