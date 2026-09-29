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
        <style>
            .wm-wrap{max-width:1050px;font-family:Tahoma,sans-serif}.wm-head{margin:22px 0}.wm-head h1{font-size:28px}.wm-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.wm-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:22px;box-shadow:0 8px 30px rgba(15,23,42,.04)}.wm-card h2{margin:0 0 6px}.wm-card .description{color:#64748b}.wm-field{margin-top:16px}.wm-field label{display:block;font-weight:700;margin-bottom:7px}.wm-field input,.wm-field select,.wm-field textarea{width:100%;max-width:none;border-radius:10px;min-height:42px}.wm-link{display:inline-flex;align-items:center;gap:6px;margin-top:10px;text-decoration:none}.wm-badge{display:inline-block;padding:4px 9px;border-radius:99px;background:#ecfdf5;color:#047857;font-size:12px}.wm-connect{margin-top:18px}@media(max-width:782px){.wm-grid{grid-template-columns:1fr}}
        </style>
        <div class="wrap wm-wrap" dir="rtl">
            <div class="wm-head"><h1>مدیر فروشگاه</h1><p>اطلاعات محرمانه رمزنگاری می‌شوند و هرگز به اپ موبایل ارسال نخواهند شد.</p></div>
            <form method="post" action="options.php"><?php settings_fields('woo_manager'); ?>
                <div class="wm-grid">
                    <section class="wm-card">
                        <h2>اتصال تاپین</h2>
                        <p class="description">اطلاعات وب‌سرویس فروشگاه تاپین را وارد کنید.</p>
                        <a class="wm-link" href="https://docs.tapin.ir/" target="_blank" rel="noopener noreferrer">دریافت API و مشاهده مستندات رسمی تاپین <span aria-hidden="true">↗</span></a>
                        <div class="wm-field"><label>شناسه فروشگاه</label><input name="woo_manager_settings[tapin_shop_id]" value="<?php echo esc_attr($s['tapin_shop_id'] ?? ''); ?>" inputmode="numeric"></div>
                        <div class="wm-field"><label>توکن وب‌سرویس <?php if(!empty($s['tapin_token'])): ?><span class="wm-badge">ذخیره شده</span><?php endif; ?></label><input type="password" autocomplete="new-password" name="woo_manager_settings[tapin_token]" placeholder="برای حفظ مقدار فعلی خالی بگذارید"></div>
                        <div class="wm-field"><label>شناسه بسته تاپین</label><input type="number" name="woo_manager_settings[tapin_box_id]" value="<?php echo esc_attr($s['tapin_box_id'] ?? ''); ?>"></div>
                        <div class="wm-field"><label>سرویس پیش‌فرض</label><select name="woo_manager_settings[tapin_order_type]"><option value="1" <?php selected($s['tapin_order_type'] ?? '1','1'); ?>>پیشتاز</option><option value="3" <?php selected($s['tapin_order_type'] ?? '1','3'); ?>>ویژه</option><option value="5" <?php selected($s['tapin_order_type'] ?? '1','5'); ?>>اکسپرس</option></select></div>
                        <div class="wm-field"><label>وزن بسته‌بندی (گرم)</label><input type="number" min="0" name="woo_manager_settings[tapin_package_weight]" value="<?php echo esc_attr($s['tapin_package_weight'] ?? '100'); ?>"></div>
                        <div class="wm-field"><label><input style="width:auto;min-height:auto" type="checkbox" name="woo_manager_settings[tapin_auto_register]" value="1" <?php checked($s['tapin_auto_register'] ?? 'no','yes'); ?>> ثبت خودکار مرسوله پس از «در حال انجام» شدن سفارش</label></div>
                    </section>
                    <section class="wm-card">
                        <h2>پیامک سفارش</h2>
                        <p class="description">برای ملی‌پیامک هر سه مورد نام کاربری، رمز عبور و شماره ارسال‌کننده الزامی است.</p>
                        <div class="wm-field"><label>سرویس فعال</label><select name="woo_manager_settings[sms_provider]"><option value="melipayamak" <?php selected($s['sms_provider'] ?? '', 'melipayamak'); ?>>ملی‌پیامک</option><option value="farazsms" <?php selected($s['sms_provider'] ?? '', 'farazsms'); ?>>فراز SMS</option></select></div>
                        <div class="wm-field"><label>نام کاربری ملی‌پیامک</label><input autocomplete="username" name="woo_manager_settings[melipayamak_username]" value="<?php echo esc_attr($s['melipayamak_username'] ?? ''); ?>"></div>
                        <div class="wm-field"><label>رمز عبور ملی‌پیامک <?php if(!empty($s['melipayamak_password'])): ?><span class="wm-badge">ذخیره شده</span><?php endif; ?></label><input type="password" autocomplete="new-password" name="woo_manager_settings[melipayamak_password]" placeholder="برای حفظ مقدار فعلی خالی بگذارید"></div>
                        <div class="wm-field"><label>شماره ارسال‌کننده ملی‌پیامک</label><input inputmode="numeric" name="woo_manager_settings[melipayamak_from]" value="<?php echo esc_attr($s['melipayamak_from'] ?? ''); ?>" placeholder="مانند 5000…"></div>
                        <div class="wm-field"><label>کلید API فراز <?php if(!empty($s['faraz_api_key'])): ?><span class="wm-badge">ذخیره شده</span><?php endif; ?></label><input type="password" autocomplete="new-password" name="woo_manager_settings[faraz_api_key]" placeholder="برای حفظ مقدار فعلی خالی بگذارید"></div>
                        <div class="wm-field"><label>شماره ارسال‌کننده فراز</label><input name="woo_manager_settings[faraz_from]" value="<?php echo esc_attr($s['faraz_from'] ?? ''); ?>"></div>
                        <div class="wm-field"><label>متن پیامک رهگیری</label><textarea name="woo_manager_settings[tracking_message]" rows="4"><?php echo esc_textarea($s['tracking_message'] ?? 'سفارش شما ارسال شد. کد رهگیری: {tracking_code}'); ?></textarea><p class="description">متغیر قابل استفاده: <code>{tracking_code}</code></p></div>
                    </section>
                </div>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
            <section class="wm-card wm-connect"><h2>اتصال امن اپ</h2><p>QR فقط آدرس سایت را دارد؛ کد محرمانه جداگانه و یک‌بارمصرف است.</p><img width="180" height="180" alt="QR فروشگاه" src="<?php echo esc_url('https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='.rawurlencode(home_url('/'))); ?>"><?php $pair=get_transient('wm_pair_display_'.get_current_user_id()); if($pair): ?><div class="notice notice-success inline"><p>کد یک‌بارمصرف: <strong style="font-size:24px;letter-spacing:4px"><?php echo esc_html($pair); ?></strong> — اعتبار ۱۰ دقیقه</p></div><?php endif; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wm_pair_code"><?php wp_nonce_field('wm_pair_code'); submit_button('ساخت کد اتصال جدید','secondary'); ?></form></section>
        </div><?php
    }
}
