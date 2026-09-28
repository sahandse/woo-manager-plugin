<?php
defined('ABSPATH') || exit;

final class WM_Logs {
    private static function table(): string { global $wpdb; return $wpdb->prefix . 'woo_manager_logs'; }
    public static function install(): void { global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset=$wpdb->get_charset_collate(); dbDelta('CREATE TABLE '.self::table()." (id bigint unsigned NOT NULL AUTO_INCREMENT, action varchar(100) NOT NULL, message text NOT NULL, context longtext NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY action (action), KEY created_at (created_at)) $charset;"); }
    public static function add(string $action,string $message,array $context=[]): void { global $wpdb; $wpdb->insert(self::table(),['action'=>sanitize_key($action),'message'=>sanitize_text_field($message),'context'=>$context?wp_json_encode($context,JSON_UNESCAPED_UNICODE):null,'created_at'=>current_time('mysql',true)],['%s','%s','%s','%s']); }
    public static function recent(int $limit=100): array { global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT id,action,message,context,created_at FROM '.self::table().' ORDER BY id DESC LIMIT %d',min(200,max(1,$limit))),ARRAY_A); foreach($rows as &$row)$row['context']=$row['context']?json_decode($row['context'],true):null; return $rows; }
}
