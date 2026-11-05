/*!
 * Didban tracker: counts visitors, page views and active time on site.
 * Stores only two random IDs in localStorage. No third-party requests.
 */
(function () {
	'use strict';

	var C = window.DidbanCfg;
	if (!C || !C.url) { return; }

	var SESSION_GAP = 30 * 60 * 1000; // new session after 30 minutes of silence
	var IDLE_LIMIT = 90 * 1000;       // stop counting time after 90s without interaction
	var PING_EVERY = 15000;

	function now() { return Date.now(); }

	function rid() {
		var a = new Uint8Array(12);
		(window.crypto || window.msCrypto).getRandomValues(a);
		return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	function read(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function write(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }

	var vid = read('didban_v');
	if (!vid) { vid = rid(); write('didban_v', vid); }

	var sid = read('didban_s');
	var lastTouch = parseInt(read('didban_t') || '0', 10);
	if (!sid || now() - lastTouch > SESSION_GAP) {
		sid = rid();
		write('didban_s', sid);
	}

	function touch() {
		lastTouch = now();
		write('didban_t', String(lastTouch));
	}

	var pending = 0;
	var lastAct = now();

	['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
		window.addEventListener(ev, function () { lastAct = now(); }, { passive: true });
	});

	setInterval(function () {
		if (!document.hidden && now() - lastAct < IDLE_LIMIT) { pending++; }
	}, 1000);

	function post(type, delta) {
		var p = new URLSearchParams();
		p.set('action', 'didban_t');
		p.set('t', type);
		p.set('v', vid);
		p.set('s', sid);
		p.set('d', String(delta));
		p.set('u', location.pathname);
		p.set('ti', document.title || '');
		if (type === 'view') { p.set('r', document.referrer || ''); }
		var body = p.toString();

		if (navigator.sendBeacon) {
			try {
				if (navigator.sendBeacon(C.url, new Blob([body], { type: 'application/x-www-form-urlencoded' }))) {
					touch();
					return;
				}
			} catch (e) { /* fall through */ }
		}
		try {
			fetch(C.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () {});
		} catch (e) { /* ignore */ }
		touch();
	}

	function ping() {
		if (pending <= 0) { return; }
		var d = pending;
		pending = 0;
		post('ping', d);
	}

	// Page load = one view.
	post('view', 0);

	setInterval(ping, PING_EVERY);

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			ping();
		} else if (now() - lastTouch > SESSION_GAP) {
			// Came back after a long break: start a fresh session.
			sid = rid();
			write('didban_s', sid);
			pending = 0;
			post('view', 0);
		}
	});

	window.addEventListener('pagehide', ping);
})();
