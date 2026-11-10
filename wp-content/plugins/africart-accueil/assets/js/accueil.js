/* AFRICART – Page d'accueil : suggestions de recherche + index interactif. Vanilla JS, aucune dépendance. */
(function () {
	'use strict';
	var CFG = window.AFK_ACC || {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmt(tpl, v) { return String(tpl || '').replace('%s', esc(v)); }

	/* ---------------- Suggestions ---------------- */
	function initSearch(root) {
		var input = root.querySelector('.afk-acc-search-input');
		var list = root.querySelector('.afk-acc-sugg');
		var form = root.querySelector('form');
		if (!input || !list || !form || !CFG.suggest) { return; }

		var timer = null, ctrl = null, focusIdx = -1, lastQ = '';

		// Placeholder plus court sur petit écran, pour qu'il ne soit pas coupé.
		var phLong = input.getAttribute('placeholder') || '', phCourt = input.getAttribute('data-placeholder-court') || '';
		function setPlaceholder() {
			if (phCourt) { input.setAttribute('placeholder', window.matchMedia('(max-width: 767px)').matches ? phCourt : phLong); }
		}
		setPlaceholder();
		window.addEventListener('resize', setPlaceholder);

		function items() { return list.querySelectorAll('a'); }
		function open() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
		function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); focusIdx = -1; }
		function allUrl(q) {
			var u = new URL(form.action, window.location.href);
			u.searchParams.set('type', (form.querySelector('[name="type"]') || {}).value || 'oeuvre');
			u.searchParams.set('keywords', q);
			return u.toString();
		}
		function setFocus(i) {
			var els = items();
			if (!els.length) { return; }
			focusIdx = (i + els.length) % els.length;
			for (var k = 0; k < els.length; k++) {
				els[k].classList.toggle('is-focus', k === focusIdx);
				els[k].setAttribute('aria-selected', k === focusIdx ? 'true' : 'false');
			}
			els[focusIdx].scrollIntoView({ block: 'nearest' });
		}

		function render(q, data) {
			var h = '';
			(data.countries || []).forEach(function (c) {
				h += '<a role="option" class="afk-acc-sugg-item is-artist" href="' + esc(c.url) + '">' +
					'<span class="afk-acc-sugg-avatar is-country" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></span>' +
					'<span class="afk-acc-sugg-name">' + esc(c.name) + '</span>' +
					'<span class="afk-acc-sugg-meta">' + esc(CFG.country || 'pays') + ', ' + esc(c.count) + ' ' + (c.count > 1 ? 'œuvres' : 'œuvre') + '</span></a>';
			});
			(data.artists || []).forEach(function (a) {
				h += '<a role="option" class="afk-acc-sugg-item is-artist" href="' + esc(a.url) + '">' +
					'<span class="afk-acc-sugg-avatar" aria-hidden="true">' + esc((a.name || '?').charAt(0).toUpperCase()) + '</span>' +
					'<span class="afk-acc-sugg-name">' + esc(a.name) + '</span>' +
					'<span class="afk-acc-sugg-meta">' + esc(CFG.artist || 'artiste') + ', ' + esc(a.count) + ' ' + (a.count > 1 ? 'œuvres' : 'œuvre') + '</span></a>';
			});
			(data.works || []).forEach(function (w) {
				h += '<a role="option" class="afk-acc-sugg-item" href="' + esc(w.url) + '">' +
					(w.thumb ? '<img class="afk-acc-sugg-thumb" src="' + esc(w.thumb) + '" alt="" width="36" height="36">' : '<span class="afk-acc-sugg-thumb" aria-hidden="true"></span>') +
					'<span class="afk-acc-sugg-title">' + esc(w.title) + '</span>' +
					'<span class="afk-acc-sugg-meta">' + esc(w.artist) + '</span></a>';
			});
			if (!h) { h = '<div class="afk-acc-sugg-msg">' + fmt(CFG.none, q) + '</div>'; }
			h += '<a role="option" class="afk-acc-sugg-all" href="' + esc(allUrl(q)) + '">' + fmt(CFG.all, q) + '</a>';
			list.innerHTML = h;
			focusIdx = -1;
			open();
		}

		function fetchSugg(q) {
			if (!navigator.onLine) {
				list.innerHTML = '<div class="afk-acc-sugg-msg">' + esc(CFG.offline) + '</div>';
				open();
				return;
			}
			if (ctrl && ctrl.abort) { ctrl.abort(); }
			ctrl = window.AbortController ? new AbortController() : null;
			var url = CFG.suggest + (CFG.suggest.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q);
			fetch(url, { credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
				.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
				.then(function (data) { if (q === lastQ) { render(q, data); } })
				.catch(function (e) { if (e && e.name === 'AbortError') { return; } close(); });
		}

		input.addEventListener('input', function () {
			var q = input.value.trim();
			lastQ = q;
			clearTimeout(timer);
			if (q.length < 3) { close(); return; }
			timer = setTimeout(function () { fetchSugg(q); }, 180);
		});

		input.addEventListener('keydown', function (e) {
			if (list.hidden) { return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); setFocus(focusIdx + 1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); setFocus(focusIdx - 1); }
			else if (e.key === 'Escape') { close(); }
			else if (e.key === 'Enter' && focusIdx > -1) {
				var el = items()[focusIdx];
				if (el) { e.preventDefault(); window.location.href = el.href; }
			}
		});

		input.addEventListener('focus', function () { if (input.value.trim().length >= 3 && list.innerHTML) { open(); } });
		document.addEventListener('click', function (e) { if (!root.contains(e.target)) { close(); } });
		form.addEventListener('submit', function (e) {
			// Champ vide : on évite d'envoyer une recherche vide.
			if (!input.value.trim()) { e.preventDefault(); input.focus(); }
		});
	}

	/* ---------------- Index : l'image suit la ligne survolée ---------------- */
	function initIndex(root) {
		var rows = root.querySelectorAll('.afk-acc-row');
		var fig = root.querySelector('.afk-acc-index-preview');
		if (!rows.length || !fig) { return; }
		var img = fig.querySelector('img');
		var t = fig.querySelector('.afk-acc-preview-title');
		var a = fig.querySelector('.afk-acc-preview-artist');
		var preloaded = {};

		function activate(row) {
			if (row.classList.contains('is-active')) { return; }
			for (var i = 0; i < rows.length; i++) { rows[i].classList.toggle('is-active', rows[i] === row); }
			if (t) { t.textContent = row.getAttribute('data-title') || ''; }
			if (a) { a.textContent = row.getAttribute('data-artist') || ''; }
			if (!img) { return; }
			var src = row.getAttribute('data-src');
			if (!src) { return; }
			fig.classList.add('is-loading');
			var done = function () { fig.classList.remove('is-loading'); };
			img.onload = done; img.onerror = done;
			var ss = row.getAttribute('data-srcset');
			if (ss) { img.setAttribute('srcset', ss); } else { img.removeAttribute('srcset'); }
			img.src = src;
			if (img.complete) { done(); }
		}
		function preload(row) {
			var src = row.getAttribute('data-src');
			if (src && !preloaded[src]) { preloaded[src] = true; var i = new Image(); i.src = src; }
		}

		Array.prototype.forEach.call(rows, function (row) {
			row.addEventListener('mouseenter', function () { activate(row); });
			row.addEventListener('focus', function () { activate(row); });
			row.addEventListener('pointerenter', function () { preload(row); }, { once: true });
		});
	}

	/* Largeur de la barre de défilement, pour que la pleine largeur (100vw) ne crée pas de défilement horizontal. */
	function setScrollbar() {
		var sbw = window.innerWidth - document.documentElement.clientWidth;
		document.documentElement.style.setProperty('--afk-acc-sbw', (sbw > 0 ? sbw : 0) + 'px');
	}

	function boot() {
		setScrollbar();
		window.addEventListener('resize', setScrollbar);
		Array.prototype.forEach.call(document.querySelectorAll('[data-afk-search]'), initSearch);
		Array.prototype.forEach.call(document.querySelectorAll('[data-afk-index]'), initIndex);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
