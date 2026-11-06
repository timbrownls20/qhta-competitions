/**
 * qhta-competitions form: progressive enhancement, plain ES5, no build.
 * - show_if conditional fields (hidden fields are disabled so they are neither
 *   validated by the browser nor submitted)
 * - label_if relabelling
 * - category filtering by year level, and inline/upload fields by category kind
 * - live word counter for inline pieces
 * The server re-checks everything.
 */
(function () {
	'use strict';

	function each(list, fn) {
		for (var i = 0; i < list.length; i++) {
			fn(list[i], i);
		}
	}

	function countWords(text) {
		var parts = String(text).split(/[\s  -​　]+/);
		var n = 0;
		each(parts, function (p) {
			// ES5 has no \p{L}: Latin, Greek, Cyrillic and digits cover our users.
			if (/[A-Za-z0-9À-ɏͰ-ϿЀ-ӿ]/.test(p)) {
				n++;
			}
		});
		return n;
	}

	function fieldValue(form, key) {
		var els = form.querySelectorAll('[name="qc_f[' + key + ']"]');
		var val = '';
		each(els, function (el) {
			if (el.disabled) {
				return;
			}
			if (el.type === 'radio') {
				if (el.checked) {
					val = el.value;
				}
			} else if (el.type === 'checkbox') {
				val = el.checked ? el.value : '';
			} else {
				val = el.value;
			}
		});
		return val;
	}

	function setEnabled(wrapper, on) {
		wrapper.style.display = on ? '' : 'none';
		wrapper.setAttribute('aria-hidden', on ? 'false' : 'true');
		each(wrapper.querySelectorAll('input, select, textarea'), function (el) {
			el.disabled = !on;
		});
	}

	function init(form) {
		var cats = [];
		try {
			cats = JSON.parse(form.getAttribute('data-qc-categories') || '[]');
		} catch (e) {
			cats = [];
		}
		var catSelect = form.querySelector('[data-qc-category]');
		var yearSelect = form.querySelector('[data-qc-year]');

		function currentCategory() {
			var slug = catSelect ? catSelect.value : '';
			for (var i = 0; i < cats.length; i++) {
				if (cats[i].slug === slug) {
					return cats[i];
				}
			}
			return null;
		}

		function filterCategories() {
			if (!catSelect || catSelect.tagName !== 'SELECT' || !yearSelect) {
				return;
			}
			var year = yearSelect.value;
			each(catSelect.options, function (opt) {
				if (!opt.value) {
					return;
				}
				var years = opt.getAttribute('data-years');
				var ok = !year || !years || (',' + years + ',').indexOf(',' + year + ',') !== -1;
				opt.disabled = !ok;
				opt.hidden = !ok;
				if (!ok && opt.selected) {
					catSelect.value = '';
				}
			});
		}

		function updateCounters() {
			var cat = currentCategory();
			each(form.querySelectorAll('textarea[data-wordcount]'), function (ta) {
				var out = form.querySelector('[data-wordcount-for="' + ta.id + '"]');
				if (!out) {
					return;
				}
				var n = countWords(ta.value);
				var text = n + (n === 1 ? ' word' : ' words');
				var over = false;
				if (cat && cat.max) {
					text += ' of ' + cat.max + ' maximum';
					over = n > cat.max;
				}
				if (cat && cat.min && n < cat.min) {
					text += ' (minimum ' + cat.min + ')';
				}
				out.textContent = text;
				out.className = 'qc-wordcount' + (over ? ' qc-wordcount--over' : '');
			});
		}

		function update() {
			filterCategories();
			var cat = currentCategory();
			var kind = cat ? cat.kind : 'both';

			// Resolve show_if in document order so chains work.
			each(form.querySelectorAll('.qc-field[data-key]'), function (w) {
				var on = true;
				var k = w.getAttribute('data-kind');
				if (k === 'inline' && kind === 'upload') {
					on = false;
				}
				if (k === 'upload' && kind === 'inline') {
					on = false;
				}
				var dep = w.getAttribute('data-show-if-field');
				if (on && dep) {
					on = fieldValue(form, dep) === w.getAttribute('data-show-if-equals');
				}
				setEnabled(w, on);
			});

			each(form.querySelectorAll('[data-label-if-field]'), function (span) {
				var match = fieldValue(form, span.getAttribute('data-label-if-field')) === span.getAttribute('data-label-if-equals');
				span.textContent = span.getAttribute(match ? 'data-label-alt' : 'data-label-default');
			});

			updateCounters();
		}

		form.addEventListener('change', update);
		form.addEventListener('input', function (e) {
			if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-wordcount')) {
				updateCounters();
			}
		});
		update();

		var errors = form.parentNode.querySelector('.qc-errors');
		if (errors && errors.focus) {
			errors.focus();
		}
	}

	function boot() {
		each(document.querySelectorAll('.qc-form form'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
