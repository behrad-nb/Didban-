/*!
 * Didban admin: dependency-free SVG charts + live updates.
 */
(function () {
	'use strict';

	var D = window.DidbanData;
	if (!D) { return; }

	var NS = 'http://www.w3.org/2000/svg';
	var T = D.i18n || {};

	var COLORS = { v: '#2dd4bf', p: '#f5b942', bar: '#0f9d8f' };
	var DEVICE_COLORS = { mobile: '#14b8a6', desktop: '#243b53', tablet: '#f5b942', bot: '#9aa5b1' };

	/* ---------- helpers ---------- */

	function el(name, attrs, parent) {
		var e = document.createElementNS(NS, name);
		for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) { e.setAttribute(k, attrs[k]); } }
		if (parent) { parent.appendChild(e); }
		return e;
	}

	function fa(s) {
		s = String(s);
		return D.fa ? s.replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(d); }) : s;
	}

	function num(n) {
		return fa(Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','));
	}

	function dur(s) {
		s = Math.round(s);
		if (s <= 0) { return '—'; }
		if (s < 60) { return fa(s) + ' ثانیه'; }
		var m = Math.floor(s / 60), r = s % 60;
		return fa(m) + ' دقیقه' + (r ? ' و ' + fa(r) + ' ثانیه' : '');
	}

	function tick(s) {
		if (s >= 60) { return fa(+(s / 60).toFixed(1)) + ' د'; }
		return fa(s) + ' ث';
	}

	function scale(v) {
		if (v <= 0) { return { max: 4, step: 1 }; }
		var raw = v / 4;
		var p = Math.pow(10, Math.floor(Math.log10(raw)));
		var n = raw / p;
		var s = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
		var step = Math.max(1, s * p);
		return { max: step * Math.ceil(v / step), step: step };
	}

	function smooth(pts, yMin, yMax) {
		if (pts.length < 2) { return ''; }
		var t = 0.18;
		var d = 'M' + pts[0][0] + ',' + pts[0][1];
		function cl(y) { return Math.min(yMax, Math.max(yMin, y)); }
		for (var i = 0; i < pts.length - 1; i++) {
			var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
			var c1x = p1[0] + (p2[0] - p0[0]) * t, c1y = cl(p1[1] + (p2[1] - p0[1]) * t);
			var c2x = p2[0] - (p3[0] - p1[0]) * t, c2y = cl(p2[1] - (p3[1] - p1[1]) * t);
			d += ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + p2[0] + ',' + p2[1];
		}
		return d;
	}

	function makeTip(host) {
		var tip = document.createElement('div');
		tip.className = 'dd-tip';
		tip.setAttribute('dir', 'rtl');
		host.appendChild(tip);
		return tip;
	}

	function placeTip(tip, host, x) {
		var w = tip.offsetWidth, W = host.clientWidth;
		var left = Math.min(Math.max(x - w / 2, 4), W - w - 4);
		tip.style.left = left + 'px';
		tip.style.top = '6px';
	}

	function axisLabels(svg, S, x, y0, n, color) {
		var step = Math.ceil(n / 7);
		for (var i = 0; i < n; i++) {
			var show = i % step === 0 || (i === n - 1 && (n - 1) % step >= Math.ceil(step / 2));
			if (!show) { continue; }
			var t = el('text', { x: x(i), y: y0 + 20, 'text-anchor': 'middle', 'font-size': 11, fill: color }, svg);
			t.textContent = S.labels[i];
		}
	}

	/* ---------- line / area chart ---------- */

	function lineChart(host, S) {
		host.innerHTML = '';
		var W = host.clientWidth || 600, H = host.clientHeight || 320;
		var m = { t: 16, r: 28, b: 30, l: 44 };
		var iw = W - m.l - m.r, ih = H - m.t - m.b;
		var n = S.labels.length;
		var maxv = Math.max.apply(null, S.visitors.concat(S.pageviews));
		var sc = scale(maxv);

		function x(i) { return n > 1 ? m.l + i * iw / (n - 1) : m.l + iw / 2; }
		function y(v) { return m.t + ih - (v / sc.max) * ih; }

		var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img' }, host);
		var defs = el('defs', {}, svg);
		[['ddgv', COLORS.v], ['ddgp', COLORS.p]].forEach(function (g) {
			var lg = el('linearGradient', { id: g[0], x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
			el('stop', { offset: '0%', 'stop-color': g[1], 'stop-opacity': 0.32 }, lg);
			el('stop', { offset: '100%', 'stop-color': g[1], 'stop-opacity': 0 }, lg);
		});

		for (var v = 0; v <= sc.max + 0.001; v += sc.step) {
			el('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v), stroke: 'rgba(255,255,255,' + (v === 0 ? 0.22 : 0.08) + ')', 'stroke-dasharray': v === 0 ? '' : '3 5' }, svg);
			var yl = el('text', { x: m.l - 10, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 11, fill: 'rgba(255,255,255,.5)' }, svg);
			yl.textContent = num(v);
		}
		axisLabels(svg, S, x, m.t + ih, n, 'rgba(255,255,255,.55)');

		var total = S.sessions.reduce(function (a, b) { return a + b; }, 0);
		if (!total) {
			var et = el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', 'font-size': 14, fill: 'rgba(255,255,255,.55)' }, svg);
			et.textContent = T.empty || '';
			return;
		}

		function draw(vals, color, grad, width) {
			var pts = vals.map(function (val, i) { return [x(i), y(val)]; });
			if (n < 2) { el('circle', { cx: pts[0][0], cy: pts[0][1], r: 4, fill: color }, svg); return; }
			var line = smooth(pts, m.t, m.t + ih);
			el('path', { d: line + ' L' + x(n - 1) + ',' + (m.t + ih) + ' L' + x(0) + ',' + (m.t + ih) + ' Z', fill: 'url(#' + grad + ')' }, svg);
			el('path', { d: line, fill: 'none', stroke: color, 'stroke-width': width, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, svg);
		}
		draw(S.pageviews, COLORS.p, 'ddgp', 2);
		draw(S.visitors, COLORS.v, 'ddgv', 2.8);

		// Hover layer.
		var guide = el('line', { y1: m.t, y2: m.t + ih, stroke: 'rgba(255,255,255,.3)', 'stroke-dasharray': '2 4', opacity: 0 }, svg);
		var dotV = el('circle', { r: 5, fill: '#0b1623', stroke: COLORS.v, 'stroke-width': 2.5, opacity: 0 }, svg);
		var dotP = el('circle', { r: 4, fill: '#0b1623', stroke: COLORS.p, 'stroke-width': 2, opacity: 0 }, svg);
		var tip = makeTip(host);
		var hit = el('rect', { x: m.l - 10, y: m.t, width: iw + 20, height: ih, fill: 'transparent', style: 'touch-action:pan-y;cursor:crosshair' }, svg);

		function move(ev) {
			var r = svg.getBoundingClientRect();
			var px = ev.clientX - r.left;
			var i = n > 1 ? Math.round((px - m.l) / iw * (n - 1)) : 0;
			i = Math.max(0, Math.min(n - 1, i));
			guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('opacity', 1);
			dotV.setAttribute('cx', x(i)); dotV.setAttribute('cy', y(S.visitors[i])); dotV.setAttribute('opacity', 1);
			dotP.setAttribute('cx', x(i)); dotP.setAttribute('cy', y(S.pageviews[i])); dotP.setAttribute('opacity', 1);
			tip.innerHTML = '<b>' + S.labels[i] + '</b>' +
				'<span><i style="background:' + COLORS.v + '"></i>' + (T.visitors || '') + '<em>' + num(S.visitors[i]) + '</em></span>' +
				'<span><i style="background:' + COLORS.p + '"></i>' + (T.views || '') + '<em>' + num(S.pageviews[i]) + '</em></span>' +
				'<span class="mute">' + (T.avg || '') + '<em>' + dur(S.avg[i]) + '</em></span>';
			tip.classList.add('on');
			placeTip(tip, host, x(i));
		}
		function leave() {
			guide.setAttribute('opacity', 0); dotV.setAttribute('opacity', 0); dotP.setAttribute('opacity', 0);
			tip.classList.remove('on');
		}
		hit.addEventListener('pointermove', move);
		hit.addEventListener('pointerdown', move);
		hit.addEventListener('pointerleave', leave);
	}

	/* ---------- bar chart (average time) ---------- */

	function barChart(host, S) {
		host.innerHTML = '';
		var W = host.clientWidth || 500, H = host.clientHeight || 220;
		var m = { t: 12, r: 14, b: 30, l: 44 };
		var iw = W - m.l - m.r, ih = H - m.t - m.b;
		var n = S.labels.length;
		var sc = scale(Math.max.apply(null, S.avg));
		var slot = iw / n, bw = Math.max(3, Math.min(34, slot * 0.58));

		function cx(i) { return m.l + slot * i + slot / 2; }
		function y(v) { return m.t + ih - (v / sc.max) * ih; }

		var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img' }, host);
		var defs = el('defs', {}, svg);
		var lg = el('linearGradient', { id: 'ddgb', x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
		el('stop', { offset: '0%', 'stop-color': '#2dd4bf' }, lg);
		el('stop', { offset: '100%', 'stop-color': COLORS.bar }, lg);

		for (var v = 0; v <= sc.max + 0.001; v += sc.step) {
			el('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v), stroke: v === 0 ? '#c9d3df' : '#e6ebf2', 'stroke-dasharray': v === 0 ? '' : '3 5' }, svg);
			var yl = el('text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#7b8a9c' }, svg);
			yl.textContent = tick(v);
		}
		axisLabels(svg, S, cx, m.t + ih, n, '#7b8a9c');

		var tip = makeTip(host);
		var bars = [];
		S.avg.forEach(function (val, i) {
			var h = Math.max(val > 0 ? 3 : 0, (val / sc.max) * ih);
			var r = el('rect', { x: cx(i) - bw / 2, y: m.t + ih - h, width: bw, height: h, rx: Math.min(5, bw / 2), fill: 'url(#ddgb)' }, svg);
			bars.push(r);
			var hit = el('rect', { x: m.l + slot * i, y: m.t, width: slot, height: ih, fill: 'transparent' }, svg);
			function on() {
				bars.forEach(function (b) { b.setAttribute('opacity', 0.35); });
				r.setAttribute('opacity', 1);
				tip.innerHTML = '<b>' + S.labels[i] + '</b><span>' + (T.avg || '') + '<em>' + dur(val) + '</em></span><span class="mute">' + (T.sessions || '') + '<em>' + num(S.sessions[i]) + '</em></span>';
				tip.classList.add('on');
				placeTip(tip, host, cx(i));
			}
			function off() {
				bars.forEach(function (b) { b.setAttribute('opacity', 1); });
				tip.classList.remove('on');
			}
			hit.addEventListener('pointerenter', on);
			hit.addEventListener('pointerdown', on);
			hit.addEventListener('pointerleave', off);
		});
	}

	/* ---------- donut (devices) ---------- */

	function donut() {
		var host = document.getElementById('dd-donut');
		var legend = document.getElementById('dd-donut-legend');
		if (!host) { return; }
		var data = D.devices || [];
		var total = data.reduce(function (a, d) { return a + d.n; }, 0);
		var size = 132, r = 50, sw = 16, c = 2 * Math.PI * r;
		var svg = el('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img' }, host);
		el('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: '#e9eef4', 'stroke-width': sw }, svg);

		if (!total) {
			legend.innerHTML = '<li class="dd-empty">هنوز داده‌ای وجود ندارد</li>';
			return;
		}
		var off = 0;
		var gap = data.length > 1 ? 3 : 0;
		legend.innerHTML = '';
		data.forEach(function (d) {
			var len = (d.n / total) * c;
			el('circle', {
				cx: size / 2, cy: size / 2, r: r, fill: 'none',
				stroke: DEVICE_COLORS[d.k] || '#9aa5b1', 'stroke-width': sw,
				'stroke-dasharray': Math.max(0, len - gap) + ' ' + (c - Math.max(0, len - gap)),
				'stroke-dashoffset': -off,
				transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')'
			}, svg);
			off += len;
			var li = document.createElement('li');
			li.innerHTML = '<i style="background:' + (DEVICE_COLORS[d.k] || '#9aa5b1') + '"></i><span>' + d.label + '</span><b>' + fa(Math.round(d.n / total * 100)) + '٪</b>';
			legend.appendChild(li);
		});
		var t1 = el('text', { x: size / 2, y: size / 2 + 2, 'text-anchor': 'middle', 'font-size': 20, 'font-weight': 700, fill: '#0e1a2b' }, svg);
		t1.textContent = num(total);
		var t2 = el('text', { x: size / 2, y: size / 2 + 20, 'text-anchor': 'middle', 'font-size': 11, fill: '#7b8a9c' }, svg);
		t2.textContent = T.sessions || '';
	}

	/* ---------- wiring ---------- */

	var chartHost = document.getElementById('dd-chart');
	var barsHost = document.getElementById('dd-bars');

	function drawAll() {
		if (chartHost) { lineChart(chartHost, D.series); }
		if (barsHost) { barChart(barsHost, D.series); }
	}

	function post(action, extra) {
		var f = new FormData();
		f.append('action', action);
		f.append('nonce', D.nonce);
		for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { f.append(k, extra[k]); } }
		return fetch(D.ajax, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	var btns = document.querySelectorAll('.dd-range button');
	Array.prototype.forEach.call(btns, function (b) {
		b.addEventListener('click', function () {
			Array.prototype.forEach.call(btns, function (x) { x.classList.remove('is-on'); });
			b.classList.add('is-on');
			post('didban_series', { days: b.getAttribute('data-days') }).then(function (res) {
				if (res && res.success) { D.series = res.data; drawAll(); }
			}).catch(function () {});
		});
	});

	var resizeT;
	window.addEventListener('resize', function () {
		clearTimeout(resizeT);
		resizeT = setTimeout(drawAll, 150);
	});

	function live() {
		if (document.hidden) { return; }
		post('didban_live', {}).then(function (res) {
			if (!res || !res.success) { return; }
			var a = document.getElementById('didban-online');
			var b = document.getElementById('didban-members');
			var f = document.getElementById('didban-feed');
			if (a) { a.textContent = res.data.online; }
			if (b) { b.textContent = res.data.members; }
			if (f) { f.innerHTML = res.data.html; }
		}).catch(function () {});
	}
	setInterval(live, 30000);

	drawAll();
	donut();
})();
