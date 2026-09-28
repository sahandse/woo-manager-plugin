<?php
defined('ABSPATH') || exit;

final class WM_Devices {
    private static function table(): string { global $wpdb; return $wpdb->prefix . 'woo_manager_devices'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            device_id varchar(100) NOT NULL,
            device_name varchar(190) NOT NULL,
            token_hash char(64) NOT NULL,
            role varchar(30) NOT NULL DEFAULT 'manager',
            created_at datetime NOT NULL,
            last_seen_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY device_id (device_id), UNIQUE KEY token_hash (token_hash)
        ) $charset;");
    }
    public static function boot(): void { add_action('admin_post_wm_pair_code', [self::class, 'generate_code']); }
    public static function generate_code(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('wm_pair_code');
        $code = (string)random_int(100000, 999999);
        set_transient('wm_pair_' . hash('sha256', $code), ['user_id'=>get_current_user_id(),'attempts'=>0], 10 * MINUTE_IN_SECONDS);
        set_transient('wm_pair_display_' . get_current_user_id(), $code, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=woo-manager')); exit;
    }
    public static function exchange(string $code, string $device_id, string $device_name) {
        $key = 'wm_pair_' . hash('sha256', $code);
        $pair = get_transient($key);
        if (!is_array($pair)) return new WP_Error('invalid_pair_code', 'کد اتصال نامعتبر یا منقضی شده است.', ['status'=>401]);
        delete_transient($key);
        $token = bin2hex(random_bytes(32));
        global $wpdb;
        $wpdb->replace(self::table(), ['device_id'=>$device_id,'device_name'=>$device_name,'token_hash'=>hash('sha256',$token),'role'=>'manager','created_at'=>current_time('mysql', true),'last_seen_at'=>current_time('mysql', true),'revoked_at'=>null], ['%s','%s','%s','%s','%s','%s','%s']);
        if (!$wpdb->insert_id) return new WP_Error('device_store_failed', 'ذخیره دستگاه انجام نشد.', ['status'=>500]);
        return ['token'=>$token,'device_id'=>$device_id,'site_name'=>get_bloginfo('name'),'api_url'=>rest_url('woo-manager/v1'),'plugin_version'=>WOO_MANAGER_VERSION,'api_version'=>1];
    }
    public static function authenticate(WP_REST_Request $request): bool {
        $header = trim((string)$request->get_header('authorization'));
        if (stripos($header, 'Bearer ') !== 0) return false;
        $hash = hash('sha256', trim(substr($header, 7)));
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE token_hash=%s AND revoked_at IS NULL', $hash));
        if (!$id) return false;
        $wpdb->update(self::table(), ['last_seen_at'=>current_time('mysql', true)], ['id'=>(int)$id], ['%s'], ['%d']);
        return true;
    }
    public static function all(): array { global $wpdb; return $wpdb->get_results('SELECT id,device_id,device_name,role,created_at,last_seen_at,revoked_at FROM '.self::table().' ORDER BY id DESC',ARRAY_A); }
    public static function revoke(int $id): bool { global $wpdb; return false!==$wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>$id],['%s'],['%d']); }
}
