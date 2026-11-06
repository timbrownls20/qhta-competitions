/*
 * qhta-competitions — visual editor for a round's structured settings.
 *
 * Each section (categories, fields, declarations, eligibility, rubric, emails)
 * is stored as JSON in a textarea named qc_json[section]. This script draws a
 * point-and-click editor from that JSON and writes the JSON back on every
 * change, so the server-side save and validation are unchanged. The textarea
 * stays available under "Advanced: edit as JSON". Keys the editor doesn't
 * know about are kept as they are.
 *
 * Plain ES5, no build step. Needs jQuery and jQuery UI Sortable (bundled with
 * WordPress) for drag-to-reorder; the up/down buttons work without it.
 */
(function () {
	'use strict';

	var D = window.qcEditor || {};
	var T = D.i18n || {};
	var editors = {};
	var listeners = [];

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	function t(key) {
		return Object.prototype.hasOwnProperty.call(T, key) ? T[key] : key;
	}

	function tf(key) {
		var s = t(key);
		for (var i = 1; i < arguments.length; i++) {
			s = s.replace('%' + i, String(arguments[i]));
		}
		return s;
	}

	/**
	 * Make an element. attrs: plain attributes, plus `text`, `html` (never
	 * used with user content), `on` {event: fn}, `props` {name: value}.
	 */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		var k;
		attrs = attrs || {};
		for (k in attrs) {
			if (!Object.prototype.hasOwnProperty.call(attrs, k) || attrs[k] === null || attrs[k] === undefined || attrs[k] === false) {
				continue;
			}
			if (k === 'text') {
				node.textContent = attrs[k];
			} else if (k === 'on') {
				for (var ev in attrs.on) {
					if (Object.prototype.hasOwnProperty.call(attrs.on, ev)) {
						node.addEventListener(ev, attrs.on[ev]);
					}
				}
			} else if (k === 'props') {
				for (var p in attrs.props) {
					if (Object.prototype.hasOwnProperty.call(attrs.props, p)) {
						node[p] = attrs.props[p];
					}
				}
			} else if (attrs[k] === true) {
				node.setAttribute(k, '');
			} else {
				node.setAttribute(k, attrs[k]);
			}
		}
		appendAll(node, children);
		return node;
	}

	function appendAll(node, children) {
		if (children === null || children === undefined || children === false) {
			return;
		}
		if (!(children instanceof Array)) {
			children = [children];
		}
		for (var i = 0; i < children.length; i++) {
			var c = children[i];
			if (c === null || c === undefined || c === false) {
				continue;
			}
			if (c instanceof Array) {
				appendAll(node, c);
			} else {
				node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
			}
		}
	}

	var uid = 0;
	function nextId(prefix) {
		uid += 1;
		return 'qcv-' + prefix + '-' + uid;
	}

	function slugify(label, sep) {
		var s = String(label || '').toLowerCase()
			.replace(/[‘’']/g, '')
			.replace(/[^a-z0-9]+/g, sep)
			.replace(new RegExp('^\\' + sep + '+|\\' + sep + '+$', 'g'), '');
		return s.substring(0, 40).replace(new RegExp('\\' + sep + '+$'), '');
	}

	/** A key based on `label`, unique among `taken` (array of strings). */
	function uniqueKey(label, taken, sep, fallback) {
		var base = slugify(label, sep);
		if (!base || !/^[a-z0-9]/.test(base)) {
			base = fallback + (base ? sep + base : '');
		}
		var key = base;
		var n = 2;
		while (taken.indexOf(key) !== -1) {
			key = base + sep + n;
			n += 1;
		}
		return key;
	}

	function move(arr, from, to) {
		if (to < 0 || to >= arr.length) {
			return;
		}
		var item = arr.splice(from, 1)[0];
		arr.splice(to, 0, item);
	}

	/** Reorder in place, so objects holding the array (the rubric) keep it. */
	function replaceAll(arr, next) {
		Array.prototype.splice.apply(arr, [0, arr.length].concat(next));
	}

	function clone(v) {
		return JSON.parse(JSON.stringify(v));
	}

	function isObj(v) {
		return v !== null && typeof v === 'object' && !(v instanceof Array);
	}

	function optValue(o) {
		return isObj(o) ? String(o.value !== undefined ? o.value : (o.label !== undefined ? o.label : '')) : String(o);
	}

	function optLabel(o) {
		return isObj(o) ? String(o.label !== undefined ? o.label : optValue(o)) : String(o);
	}

	function emit() {
		for (var i = 0; i < listeners.length; i++) {
			listeners[i]();
		}
	}

	function onChange(fn) {
		listeners.push(fn);
	}

	/** Current form fields (from the fields editor, else the stored ones). */
	function currentFields() {
		if (editors.fields && editors.fields.data instanceof Array) {
			return editors.fields.data;
		}
		return D.fields instanceof Array ? D.fields : [];
	}

	function fieldTitle(f) {
		var label = f.label ? String(f.label) : '';
		if (!label && f.type === 'help') {
			label = String(f.help || '').replace(/<[^>]*>/g, '').substring(0, 60);
		}
		return label || f.key || '';
	}

	function select(options, value, attrs) {
		var s = el('select', attrs || {});
		var found = false;
		for (var i = 0; i < options.length; i++) {
			var o = options[i];
			var opt = el('option', { value: o[0], text: o[1] });
			if (String(o[0]) === String(value)) {
				opt.selected = true;
				found = true;
			}
			s.appendChild(opt);
		}
		if (!found && value !== undefined && value !== null && value !== '') {
			var missing = el('option', { value: value, text: tf('missing_option', value) });
			missing.selected = true;
			s.appendChild(missing);
		}
		return s;
	}

	function checkbox(label, checked, onToggle, attrs) {
		var input = el('input', { type: 'checkbox' });
		input.checked = !!checked;
		if (attrs && attrs.disabled) {
			input.disabled = true;
		}
		input.addEventListener('change', function () {
			onToggle(input.checked, input);
		});
		return el('label', { 'class': 'qcv-check' + (attrs && attrs.cls ? ' ' + attrs.cls : '') }, [input, ' ', label]);
	}

	function row(label, control, help, id) {
		var lab = id ? el('label', { 'for': id, text: label }) : el('span', { 'class': 'qcv-row__label', text: label });
		return el('div', { 'class': 'qcv-row' }, [
			el('div', { 'class': 'qcv-row__head' }, lab),
			el('div', { 'class': 'qcv-row__control' }, [control, help ? el('p', { 'class': 'description', text: help }) : null])
		]);
	}

	function iconButton(label, symbol, onClick, extra) {
		return el('button', {
			type: 'button',
			'class': 'button button-small qcv-icon' + (extra ? ' ' + extra : ''),
			title: label,
			'aria-label': label,
			text: symbol,
			on: { click: function (e) { e.preventDefault(); onClick(); } }
		});
	}

	/** Up / down / remove buttons for item i of a list editor. */
	function itemActions(ed, i, name) {
		return el('span', { 'class': 'qcv-actions' }, [
			iconButton(tf('move_up', name), '↑', function () { move(ed.data, i, i - 1); ed.changed(true); }, i === 0 ? 'qcv-hide' : ''),
			iconButton(tf('move_down', name), '↓', function () { move(ed.data, i, i + 1); ed.changed(true); }, i === ed.data.length - 1 ? 'qcv-hide' : ''),
			iconButton(tf('remove', name), '✕', function () { ed.remove(i); }, 'qcv-remove')
		]);
	}

	/** Drag-to-reorder with jQuery UI Sortable, when it's loaded. */
	function sortable(ed, list) {
		var $ = window.jQuery;
		if (!$ || !$.fn || !$.fn.sortable) {
			return;
		}
		$(list).sortable({
			handle: '.qcv-handle',
			items: '> .qcv-item',
			axis: 'y',
			tolerance: 'pointer',
			update: function () {
				var order = [];
				$(list).children('.qcv-item').each(function () {
					order.push(parseInt(this.getAttribute('data-index'), 10));
				});
				var next = [];
				for (var i = 0; i < order.length; i++) {
					next.push(ed.data[order[i]]);
				}
				replaceAll(ed.data, next);
				ed.changed(true);
			}
		});
	}

	function handle() {
		return el('span', { 'class': 'qcv-handle', title: t('drag'), 'aria-hidden': 'true', text: '☰' });
	}

	/** Insert text at the caret of an input/textarea and fire `input`. */
	function insertAtCaret(input, text) {
		var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
		var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : input.value.length;
		input.value = input.value.substring(0, start) + text + input.value.substring(end);
		input.focus();
		input.selectionStart = input.selectionEnd = start + text.length;
		input.dispatchEvent(new Event('input'));
	}

	/* ------------------------------------------------------------------
	 * Editor base: owns the JSON textarea for one section.
	 * ---------------------------------------------------------------- */

	function Editor(box, section, builder) {
		var self = this;
		this.box = box;
		this.section = section;
		this.builder = builder;
		this.textarea = box.querySelector('textarea.qc-json');
		this.ui = box.querySelector('.qcv-ui');
		this.advanced = box.querySelector('.qcv-advanced');
		this.isNew = [];
		this.open = [];
		this.undo = null;
		this.data = null;

		this.parse();
		this.textarea.addEventListener('input', function () {
			var before = self.data;
			self.parse();
			if (self.data !== null && before !== self.data) {
				self.render();
				emit();
			}
		});
		if (this.data === null && this.advanced) {
			this.advanced.open = true;
		}
		this.render();
	}

	Editor.prototype.parse = function () {
		try {
			var v = JSON.parse(this.textarea.value);
			this.data = this.builder.normalise ? this.builder.normalise(v) : v;
			this.error = '';
		} catch (e) {
			this.data = null;
			this.error = e.message;
		}
	};

	/** Write JSON back; re-render when the structure changed. */
	Editor.prototype.changed = function (rerender) {
		var out = this.builder.serialise ? this.builder.serialise(this) : this.data;
		this.textarea.value = JSON.stringify(out, null, 2);
		if (rerender) {
			this.render();
		}
		emit();
	};

	Editor.prototype.remove = function (i) {
		this.undo = { item: this.data[i], index: i };
		this.data.splice(i, 1);
		this.changed(true);
	};

	Editor.prototype.isOpen = function (item) {
		return this.open.indexOf(item) !== -1;
	};

	Editor.prototype.setOpen = function (item, open) {
		// One card open at a time keeps long forms readable.
		this.open = open ? [item] : this.open.filter(function (o) { return o !== item; });
	};

	Editor.prototype.render = function () {
		var self = this;
		this.ui.textContent = '';
		if (this.data === null) {
			this.ui.appendChild(el('div', { 'class': 'notice notice-warning inline' }, el('p', { text: tf('json_broken', this.error || '') })));
			return;
		}
		if (this.undo) {
			var undo = this.undo;
			this.ui.appendChild(el('p', { 'class': 'qcv-undo' }, [
				t('removed') + ' ',
				el('button', {
					type: 'button',
					'class': 'button-link',
					text: t('undo'),
					on: {
						click: function () {
							self.data.splice(Math.min(undo.index, self.data.length), 0, undo.item);
							self.undo = null;
							self.changed(true);
						}
					}
				})
			]));
			this.undo = null;
		}
		this.builder.render(this, this.ui);
	};

	/* ------------------------------------------------------------------
	 * Form fields
	 * ---------------------------------------------------------------- */

	var INPUT_TYPES_WITH_OPTIONS = ['select', 'radio', 'year_level'];
	var DISPLAY_ONLY = ['heading', 'help'];

	var Fields = {
		normalise: function (v) {
			return v instanceof Array ? v : null;
		},

		render: function (ed, ui) {
			var list = el('ol', { 'class': 'qcv-list' });
			for (var i = 0; i < ed.data.length; i++) {
				list.appendChild(Fields.item(ed, i));
			}
			ui.appendChild(list);
			if (!ed.data.length) {
				ui.appendChild(el('p', { 'class': 'qcv-empty', text: t('no_fields') }));
			}
			var types = D.fieldTypes || {};
			var addType = select(Object.keys(types).map(function (k) { return [k, types[k]]; }), 'text', { 'aria-label': t('new_field_type') });
			ui.appendChild(el('p', { 'class': 'qcv-add' }, [
				el('button', {
					type: 'button',
					'class': 'button',
					text: t('add_field'),
					on: {
						click: function () {
							var type = addType.value;
							var f = { key: '', type: type, label: '', required: DISPLAY_ONLY.indexOf(type) === -1, pii: false, judge_visible: true };
							if (INPUT_TYPES_WITH_OPTIONS.indexOf(type) !== -1) {
								f.options = type === 'year_level' ? ['7', '8', '9', '10', '11', '12'] : [];
							}
							f.key = uniqueKey(types[type] || type, Fields.keys(ed), '_', 'field');
							ed.data.push(f);
							ed.isNew.push(f);
							ed.setOpen(f, true);
							ed.changed(true);
							var last = ui.querySelectorAll('.qcv-item');
							last = last[last.length - 1];
							if (last) {
								var input = last.querySelector('.qcv-label-input');
								if (input) {
									input.focus();
								}
							}
						}
					}
				}),
				' ', addType
			]));
			sortable(ed, list);
		},

		keys: function (ed, except) {
			var keys = [];
			for (var i = 0; i < ed.data.length; i++) {
				if (ed.data[i] !== except) {
					keys.push(String(ed.data[i].key || ''));
				}
			}
			return keys;
		},

		summary: function (f) {
			var tags = [];
			if (f.required && DISPLAY_ONLY.indexOf(f.type) === -1) {
				tags.push(el('span', { 'class': 'qcv-tag', text: t('tag_required') }));
			}
			if (f.pii) {
				tags.push(el('span', { 'class': 'qcv-tag qcv-tag--pii', text: t('tag_personal') }));
			} else if (f.judge_visible === false && DISPLAY_ONLY.indexOf(f.type) === -1) {
				tags.push(el('span', { 'class': 'qcv-tag', text: t('tag_hidden_judges') }));
			}
			if (f.show_if) {
				tags.push(el('span', { 'class': 'qcv-tag qcv-tag--cond', text: t('tag_conditional') }));
			}
			return tags;
		},

		item: function (ed, i) {
			var f = ed.data[i];
			var types = D.fieldTypes || {};
			var open = ed.isOpen(f);
			var bodyId = nextId('field');
			var title = el('span', { 'class': 'qcv-item__title', text: fieldTitle(f) || t('untitled') });
			var tags = el('span', { 'class': 'qcv-tags' }, Fields.summary(f));
			var head = el('div', { 'class': 'qcv-item__head' + (f.type === 'heading' ? ' qcv-item__head--heading' : '') }, [
				handle(),
				el('button', {
					type: 'button',
					'class': 'qcv-item__toggle',
					'aria-expanded': open ? 'true' : 'false',
					'aria-controls': bodyId,
					on: {
						click: function () {
							ed.setOpen(f, !ed.isOpen(f));
							ed.render();
						}
					}
				}, [el('span', { 'class': 'qcv-item__type', text: types[f.type] || f.type }), title]),
				tags,
				itemActions(ed, i, fieldTitle(f) || t('field'))
			]);
			var li = el('li', { 'class': 'qcv-item' + (open ? ' is-open' : ''), 'data-index': i }, head);
			if (open) {
				li.appendChild(Fields.body(ed, f, bodyId, function () {
					title.textContent = fieldTitle(f) || t('untitled');
					tags.textContent = '';
					appendAll(tags, Fields.summary(f));
				}));
			}
			return li;
		},

		body: function (ed, f, bodyId, refreshHead) {
			var types = D.fieldTypes || {};
			var isNew = ed.isNew.indexOf(f) !== -1;
			var displayOnly = DISPLAY_ONLY.indexOf(f.type) !== -1;
			var body = el('div', { 'class': 'qcv-item__body', id: bodyId });
			var keyNote = el('code', { text: f.key });

			function set(prop, value) {
				if (value === null || value === undefined || value === '') {
					delete f[prop];
				} else {
					f[prop] = value;
				}
				refreshHead();
				ed.changed(false);
			}

			// Type.
			var typeSel = select(Object.keys(types).map(function (k) { return [k, types[k]]; }), f.type, { id: nextId('type') });
			typeSel.addEventListener('change', function () {
				f.type = typeSel.value;
				if (INPUT_TYPES_WITH_OPTIONS.indexOf(f.type) !== -1 && !(f.options instanceof Array && f.options.length)) {
					f.options = f.type === 'year_level' ? ['7', '8', '9', '10', '11', '12'] : [];
				}
				if (DISPLAY_ONLY.indexOf(f.type) !== -1) {
					f.required = false;
				}
				ed.changed(true);
			});
			body.appendChild(row(t('type'), typeSel, D.typeHelp && D.typeHelp[f.type] ? D.typeHelp[f.type] : '', typeSel.id));

			// Label / heading text.
			if (f.type !== 'help') {
				var labelId = nextId('label');
				var label = el('input', { type: 'text', 'class': 'large-text qcv-label-input', id: labelId, value: f.label || '' });
				label.addEventListener('input', function () {
					f.label = label.value;
					if (isNew) {
						f.key = uniqueKey(label.value, Fields.keys(ed, f), '_', 'field');
						keyNote.textContent = f.key;
					}
					refreshHead();
					ed.changed(false);
				});
				body.appendChild(row(f.type === 'heading' ? t('heading_text') : t('question'), label, '', labelId));
			}

			// Help text.
			var helpId = nextId('help');
			var help = el('textarea', { 'class': 'large-text', rows: f.type === 'help' ? 4 : 2, id: helpId });
			help.value = f.help || '';
			help.addEventListener('input', function () { set('help', help.value); });
			if (f.type === 'help') {
				body.appendChild(row(t('info_text'), help, t('info_text_help'), helpId));
			} else if (f.type !== 'heading') {
				body.appendChild(row(t('help_text'), help, t('help_text_help'), helpId));
			}

			// Options.
			if (INPUT_TYPES_WITH_OPTIONS.indexOf(f.type) !== -1) {
				var optId = nextId('opts');
				var opts = el('textarea', { 'class': 'regular-text', rows: 5, id: optId });
				opts.value = (f.options || []).map(optLabel).join('\n');
				opts.addEventListener('input', function () {
					var before = f.options || [];
					var lines = opts.value.split('\n');
					var next = [];
					for (var i = 0; i < lines.length; i++) {
						var line = lines[i].trim();
						if (!line) {
							continue;
						}
						var kept = null;
						for (var j = 0; j < before.length; j++) {
							if (optLabel(before[j]) === line) {
								kept = before[j];
								break;
							}
						}
						next.push(kept !== null ? kept : line);
					}
					f.options = next;
					ed.changed(false);
				});
				body.appendChild(row(f.type === 'year_level' ? t('year_levels') : t('choices'), opts, t('choices_help'), optId));
			}

			if (!displayOnly) {
				// Required.
				body.appendChild(row(t('answer'), el('div', {}, [
					checkbox(t('required'), f.required, function (on) { f.required = on; refreshHead(); ed.changed(false); })
				])));

				// Privacy.
				var judgeBox;
				var judgeWrap = el('span');
				function drawJudge() {
					judgeWrap.textContent = '';
					judgeBox = checkbox(t('judges_see'), !f.pii && f.judge_visible !== false, function (on) {
						f.judge_visible = on;
						refreshHead();
						ed.changed(false);
					}, { disabled: !!f.pii });
					judgeWrap.appendChild(judgeBox);
				}
				drawJudge();
				body.appendChild(row(t('privacy'), el('div', {}, [
					checkbox(t('personal'), f.pii, function (on) {
						f.pii = on;
						f.judge_visible = !on;
						drawJudge();
						refreshHead();
						ed.changed(false);
					}),
					el('br'),
					judgeWrap
				]), t('personal_help')));

				// Role and prefill.
				var roles = D.roles || {};
				if (['text', 'email'].indexOf(f.type) !== -1) {
					var roleSel = select([['', t('role_none')]].concat(Object.keys(roles).map(function (k) { return [k, roles[k]]; })), f.role || '', { id: nextId('role') });
					roleSel.addEventListener('change', function () { set('role', roleSel.value); });
					body.appendChild(row(t('role'), roleSel, t('role_help'), roleSel.id));
				}
				var prefills = D.prefills || {};
				if (['text', 'email', 'tel', 'address'].indexOf(f.type) !== -1) {
					var preSel = select([['', t('prefill_none')]].concat(Object.keys(prefills).map(function (k) { return [k, prefills[k]]; })), f.prefill || '', { id: nextId('prefill') });
					preSel.addEventListener('change', function () { set('prefill', preSel.value); });
					body.appendChild(row(t('prefill'), preSel, t('prefill_help'), preSel.id));
				}
				if (f.type === 'text') {
					body.appendChild(row(t('admin_check'), checkbox(t('admin_verify'), f.admin_verify, function (on) { set('admin_verify', on ? true : null); })));
				}
			}

			// Conditions.
			body.appendChild(Fields.condition(ed, f, 'show_if', refreshHead));
			if (!displayOnly) {
				body.appendChild(Fields.condition(ed, f, 'label_if', refreshHead));
			}

			// Key.
			body.appendChild(el('p', { 'class': 'qcv-key description' }, [
				t('key') + ' ', keyNote, ' — ',
				isNew ? t('key_new') : t('key_fixed')
			]));
			return body;
		},

		/** "Show only when…" / "Change the wording when…". */
		condition: function (ed, f, prop, refreshHead) {
			var wrap = el('div', { 'class': 'qcv-cond' });
			var others = ed.data.filter(function (o) {
				return o !== f && DISPLAY_ONLY.indexOf(o.type) === -1 && o.key;
			});

			function draw() {
				wrap.textContent = '';
				var c = isObj(f[prop]) ? f[prop] : null;
				var modeSel = select([
					['', prop === 'show_if' ? t('show_always') : t('label_always')],
					['when', prop === 'show_if' ? t('show_when') : t('label_when')]
				], c ? 'when' : '', { id: nextId('cond') });
				modeSel.addEventListener('change', function () {
					if (modeSel.value === 'when') {
						var first = others[0];
						f[prop] = { field: first ? first.key : '', equals: first ? Fields.firstValue(first) : '' };
						if (prop === 'label_if') {
							f[prop].label = f.label || '';
						}
					} else {
						f[prop] = null;
					}
					draw();
					refreshHead();
					ed.changed(false);
				});
				var parts = [modeSel];
				if (c) {
					var fieldSel = select(others.map(function (o) { return [o.key, fieldTitle(o)]; }), c.field, { 'aria-label': t('cond_field') });
					fieldSel.addEventListener('change', function () {
						var target = Fields.byKey(ed, fieldSel.value);
						c.field = fieldSel.value;
						c.equals = target ? Fields.firstValue(target) : '';
						draw();
						ed.changed(false);
					});
					parts.push(' ', fieldSel, ' ', Fields.valueControl(ed, c, function () { ed.changed(false); }));
					if (prop === 'label_if') {
						var alt = el('input', { type: 'text', 'class': 'large-text', value: c.label || '', 'aria-label': t('label_alt') });
						alt.addEventListener('input', function () { c.label = alt.value; ed.changed(false); });
						parts.push(el('div', { 'class': 'qcv-cond__alt' }, [el('span', { text: t('label_alt') }), alt]));
					}
				}
				wrap.appendChild(row(prop === 'show_if' ? t('show_question') : t('wording'), el('div', {}, parts), '', modeSel.id));
			}
			draw();
			return wrap;
		},

		byKey: function (ed, key) {
			for (var i = 0; i < ed.data.length; i++) {
				if (ed.data[i].key === key) {
					return ed.data[i];
				}
			}
			return null;
		},

		firstValue: function (f) {
			if (f.type === 'checkbox') {
				return '1';
			}
			if (f.options && f.options.length) {
				return optValue(f.options[0]);
			}
			return '';
		},

		/** The "is …" part of a condition, matched to the controlling field. */
		valueControl: function (ed, c, changed) {
			var target = Fields.byKey(ed, c.field);
			if (target && target.type === 'checkbox') {
				c.equals = '1';
				return el('span', { 'class': 'qcv-cond__is', text: t('is_ticked') });
			}
			if (target && target.options && target.options.length) {
				var sel = select(target.options.map(function (o) { return [optValue(o), optLabel(o)]; }), String(c.equals), { 'aria-label': t('cond_value') });
				sel.addEventListener('change', function () { c.equals = sel.value; changed(); });
				return el('span', {}, [el('span', { 'class': 'qcv-cond__is', text: t('is') + ' ' }), sel]);
			}
			var input = el('input', { type: 'text', value: c.equals === undefined ? '' : String(c.equals), 'aria-label': t('cond_value') });
			input.addEventListener('input', function () { c.equals = input.value; changed(); });
			return el('span', {}, [el('span', { 'class': 'qcv-cond__is', text: t('is_exactly') + ' ' }), input]);
		}
	};

	/* ------------------------------------------------------------------
	 * Categories
	 * ---------------------------------------------------------------- */

	var Categories = {
		normalise: function (v) {
			return v instanceof Array ? v : null;
		},

		yearOptions: function () {
			var fields = currentFields();
			for (var i = 0; i < fields.length; i++) {
				if (fields[i].type === 'year_level' && fields[i].options && fields[i].options.length) {
					return fields[i].options.map(optValue);
				}
			}
			return null;
		},

		render: function (ed, ui) {
			var kinds = D.submissionKinds || {};
			var years = Categories.yearOptions();
			var table = el('table', { 'class': 'widefat qcv-table' });
			table.appendChild(el('thead', {}, el('tr', {}, [
				el('th', { 'class': 'qcv-col-handle' }),
				el('th', { text: t('cat_name') }),
				el('th', { text: t('cat_entry') }),
				el('th', { text: t('cat_words') }),
				el('th', { text: t('cat_years') }),
				el('th', { 'class': 'qcv-col-actions' })
			])));
			var tbody = el('tbody');
			for (var i = 0; i < ed.data.length; i++) {
				tbody.appendChild(Categories.row(ed, i, kinds, years));
			}
			table.appendChild(tbody);
			ui.appendChild(table);
			if (!ed.data.length) {
				ui.appendChild(el('p', { 'class': 'qcv-empty', text: t('no_categories') }));
			}
			ui.appendChild(el('p', { 'class': 'description', text: years ? t('cat_years_help') : t('cat_years_help_text') }));
			ui.appendChild(el('p', { 'class': 'qcv-add' }, el('button', {
				type: 'button',
				'class': 'button',
				text: t('add_category'),
				on: {
					click: function () {
						var c = { slug: uniqueKey('category', Categories.slugs(ed), '-', 'category'), label: '', year_levels: [], word_min: null, word_max: null, submission_kind: 'upload' };
						ed.data.push(c);
						ed.isNew.push(c);
						ed.changed(true);
						var inputs = ui.querySelectorAll('.qcv-label-input');
						if (inputs.length) {
							inputs[inputs.length - 1].focus();
						}
					}
				}
			})));
			Categories.sortableRows(ed, tbody);
		},

		sortableRows: function (ed, tbody) {
			var $ = window.jQuery;
			if (!$ || !$.fn || !$.fn.sortable) {
				return;
			}
			$(tbody).sortable({
				handle: '.qcv-handle',
				items: '> tr',
				axis: 'y',
				update: function () {
					var next = [];
					$(tbody).children('tr').each(function () {
						next.push(ed.data[parseInt(this.getAttribute('data-index'), 10)]);
					});
					replaceAll(ed.data, next);
					ed.changed(true);
				}
			});
		},

		slugs: function (ed, except) {
			return ed.data.filter(function (c) { return c !== except; }).map(function (c) { return String(c.slug || ''); });
		},

		row: function (ed, i, kinds, years) {
			var c = ed.data[i];
			var isNew = ed.isNew.indexOf(c) !== -1;
			var name = el('input', { type: 'text', 'class': 'regular-text qcv-label-input', value: c.label || '', 'aria-label': t('cat_name') });
			name.addEventListener('input', function () {
				c.label = name.value;
				if (isNew) {
					c.slug = uniqueKey(name.value, Categories.slugs(ed, c), '-', 'category');
				}
				ed.changed(false);
			});
			var kind = select(Object.keys(kinds).map(function (k) { return [k, kinds[k]]; }), c.submission_kind || 'upload', { 'aria-label': t('cat_entry') });
			kind.addEventListener('change', function () { c.submission_kind = kind.value; ed.changed(false); });

			function num(prop, label) {
				var input = el('input', { type: 'number', min: prop === 'word_max' ? 1 : 0, step: 1, 'class': 'small-text', value: c[prop] === null || c[prop] === undefined ? '' : c[prop], 'aria-label': label, placeholder: t('any') });
				input.addEventListener('input', function () {
					c[prop] = input.value === '' ? null : parseInt(input.value, 10);
					ed.changed(false);
				});
				return input;
			}

			var yearCell;
			var chosen = (c.year_levels || []).map(String);
			if (years) {
				yearCell = el('span', { 'class': 'qcv-years' }, years.map(function (y) {
					return checkbox(y, chosen.indexOf(y) !== -1, function (on) {
						var list = (c.year_levels || []).map(String).filter(function (v) { return v !== y; });
						if (on) {
							list.push(y);
						}
						c.year_levels = years.filter(function (v) { return list.indexOf(v) !== -1; });
						ed.changed(false);
					}, { cls: 'qcv-check--inline' });
				}));
			} else {
				yearCell = el('input', { type: 'text', 'class': 'small-text qcv-years-text', value: chosen.join(', '), 'aria-label': t('cat_years'), placeholder: t('any') });
				yearCell.addEventListener('input', function () {
					c.year_levels = yearCell.value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s; });
					ed.changed(false);
				});
			}

			return el('tr', { 'class': 'qcv-item', 'data-index': i }, [
				el('td', { 'class': 'qcv-col-handle' }, handle()),
				el('td', { 'class': 'qcv-col-name' }, name),
				el('td', {}, kind),
				el('td', { 'class': 'qcv-words' }, [num('word_min', t('min_words')), ' – ', num('word_max', t('max_words'))]),
				el('td', {}, yearCell),
				el('td', { 'class': 'qcv-col-actions' }, itemActions(ed, i, c.label || t('category')))
			]);
		}
	};

	/* ------------------------------------------------------------------
	 * Declarations
	 * ---------------------------------------------------------------- */

	var Declarations = {
		normalise: function (v) {
			return v instanceof Array ? v : null;
		},

		render: function (ed, ui) {
			var list = el('ol', { 'class': 'qcv-list qcv-list--plain' });
			for (var i = 0; i < ed.data.length; i++) {
				list.appendChild(Declarations.item(ed, i));
			}
			ui.appendChild(list);
			if (!ed.data.length) {
				ui.appendChild(el('p', { 'class': 'qcv-empty', text: t('no_declarations') }));
			}
			ui.appendChild(el('p', { 'class': 'qcv-add' }, el('button', {
				type: 'button',
				'class': 'button',
				text: t('add_declaration'),
				on: {
					click: function () {
						var d = { key: uniqueKey('declaration', Declarations.keys(ed), '_', 'declaration'), text: '', required: true };
						ed.data.push(d);
						ed.isNew.push(d);
						ed.changed(true);
						var boxes = ui.querySelectorAll('textarea');
						if (boxes.length) {
							boxes[boxes.length - 1].focus();
						}
					}
				}
			})));
			sortable(ed, list);
		},

		keys: function (ed, except) {
			return ed.data.filter(function (d) { return d !== except; }).map(function (d) { return String(d.key || ''); });
		},

		item: function (ed, i) {
			var d = ed.data[i];
			var isNew = ed.isNew.indexOf(d) !== -1;
			var text = el('textarea', { 'class': 'large-text', rows: 2, 'aria-label': tf('declaration_n', i + 1) });
			text.value = d.text || '';
			text.addEventListener('input', function () {
				d.text = text.value;
				if (isNew) {
					d.key = uniqueKey(text.value.split(/\s+/).slice(0, 5).join(' '), Declarations.keys(ed, d), '_', 'declaration');
				}
				ed.changed(false);
			});
			return el('li', { 'class': 'qcv-item qcv-decl', 'data-index': i }, [
				el('div', { 'class': 'qcv-decl__main' }, [
					handle(),
					el('div', { 'class': 'qcv-decl__text' }, [
						text,
						el('div', { 'class': 'qcv-decl__opts' }, [
							checkbox(t('decl_required'), d.required !== false, function (on) { d.required = on; ed.changed(false); }, { cls: 'qcv-check--inline' }),
							checkbox(t('decl_consent'), d.kind === 'publication_consent', function (on) {
								if (on) {
									d.kind = 'publication_consent';
								} else {
									delete d.kind;
								}
								ed.changed(false);
							}, { cls: 'qcv-check--inline' })
						])
					]),
					itemActions(ed, i, tf('declaration_n', i + 1))
				])
			]);
		}
	};

	/* ------------------------------------------------------------------
	 * Rubric
	 * ---------------------------------------------------------------- */

	var Rubric = {
		normalise: function (v) {
			if (!isObj(v)) {
				return null;
			}
			if (!(v.criteria instanceof Array)) {
				v.criteria = [];
			}
			return v;
		},

		serialise: function (ed) {
			return ed.rubric;
		},

		render: function (ed, ui) {
			// The list being edited is the criteria array; keep the wrapper object.
			if (ed.rubric !== ed.data) {
				if (isObj(ed.data)) {
					ed.rubric = ed.data;
				}
				ed.data = ed.rubric.criteria;
			}
			var crit = ed.data;
			var table = el('table', { 'class': 'widefat qcv-table' });
			table.appendChild(el('thead', {}, el('tr', {}, [
				el('th', { 'class': 'qcv-col-handle' }),
				el('th', { text: t('criterion') }),
				el('th', { text: t('max_score') }),
				el('th', { text: t('weight') }),
				el('th', { 'class': 'qcv-col-actions' })
			])));
			var tbody = el('tbody');
			for (var i = 0; i < crit.length; i++) {
				tbody.appendChild(Rubric.row(ed, i));
			}
			table.appendChild(tbody);
			ui.appendChild(table);
			if (!crit.length) {
				ui.appendChild(el('p', { 'class': 'qcv-empty', text: t('no_criteria') }));
			}
			ui.appendChild(el('p', { 'class': 'description', text: t('rubric_help') }));
			ui.appendChild(el('p', { 'class': 'qcv-add' }, el('button', {
				type: 'button',
				'class': 'button',
				text: t('add_criterion'),
				on: {
					click: function () {
						var c = { key: uniqueKey('criterion', Rubric.keys(ed), '_', 'criterion'), label: '', max: 10, weight: 1 };
						ed.data.push(c);
						ed.isNew.push(c);
						ed.changed(true);
						var inputs = ui.querySelectorAll('.qcv-label-input');
						if (inputs.length) {
							inputs[inputs.length - 1].focus();
						}
					}
				}
			})));
			Categories.sortableRows(ed, tbody);
		},

		keys: function (ed, except) {
			return ed.data.filter(function (c) { return c !== except; }).map(function (c) { return String(c.key || ''); });
		},

		row: function (ed, i) {
			var c = ed.data[i];
			var isNew = ed.isNew.indexOf(c) !== -1;
			var label = el('input', { type: 'text', 'class': 'large-text qcv-label-input', value: c.label || '', 'aria-label': t('criterion') });
			label.addEventListener('input', function () {
				c.label = label.value;
				if (isNew) {
					c.key = uniqueKey(label.value, Rubric.keys(ed, c), '_', 'criterion');
				}
				ed.changed(false);
			});
			function num(prop, min, step, aria) {
				var input = el('input', { type: 'number', min: min, step: step, 'class': 'small-text', value: c[prop] === undefined ? '' : c[prop], 'aria-label': aria });
				input.addEventListener('input', function () {
					c[prop] = input.value === '' ? '' : parseFloat(input.value);
					ed.changed(false);
				});
				return input;
			}
			return el('tr', { 'class': 'qcv-item', 'data-index': i }, [
				el('td', { 'class': 'qcv-col-handle' }, handle()),
				el('td', {}, label),
				el('td', {}, num('max', 1, 1, t('max_score'))),
				el('td', {}, num('weight', 0, 0.5, t('weight'))),
				el('td', { 'class': 'qcv-col-actions' }, itemActions(ed, i, c.label || t('criterion')))
			]);
		}
	};

	/* ------------------------------------------------------------------
	 * Eligibility
	 * ---------------------------------------------------------------- */

	var MANAGED_RULES = ['required_true', 'qhta_membership', 'confirmer_differs'];

	var Eligibility = {
		normalise: function (v) {
			return v instanceof Array ? v : null;
		},

		serialise: function (ed) {
			return ed.data;
		},

		find: function (ed, rule) {
			for (var i = 0; i < ed.data.length; i++) {
				if (isObj(ed.data[i]) && ed.data[i].rule === rule) {
					return ed.data[i];
				}
			}
			return null;
		},

		modeSelect: function (r, ed, aria) {
			var modes = D.modes || {};
			var sel = select(Object.keys(modes).map(function (k) { return [k, modes[k]]; }), r.mode || D.defaultModes[r.rule] || 'block', { 'aria-label': aria });
			sel.addEventListener('change', function () { r.mode = sel.value; ed.changed(false); });
			return sel;
		},

		render: function (ed, ui) {
			var fields = currentFields();
			var ticks = fields.filter(function (f) { return f.type === 'checkbox' && f.key; });
			var choices = fields.filter(function (f) { return (f.type === 'radio' || f.type === 'select') && f.key; });

			ui.appendChild(el('p', { 'class': 'description', text: t('elig_always') }));

			// Must-tick questions.
			var must = ed.data.filter(function (r) { return isObj(r) && r.rule === 'required_true'; });
			var section = el('div', { 'class': 'qcv-elig' }, el('h4', { text: t('elig_must_tick') }));
			if (!must.length) {
				section.appendChild(el('p', { 'class': 'qcv-empty', text: t('elig_none') }));
			}
			must.forEach(function (r) {
				var fieldSel = select(ticks.map(function (f) { return [f.key, fieldTitle(f)]; }), r.field || '', { 'aria-label': t('elig_tick_field') });
				fieldSel.addEventListener('change', function () { r.field = fieldSel.value; ed.changed(false); });
				var msg = el('input', { type: 'text', 'class': 'large-text', value: r.message || '', placeholder: t('elig_message_default'), 'aria-label': t('elig_message') });
				msg.addEventListener('input', function () {
					if (msg.value) {
						r.message = msg.value;
					} else {
						delete r.message;
					}
					ed.changed(false);
				});
				section.appendChild(el('div', { 'class': 'qcv-elig__rule' }, [
					el('div', {}, [el('span', { text: t('elig_if_not_ticked_1') + ' ' }), fieldSel, el('span', { text: ' ' + t('elig_if_not_ticked_2') + ' ' }), Eligibility.modeSelect(r, ed, t('elig_mode')),
						' ', iconButton(t('elig_remove'), '✕', function () { ed.remove(ed.data.indexOf(r)); }, 'qcv-remove')]),
					el('div', { 'class': 'qcv-elig__msg' }, [el('span', { text: t('elig_message') }), msg])
				]));
			});
			if (ticks.length) {
				section.appendChild(el('p', {}, el('button', {
					type: 'button',
					'class': 'button',
					text: t('elig_add_tick'),
					on: {
						click: function () {
							ed.data.push({ rule: 'required_true', field: ticks[0].key, mode: 'block' });
							ed.changed(true);
						}
					}
				})));
			} else {
				section.appendChild(el('p', { 'class': 'description', text: t('elig_no_ticks') }));
			}
			ui.appendChild(section);

			// Membership.
			var mem = Eligibility.find(ed, 'qhta_membership');
			var memBox = el('div', { 'class': 'qcv-elig' }, el('h4', { text: t('elig_membership') }));
			memBox.appendChild(checkbox(t('elig_membership_on'), !!mem, function (on) {
				if (on) {
					ed.data.push({ rule: 'qhta_membership', mode: 'flag' });
				} else {
					ed.data.splice(ed.data.indexOf(mem), 1);
				}
				ed.changed(true);
			}));
			if (mem) {
				var memField = select([['', t('elig_membership_no_q')]].concat(choices.map(function (f) { return [f.key, fieldTitle(f)]; })), mem.field || '', { 'aria-label': t('elig_membership_q') });
				memField.addEventListener('change', function () {
					if (memField.value) {
						mem.field = memField.value;
					} else {
						delete mem.field;
					}
					ed.changed(false);
				});
				memBox.appendChild(el('div', { 'class': 'qcv-elig__rule' }, [
					el('div', {}, [el('span', { text: t('elig_membership_q') + ' ' }), memField]),
					el('div', {}, [el('span', { text: t('elig_membership_mode') + ' ' }), Eligibility.modeSelect(mem, ed, t('elig_mode'))]),
					el('p', { 'class': 'description', text: t('elig_membership_help') })
				]));
			}
			ui.appendChild(memBox);

			// Referee must be someone else.
			var diff = Eligibility.find(ed, 'confirmer_differs');
			var diffBox = el('div', { 'class': 'qcv-elig' }, el('h4', { text: t('elig_referee') }));
			diffBox.appendChild(checkbox(t('elig_referee_on'), !!diff, function (on) {
				if (on) {
					ed.data.push({ rule: 'confirmer_differs', mode: 'flag' });
				} else {
					ed.data.splice(ed.data.indexOf(diff), 1);
				}
				ed.changed(true);
			}));
			if (diff) {
				diffBox.appendChild(el('div', { 'class': 'qcv-elig__rule' }, [
					el('span', { text: t('elig_referee_mode') + ' ' }), Eligibility.modeSelect(diff, ed, t('elig_mode'))
				]));
			}
			ui.appendChild(diffBox);

			// Anything else in the list (always-on rules, unknown rules) is kept untouched.
			var other = ed.data.filter(function (r) { return !isObj(r) || MANAGED_RULES.indexOf(r.rule) === -1; });
			if (other.length) {
				ui.appendChild(el('p', { 'class': 'description qcv-key', text: tf('elig_other', other.length) }));
			}
		}
	};

	/* ------------------------------------------------------------------
	 * Emails
	 * ---------------------------------------------------------------- */

	var Emails = {
		normalise: function (v) {
			if (v instanceof Array && !v.length) {
				return {};
			}
			return isObj(v) ? v : null;
		},

		render: function (ed, ui) {
			var events = D.events || {};
			ed.stash = ed.stash || {};
			ui.appendChild(el('p', { 'class': 'description', text: t('emails_help') }));
			Object.keys(events).forEach(function (event) {
				var info = events[event];
				var tpl = isObj(ed.data[event]) ? ed.data[event] : null;
				var open = ed.isOpen(event);
				var bodyId = nextId('email');
				var card = el('div', { 'class': 'qcv-item qcv-email' + (open ? ' is-open' : '') + (tpl ? '' : ' is-off') });
				var head = el('div', { 'class': 'qcv-item__head' }, [
					el('button', {
						type: 'button',
						'class': 'qcv-item__toggle',
						'aria-expanded': open ? 'true' : 'false',
						'aria-controls': bodyId,
						on: { click: function () { ed.setOpen(event, !ed.isOpen(event)); ed.render(); } }
					}, [el('span', { 'class': 'qcv-item__type', text: info.to }), el('span', { 'class': 'qcv-item__title', text: info.label })]),
					el('span', { 'class': 'qcv-tags' }, el('span', { 'class': 'qcv-tag' + (tpl ? ' qcv-tag--on' : ''), text: tpl ? t('email_on') : t('email_off') }))
				]);
				card.appendChild(head);
				if (open) {
					card.appendChild(Emails.body(ed, event, info, tpl, bodyId));
				}
				ui.appendChild(card);
			});
		},

		body: function (ed, event, info, tpl, bodyId) {
			var body = el('div', { 'class': 'qcv-item__body', id: bodyId });
			if (info.note) {
				body.appendChild(el('p', { 'class': 'description', text: info.note }));
			}
			body.appendChild(checkbox(t('email_send'), !!tpl, function (on) {
				if (on) {
					ed.data[event] = ed.stash[event] || { subject: info.defaultSubject || '', body: '' };
				} else {
					ed.stash[event] = ed.data[event];
					delete ed.data[event];
				}
				ed.setOpen(event, true);
				ed.changed(true);
			}));
			if (!tpl) {
				return body;
			}
			var last = null;
			var subjId = nextId('subj');
			var subject = el('input', { type: 'text', 'class': 'large-text', id: subjId, value: tpl.subject || '' });
			subject.addEventListener('input', function () { tpl.subject = subject.value; ed.changed(false); });
			subject.addEventListener('focus', function () { last = subject; });
			var textId = nextId('body');
			var text = el('textarea', { 'class': 'large-text', rows: 10, id: textId });
			text.value = tpl.body || '';
			text.addEventListener('input', function () { tpl.body = text.value; ed.changed(false); });
			text.addEventListener('focus', function () { last = text; });
			body.appendChild(row(t('email_subject'), subject, '', subjId));
			body.appendChild(row(t('email_body'), text, t('email_body_help'), textId));

			var tokens = (D.tokens || []).filter(function (tok) {
				return !tok.events || tok.events.indexOf(event) !== -1;
			}).map(function (tok) { return [tok.token, tok.label]; });
			currentFields().forEach(function (f) {
				if (f.key && DISPLAY_ONLY.indexOf(f.type) === -1 && f.type !== 'file') {
					tokens.push([f.key, fieldTitle(f)]);
				}
			});
			var buttons = el('div', { 'class': 'qcv-tokens' }, el('span', { 'class': 'qcv-tokens__label', text: t('insert') }));
			tokens.forEach(function (tok) {
				buttons.appendChild(el('button', {
					type: 'button',
					'class': 'button button-small',
					title: '{{' + tok[0] + '}}',
					text: tok[1],
					on: {
						mousedown: function (e) { e.preventDefault(); },
						click: function () { insertAtCaret(last || text, '{{' + tok[0] + '}}'); }
					}
				}));
			});
			body.appendChild(row('', buttons));
			return body;
		}
	};

	/* ------------------------------------------------------------------
	 * Confirmer box: field pickers follow the form fields.
	 * ---------------------------------------------------------------- */

	function confirmerSync() {
		var box = document.getElementById('qc_confirmer');
		if (!box) {
			return;
		}
		var role = box.querySelector('select[name="qc_confirmer[role]"]');
		var pickers = [
			{ sel: box.querySelector('select[name="qc_confirmer[email_field]"]'), types: ['email'] },
			{ sel: box.querySelector('select[name="qc_confirmer[name_field]"]'), types: ['text'] }
		];
		var fields = currentFields();
		pickers.forEach(function (p) {
			if (!p.sel) {
				return;
			}
			var current = p.sel.value;
			p.sel.textContent = '';
			p.sel.appendChild(el('option', { value: '', text: t('choose_field') }));
			var found = current === '';
			fields.forEach(function (f) {
				if (f.key && p.types.indexOf(f.type) !== -1) {
					var o = el('option', { value: f.key, text: fieldTitle(f) });
					if (f.key === current) {
						o.selected = true;
						found = true;
					}
					p.sel.appendChild(o);
				}
			});
			if (!found) {
				var miss = el('option', { value: current, text: tf('missing_option', current) });
				miss.selected = true;
				p.sel.appendChild(miss);
			}
		});
		var rows = box.querySelectorAll('.qc-conf-detail');
		for (var i = 0; i < rows.length; i++) {
			rows[i].style.display = role && role.value === 'none' ? 'none' : '';
		}
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	var BUILDERS = {
		categories: Categories,
		fields: Fields,
		declarations: Declarations,
		eligibility: Eligibility,
		rubric: Rubric,
		emails: Emails
	};

	function boot() {
		var boxes = document.querySelectorAll('.qcv[data-section]');
		var i;
		// Fields first: other editors read the field list.
		var order = ['fields', 'categories', 'declarations', 'eligibility', 'rubric', 'emails'];
		order.forEach(function (section) {
			for (i = 0; i < boxes.length; i++) {
				if (boxes[i].getAttribute('data-section') === section && BUILDERS[section]) {
					boxes[i].className += ' qcv--ready';
					editors[section] = new Editor(boxes[i], section, BUILDERS[section]);
				}
			}
		});
		// Re-draw the editors that depend on the form fields when the fields change.
		var redrawing = false;
		onChange(function () {
			if (redrawing) {
				return;
			}
			redrawing = true;
			['categories', 'eligibility', 'emails'].forEach(function (s) {
				var ed = editors[s];
				if (ed && ed.data !== null && !ed.ui.contains(document.activeElement)) {
					ed.render();
				}
			});
			confirmerSync();
			redrawing = false;
		});
		var role = document.querySelector('#qc_confirmer select[name="qc_confirmer[role]"]');
		if (role) {
			role.addEventListener('change', confirmerSync);
		}
		confirmerSync();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Exposed for tests only.
	window.qcVisualEditors = editors;
	window.qcVisualClone = clone;
}());
