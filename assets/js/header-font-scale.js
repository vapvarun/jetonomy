/**
 * Jetonomy — header font-scale toggle (BuddyNext A/A+/A++ pattern).
 *
 * Loaded only when BuddyNext has not booted (it provides its own).
 */
(function () {
	var scales = ['100', '110', '120'];
	function applyScale(s) {
		document.documentElement.setAttribute('data-bn-font-scale', s);
		try { localStorage.setItem('bn_font_scale', s); } catch (e) { /* noop */ }
		var btns = document.querySelectorAll('.jt-font-scale__btn');
		btns.forEach(function (b) { b.classList.toggle('active', b.dataset.scale === s); });
	}
	var saved = '100';
	try { saved = localStorage.getItem('bn_font_scale') || '100'; } catch (e) { /* noop */ }
	if (scales.indexOf(saved) === -1) { saved = '100'; }
	applyScale(saved);
	window.jtSetFontScale = function (s) { if (scales.indexOf(s) !== -1) { applyScale(s); } };
	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var btn = e.target.closest('.jt-font-scale__btn');
		if (btn && btn.dataset && btn.dataset.scale) {
			window.jtSetFontScale(btn.dataset.scale);
		}
	});
})();
