<?php
/**
 * Plugin Name: دیدبان | مانیتور فعالیت کاربران
 * Plugin URI:  https://example.com/didban
 * Description: ببینید کاربران سایت شما چه می‌کنند: IP، ورود و تلاش‌های ناموفق، خریدها، بازدید روزانه، تعداد بازدیدکننده و مدت ماندگاری در سایت — در یک داشبورد زیبا.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:      Didban
 * License:     GPL-2.0-or-later
 * Text Domain: didban
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIDBAN_VERSION', '1.0.0' );
define( 'DIDBAN_FILE', __FILE__ );
define( 'DIDBAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIDBAN_URL', plugin_dir_url( __FILE__ ) );

require_once DIDBAN_DIR . 'includes/class-didban-db.php';
require_once DIDBAN_DIR . 'includes/class-didban-collector.php';
require_once DIDBAN_DIR . 'includes/class-didban-admin.php';

register_activation_hook( __FILE__, array( 'Didban_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Didban_DB', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		Didban_DB::maybe_upgrade();
		Didban_Collector::init();
		if ( is_admin() ) {
			Didban_Admin::init();
		}
	}
);

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DIDBAN_FILE, true );
		}
	}
);
