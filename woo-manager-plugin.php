<?php
/**
 * Plugin Name: Woo Manager
 * Description: API امن مدیریت ووکامرس، ارسال تاپین و پیامک فارسی.
 * Version: 1.3.1
 * Author: Sahand Rezvan
 * Requires PHP: 7.4
 * Requires at least: 6.4
 * WC requires at least: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: woo-manager
 */

defined('ABSPATH') || exit;

define('WOO_MANAGER_VERSION', '1.3.1');
define('WOO_MANAGER_FILE', __FILE__);
define('WOO_MANAGER_PATH', plugin_dir_path(__FILE__));
$woo_manager_autoload = WOO_MANAGER_PATH . 'vendor/autoload.php';
if (file_exists($woo_manager_autoload)) require_once $woo_manager_autoload;

require_once WOO_MANAGER_PATH . 'includes/class-wm-crypto.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-settings.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-devices.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-order-mapper.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-pdf.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-logs.php';
require_once WOO_MANAGER_PATH . 'includes/integrations/class-wm-tapin.php';
require_once WOO_MANAGER_PATH . 'includes/integrations/sms/interface-wm-sms-provider.php';
require_once WOO_MANAGER_PATH . 'includes/integrations/sms/class-wm-melipayamak.php';
require_once WOO_MANAGER_PATH . 'includes/integrations/sms/class-wm-farazsms.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-rest.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-orders-fix.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-order-detail-fix.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-packing.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-workflows.php';
require_once WOO_MANAGER_PATH . 'includes/class-wm-commerce-actions.php';

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WOO_MANAGER_FILE, true);
    }
});

register_activation_hook(__FILE__, static function (): void {
    if (!get_option('woo_manager_install_id')) {
        add_option('woo_manager_install_id', wp_generate_uuid4(), '', false);
    }
    WM_Devices::install();
    WM_Logs::install();
    WM_Workflows::install();
    update_option('woo_manager_version', WOO_MANAGER_VERSION, false);
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('woo_manager_retry_queue');
});

add_action('plugins_loaded', static function (): void {
    if (get_option('woo_manager_version') !== WOO_MANAGER_VERSION) {
        WM_Devices::install();
        WM_Logs::install();
        WM_Workflows::install();
        update_option('woo_manager_version', WOO_MANAGER_VERSION, false);
    }
    WM_Settings::boot();
    WM_Devices::boot();
    WM_REST::boot();
    WM_Orders_Fix::boot();
    WM_Order_Detail_Fix::boot();
    WM_Packing::boot();
    WM_Workflows::boot();
    WM_Commerce_Actions::boot();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=woo-manager')) . '">تنظیمات</a>');
    return $links;
});
