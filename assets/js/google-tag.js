/**
 * Omni Webmaster & SEO Suite: Google tag (gtag.js) loader.
 *
 * Settings come from <script type="application/json" id="omni-google-tag-config">,
 * so the page prints no inline JavaScript. The commands below are Google's standard snippet.
 */
(function () {
	var el = document.getElementById('omni-google-tag-config');
	var cfg, t;
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

	window.dataLayer = window.dataLayer || [];
	// gtag.js only accepts the arguments object, not an array.
	window.gtag = window.gtag || function () {
		window.dataLayer.push(arguments);
	};

	t = document.createElement('script');
	t.async = true;
	t.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(String(cfg.id));
	document.head.appendChild(t);

	window.gtag('js', new Date());
	if (cfg.config && typeof cfg.config === 'object') {
		window.gtag('config', String(cfg.id), cfg.config);
	} else {
		window.gtag('config', String(cfg.id));
	}
}());
