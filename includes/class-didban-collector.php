<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WordPress / WooCommerce hooks and receives front-end tracking pings.
 */
class Didban_Collector {

	public static function init() {
		// Front-end visit tracker.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_didban_t', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_didban_t', array( __CLASS__, 'ajax_track' ) );

		// Authentication.
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'retrieve_password', array( __CLASS__, 'on_password_request' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ) );

		// Accounts and content.
		add_action( 'user_register', array( __CLASS__, 'on_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_comment' ), 10, 2 );

		// WooCommerce.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_block' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 4 );

		// Retention.
		add_action( 'didban_cleanup', array( 'Didban_DB', 'cleanup' ) );
	}

	/* ------------------------------------------------------------------
	 * Front-end tracker
	 * ---------------------------------------------------------------- */

	public static function enqueue() {
		if ( is_customize_preview() ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) && ! Didban_DB::opt( 'track_admins' ) ) {
			return;
		}
		wp_enqueue_script( 'didban-tracker', DIDBAN_URL . 'assets/tracker.js', array(), DIDBAN_VERSION, true );
		wp_localize_script( 'didban-tracker', 'DidbanCfg', array( 'url' => admin_url( 'admin-ajax.php' ) ) );
	}

	private static function clean_id( $v ) {
		$v = is_string( $v ) ? $v : '';
		return preg_match( '/^[a-z0-9]{8,40}$/i', $v ) ? $v : '';
	}

	public static function ajax_track() {
		global $wpdb;

		$ua = Didban_DB::parse_ua( isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' );
		if ( 'bot' === $ua['type'] ) {
			wp_die( '', '', array( 'response' => 204 ) );
		}

		$uid = get_current_user_id();
		if ( $uid && ! Didban_DB::opt( 'track_admins' ) && user_can( $uid, 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 204 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public analytics beacon, no state-changing privileges.
		$vid = self::clean_id( isset( $_POST['v'] ) ? wp_unslash( $_POST['v'] ) : '' );
		$sid = self::clean_id( isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : '' );
		if ( ! $vid || ! $sid ) {
			wp_die( '', '', array( 'response' => 204 ) );
		}

		$type  = ( isset( $_POST['t'] ) && 'view' === $_POST['t'] ) ? 'view' : 'ping';
		$delta = isset( $_POST['d'] ) ? min( 60, max( 0, (int) $_POST['d'] ) ) : 0;

		$path = isset( $_POST['u'] ) ? wp_unslash( $_POST['u'] ) : '/';
		$path = wp_parse_url( $path, PHP_URL_PATH );
		$path = mb_substr( sanitize_text_field( $path ? $path : '/' ), 0, 255 );

		$title = isset( $_POST['ti'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['ti'] ) ), 0, 200 ) : '';

		$ref_host = '';
		if ( isset( $_POST['r'] ) && '' !== $_POST['r'] ) {
			$h        = wp_parse_url( esc_url_raw( wp_unslash( $_POST['r'] ) ), PHP_URL_HOST );
			$own      = wp_parse_url( home_url(), PHP_URL_HOST );
			$ref_host = ( $h && $h !== $own ) ? mb_substr( $h, 0, 255 ) : '';
		}
		// phpcs:enable

		$now      = Didban_DB::now();
		$sessions = Didban_DB::t( 'sessions' );
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $sessions WHERE session_id = %s", $sid ) );

		if ( ! $row ) {
			$wpdb->insert(
				$sessions,
				array(
					'session_id'  => $sid,
					'visitor_id'  => $vid,
					'user_id'     => (int) $uid,
					'ip'          => Didban_DB::get_ip(),
					'device'      => mb_substr( $ua['label'], 0, 80 ),
					'device_type' => $ua['type'],
					'referrer'    => $ref_host,
					'landing'     => $path,
					'started_at'  => $now,
					'last_seen'   => $now,
					'duration'    => $delta,
					'pageviews'   => ( 'view' === $type ) ? 1 : 0,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $sessions SET last_seen = %s, duration = duration + %d, pageviews = pageviews + %d, user_id = IF(%d > 0, %d, user_id) WHERE id = %d",
					$now,
					$delta,
					( 'view' === $type ) ? 1 : 0,
					$uid,
					$uid,
					$row->id
				)
			);
		}

		if ( 'view' === $type ) {
			$wpdb->insert(
				Didban_DB::t( 'pageviews' ),
				array(
					'session_id' => $sid,
					'user_id'    => (int) $uid,
					'url'        => $path,
					'title'      => $title,
					'created_at' => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s' )
			);
		}

		wp_die( '', '', array( 'response' => 204 ) );
	}

	/* ------------------------------------------------------------------
	 * Authentication events
	 * ---------------------------------------------------------------- */

	public static function on_login( $user_login, $user ) {
		global $wpdb;
		$ip    = Didban_DB::get_ip();
		$fails = 0;
		if ( $ip ) {
			$fails = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Didban_DB::t( 'events' ) . " WHERE type = 'login_failed' AND ip = %s AND created_at > %s",
					$ip,
					Didban_DB::ago( HOUR_IN_SECONDS )
				)
			);
		}
		Didban_DB::log(
			'login_success',
			array(
				'roles'          => array_values( (array) $user->roles ),
				'prior_failures' => $fails,
			),
			$user->ID,
			$user_login
		);
	}

	public static function on_login_failed( $username, $error = null ) {
		$username = (string) $username;
		$exists   = ( '' !== $username && ( username_exists( $username ) || email_exists( $username ) ) ) ? 1 : 0;
		$reason   = is_wp_error( $error ) ? $error->get_error_code() : '';
		Didban_DB::log(
			'login_failed',
			array(
				'user_exists' => $exists,
				'reason'      => $reason,
			),
			0,
			$username
		);
	}

	public static function on_logout( $user_id = 0 ) {
		Didban_DB::log( 'logout', array(), $user_id ? $user_id : null );
	}

	public static function on_password_request( $user_login ) {
		Didban_DB::log( 'password_request', array(), 0, (string) $user_login );
	}

	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			Didban_DB::log( 'password_reset', array(), $user->ID, $user->user_login );
		}
	}

	/* ------------------------------------------------------------------
	 * Accounts / content
	 * ---------------------------------------------------------------- */

	public static function on_register( $user_id ) {
		Didban_DB::log( 'register', array(), $user_id );
	}

	public static function on_profile_update( $user_id ) {
		Didban_DB::log( 'profile_update', array(), $user_id );
	}

	public static function on_post_status( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || ! $post ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'shop_order', 'shop_order_refund' ), true ) ) {
			return;
		}
		$pt = get_post_type_object( $post->post_type );
		Didban_DB::log(
			'post_publish',
			array(
				'post_id' => $post->ID,
				'title'   => wp_strip_all_tags( $post->post_title ),
				'ptype'   => $pt ? $pt->labels->singular_name : $post->post_type,
			)
		);
	}

	public static function on_comment( $id, $comment ) {
		if ( ! $comment || in_array( $comment->comment_type, array( 'pingback', 'trackback', 'order_note', 'webhook_delivery' ), true ) ) {
			return;
		}
		Didban_DB::log(
			'comment',
			array(
				'post_id' => (int) $comment->comment_post_ID,
				'title'   => wp_strip_all_tags( get_the_title( $comment->comment_post_ID ) ),
			),
			(int) $comment->user_id,
			$comment->user_id ? null : $comment->comment_author
		);
	}

	/* ------------------------------------------------------------------
	 * WooCommerce
	 * ---------------------------------------------------------------- */

	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
		$pid = $variation_id ? $variation_id : $product_id;
		Didban_DB::log(
			'add_to_cart',
			array(
				'product_id' => (int) $product_id,
				'name'       => wp_strip_all_tags( get_the_title( $pid ) ),
				'qty'        => (int) $quantity,
			)
		);
	}

	private static function order_payload( $order ) {
		$items = array();
		foreach ( $order->get_items() as $it ) {
			$items[] = array(
				'name' => $it->get_name(),
				'qty'  => (int) $it->get_quantity(),
			);
		}
		return array(
			'order_id' => $order->get_id(),
			'total'    => (float) $order->get_total(),
			'currency' => $order->get_currency(),
			'status'   => $order->get_status(),
			'payment'  => $order->get_payment_method_title(),
			'items'    => $items,
			'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'guest'    => $order->get_customer_id() ? 0 : 1,
		);
	}

	private static function log_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || $order->get_meta( '_didban_logged' ) ) {
			return;
		}
		$order->update_meta_data( '_didban_logged', 1 );
		$order->save_meta_data();

		$uid  = (int) $order->get_customer_id();
		$name = null;
		if ( ! $uid ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( '' === $name ) {
				$name = (string) $order->get_billing_email();
			}
		}
		Didban_DB::log( 'order_new', self::order_payload( $order ), $uid, $name );
	}

	public static function on_order_classic( $order_id, $posted = null, $order = null ) {
		self::log_order( ( $order && is_a( $order, 'WC_Order' ) ) ? $order : wc_get_order( $order_id ) );
	}

	public static function on_order_block( $order ) {
		self::log_order( $order );
	}

	public static function on_order_status( $order_id, $from, $to, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$uid  = (int) $order->get_customer_id();
		$name = $uid ? null : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$by   = get_current_user_id();
		$bu   = $by ? get_userdata( $by ) : null;
		Didban_DB::log(
			'order_status',
			array(
				'order_id' => (int) $order_id,
				'from'     => $from,
				'to'       => $to,
				'total'    => (float) $order->get_total(),
				'currency' => $order->get_currency(),
				'by'       => ( $bu && $by !== $uid ) ? $bu->user_login : '',
			),
			$uid,
			$name
		);
	}
}
