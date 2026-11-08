/**
 * Omni Webmaster & SEO Suite: Meta Pixel loader.
 *
 * Settings come from <script type="application/json" id="omni-meta-pixel-config">,
 * so the page prints no inline JavaScript. The bootstrap below is Meta's standard base code.
 */
(function () {
	var el = document.getElementById('omni-meta-pixel-config');
	var cfg;
	if (!el) {
		return;
	}
	try {
		cfg = JSON.parse(el.textContent);
	} catch (e) {
		return;
	}
	if (!cfg || !cfg.id) {
		return;
	}

	!function (f, b, e, v, n, t, s) {
		if (f.fbq) return; n = f.fbq = function () {
			n.callMethod ?
			n.callMethod.apply(n, arguments) : n.queue.push(arguments)
		};
		if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0';
		n.queue = []; t = b.createElement(e); t.async = !0;
		t.src = v; s = b.getElementsByTagName(e)[0];
		s.parentNode.insertBefore(t, s)
	}(window, document, 'script',
		'https://connect.facebook.net/en_US/fbevents.js');

	window.fbq('init', String(cfg.id));
	window.fbq('track', 'PageView');

	(cfg.events || []).forEach(function (ev) {
		if (ev && ev[0]) {
			window.fbq('track', ev[0], ev[1] || {});
		}
	});
}());
