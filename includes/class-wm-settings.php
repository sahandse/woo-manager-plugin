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
        foreach (['tapin_shop_id', 'melipayamak_username', 'melipayamak_from', 'faraz_from', 'sms_provider'] as $key) {
            $clean[$key] = sanitize_text_field($input[$key] ?? '');
        }
        foreach (self::SECRET_FIELDS as $key) {
            $value = trim((string)($input[$key] ?? ''));
            $clean[$key] = $value === '' ? ($old[$key] ?? '') : WM_Crypto::encrypt($value);
        }
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
        <h2>پیامک</h2><table class="form-table"><tr><th>سرویس فعال</th><td><select name="woo_manager_settings[sms_provider]"><option value="melipayamak" <?php selected($s['sms_provider'] ?? '', 'melipayamak'); ?>>ملی‌پیامک</option><option value="farazsms" <?php selected($s['sms_provider'] ?? '', 'farazsms'); ?>>فراز SMS</option></select></td></tr><tr><th>نام کاربری ملی‌پیامک</th><td><input name="woo_manager_settings[melipayamak_username]" value="<?php echo esc_attr($s['melipayamak_username'] ?? ''); ?>"></td></tr><tr><th>رمز ملی‌پیامک</th><td><input type="password" name="woo_manager_settings[melipayamak_password]"></td></tr><tr><th>شماره ارسال ملی‌پیامک</th><td><input name="woo_manager_settings[melipayamak_from]" value="<?php echo esc_attr($s['melipayamak_from'] ?? ''); ?>"></td></tr><tr><th>کلید API فراز</th><td><input type="password" name="woo_manager_settings[faraz_api_key]"></td></tr><tr><th>شماره ارسال فراز</th><td><input name="woo_manager_settings[faraz_from]" value="<?php echo esc_attr($s['faraz_from'] ?? ''); ?>"></td></tr></table>
        <?php submit_button('ذخیره تنظیمات'); ?></form></div><?php
    }
}
