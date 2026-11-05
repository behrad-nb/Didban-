<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables, settings and small helpers shared by the whole plugin.
 */
class Didban_DB {

	public static $months = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return array(
			'track_admins'        => 0,
			'anonymize_ip'        => 0,
			'trust_proxy'         => 0,
			'retention'           => 90,
			'calendar'            => 'jalali',
			'load_font'           => 1,
			'fail_threshold'      => 5,
			'delete_on_uninstall' => 0,
		);
	}

	public static function opt( $key ) {
		$o = wp_parse_args( (array) get_option( 'didban_settings', array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'didban_' . $name;
	}

	/* ------------------------------------------------------------------
	 * Install / upgrade / cleanup
	 * ---------------------------------------------------------------- */

	public static function activate() {
		self::create_tables();
		update_option( 'didban_db_version', DIDBAN_VERSION );
		if ( ! wp_next_scheduled( 'didban_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'didban_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'didban_cleanup' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'didban_db_version' ) !== DIDBAN_VERSION ) {
			self::create_tables();
			update_option( 'didban_db_version', DIDBAN_VERSION );
		}
		if ( ! wp_next_scheduled( 'didban_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'didban_cleanup' );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$events = self::t( 'events' );
		dbDelta(
			"CREATE TABLE $events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  type VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  username VARCHAR(100) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  device VARCHAR(80) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  details LONGTEXT NULL,
  PRIMARY KEY  (id),
  KEY type_time (type,created_at),
  KEY user_id (user_id),
  KEY ip (ip),
  KEY created_at (created_at)
) $c;"
		);

		$sessions = self::t( 'sessions' );
		dbDelta(
			"CREATE TABLE $sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(40) NOT NULL,
  visitor_id VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  device VARCHAR(80) NOT NULL DEFAULT '',
  device_type VARCHAR(12) NOT NULL DEFAULT 'desktop',
  referrer VARCHAR(255) NOT NULL DEFAULT '',
  landing VARCHAR(255) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  duration INT UNSIGNED NOT NULL DEFAULT 0,
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY session_id (session_id),
  KEY started_at (started_at),
  KEY visitor_day (visitor_id,started_at),
  KEY last_seen (last_seen),
  KEY user_id (user_id)
) $c;"
		);

		$views = self::t( 'pageviews' );
		dbDelta(
			"CREATE TABLE $views (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  url VARCHAR(255) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY created_at (created_at)
) $c;"
		);
	}

	public static function cleanup() {
		global $wpdb;
		$days = (int) self::opt( 'retention' );
		if ( $days <= 0 ) {
			return;
		}
		$cut = self::ago( $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'events' ) . ' WHERE created_at < %s', $cut ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'sessions' ) . ' WHERE started_at < %s', $cut ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'pageviews' ) . ' WHERE created_at < %s', $cut ) );
	}

	/* ------------------------------------------------------------------
	 * Request helpers
	 * ---------------------------------------------------------------- */

	/** MySQL datetime (site timezone) for "now minus $seconds". */
	public static function ago( $seconds ) {
		return wp_date( 'Y-m-d H:i:s', time() - (int) $seconds );
	}

	public static function now() {
		return wp_date( 'Y-m-d H:i:s' );
	}

	public static function get_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		if ( self::opt( 'trust_proxy' ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$parts = explode( ',', wp_unslash( $_SERVER[ $h ] ) );
					$cand  = trim( $parts[0] );
					if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
						$ip = $cand;
						break;
					}
				}
			}
		}

		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		if ( $ip && self::opt( 'anonymize_ip' ) ) {
			if ( false !== strpos( $ip, ':' ) ) {
				$p  = explode( ':', $ip );
				$ip = implode( ':', array_slice( $p, 0, 3 ) ) . '::';
			} else {
				$p    = explode( '.', $ip );
				$p[3] = '0';
				$ip   = implode( '.', $p );
			}
		}
		return $ip;
	}

	public static function parse_ua( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|curl\/|wget|python-requests|monitor|pingdom|uptime|lighthouse|gtmetrix|wordpress\//i', $ua ) ) {
			return array(
				'type'  => 'bot',
				'label' => 'ربات / نامشخص',
			);
		}

		$type = 'desktop';
		if ( preg_match( '/ipad|tablet|(android(?!.*mobile))/i', $ua ) ) {
			$type = 'tablet';
		} elseif ( preg_match( '/mobile|iphone|ipod|android|phone/i', $ua ) ) {
			$type = 'mobile';
		}

		$browser = 'سایر';
		$bmap    = array(
			'Edg'            => 'Edge',
			'OPR'            => 'Opera',
			'Opera'          => 'Opera',
			'SamsungBrowser' => 'Samsung',
			'Firefox'        => 'Firefox',
			'FxiOS'          => 'Firefox',
			'CriOS'          => 'Chrome',
			'Chrome'         => 'Chrome',
			'Safari'         => 'Safari',
			'MSIE'           => 'IE',
			'Trident'        => 'IE',
		);
		foreach ( $bmap as $k => $v ) {
			if ( false !== stripos( $ua, $k ) ) {
				$browser = $v;
				break;
			}
		}

		$os   = 'سایر';
		$omap = array(
			'Windows'  => 'Windows',
			'iPhone'   => 'iOS',
			'iPad'     => 'iPadOS',
			'Android'  => 'Android',
			'Mac OS X' => 'macOS',
			'CrOS'     => 'ChromeOS',
			'Linux'    => 'Linux',
		);
		foreach ( $omap as $k => $v ) {
			if ( false !== stripos( $ua, $k ) ) {
				$os = $v;
				break;
			}
		}

		return array(
			'type'  => $type,
			'label' => $browser . ' / ' . $os,
		);
	}

	public static function device_label( $type ) {
		$m = array(
			'mobile'  => 'موبایل',
			'tablet'  => 'تبلت',
			'desktop' => 'دسکتاپ',
			'bot'     => 'ربات',
		);
		return isset( $m[ $type ] ) ? $m[ $type ] : $type;
	}

	public static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$p   = wp_parse_url( $uri, PHP_URL_PATH );
		return mb_substr( sanitize_text_field( $p ? $p : '/' ), 0, 255 );
	}

	/**
	 * Write one row to the events table.
	 *
	 * @param string   $type     Event type key.
	 * @param array    $data     Extra details (stored as JSON).
	 * @param int|null $user_id  Null = current user.
	 * @param string|null $username Null = derive from user id.
	 */
	public static function log( $type, $data = array(), $user_id = null, $username = null ) {
		global $wpdb;

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( null === $username ) {
			$username = '';
			if ( $user_id ) {
				$u        = get_userdata( $user_id );
				$username = $u ? $u->user_login : '';
			}
		}

		$ua = self::parse_ua( isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' );

		$wpdb->insert(
			self::t( 'events' ),
			array(
				'created_at' => self::now(),
				'type'       => $type,
				'user_id'    => (int) $user_id,
				'username'   => mb_substr( sanitize_text_field( (string) $username ), 0, 100 ),
				'ip'         => self::get_ip(),
				'device'     => mb_substr( $ua['label'], 0, 80 ),
				'url'        => self::current_path(),
				'details'    => $data ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : null,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/* ------------------------------------------------------------------
	 * Formatting helpers (Jalali calendar, Persian digits, durations)
	 * ---------------------------------------------------------------- */

	public static function is_jalali() {
		return 'jalali' === self::opt( 'calendar' );
	}

	public static function digits( $s ) {
		if ( ! self::is_jalali() ) {
			return (string) $s;
		}
		return strtr(
			(string) $s,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}

	public static function n( $x, $decimals = 0 ) {
		return self::digits( number_format( (float) $x, $decimals ) );
	}

	public static function dt( $s ) {
		try {
			return new DateTime( $s, wp_timezone() );
		} catch ( Exception $e ) {
			return new DateTime( 'now', wp_timezone() );
		}
	}

	/** Gregorian to Jalali. */
	public static function g2j( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + ( (int) ( ( $gy2 + 3 ) / 4 ) ) - ( (int) ( ( $gy2 + 99 ) / 100 ) ) + ( (int) ( ( $gy2 + 399 ) / 400 ) ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * ( (int) ( $days / 12053 ) ) );
		$days %= 12053;
		$jy   += 4 * ( (int) ( $days / 1461 ) );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy   += (int) ( ( $days - 1 ) / 365 );
			$days  = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + (int) ( $days / 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + (int) ( ( $days - 186 ) / 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	public static function fmt_date( $mysql, $with_time = true ) {
		$d = self::dt( $mysql );
		if ( self::is_jalali() ) {
			list( $jy, $jm, $jd ) = self::g2j( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
			$out = $jd . ' ' . self::$months[ $jm - 1 ] . ' ' . $jy;
		} else {
			$out = $d->format( 'Y/m/d' );
		}
		if ( $with_time ) {
			$out .= self::is_jalali() ? '، ' : ' ';
			$out .= $d->format( 'H:i' );
		}
		return self::digits( $out );
	}

	/** Short day label for chart axes, from Y-m-d. */
	public static function fmt_day( $ymd ) {
		$d = self::dt( $ymd . ' 00:00:00' );
		if ( self::is_jalali() ) {
			list( , $jm, $jd ) = self::g2j( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
			return self::digits( $jd . ' ' . self::$months[ $jm - 1 ] );
		}
		return $d->format( 'M j' );
	}

	public static function time_ago( $mysql ) {
		$diff = time() - self::dt( $mysql )->getTimestamp();
		if ( $diff < 45 ) {
			return 'لحظاتی پیش';
		}
		if ( $diff < 3600 ) {
			return self::n( max( 1, round( $diff / 60 ) ) ) . ' دقیقه پیش';
		}
		if ( $diff < 86400 ) {
			return self::n( round( $diff / 3600 ) ) . ' ساعت پیش';
		}
		return self::n( round( $diff / 86400 ) ) . ' روز پیش';
	}

	/** Seconds to a readable Persian duration. */
	public static function dur( $sec ) {
		$sec = (int) round( $sec );
		if ( $sec <= 0 ) {
			return '—';
		}
		if ( $sec < 60 ) {
			return self::n( $sec ) . ' ثانیه';
		}
		$m = (int) floor( $sec / 60 );
		$s = $sec % 60;
		if ( $m >= 60 ) {
			$h = (int) floor( $m / 60 );
			$m = $m % 60;
			return self::n( $h ) . ' ساعت و ' . self::n( $m ) . ' دقیقه';
		}
		return self::n( $m ) . ' دقیقه' . ( $s ? ' و ' . self::n( $s ) . ' ثانیه' : '' );
	}

	public static function money( $amount, $currency = '' ) {
		$symbol = $currency;
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = wp_strip_all_tags( html_entity_decode( get_woocommerce_currency_symbol( $currency ? $currency : null ) ) );
		}
		return self::n( $amount ) . ' ' . $symbol;
	}
}
