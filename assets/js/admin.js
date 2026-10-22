(function () {
	'use strict';

	const i18n = wp.i18n;
	const __ = (s) => i18n.__(s, 'sombrilla-bm');
	const sprintf = i18n.sprintf;
	const D = window.SBM;
	const root = document.getElementById('sbm-app');
	if (!root) return;

	const state = { backups: D.backups || [], settings: D.settings, nextRun: D.nextRun, free: D.free, running: false };

	/* ---------- helpers ---------- */
	const ICONS = {
		umbrella: '<path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/>',
		shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
		upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
		trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
		restore: '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
		check: '<polyline points="20 6 9 17 4 12"/>',
		alert: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
		x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		globe: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		folder: '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
	};

	function icon(name) {
		const t = document.createElement('template');
		t.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
		return t.content.firstChild;
	}

	function h(tag, attrs, ...kids) {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(attrs || {})) {
			if (v == null || v === false) continue;
			if (k === 'class') e.className = v;
			else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		}
		kids.flat().forEach((c) => {
			if (c == null || c === false) return;
			e.append(c.nodeType ? c : document.createTextNode(String(c)));
		});
		return e;
	}

	const $ = (sel, ctx) => (ctx || root).querySelector(sel);
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
	const normUrl = (u) => (u || '').replace(/\/+$/, '').toLowerCase();

	function fmtBytes(n) {
		n = Number(n) || 0;
		const u = ['B', 'KB', 'MB', 'GB', 'TB'];
		let i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (i ? n.toFixed(n >= 100 ? 0 : 1) : n) + ' ' + u[i];
	}
	function fmtDate(ts) {
		return new Date(ts * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
	}
	function fmtDur(s) {
		s = Math.max(0, Math.round(s));
		const hh = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60), ss = s % 60;
		if (hh) return hh + 'h ' + mm + 'm';
		if (mm) return mm + 'm ' + String(ss).padStart(2, '0') + 's';
		return ss + 's';
	}
	function randomHex(n) {
		const a = new Uint8Array(n);
		crypto.getRandomValues(a);
		return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
	}

	async function api(action, data = {}) {
		const fd = new FormData();
		fd.append('action', 'sombrilla_bm_' + action);
		fd.append('nonce', D.nonce);
		Object.entries(data).forEach(([k, v]) => {
			if (v == null) return;
			if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x));
			else fd.append(k, v === true ? '1' : v === false ? '0' : v);
		});
		let res, text;
		try {
			res = await fetch(D.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
			text = await res.text();
		} catch (e) {
			const err = new Error(__('Error de conexión con el servidor.'));
			err.network = true;
			throw err;
		}
		let json;
		try { json = JSON.parse(text); } catch (e) {
			const err = new Error(sprintf(__('Respuesta inesperada del servidor (HTTP %d).'), res.status));
			err.network = true;
			throw err;
		}
		if (!json.success) {
			const err = new Error((json.data && json.data.message) || __('Error desconocido.'));
			err.data = json.data || {};
			err.status = res.status;
			throw err;
		}
		return json.data;
	}

	function toast(msg, kind) {
		const t = h('div', { class: 'sbm-toast sbm-toast--' + (kind || 'ok') }, icon(kind === 'err' ? 'alert' : 'check'), h('span', null, msg));
		$('#sbm-toasts').append(t);
		setTimeout(() => t.remove(), 4500);
	}

	/* ---------- modal ---------- */
	function modal({ title, icon: ic = 'shield', tone = '', body, actions = [], wide = false, dismissable = true }) {
		const iconEl = h('span', { class: 'sbm-modal__icon' }, icon(ic));
		const titleEl = h('h3', null, title);
		const head = h('div', { class: 'sbm-modal__head' }, iconEl, titleEl);
		const bodyEl = h('div', { class: 'sbm-modal__body' }, body);
		const foot = h('div', { class: 'sbm-modal__foot' });
		const box = h('div', { class: 'sbm-modal' + (wide ? ' sbm-modal--wide' : '') }, head, bodyEl, foot);
		const overlay = h('div', { class: 'sbm-overlay', role: 'dialog', 'aria-modal': 'true' }, box);
		const onKey = (e) => { if (e.key === 'Escape' && dismissable) m.close(); };
		const m = {
			el: overlay, body: bodyEl,
			close() { overlay.remove(); document.removeEventListener('keydown', onKey); },
			setTitle(t) { titleEl.textContent = t; },
			setTone(t, name) {
				iconEl.className = 'sbm-modal__icon' + (t ? ' sbm-modal__icon--' + t : '');
				if (name) iconEl.replaceChildren(icon(name));
			},
			setBody(node) { bodyEl.replaceChildren(node); },
			setActions(list) {
				foot.replaceChildren();
				return list.map((a) => {
					const b = h('button', { type: 'button', class: 'sbm-btn' + (a.kind ? ' sbm-btn--' + a.kind : ''), disabled: a.disabled }, a.icon ? icon(a.icon) : null, a.label);
					b.addEventListener('click', () => a.onClick && a.onClick(m, b));
					foot.append(b);
					return b;
				});
			},
		};
		m.setTone(tone);
		m.setActions(actions);
		if (dismissable) {
			overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) m.close(); });
			document.addEventListener('keydown', onKey);
		}
		root.append(overlay);
		return m;
	}

	/* ---------- progress panel ---------- */
	function openPanel({ title, phases = {}, ic = 'clock' }) {
		const stepsEl = h('div', { class: 'sbm-steps' });
		const pct = h('div', { class: 'sbm-progress__pct' }, '0%');
		const msg = h('div', { class: 'sbm-progress__msg' });
		const fill = h('div', { class: 'sbm-bar__fill' });
		const bar = h('div', { class: 'sbm-bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 }, fill);
		const detail = h('span');
		const speed = h('span');
		const logEl = h('div', { class: 'sbm-log', hidden: true });
		const extra = h('div');
		const toggle = h('button', { type: 'button', class: 'sbm-log-toggle' }, __('Ver detalles'));
		toggle.addEventListener('click', () => {
			logEl.hidden = !logEl.hidden;
			toggle.textContent = logEl.hidden ? __('Ver detalles') : __('Ocultar detalles');
			logEl.scrollTop = logEl.scrollHeight;
		});
		const body = h('div', { class: 'sbm-stack' },
			stepsEl,
			h('div', { class: 'sbm-progress' },
				h('div', { class: 'sbm-progress__top' }, pct, msg),
				bar,
				h('div', { class: 'sbm-progress__info' }, detail, speed)),
			extra, toggle, logEl);
		const m = modal({ title, icon: ic, body, wide: true, dismissable: false });

		let lastSeq = 0, stepsKey = '', samples = [];

		const P = {
			modal: m,
			close: () => m.close(),
			log(text, level) {
				const d = new Date();
				const atBottom = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 8;
				logEl.append(h('div', { class: level ? 'is-' + level : '' }, h('time', null, d.toLocaleTimeString()), text));
				if (atBottom) logEl.scrollTop = logEl.scrollHeight;
			},
			update(job) {
				const ph = job.phases && Object.keys(job.phases).length ? job.phases : phases;
				const keys = Object.keys(ph);
				const cur = job.status === 'done' ? keys.length : keys.indexOf(job.phase);
				const key = JSON.stringify([keys, cur]);
				if (key !== stepsKey) {
					stepsKey = key;
					stepsEl.replaceChildren(...keys.map((k, i) => h('div', { class: 'sbm-step' + (i < cur ? ' is-done' : i === cur ? ' is-active' : '') }, ph[k])));
				}
				const indet = !!job.indeterminate;
				bar.classList.toggle('is-indeterminate', indet);
				fill.style.width = job.percent + '%';
				bar.setAttribute('aria-valuenow', Math.floor(job.percent));
				pct.textContent = indet ? '…' : Math.floor(job.percent) + '%';
				msg.textContent = job.message || '';
				detail.textContent = job.detail || '';

				speed.textContent = '';
				if (job.unit === 'bytes' && job.status === 'running') {
					const now = Date.now();
					samples.push([now, job.done]);
					samples = samples.filter((s) => now - s[0] <= 12000);
					const a = samples[0], b = samples[samples.length - 1];
					if (b[0] - a[0] >= 1500 && b[1] > a[1]) {
						const bps = ((b[1] - a[1]) / (b[0] - a[0])) * 1000;
						let txt = fmtBytes(bps) + '/s';
						if (job.total > job.done) txt += ' · ' + sprintf(__('quedan ~%s'), fmtDur((job.total - job.done) / bps));
						speed.textContent = txt;
					}
				}
				(job.log || []).forEach((l) => {
					if (l.i > lastSeq) { lastSeq = l.i; P.log(l.m, l.l); }
				});
			},
			setCancel(fn, confirmText) {
				m.setActions([{
					label: __('Cancelar'),
					onClick: async (mm, btn) => {
						if (confirmText && !window.confirm(confirmText)) return;
						btn.disabled = true;
						btn.textContent = __('Cancelando…');
						await fn();
					},
				}]);
			},
			finish({ tone, title: t, message, icon: ic2, actions }) {
				bar.classList.remove('is-indeterminate');
				bar.classList.add(tone === 'ok' ? 'is-ok' : 'is-err');
				if (tone === 'ok') { fill.style.width = '100%'; pct.textContent = '100%'; }
				m.setTone(tone, ic2 || (tone === 'ok' ? 'check' : 'alert'));
				if (t) m.setTitle(t);
				msg.textContent = '';
				speed.textContent = '';
				if (message) extra.replaceChildren(message.nodeType ? message : h('div', { class: 'sbm-callout' + (tone === 'ok' ? '' : ' sbm-callout--danger') }, message));
				m.setActions(actions || [{ label: __('Cerrar'), kind: 'primary', onClick: () => m.close() }]);
			},
			stalled(text) {
				return new Promise((resolve) => {
					extra.replaceChildren(h('div', { class: 'sbm-callout sbm-callout--warn' }, h('strong', null, __('Se perdió la conexión con el servidor.')), text));
					m.setActions([
						{ label: __('Cancelar'), onClick: () => { extra.replaceChildren(); resolve(false); } },
						{ label: __('Reintentar'), kind: 'primary', onClick: () => { extra.replaceChildren(); resolve(true); } },
					]);
				});
			},
		};
		return P;
	}

	/* ---------- job runner ---------- */
	const TITLES = {
		backup: () => __('Creando backup'),
		restore: () => __('Restaurando sitio'),
		download: () => __('Descargando backup'),
	};

	async function runJob(job, opts = {}) {
		const token = job.token;
		const id = job.id;
		let cur = job;
		let since = job.seq || 0;
		const panel = openPanel({ title: opts.title || TITLES[job.type](), phases: job.phases });
		panel.update({ ...cur, log: cur.log || [] });
		since = Math.max(since, cur.seq || 0);
		state.running = true;
		const beforeUnload = (e) => { e.preventDefault(); e.returnValue = ''; };
		window.addEventListener('beforeunload', beforeUnload);

		let inflight = false;
		const poll = setInterval(async () => {
			if (!inflight) return;
			try {
				const r = await api('status', { id, token, since });
				const j = r.job;
				if (inflight && j.status === 'running' && j.percent >= cur.percent) {
					panel.update(j);
					since = Math.max(since, j.seq);
					cur = { ...cur, percent: j.percent, done: j.done };
				}
			} catch (e) { /* el paso en curso informará el error */ }
		}, 1500);

		panel.setCancel(async () => {
			try { await api('cancel', { id, token }); } catch (e) { /* se verá en el siguiente paso */ }
		}, job.type === 'restore' ? __('Cancelar una restauración en curso puede dejar el sitio en un estado inconsistente. ¿Cancelar de todos modos?') : null);

		let failures = 0;
		while (cur.status === 'running') {
			inflight = true;
			try {
				const r = await api('step', { id, token, since });
				failures = 0;
				cur = r.job;
				since = Math.max(since, cur.seq);
				panel.update(cur);
				if (r.busy) await sleep(1500);
			} catch (e) {
				if (!e.network && e.status) {
					cur = { ...cur, status: 'error', error: e.message };
					panel.log(e.message, 'error');
					break;
				}
				failures++;
				if (failures >= 5) {
					const retry = await panel.stalled(e.message);
					if (!retry) { try { await api('cancel', { id, token }); } catch (e2) { /* noop */ } cur = { ...cur, status: 'cancelled' }; break; }
					failures = 0;
				} else {
					await sleep(1000 * failures);
				}
			} finally {
				inflight = false;
			}
		}
		clearInterval(poll);
		window.removeEventListener('beforeunload', beforeUnload);
		state.running = false;

		if (cur.status === 'done') {
			if (opts.autoClose) panel.close();
		} else if (cur.status === 'cancelled') {
			panel.close();
		} else {
			panel.finish({ tone: 'err', title: __('La operación falló'), message: cur.error || __('Error desconocido.') });
		}
		return { job: cur, panel };
	}

	async function startJob(type, params, opts) {
		try {
			const r = await api('start', { type, ...params });
			return await runJob(r.job, opts);
		} catch (e) {
			if (e.data && e.data.active) {
				toast(__('Hay una operación en curso; se retoma su progreso.'), 'err');
				return runJob(e.data.active);
			}
			toast(e.message, 'err');
			return null;
		}
	}

	/* ---------- backups ---------- */
	async function refreshBackups() {
		try {
			state.backups = (await api('list')).backups;
			renderList();
			renderStats();
		} catch (e) { toast(e.message, 'err'); }
	}

	const SCOPE_LABELS = () => ({
		db: __('Base de datos'),
		uploads: __('Uploads'),
		plugins: __('Plugins'),
		themes: __('Themes'),
		other: __('Otros (mu-plugins, idiomas…)'),
	});
	const LABELS = () => ({ manual: __('Manual'), scheduled: __('Programado'), 'pre-restore': __('Seguridad previa') });

	function scopeChips(selected, name) {
		const labels = SCOPE_LABELS();
		return Object.keys(labels).map((k) => h('label', { class: 'sbm-chip' },
			h('input', { type: 'checkbox', name: name || 'scope', value: k, checked: selected.includes(k) }), labels[k]));
	}

	function createBackupFlow() {
		const chips = h('div', { class: 'sbm-checks' }, scopeChips(Object.keys(SCOPE_LABELS())));
		const note = h('input', { class: 'sbm-input', type: 'text', maxlength: 200, placeholder: __('Nota opcional (ej. antes de actualizar el tema)') });
		const body = h('div', { class: 'sbm-stack' },
			h('p', { class: 'sbm-muted' }, __('Elige qué incluir en el backup. Se guarda en el servidor y podrás descargarlo o restaurarlo cuando quieras.')),
			chips, note);
		modal({
			title: __('Crear backup'), icon: 'shield', body,
			actions: [
				{ label: __('Cancelar'), onClick: (m) => m.close() },
				{
					label: __('Iniciar backup'), kind: 'primary', icon: 'shield',
					onClick: async (m) => {
						const scope = [...chips.querySelectorAll('input:checked')].map((i) => i.value);
						if (!scope.length) return toast(__('Selecciona al menos un elemento.'), 'err');
						m.close();
						const res = await startJob('backup', { scope, note: note.value });
						if (res && res.job.status === 'done') {
							const file = res.job.result.file;
							res.panel.finish({
								tone: 'ok', title: __('Backup completado'),
								message: sprintf(__('%1$s · %2$s'), file, fmtBytes(res.job.result.size)),
								actions: [
									{ label: __('Cerrar'), onClick: (mm) => mm.close() },
									{ label: __('Descargar'), kind: 'primary', icon: 'download', onClick: () => { window.location = downloadUrl(file); } },
								],
							});
							refreshBackups();
						}
					},
				},
			],
		});
	}

	function downloadUrl(file) {
		const b = state.backups.find((x) => x.file === file);
		if (b && b.link) return b.link;
		return D.ajaxUrl + '?action=sombrilla_bm_download&nonce=' + encodeURIComponent(D.nonce) + '&file=' + encodeURIComponent(file);
	}

	async function copyLink(file) {
		const url = downloadUrl(file);
		try {
			await navigator.clipboard.writeText(url);
		} catch (e) {
			const ta = h('textarea', { style: 'position:fixed;opacity:0' });
			ta.value = url;
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			ta.remove();
		}
		toast(__('Enlace copiado al portapapeles.'), 'ok');
	}

	function summaryNode(b) {
		const rows = [
			[__('Sitio'), (b.site_name ? b.site_name + ' · ' : '') + b.home_url],
			[__('Creado'), fmtDate(b.created)],
			[__('Tamaño'), fmtBytes(b.size)],
			[__('WordPress / PHP'), b.wp_version + ' / ' + b.php_version],
			[__('Contenido'), [b.has_db ? sprintf(__('%d tablas'), b.tables) : null, b.files ? sprintf(__('%s archivos'), b.files.toLocaleString()) : null].filter(Boolean).join(' · ') || '—'],
		];
		const dl = h('dl', { class: 'sbm-dl' });
		rows.forEach(([k, v]) => dl.append(h('dt', null, k), h('dd', null, v)));
		return dl;
	}

	function restoreFlow(file, summary) {
		const b = summary || state.backups.find((x) => x.file === file);
		if (!b) return toast(__('Backup no encontrado.'), 'err');

		const changed = b.has_db && b.home_url && normUrl(b.home_url) !== normUrl(D.homeUrl);
		const chkDb = h('input', { type: 'checkbox', checked: b.has_db, disabled: !b.has_db });
		const chkFiles = h('input', { type: 'checkbox', checked: true });
		const chkSafe = h('input', { type: 'checkbox', checked: true });
		const chkOk = h('input', { type: 'checkbox' });

		const body = h('div', { class: 'sbm-stack' },
			summaryNode(b),
			changed
				? h('div', { class: 'sbm-callout sbm-callout--info' },
					h('strong', null, __('Cambio de dominio detectado')),
					h('span', null, __('Al terminar se reemplazarán todas las URLs automáticamente:')),
					h('span', null, h('code', null, b.home_url), ' → ', h('code', null, D.homeUrl)))
				: null,
			h('div', { class: 'sbm-checks' },
				h('label', { class: 'sbm-chip' }, chkDb, __('Base de datos')),
				h('label', { class: 'sbm-chip' }, chkFiles, __('Archivos de wp-content'))),
			h('label', { class: 'sbm-confirm' }, chkSafe, h('span', null, __('Crear un backup de seguridad antes de restaurar (recomendado)'))),
			h('div', { class: 'sbm-callout sbm-callout--danger' },
				h('strong', null, __('Esta acción sobrescribe el sitio actual')),
				h('span', null, __('La base de datos y los archivos de wp-content serán reemplazados por los del backup. Tu sesión actual podría cerrarse al terminar.')),
				h('label', { class: 'sbm-confirm' }, chkOk, h('span', null, __('Entiendo y quiero continuar')))));

		const m = modal({
			title: __('Restaurar backup'), icon: 'restore', tone: 'warn', body, wide: true,
			actions: [
				{ label: __('Cancelar'), onClick: (mm) => mm.close() },
				{ label: __('Restaurar ahora'), kind: 'danger', icon: 'restore', disabled: true, onClick: (mm) => { mm.close(); doRestore(); } },
			],
		});
		const go = m.el.querySelector('.sbm-btn--danger');
		chkOk.addEventListener('change', () => { go.disabled = !chkOk.checked; });

		async function doRestore() {
			const wantDb = chkDb.checked, wantFiles = chkFiles.checked;
			if (!wantDb && !wantFiles) return toast(__('Selecciona qué restaurar.'), 'err');
			if (chkSafe.checked) {
				const safety = await startJob('backup', { scope: ['db', 'uploads', 'plugins', 'themes', 'other'], label: 'pre-restore', note: __('Backup de seguridad antes de restaurar') }, { title: __('Backup de seguridad previo'), autoClose: true });
				if (!safety || safety.job.status !== 'done') return;
			}
			const res = await startJob('restore', { file, restore_db: wantDb, restore_files: wantFiles });
			if (res && res.job.status === 'done') {
				const r = res.job.result || {};
				const msg = h('div', { class: 'sbm-stack' },
					r.url_changed ? h('div', { class: 'sbm-callout sbm-callout--info' }, h('strong', null, __('Dominio actualizado')), h('span', null, h('code', null, r.from), ' → ', h('code', null, r.to))) : null,
					h('div', { class: 'sbm-callout' }, h('span', null, __('Para ver los cambios inicia sesión de nuevo: el backup incluye sus propios usuarios y contraseñas.'))));
				res.panel.finish({
					tone: 'ok', title: __('Restauración completada'), message: msg,
					actions: [{ label: __('Iniciar sesión'), kind: 'primary', icon: 'check', onClick: () => { window.location = r.login_url || D.homeUrl; } }],
				});
			}
		}
	}

	function deleteFlow(b) {
		modal({
			title: __('Eliminar backup'), icon: 'trash', tone: 'err',
			body: h('p', null, sprintf(__('Se eliminará %s de forma permanente.'), b.file)),
			actions: [
				{ label: __('Cancelar'), onClick: (m) => m.close() },
				{
					label: __('Eliminar'), kind: 'danger', icon: 'trash',
					onClick: async (m) => {
						try { state.backups = (await api('delete', { file: b.file })).backups; renderList(); renderStats(); toast(__('Backup eliminado.')); } catch (e) { toast(e.message, 'err'); }
						m.close();
					},
				},
			],
		});
	}

	function renderList() {
		const list = $('#sbm-backup-list');
		if (!state.backups.length) {
			list.replaceChildren(h('div', { class: 'sbm-empty' }, icon('folder'), h('div', null, __('Aún no hay backups. Crea el primero o súbelo desde la pestaña Restaurar.'))));
			return;
		}
		list.replaceChildren(...state.backups.map((b) => {
			const host = b.home_url ? b.home_url.replace(/^https?:\/\//, '') : b.file;
			return h('div', { class: 'sbm-row' },
				h('div', null,
					h('div', { class: 'sbm-row__title' }, host,
						h('span', { class: 'sbm-badge sbm-badge--' + b.label }, LABELS()[b.label] || b.label),
						b.has_db ? h('span', { class: 'sbm-badge' }, 'DB') : null),
					h('div', { class: 'sbm-row__meta' },
						h('span', null, fmtDate(b.created)), h('span', null, fmtBytes(b.size)),
						h('span', null, 'WP ' + b.wp_version), b.note ? h('span', null, b.note) : null,
						h('span', null, b.file))),
				h('div', { class: 'sbm-row__actions' },
					h('button', { type: 'button', class: 'sbm-btn sbm-btn--primary', onclick: () => restoreFlow(b.file) }, icon('restore'), __('Restaurar')),
					h('button', { type: 'button', class: 'sbm-btn sbm-btn--icon', title: __('Copiar enlace de descarga'), 'aria-label': __('Copiar enlace de descarga'), onclick: () => copyLink(b.file) }, icon('link')),
					h('a', { class: 'sbm-btn sbm-btn--icon', href: downloadUrl(b.file), title: __('Descargar'), 'aria-label': __('Descargar') }, icon('download')),
					h('button', { type: 'button', class: 'sbm-btn sbm-btn--icon', title: __('Eliminar'), 'aria-label': __('Eliminar'), onclick: () => deleteFlow(b) }, icon('trash'))));
		}));
	}

	function renderStats() {
		const total = state.backups.reduce((a, b) => a + b.size, 0);
		const last = state.backups[0];
		const stat = (v, l) => h('div', { class: 'sbm-stat' }, h('b', null, v), h('span', null, l));
		$('#sbm-stats').replaceChildren(
			stat(String(state.backups.length), __('Backups')),
			stat(fmtBytes(total), __('Espacio usado')),
			stat(last ? fmtDate(last.created) : '—', __('Último backup')),
			stat(state.free != null ? fmtBytes(state.free) : '—', __('Disco libre')));
	}

	/* ---------- upload / url ---------- */
	function sendChunk(uid, file, offset, end, onProgress, holder) {
		return new Promise((resolve, reject) => {
			const xhr = new XMLHttpRequest();
			holder.xhr = xhr;
			const fd = new FormData();
			fd.append('action', 'sombrilla_bm_upload');
			fd.append('nonce', D.nonce);
			fd.append('uid', uid);
			fd.append('offset', offset);
			fd.append('total', file.size);
			fd.append('name', file.name);
			fd.append('chunk', file.slice(offset, end), 'chunk.bin');
			xhr.open('POST', D.ajaxUrl);
			xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded); };
			xhr.onload = () => {
				let j;
				try { j = JSON.parse(xhr.responseText); } catch (e) {
					const err = new Error(sprintf(__('Respuesta inesperada del servidor (HTTP %d).'), xhr.status));
					err.network = true;
					return reject(err);
				}
				if (j.success) return resolve(j.data);
				const err = new Error((j.data && j.data.message) || __('Error al subir.'));
				err.data = j.data || {};
				reject(err);
			};
			xhr.onerror = () => { const err = new Error(__('Error de conexión con el servidor.')); err.network = true; reject(err); };
			xhr.onabort = () => { const err = new Error('aborted'); err.aborted = true; reject(err); };
			xhr.send(fd);
		});
	}

	async function uploadFlow(file) {
		if (!/\.sombrilla$/i.test(file.name)) return toast(__('El archivo debe tener extensión .sombrilla.'), 'err');
		if (!file.size) return toast(__('El archivo está vacío.'), 'err');
		if (state.running) return toast(__('Hay una operación en curso.'), 'err');

		const phases = { upload: __('Subir'), verify: __('Verificar') };
		const uid = randomHex(16);
		const panel = openPanel({ title: __('Subiendo backup'), phases, ic: 'upload' });
		const holder = {};
		let aborted = false;
		state.running = true;
		const beforeUnload = (e) => { e.preventDefault(); e.returnValue = ''; };
		window.addEventListener('beforeunload', beforeUnload);
		panel.setCancel(async () => {
			aborted = true;
			if (holder.xhr) holder.xhr.abort();
		});

		const render = (loaded, extra) => panel.update({
			status: 'running', phase: 'upload', phases, percent: Math.min(98, (loaded / file.size) * 98),
			message: file.name, detail: fmtBytes(loaded) + ' / ' + fmtBytes(file.size), unit: 'bytes', done: loaded, total: file.size, ...extra,
		});

		let offset = 0, result = null, error = null, attempts = 0;
		render(0);
		panel.log(sprintf(__('Subiendo %1$s (%2$s)'), file.name, fmtBytes(file.size)));
		try {
			while (!result && !aborted) {
				const end = Math.min(offset + D.chunk, file.size);
				try {
					const r = await sendChunk(uid, file, offset, end, (l) => render(offset + l), holder);
					attempts = 0;
					offset = r.size;
					if (r.done) result = r;
					else render(offset);
					if (end >= file.size && !r.done) throw new Error(__('El servidor no confirmó el archivo completo.'));
				} catch (e) {
					if (aborted || e.aborted) break;
					if (e.data && e.data.resync) { offset = e.data.size; continue; }
					if (!e.network || ++attempts > 4) throw e;
					await sleep(1000 * attempts);
					try { offset = (await api('upload', { uid, probe: true })).size; } catch (e2) { /* se reintenta */ }
				}
			}
		} catch (e) { error = e; }
		window.removeEventListener('beforeunload', beforeUnload);
		state.running = false;

		if (aborted) {
			try { await api('upload', { uid, abort: true }); } catch (e) { /* noop */ }
			return panel.close();
		}
		if (error) {
			return panel.finish({ tone: 'err', title: __('La subida falló'), message: error.message });
		}
		panel.update({ status: 'done', phase: 'verify', phases, percent: 100, message: '', detail: '', unit: 'items', done: 0, total: 0 });
		panel.close();
		toast(__('Backup subido y verificado.'));
		await refreshBackups();
		restoreFlow(result.file, result.summary);
	}

	async function downloadFlow(url) {
		const res = await startJob('download', { url });
		if (res && res.job.status === 'done') {
			res.panel.close();
			toast(__('Backup descargado y verificado.'));
			await refreshBackups();
			restoreFlow(res.job.result.file);
		}
	}

	/* ---------- forms ---------- */
	function renderSchedule() {
		const s = state.settings.schedule;
		const f = $('#sbm-schedule-form');
		f.elements.enabled.checked = !!s.enabled;
		f.elements.frequency.value = s.frequency;
		f.elements.time.value = s.time;
		f.elements.retention.value = s.retention;
		$('#sbm-sch-scope').replaceChildren($('#sbm-sch-scope legend'), ...scopeChips(s.scope, 'scope'));
		$('#sbm-next-run').textContent = s.enabled && state.nextRun ? sprintf(__('Próxima ejecución: %s'), fmtDate(state.nextRun)) : __('Los backups automáticos están desactivados.');
	}

	async function saveSettings(payload, okMsg) {
		try {
			const r = await api('save_settings', { settings: JSON.stringify(payload) });
			state.settings = r.settings;
			state.nextRun = r.next_run;
			renderSchedule();
			toast(okMsg);
		} catch (e) { toast(e.message, 'err'); }
	}

	function initForms() {
		renderSchedule();
		const sf = $('#sbm-schedule-form');
		sf.addEventListener('submit', (e) => {
			e.preventDefault();
			const scope = [...sf.querySelectorAll('input[name="scope"]:checked')].map((i) => i.value);
			if (!scope.length) return toast(__('Selecciona al menos un elemento.'), 'err');
			saveSettings({ schedule: { enabled: sf.elements.enabled.checked, frequency: sf.elements.frequency.value, time: sf.elements.time.value, retention: Number(sf.elements.retention.value), scope } }, __('Programación guardada.'));
		});

		const gf = $('#sbm-settings-form');
		gf.elements.exclude.value = state.settings.exclude;
		gf.elements.allow_private_urls.checked = !!state.settings.allow_private_urls;
		gf.addEventListener('submit', (e) => {
			e.preventDefault();
			saveSettings({ exclude: gf.elements.exclude.value, allow_private_urls: gf.elements.allow_private_urls.checked }, __('Ajustes guardados.'));
		});

		const drop = $('#sbm-drop'), input = $('#sbm-file');
		input.addEventListener('change', () => { if (input.files[0]) uploadFlow(input.files[0]); input.value = ''; });
		drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
		['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
		drop.addEventListener('drop', (e) => { if (e.dataTransfer.files[0]) uploadFlow(e.dataTransfer.files[0]); });

		$('#sbm-url-form').addEventListener('submit', (e) => {
			e.preventDefault();
			const v = $('#sbm-url').value.trim();
			if (v) downloadFlow(v);
		});
	}

	function initTabs() {
		const tabs = [...root.querySelectorAll('.sbm-tab')];
		const show = (name) => {
			tabs.forEach((t) => { const on = t.dataset.tab === name; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on); });
			root.querySelectorAll('.sbm-panel').forEach((p) => { p.hidden = p.dataset.panel !== name; });
			history.replaceState(null, '', '#' + name);
		};
		tabs.forEach((t) => t.addEventListener('click', () => show(t.dataset.tab)));
		const initial = location.hash.slice(1);
		if (tabs.some((t) => t.dataset.tab === initial)) show(initial);
	}

	/* ---------- init ---------- */
	$('#sbm-create-backup').addEventListener('click', createBackupFlow);
	$('#sbm-refresh').addEventListener('click', refreshBackups);
	initTabs();
	initForms();
	renderList();
	renderStats();

	api('active').then((r) => {
		if (r.job) {
			toast(__('Se retoma una operación en curso.'));
			runJob(r.job).then((res) => {
				if (res.job.status === 'done') {
					if (res.job.type === 'restore') {
						const out = res.job.result || {};
						res.panel.finish({ tone: 'ok', title: __('Restauración completada'), actions: [{ label: __('Iniciar sesión'), kind: 'primary', onClick: () => { window.location = out.login_url || D.homeUrl; } }] });
					} else {
						res.panel.finish({ tone: 'ok', title: __('Operación completada') });
						refreshBackups();
					}
				}
			});
		}
	}).catch(() => { /* sin job activo o sin permisos */ });
})();
