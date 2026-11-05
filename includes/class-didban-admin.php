<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wp-admin dashboard.
 */
class Didban_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_didban_series', array( __CLASS__, 'ajax_series' ) );
		add_action( 'wp_ajax_didban_live', array( __CLASS__, 'ajax_live' ) );
		add_action( 'admin_post_didban_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_didban_purge', array( __CLASS__, 'purge' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DIDBAN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">داشبورد</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( 'دیدبان', 'دیدبان', 'manage_options', 'didban', array( __CLASS__, 'page' ), 'dashicons-visibility', 3 );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'didban' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function get( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private static function current_tab() {
		$tab = sanitize_key( self::get( 'tab' ) );
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'overview';
	}

	private static function tabs() {
		return array(
			'overview' => 'نمای کلی',
			'activity' => 'همه فعالیت‌ها',
			'logins'   => 'ورود و امنیت',
			'visitors' => 'بازدیدکنندگان',
			'orders'   => 'خریدها',
			'settings' => 'تنظیمات',
		);
	}

	public static function type_map() {
		return array(
			'login_success'   => array( 'ورود موفق', 'ok', 'unlock' ),
			'login_failed'    => array( 'ورود ناموفق', 'bad', 'warning' ),
			'logout'          => array( 'خروج', 'mute', 'exit' ),
			'register'        => array( 'ثبت‌نام', 'info', 'admin-users' ),
			'password_request' => array( 'درخواست بازیابی رمز', 'warn', 'email-alt' ),
			'password_reset'  => array( 'تغییر رمز', 'warn', 'lock' ),
			'profile_update'  => array( 'ویرایش پروفایل', 'info', 'edit' ),
			'order_new'       => array( 'سفارش جدید', 'buy', 'cart' ),
			'order_status'    => array( 'وضعیت سفارش', 'buy', 'update' ),
			'add_to_cart'     => array( 'افزودن به سبد', 'buy', 'plus-alt' ),
			'post_publish'    => array( 'انتشار محتوا', 'info', 'megaphone' ),
			'comment'         => array( 'دیدگاه', 'info', 'admin-comments' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Assets
	 * ---------------------------------------------------------------- */

	public static function assets( $hook ) {
		if ( 'toplevel_page_didban' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		if ( Didban_DB::opt( 'load_font' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_style( 'didban-font', 'https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/Vazirmatn-font-face.css', array(), null );
		}
		wp_enqueue_style( 'didban-admin', DIDBAN_URL . 'assets/admin.css', array(), DIDBAN_VERSION );

		if ( 'overview' === self::current_tab() ) {
			wp_enqueue_script( 'didban-admin', DIDBAN_URL . 'assets/admin.js', array(), DIDBAN_VERSION, true );
			wp_localize_script(
				'didban-admin',
				'DidbanData',
				array(
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'didban' ),
					'fa'      => Didban_DB::is_jalali() ? 1 : 0,
					'series'  => self::series( 14 ),
					'devices' => self::devices(),
					'i18n'    => array(
						'visitors' => 'بازدیدکننده',
						'views'    => 'بازدید صفحه',
						'avg'      => 'میانگین حضور',
						'empty'    => 'هنوز بازدیدی ثبت نشده است',
						'sessions' => 'نشست',
					),
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Data queries
	 * ---------------------------------------------------------------- */

	public static function series( $days ) {
		global $wpdb;
		$days = max( 1, min( 365, (int) $days ) );

		$cur = new DateTime( 'today', wp_timezone() );
		$cur->modify( '-' . ( $days - 1 ) . ' days' );
		$dates = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$dates[] = $cur->format( 'Y-m-d' );
			$cur->modify( '+1 day' );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(started_at) AS d, COUNT(DISTINCT visitor_id) AS v, COUNT(*) AS s, SUM(pageviews) AS pv, AVG(NULLIF(duration,0)) AS a FROM ' . Didban_DB::t( 'sessions' ) . ' WHERE started_at >= %s GROUP BY DATE(started_at)',
				$dates[0] . ' 00:00:00'
			),
			OBJECT_K
		);

		$out = array(
			'dates'     => $dates,
			'labels'    => array(),
			'visitors'  => array(),
			'pageviews' => array(),
			'sessions'  => array(),
			'avg'       => array(),
		);
		foreach ( $dates as $d ) {
			$r                    = isset( $rows[ $d ] ) ? $rows[ $d ] : null;
			$out['labels'][]      = Didban_DB::fmt_day( $d );
			$out['visitors'][]    = $r ? (int) $r->v : 0;
			$out['pageviews'][]   = $r ? (int) $r->pv : 0;
			$out['sessions'][]    = $r ? (int) $r->s : 0;
			$out['avg'][]         = $r ? (int) round( $r->a ) : 0;
		}
		return $out;
	}

	private static function devices() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT device_type AS k, COUNT(*) AS n FROM ' . Didban_DB::t( 'sessions' ) . ' WHERE started_at >= %s GROUP BY device_type ORDER BY n DESC',
				Didban_DB::ago( 30 * DAY_IN_SECONDS )
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'k'     => $r->k,
				'label' => Didban_DB::device_label( $r->k ),
				'n'     => (int) $r->n,
			);
		}
		return $out;
	}

	private static function online() {
		global $wpdb;
		$r = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT visitor_id) AS v, COUNT(DISTINCT NULLIF(user_id,0)) AS m FROM ' . Didban_DB::t( 'sessions' ) . ' WHERE last_seen >= %s',
				Didban_DB::ago( 300 )
			)
		);
		return array( $r ? (int) $r->v : 0, $r ? (int) $r->m : 0 );
	}

	private static function order_stats( $since ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT details FROM ' . Didban_DB::t( 'events' ) . " WHERE type = 'order_new' AND created_at >= %s",
				$since
			)
		);
		$count = 0;
		$sum   = 0;
		$cur   = '';
		foreach ( (array) $rows as $json ) {
			$d = json_decode( $json, true );
			++$count;
			if ( is_array( $d ) ) {
				$sum += isset( $d['total'] ) ? (float) $d['total'] : 0;
				if ( ! $cur && ! empty( $d['currency'] ) ) {
					$cur = $d['currency'];
				}
			}
		}
		return array( $count, $sum, $cur );
	}

	private static function list_events( $a = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$a,
			array(
				'types'   => array(),
				'ip'      => '',
				'user_id' => 0,
				'q'       => '',
				'page'    => 1,
				'per'     => 30,
			)
		);
		$tbl    = Didban_DB::t( 'events' );
		$where  = array( '1=1' );
		$params = array();

		if ( $a['types'] ) {
			$where[] = 'type IN (' . implode( ',', array_fill( 0, count( $a['types'] ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_values( $a['types'] ) );
		}
		if ( '' !== $a['ip'] ) {
			$where[]  = 'ip = %s';
			$params[] = $a['ip'];
		}
		if ( $a['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $a['user_id'];
		}
		if ( '' !== $a['q'] ) {
			$like     = '%' . $wpdb->esc_like( $a['q'] ) . '%';
			$where[]  = '(username LIKE %s OR ip LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$w = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM $tbl WHERE $w";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$per    = max( 1, (int) $a['per'] );
		$offset = max( 0, ( (int) $a['page'] - 1 ) * $per );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $tbl WHERE $w ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, $offset ) ) )
		);
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/* ------------------------------------------------------------------
	 * Rendering helpers
	 * ---------------------------------------------------------------- */

	private static function status_name( $s ) {
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $s ) : $s;
	}

	/** Human sentence for one event (returns safe HTML). */
	public static function describe( $e ) {
		$d = $e->details ? json_decode( $e->details, true ) : array();
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		$who = '<b>' . esc_html( '' !== $e->username ? $e->username : 'مهمان' ) . '</b>';

		switch ( $e->type ) {
			case 'login_success':
				$t = $who . ' وارد حساب کاربری شد';
				if ( ! empty( $d['prior_failures'] ) ) {
					$t .= ' <em class="dd-warn">بعد از ' . esc_html( Didban_DB::n( $d['prior_failures'] ) ) . ' تلاش ناموفق از همین IP</em>';
				}
				break;
			case 'login_failed':
				$t = 'تلاش ناموفق برای ورود با «<b>' . esc_html( $e->username ) . '</b>»';
				$t .= ! empty( $d['user_exists'] ) ? ' <em class="dd-warn">این حساب وجود دارد</em>' : ' <em class="dd-mute">حسابی با این نام نیست</em>';
				break;
			case 'logout':
				$t = $who . ' از حساب خارج شد';
				break;
			case 'register':
				$t = 'حساب جدید ساخته شد: ' . $who;
				break;
			case 'password_request':
				$t = 'درخواست بازیابی رمز برای «<b>' . esc_html( $e->username ) . '</b>»';
				break;
			case 'password_reset':
				$t = $who . ' رمز عبور را تغییر داد';
				break;
			case 'profile_update':
				$t = $who . ' پروفایل را ویرایش کرد';
				break;
			case 'add_to_cart':
				$t = $who . ' «' . esc_html( isset( $d['name'] ) ? $d['name'] : '' ) . '» را به سبد خرید افزود';
				if ( ! empty( $d['qty'] ) && $d['qty'] > 1 ) {
					$t .= ' (×' . esc_html( Didban_DB::n( $d['qty'] ) ) . ')';
				}
				break;
			case 'order_new':
				$items = array();
				foreach ( ( isset( $d['items'] ) ? (array) $d['items'] : array() ) as $it ) {
					$items[] = esc_html( $it['name'] ) . ( $it['qty'] > 1 ? ' ×' . esc_html( Didban_DB::n( $it['qty'] ) ) : '' );
				}
				$t = $who . ' سفارش <b>#' . esc_html( Didban_DB::n( isset( $d['order_id'] ) ? $d['order_id'] : 0 ) ) . '</b> را ثبت کرد';
				if ( $items ) {
					$t .= ': ' . implode( '، ', array_slice( $items, 0, 4 ) ) . ( count( $items ) > 4 ? ' و ...' : '' );
				}
				if ( isset( $d['total'] ) ) {
					$t .= ' <em class="dd-money">' . esc_html( Didban_DB::money( $d['total'], isset( $d['currency'] ) ? $d['currency'] : '' ) ) . '</em>';
				}
				break;
			case 'order_status':
				$t = 'وضعیت سفارش <b>#' . esc_html( Didban_DB::n( isset( $d['order_id'] ) ? $d['order_id'] : 0 ) ) . '</b> از «' . esc_html( self::status_name( isset( $d['from'] ) ? $d['from'] : '' ) ) . '» به «' . esc_html( self::status_name( isset( $d['to'] ) ? $d['to'] : '' ) ) . '» تغییر کرد';
				if ( ! empty( $d['by'] ) ) {
					$t .= ' <em class="dd-mute">توسط ' . esc_html( $d['by'] ) . '</em>';
				}
				break;
			case 'post_publish':
				$t = $who . ' ' . esc_html( isset( $d['ptype'] ) ? $d['ptype'] : 'محتوا' ) . ' «' . esc_html( isset( $d['title'] ) ? $d['title'] : '' ) . '» را منتشر کرد';
				break;
			case 'comment':
				$t = $who . ' روی «' . esc_html( isset( $d['title'] ) ? $d['title'] : '' ) . '» دیدگاه گذاشت';
				break;
			default:
				$t = esc_html( $e->type );
		}
		return $t;
	}

	private static function badge( $type ) {
		$map = self::type_map();
		$m   = isset( $map[ $type ] ) ? $map[ $type ] : array( $type, 'mute', 'marker' );
		return '<span class="dd-badge t-' . esc_attr( $m[1] ) . '"><span class="dashicons dashicons-' . esc_attr( $m[2] ) . '"></span>' . esc_html( $m[0] ) . '</span>';
	}

	private static function ip_link( $ip ) {
		if ( '' === $ip ) {
			return '<span class="dd-mute">—</span>';
		}
		return '<a class="dd-ip" dir="ltr" href="' . esc_url( self::url( array( 'tab' => 'activity', 'ip' => $ip ) ) ) . '">' . esc_html( $ip ) . '</a>';
	}

	/** Compact event list used in the live feed. */
	public static function feed_html( $rows ) {
		if ( ! $rows ) {
			return '<li class="dd-empty">هنوز رویدادی ثبت نشده. به‌محض ورود یا خرید کاربران، اینجا نمایش داده می‌شود.</li>';
		}
		$map = self::type_map();
		$h   = '';
		foreach ( $rows as $e ) {
			$m  = isset( $map[ $e->type ] ) ? $map[ $e->type ] : array( $e->type, 'mute', 'marker' );
			$h .= '<li class="dd-ev t-' . esc_attr( $m[1] ) . '">';
			$h .= '<span class="dd-ev-i"><span class="dashicons dashicons-' . esc_attr( $m[2] ) . '"></span></span>';
			$h .= '<div class="dd-ev-b"><p>' . self::describe( $e ) . '</p><small>';
			$h .= '<span dir="ltr">' . esc_html( '' !== $e->ip ? $e->ip : '—' ) . '</span>';
			$h .= '<span>' . esc_html( $e->device ) . '</span></small></div>';
			$h .= '<time>' . esc_html( Didban_DB::time_ago( $e->created_at ) ) . '</time></li>';
		}
		return $h;
	}

	private static function events_table( $rows ) {
		if ( ! $rows ) {
			echo '<div class="dd-empty-box">رویدادی برای نمایش وجود ندارد.</div>';
			return;
		}
		echo '<div class="dd-scroll"><table class="dd-table"><thead><tr><th>زمان</th><th>رویداد</th><th>شرح</th><th>IP</th><th>دستگاه</th></tr></thead><tbody>';
		foreach ( $rows as $e ) {
			echo '<tr><td class="dd-nowrap">' . esc_html( Didban_DB::fmt_date( $e->created_at ) ) . '<small>' . esc_html( Didban_DB::time_ago( $e->created_at ) ) . '</small></td>';
			echo '<td>' . self::badge( $e->type ) . '</td>';
			echo '<td class="dd-desc">' . self::describe( $e ) . '</td>';
			echo '<td>' . self::ip_link( $e->ip ) . '</td>';
			echo '<td class="dd-nowrap">' . esc_html( $e->device ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function pager( $total, $per, $page, $args ) {
		$pages = (int) ceil( $total / max( 1, $per ) );
		if ( $pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => self::url( $args ) . '&paged=%#%',
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => 'قبلی',
				'next_text' => 'بعدی',
				'type'      => 'plain',
			)
		);
		echo '<div class="dd-pager"><span>' . esc_html( Didban_DB::n( $total ) ) . ' مورد</span><div>' . wp_kses_post( $links ) . '</div></div>';
	}

	private static function delta( $cur, $prev ) {
		if ( $prev <= 0 ) {
			return $cur > 0 ? '<span class="dd-delta up">جدید</span>' : '<span class="dd-delta">—</span>';
		}
		$p = (int) round( ( $cur - $prev ) / $prev * 100 );
		if ( 0 === $p ) {
			return '<span class="dd-delta">بدون تغییر نسبت به دیروز</span>';
		}
		$cls = $p > 0 ? 'up' : 'down';
		return '<span class="dd-delta ' . $cls . '">' . ( $p > 0 ? '▲ ' : '▼ ' ) . esc_html( Didban_DB::digits( abs( $p ) ) ) . '٪ نسبت به دیروز</span>';
	}

	/* ------------------------------------------------------------------
	 * Page shell
	 * ---------------------------------------------------------------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = self::current_tab();
		list( $online, $members ) = self::online();

		echo '<div class="wrap didban" dir="rtl"><h1 class="screen-reader-text">دیدبان</h1>';

		echo '<header class="dd-head"><div class="dd-brand"><span class="dd-logo"><svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true"><path d="M2 16c3.5-6.2 8.3-9.3 14-9.3S26.500 9.800 30 16c-3.500 6.200-8.300 9.300-14 9.300S5.500 22.200 2 16z" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linejoin="round"/><circle cx="16" cy="16" r="4.500" fill="currentColor"/></svg></span><div><b>دیدبان</b><small>هر کاری که کاربران در سایت شما می‌کنند</small></div></div>';
		echo '<div class="dd-live"><i class="dd-pulse"></i><span><b id="didban-online">' . esc_html( Didban_DB::n( $online ) ) . '</b> نفر آنلاین</span><small id="didban-members">' . esc_html( Didban_DB::n( $members ) ) . ' عضو واردشده</small></div></header>';

		echo '<nav class="dd-tabs">';
		foreach ( self::tabs() as $k => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( self::url( array( 'tab' => $k ) ) ),
				$k === $tab ? 'is-on' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( '' !== self::get( 'saved' ) ) {
			echo '<div class="dd-note ok">تنظیمات ذخیره شد.</div>';
		}
		if ( '' !== self::get( 'purged' ) ) {
			echo '<div class="dd-note ok">همه داده‌های ثبت‌شده پاک شد.</div>';
		}

		echo '<main class="dd-main">';
		switch ( $tab ) {
			case 'activity':
				self::tab_activity();
				break;
			case 'logins':
				self::tab_logins();
				break;
			case 'visitors':
				'' !== self::get( 'session' ) ? self::tab_session( (int) self::get( 'session' ) ) : self::tab_visitors();
				break;
			case 'orders':
				self::tab_orders();
				break;
			case 'settings':
				self::tab_settings();
				break;
			default:
				self::tab_overview();
		}
		echo '</main></div>';
	}

	/* ------------------------------------------------------------------
	 * Tab: overview
	 * ---------------------------------------------------------------- */

	private static function tab_overview() {
		global $wpdb;
		$ev  = Didban_DB::t( 'events' );
		$ser = self::series( 14 );
		$i   = count( $ser['dates'] ) - 1;

		$v   = $ser['visitors'][ $i ];
		$pv  = $ser['pageviews'][ $i ];
		$av  = $ser['avg'][ $i ];
		$v0  = $ser['visitors'][ $i - 1 ];
		$pv0 = $ser['pageviews'][ $i - 1 ];
		$av0 = $ser['avg'][ $i - 1 ];

		$today = wp_date( 'Y-m-d' ) . ' 00:00:00';
		list( $online, $members ) = self::online();
		$fails    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ev WHERE type='login_failed' AND created_at >= %s", $today ) );
		$fail_ips = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM $ev WHERE type='login_failed' AND created_at >= %s", $today ) );
		list( $oc, $osum, $ocur ) = self::order_stats( $today );

		$kpis = array(
			array( 'بازدیدکننده امروز', Didban_DB::n( $v ), self::delta( $v, $v0 ), '' ),
			array( 'بازدید صفحه', Didban_DB::n( $pv ), self::delta( $pv, $pv0 ), '' ),
			array( 'میانگین زمان حضور', Didban_DB::dur( $av ), self::delta( $av, $av0 ), 'is-text' ),
			array( 'آنلاین الان', Didban_DB::n( $online ), '<span class="dd-delta">' . esc_html( Didban_DB::n( $members ) ) . ' عضو واردشده</span>', '' ),
			array( 'ورود ناموفق امروز', Didban_DB::n( $fails ), '<span class="dd-delta ' . ( $fails ? 'down' : '' ) . '">از ' . esc_html( Didban_DB::n( $fail_ips ) ) . ' IP مختلف</span>', $fails ? 'is-bad' : '' ),
			array( 'سفارش امروز', Didban_DB::n( $oc ), '<span class="dd-delta">' . ( $oc ? esc_html( Didban_DB::money( $osum, $ocur ) ) : 'هنوز سفارشی ثبت نشده' ) . '</span>', '' ),
		);

		echo '<section class="dd-strip">';
		foreach ( $kpis as $k ) {
			echo '<div class="dd-kpi ' . esc_attr( $k[3] ) . '"><span class="dd-kpi-l">' . esc_html( $k[0] ) . '</span><strong>' . esc_html( $k[1] ) . '</strong>' . $k[2] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		echo '</section>';

		if ( 0 === array_sum( $ser['sessions'] ) ) {
			echo '<div class="dd-note info">هنوز بازدیدی ثبت نشده است. بازدید مدیران سایت به‌صورت پیش‌فرض شمرده نمی‌شود؛ برای تست، سایت را در یک پنجره ناشناس باز کنید.</div>';
		}

		// Row 1: main chart + devices/referrers.
		echo '<div class="dd-row r-wide"><section class="dd-stage"><div class="dd-stage-top"><div><h2>بازدید روزانه</h2><p>بازدیدکننده یکتا و بازدید صفحه در هر روز</p></div>';
		echo '<div class="dd-range"><button type="button" data-days="7">۷ روز</button><button type="button" data-days="14" class="is-on">۱۴ روز</button><button type="button" data-days="30">۳۰ روز</button><button type="button" data-days="90">۹۰ روز</button></div></div>';
		echo '<div class="dd-legend"><span class="lg lg-v">بازدیدکننده یکتا</span><span class="lg lg-p">بازدید صفحه</span></div>';
		echo '<div id="dd-chart" class="dd-chart" dir="ltr"></div></section>';

		echo '<div class="dd-col"><section class="dd-panel"><h2>دستگاه‌ها <small>۳۰ روز اخیر</small></h2><div class="dd-donut-wrap"><div id="dd-donut"></div><ul id="dd-donut-legend" class="dd-dlegend"></ul></div></section>';
		echo '<section class="dd-panel"><h2>مسیر ورود بازدیدکنندگان <small>۷ روز اخیر</small></h2>' . self::referrers_html() . '</section></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Row 2: avg time + top pages.
		echo '<div class="dd-row r-even"><section class="dd-panel"><h2>میانگین زمان حضور در سایت <small>به ازای هر نشست</small></h2><div id="dd-bars" class="dd-chart short" dir="ltr"></div></section>';
		echo '<section class="dd-panel"><h2>پربازدیدترین صفحات <small>۷ روز اخیر</small></h2>' . self::top_pages_html() . '</section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Row 3: live feed + suspicious IPs.
		$feed = self::list_events( array( 'per' => 10 ) );
		echo '<div class="dd-row r-wide"><section class="dd-panel"><h2>فعالیت زنده <small>هر ۳۰ ثانیه به‌روز می‌شود</small></h2><ul id="didban-feed" class="dd-feed">' . self::feed_html( $feed['rows'] ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<a class="dd-more" href="' . esc_url( self::url( array( 'tab' => 'activity' ) ) ) . '">دیدن همه فعالیت‌ها</a></section>';
		echo '<section class="dd-panel"><h2>IPهای مشکوک <small>ورود ناموفق در ۲۴ ساعت اخیر</small></h2>' . self::suspicious_html() . '</section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function referrers_html() {
		global $wpdb;
		$tbl    = Didban_DB::t( 'sessions' );
		$since  = Didban_DB::ago( 7 * DAY_IN_SECONDS );
		$direct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE referrer = '' AND started_at >= %s", $since ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT referrer AS r, COUNT(*) AS c FROM $tbl WHERE referrer <> '' AND started_at >= %s GROUP BY referrer ORDER BY c DESC LIMIT 5", $since ) );

		$list = array();
		if ( $direct ) {
			$list[] = array( 'مستقیم / بدون منبع', $direct );
		}
		foreach ( (array) $rows as $r ) {
			$list[] = array( $r->r, (int) $r->c );
		}
		if ( ! $list ) {
			return '<div class="dd-empty-box">هنوز داده‌ای وجود ندارد.</div>';
		}
		usort(
			$list,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		$max = max( 1, $list[0][1] );
		$h   = '<ul class="dd-bars">';
		foreach ( $list as $it ) {
			$h .= '<li style="--w:' . esc_attr( round( $it[1] / $max * 100 ) ) . '%"><div><b dir="auto">' . esc_html( $it[0] ) . '</b></div><span>' . esc_html( Didban_DB::n( $it[1] ) ) . '</span><i></i></li>';
		}
		return $h . '</ul>';
	}

	private static function top_pages_html() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT url, MAX(title) AS title, COUNT(*) AS c FROM ' . Didban_DB::t( 'pageviews' ) . ' WHERE created_at >= %s GROUP BY url ORDER BY c DESC LIMIT 8',
				Didban_DB::ago( 7 * DAY_IN_SECONDS )
			)
		);
		if ( ! $rows ) {
			return '<div class="dd-empty-box">هنوز داده‌ای وجود ندارد.</div>';
		}
		$max = max( 1, (int) $rows[0]->c );
		$h   = '<ul class="dd-bars">';
		foreach ( $rows as $r ) {
			$h .= '<li style="--w:' . esc_attr( round( $r->c / $max * 100 ) ) . '%"><div><b>' . esc_html( '' !== $r->title ? $r->title : $r->url ) . '</b><small dir="ltr">' . esc_html( $r->url ) . '</small></div><span>' . esc_html( Didban_DB::n( $r->c ) ) . '</span><i></i></li>';
		}
		return $h . '</ul>';
	}

	private static function suspicious_html() {
		global $wpdb;
		$thr  = (int) Didban_DB::opt( 'fail_threshold' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ip, COUNT(*) AS c, COUNT(DISTINCT username) AS u, MAX(created_at) AS last FROM ' . Didban_DB::t( 'events' ) . " WHERE type='login_failed' AND created_at >= %s AND ip <> '' GROUP BY ip ORDER BY c DESC LIMIT 6",
				Didban_DB::ago( DAY_IN_SECONDS )
			)
		);
		if ( ! $rows ) {
			return '<div class="dd-empty-box good">در ۲۴ ساعت گذشته تلاش ناموفقی ثبت نشده است.</div>';
		}
		$h = '<ul class="dd-sus">';
		foreach ( $rows as $r ) {
			$hot = (int) $r->c >= $thr;
			$h  .= '<li class="' . ( $hot ? 'hot' : '' ) . '"><div>' . self::ip_link( $r->ip ) . '<small>' . esc_html( Didban_DB::n( $r->u ) ) . ' نام کاربری مختلف، آخرین: ' . esc_html( Didban_DB::time_ago( $r->last ) ) . '</small></div>';
			$h  .= '<span class="dd-count">' . esc_html( Didban_DB::n( $r->c ) ) . '<small>تلاش</small></span></li>';
		}
		return $h . '</ul>';
	}

	/* ------------------------------------------------------------------
	 * Tab: all activity
	 * ---------------------------------------------------------------- */

	private static function tab_activity() {
		$type = sanitize_key( self::get( 'type' ) );
		$q    = self::get( 'q' );
		$ip   = self::get( 'ip' );
		$uid  = (int) self::get( 'user_id' );
		$page = max( 1, (int) self::get( 'paged' ) );
		$map  = self::type_map();

		$res = self::list_events(
			array(
				'types'   => isset( $map[ $type ] ) ? array( $type ) : array(),
				'ip'      => $ip,
				'user_id' => $uid,
				'q'       => $q,
				'page'    => $page,
			)
		);

		echo '<section class="dd-panel flush"><form class="dd-filter" method="get"><input type="hidden" name="page" value="didban"><input type="hidden" name="tab" value="activity">';
		echo '<select name="type"><option value="">همه رویدادها</option>';
		foreach ( $map as $k => $m ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $type, $k, false ), esc_html( $m[0] ) );
		}
		echo '</select><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="جستجوی نام کاربری یا IP">';
		echo '<button class="dd-btn" type="submit">اعمال فیلتر</button>';
		if ( $ip || $uid || $q || $type ) {
			echo '<a class="dd-btn ghost" href="' . esc_url( self::url( array( 'tab' => 'activity' ) ) ) . '">پاک کردن</a>';
		}
		echo '</form>';
		if ( $ip ) {
			echo '<div class="dd-chip">فقط IP: <b dir="ltr">' . esc_html( $ip ) . '</b></div>';
		}
		self::events_table( $res['rows'] );
		self::pager(
			$res['total'],
			30,
			$page,
			array_filter(
				array(
					'tab'     => 'activity',
					'type'    => $type,
					'q'       => $q,
					'ip'      => $ip,
					'user_id' => $uid,
				)
			)
		);
		echo '</section>';
	}

	/* ------------------------------------------------------------------
	 * Tab: logins & security
	 * ---------------------------------------------------------------- */

	private static function tab_logins() {
		global $wpdb;
		$ev   = Didban_DB::t( 'events' );
		$thr  = (int) Didban_DB::opt( 'fail_threshold' );
		$d1   = Didban_DB::ago( DAY_IN_SECONDS );
		$d7   = Didban_DB::ago( 7 * DAY_IN_SECONDS );
		$d30  = Didban_DB::ago( 30 * DAY_IN_SECONDS );
		$page = max( 1, (int) self::get( 'paged' ) );

		$ok24   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ev WHERE type='login_success' AND created_at >= %s", $d1 ) );
		$bad24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ev WHERE type='login_failed' AND created_at >= %s", $d1 ) );
		$hot24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT ip FROM $ev WHERE type='login_failed' AND created_at >= %s AND ip <> '' GROUP BY ip HAVING COUNT(*) >= %d) t", $d1, $thr ) );
		$users7 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $ev WHERE type='login_success' AND created_at >= %s", $d7 ) );

		echo '<section class="dd-strip four">';
		echo '<div class="dd-kpi"><span class="dd-kpi-l">ورود موفق (۲۴ ساعت)</span><strong>' . esc_html( Didban_DB::n( $ok24 ) ) . '</strong></div>';
		echo '<div class="dd-kpi ' . ( $bad24 ? 'is-bad' : '' ) . '"><span class="dd-kpi-l">ورود ناموفق (۲۴ ساعت)</span><strong>' . esc_html( Didban_DB::n( $bad24 ) ) . '</strong></div>';
		echo '<div class="dd-kpi ' . ( $hot24 ? 'is-bad' : '' ) . '"><span class="dd-kpi-l">IP مشکوک (' . esc_html( Didban_DB::n( $thr ) ) . ' تلاش یا بیشتر)</span><strong>' . esc_html( Didban_DB::n( $hot24 ) ) . '</strong></div>';
		echo '<div class="dd-kpi"><span class="dd-kpi-l">حساب‌های واردشده (۷ روز)</span><strong>' . esc_html( Didban_DB::n( $users7 ) ) . '</strong></div></section>';

		echo '<div class="dd-row r-even">';

		// Failed attempts by IP.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip, COUNT(*) AS c, COUNT(DISTINCT username) AS u, MAX(created_at) AS last, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT username SEPARATOR '|'), '|', 3) AS names FROM $ev WHERE type='login_failed' AND created_at >= %s AND ip <> '' GROUP BY ip ORDER BY c DESC LIMIT 12",
				$d7
			)
		);
		echo '<section class="dd-panel"><h2>تلاش‌های ناموفق به تفکیک IP <small>۷ روز اخیر</small></h2>';
		if ( ! $rows ) {
			echo '<div class="dd-empty-box good">تلاش ناموفقی ثبت نشده است.</div>';
		} else {
			echo '<div class="dd-scroll"><table class="dd-table compact"><thead><tr><th>IP</th><th>تلاش</th><th>نام‌های امتحان‌شده</th><th>آخرین</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$hot   = (int) $r->c >= $thr;
				$names = array_map( 'esc_html', explode( '|', (string) $r->names ) );
				echo '<tr><td>' . self::ip_link( $r->ip ) . '</td><td><b>' . esc_html( Didban_DB::n( $r->c ) ) . '</b></td><td class="dd-names">' . implode( '، ', $names ) . '</td><td class="dd-nowrap">' . esc_html( Didban_DB::time_ago( $r->last ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td>' . ( $hot ? '<span class="dd-badge t-bad">مشکوک</span>' : '<span class="dd-badge t-mute">عادی</span>' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		// Accounts that logged in.
		$acc = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, MAX(username) AS username, MAX(created_at) AS last, COUNT(*) AS c, COUNT(DISTINCT ip) AS ips FROM $ev WHERE type='login_success' AND created_at >= %s GROUP BY user_id ORDER BY last DESC LIMIT 12",
				$d30
			)
		);
		echo '<section class="dd-panel"><h2>حساب‌هایی که وارد شده‌اند <small>۳۰ روز اخیر</small></h2>';
		if ( ! $acc ) {
			echo '<div class="dd-empty-box">هنوز ورودی ثبت نشده است.</div>';
		} else {
			echo '<div class="dd-scroll"><table class="dd-table compact"><thead><tr><th>حساب</th><th>تعداد ورود</th><th>IP متفاوت</th><th>آخرین ورود</th></tr></thead><tbody>';
			foreach ( $acc as $a ) {
				echo '<tr><td><a href="' . esc_url( self::url( array( 'tab' => 'activity', 'user_id' => $a->user_id ) ) ) . '"><b>' . esc_html( $a->username ) . '</b></a></td><td>' . esc_html( Didban_DB::n( $a->c ) ) . '</td><td>' . ( $a->ips > 2 ? '<span class="dd-badge t-warn">' . esc_html( Didban_DB::n( $a->ips ) ) . '</span>' : esc_html( Didban_DB::n( $a->ips ) ) ) . '</td><td class="dd-nowrap">' . esc_html( Didban_DB::time_ago( $a->last ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section></div>';

		// Login history.
		$res = self::list_events(
			array(
				'types' => array( 'login_success', 'login_failed', 'logout', 'password_request', 'password_reset' ),
				'page'  => $page,
				'per'   => 20,
			)
		);
		echo '<section class="dd-panel flush"><h2 class="pad">سابقه ورود، خروج و بازیابی رمز</h2>';
		self::events_table( $res['rows'] );
		self::pager( $res['total'], 20, $page, array( 'tab' => 'logins' ) );
		echo '</section>';
	}

	/* ------------------------------------------------------------------
	 * Tab: visitors / sessions
	 * ---------------------------------------------------------------- */

	private static function tab_visitors() {
		global $wpdb;
		$tbl   = Didban_DB::t( 'sessions' );
		$page  = max( 1, (int) self::get( 'paged' ) );
		$per   = 25;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tbl ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) );

		echo '<section class="dd-panel flush"><h2 class="pad">نشست‌های اخیر <small>روی هر نشست بزنید تا مسیر حرکت کاربر را ببینید</small></h2>';
		if ( ! $rows ) {
			echo '<div class="dd-empty-box">هنوز بازدیدی ثبت نشده است.</div></section>';
			return;
		}
		echo '<div class="dd-scroll"><table class="dd-table"><thead><tr><th>شروع</th><th>کاربر</th><th>IP</th><th>دستگاه</th><th>منبع</th><th>صفحه ورودی</th><th>صفحات</th><th>مدت حضور</th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			$u    = $s->user_id ? get_userdata( $s->user_id ) : null;
			$live = ( time() - Didban_DB::dt( $s->last_seen )->getTimestamp() ) < 300;
			echo '<tr><td class="dd-nowrap"><a href="' . esc_url( self::url( array( 'tab' => 'visitors', 'session' => $s->id ) ) ) . '">' . esc_html( Didban_DB::fmt_date( $s->started_at ) ) . '</a>' . ( $live ? ' <span class="dd-badge t-ok">آنلاین</span>' : '' ) . '</td>';
			echo '<td>' . ( $u ? '<b>' . esc_html( $u->user_login ) . '</b>' : '<span class="dd-mute">مهمان</span>' ) . '</td>';
			echo '<td>' . self::ip_link( $s->ip ) . '</td>';
			echo '<td class="dd-nowrap">' . esc_html( Didban_DB::device_label( $s->device_type ) . ' ، ' . $s->device ) . '</td>';
			echo '<td>' . ( '' !== $s->referrer ? '<span dir="ltr">' . esc_html( $s->referrer ) . '</span>' : '<span class="dd-mute">مستقیم</span>' ) . '</td>';
			echo '<td><span dir="ltr" class="dd-path">' . esc_html( $s->landing ) . '</span></td>';
			echo '<td>' . esc_html( Didban_DB::n( $s->pageviews ) ) . '</td>';
			echo '<td class="dd-nowrap">' . esc_html( Didban_DB::dur( $s->duration ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::pager( $total, $per, $page, array( 'tab' => 'visitors' ) );
		echo '</section>';
	}

	private static function tab_session( $id ) {
		global $wpdb;
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Didban_DB::t( 'sessions' ) . ' WHERE id = %d', $id ) );
		echo '<p><a class="dd-back" href="' . esc_url( self::url( array( 'tab' => 'visitors' ) ) ) . '">بازگشت به فهرست نشست‌ها</a></p>';
		if ( ! $s ) {
			echo '<div class="dd-empty-box">این نشست پیدا نشد.</div>';
			return;
		}
		$u = $s->user_id ? get_userdata( $s->user_id ) : null;

		echo '<section class="dd-strip four">';
		echo '<div class="dd-kpi is-text"><span class="dd-kpi-l">کاربر</span><strong>' . esc_html( $u ? $u->user_login : 'مهمان' ) . '</strong><span class="dd-delta" dir="ltr">' . esc_html( $s->ip ) . '</span></div>';
		echo '<div class="dd-kpi is-text"><span class="dd-kpi-l">دستگاه</span><strong>' . esc_html( Didban_DB::device_label( $s->device_type ) ) . '</strong><span class="dd-delta">' . esc_html( $s->device ) . '</span></div>';
		echo '<div class="dd-kpi is-text"><span class="dd-kpi-l">مدت حضور</span><strong>' . esc_html( Didban_DB::dur( $s->duration ) ) . '</strong><span class="dd-delta">' . esc_html( Didban_DB::n( $s->pageviews ) ) . ' صفحه دیده شد</span></div>';
		echo '<div class="dd-kpi is-text"><span class="dd-kpi-l">منبع ورود</span><strong>' . esc_html( '' !== $s->referrer ? $s->referrer : 'مستقیم' ) . '</strong><span class="dd-delta">' . esc_html( Didban_DB::fmt_date( $s->started_at ) ) . '</span></div></section>';

		$items = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Didban_DB::t( 'pageviews' ) . ' WHERE session_id = %s ORDER BY id ASC', $s->session_id ) ) as $p ) {
			$items[] = array(
				'time' => $p->created_at,
				'cls'  => 'page',
				'icon' => 'admin-page',
				'html' => 'بازدید صفحه «<b>' . esc_html( '' !== $p->title ? $p->title : $p->url ) . '</b>» <span class="dd-path" dir="ltr">' . esc_html( $p->url ) . '</span>',
			);
		}
		$map  = self::type_map();
		$from = wp_date( 'Y-m-d H:i:s', Didban_DB::dt( $s->started_at )->getTimestamp() - 10 );
		$to   = wp_date( 'Y-m-d H:i:s', Didban_DB::dt( $s->last_seen )->getTimestamp() + 120 );
		$evs  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Didban_DB::t( 'events' ) . ' WHERE (ip = %s OR (user_id > 0 AND user_id = %d)) AND created_at BETWEEN %s AND %s ORDER BY id ASC LIMIT 200',
				$s->ip,
				$s->user_id,
				$from,
				$to
			)
		);
		foreach ( (array) $evs as $e ) {
			$m       = isset( $map[ $e->type ] ) ? $map[ $e->type ] : array( $e->type, 'mute', 'marker' );
			$items[] = array(
				'time' => $e->created_at,
				'cls'  => $m[1],
				'icon' => $m[2],
				'html' => self::describe( $e ),
			);
		}
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $a['time'], $b['time'] );
			}
		);

		echo '<section class="dd-panel"><h2>مسیر حرکت کاربر در این نشست</h2><ol class="dd-tl">';
		foreach ( $items as $it ) {
			echo '<li class="t-' . esc_attr( $it['cls'] ) . '"><span class="dd-tl-i"><span class="dashicons dashicons-' . esc_attr( $it['icon'] ) . '"></span></span><div>' . $it['html'] . '</div><time>' . esc_html( Didban_DB::digits( Didban_DB::dt( $it['time'] )->format( 'H:i:s' ) ) ) . '</time></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ol></section>';
	}

	/* ------------------------------------------------------------------
	 * Tab: purchases
	 * ---------------------------------------------------------------- */

	private static function tab_orders() {
		$kind = sanitize_key( self::get( 'kind' ) );
		$kinds = array(
			'order_new'    => 'سفارش‌های جدید',
			'order_status' => 'تغییر وضعیت سفارش',
			'add_to_cart'  => 'افزودن به سبد خرید',
		);
		if ( ! isset( $kinds[ $kind ] ) ) {
			$kind = 'order_new';
		}
		$page = max( 1, (int) self::get( 'paged' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="dd-note warn">ووکامرس روی این سایت فعال نیست؛ تا زمانی که فعال نشود، خرید و سبد خرید ثبت نمی‌شود.</div>';
		}

		$periods = array(
			'امروز'    => wp_date( 'Y-m-d' ) . ' 00:00:00',
			'۷ روز اخیر'  => Didban_DB::ago( 7 * DAY_IN_SECONDS ),
			'۳۰ روز اخیر' => Didban_DB::ago( 30 * DAY_IN_SECONDS ),
		);
		echo '<section class="dd-strip three">';
		foreach ( $periods as $label => $since ) {
			list( $c, $sum, $cur ) = self::order_stats( $since );
			echo '<div class="dd-kpi"><span class="dd-kpi-l">سفارش‌ها، ' . esc_html( $label ) . '</span><strong>' . esc_html( Didban_DB::n( $c ) ) . '</strong><span class="dd-delta">' . ( $c ? esc_html( Didban_DB::money( $sum, $cur ) ) : '—' ) . '</span></div>';
		}
		echo '</section>';

		echo '<nav class="dd-pills">';
		foreach ( $kinds as $k => $l ) {
			printf( '<a href="%s" class="%s">%s</a>', esc_url( self::url( array( 'tab' => 'orders', 'kind' => $k ) ) ), $k === $kind ? 'is-on' : '', esc_html( $l ) );
		}
		echo '</nav>';

		$res = self::list_events(
			array(
				'types' => array( $kind ),
				'page'  => $page,
			)
		);
		echo '<section class="dd-panel flush">';
		self::events_table( $res['rows'] );
		self::pager( $res['total'], 30, $page, array( 'tab' => 'orders', 'kind' => $kind ) );
		echo '</section>';
	}

	/* ------------------------------------------------------------------
	 * Tab: settings
	 * ---------------------------------------------------------------- */

	private static function switch_row( $name, $title, $desc ) {
		printf(
			'<label class="dd-set"><span class="dd-set-t"><b>%1$s</b><small>%2$s</small></span><span class="dd-switch"><input type="checkbox" name="%3$s" value="1" %4$s><i></i></span></label>',
			esc_html( $title ),
			esc_html( $desc ),
			esc_attr( $name ),
			checked( (int) Didban_DB::opt( $name ), 1, false )
		);
	}

	private static function tab_settings() {
		echo '<div class="dd-row r-even"><section class="dd-panel"><h2>تنظیمات</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="didban_save">';
		wp_nonce_field( 'didban_save' );

		self::switch_row( 'track_admins', 'شمردن بازدید مدیران', 'اگر خاموش باشد، بازدید و فعالیت حساب‌های مدیر در آمار بازدید نمی‌آید.' );
		self::switch_row( 'anonymize_ip', 'ناشناس‌سازی IP', 'آخرین بخش IP ذخیره نمی‌شود. برای رعایت حریم خصوصی مناسب است، اما شناسایی مهاجم سخت‌تر می‌شود.' );
		self::switch_row( 'trust_proxy', 'اعتماد به هدر پروکسی (Cloudflare و مشابه)', 'فقط وقتی روشن کنید که سایت پشت Cloudflare یا پروکسی دیگری است؛ در غیر این صورت IP واقعی کاربر دیده نمی‌شود.' );
		self::switch_row( 'load_font', 'بارگذاری فونت وزیرمتن', 'فونت از اینترنت بارگذاری می‌شود. اگر دسترسی ندارید خاموش کنید.' );
		self::switch_row( 'delete_on_uninstall', 'حذف داده‌ها هنگام حذف افزونه', 'با پاک‌کردن افزونه، جدول‌ها و تنظیمات هم حذف می‌شوند.' );

		echo '<div class="dd-fields">';
		echo '<label>نگهداری داده‌ها (روز)<input type="number" min="0" max="3650" name="retention" value="' . esc_attr( Didban_DB::opt( 'retention' ) ) . '"><small>۰ یعنی همیشه نگه داشته شود.</small></label>';
		echo '<label>حد هشدار ورود ناموفق از یک IP<input type="number" min="2" max="100" name="fail_threshold" value="' . esc_attr( Didban_DB::opt( 'fail_threshold' ) ) . '"><small>در ۲۴ ساعت؛ بیشتر از آن «مشکوک» علامت می‌خورد.</small></label>';
		echo '<label>تقویم<select name="calendar"><option value="jalali"' . selected( Didban_DB::opt( 'calendar' ), 'jalali', false ) . '>شمسی (ارقام فارسی)</option><option value="gregorian"' . selected( Didban_DB::opt( 'calendar' ), 'gregorian', false ) . '>میلادی</option></select></label>';
		echo '</div><button class="dd-btn" type="submit">ذخیره تنظیمات</button></form></section>';

		echo '<section class="dd-panel"><h2>پاک‌سازی و حریم خصوصی</h2><p class="dd-p">این افزونه IP، نام کاربری و رفتار کاربران را ذخیره می‌کند. بهتر است در صفحه قوانین و حریم خصوصی سایت به کاربران اطلاع دهید. این داده‌ها فقط روی سرور خود شما نگهداری می‌شوند و جایی ارسال نمی‌شوند.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'همه داده‌های ثبت‌شده برای همیشه پاک شود؟\');">';
		echo '<input type="hidden" name="action" value="didban_purge">';
		wp_nonce_field( 'didban_purge' );
		echo '<button class="dd-btn danger" type="submit">پاک کردن همه داده‌ها</button></form></section></div>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'didban_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$o = array(
			'track_admins'        => ! empty( $_POST['track_admins'] ) ? 1 : 0,
			'anonymize_ip'        => ! empty( $_POST['anonymize_ip'] ) ? 1 : 0,
			'trust_proxy'         => ! empty( $_POST['trust_proxy'] ) ? 1 : 0,
			'load_font'           => ! empty( $_POST['load_font'] ) ? 1 : 0,
			'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ) ? 1 : 0,
			'retention'           => isset( $_POST['retention'] ) ? max( 0, min( 3650, (int) $_POST['retention'] ) ) : 90,
			'fail_threshold'      => isset( $_POST['fail_threshold'] ) ? max( 2, min( 100, (int) $_POST['fail_threshold'] ) ) : 5,
			'calendar'            => ( isset( $_POST['calendar'] ) && 'gregorian' === $_POST['calendar'] ) ? 'gregorian' : 'jalali',
		);
		// phpcs:enable
		update_option( 'didban_settings', $o );
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'saved' => 1 ) ) );
		exit;
	}

	public static function purge() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'didban_purge' );
		foreach ( array( 'events', 'sessions', 'pageviews' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Didban_DB::t( $t ) );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'purged' => 1 ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	public static function ajax_series() {
		check_ajax_referer( 'didban', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 14;
		$days = in_array( $days, array( 7, 14, 30, 90 ), true ) ? $days : 14;
		wp_send_json_success( self::series( $days ) );
	}

	public static function ajax_live() {
		check_ajax_referer( 'didban', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		list( $online, $members ) = self::online();
		$feed = self::list_events( array( 'per' => 10 ) );
		wp_send_json_success(
			array(
				'online'  => Didban_DB::n( $online ),
				'members' => Didban_DB::n( $members ) . ' عضو واردشده',
				'html'    => self::feed_html( $feed['rows'] ),
			)
		);
	}
}
