/* Apotheke – vanilla JS, progressive enhancement (everything works without it). */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = () => ($('meta[name=csrf]') || {}).content || '';
  const MAX_SIDE = 1600, QUALITY = 0.8, RETRY_AFTER_MAX = 60;
  const LIMIT_NOTE = 'This photo is over the upload limit, so a smaller copy will be saved. The original file is not kept.';

  async function post(url, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) for (const k in data) fd.append(k, data[k]);
    if (!fd.has('csrf')) fd.append('csrf', csrf());
    const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
    let j = null;
    try { j = await r.json(); } catch (_) { /* non-JSON */ }
    if (!j) throw new Error(r.status === 413 ? 'That photo is too large.' : 'Server error (' + r.status + ')');
    if (j.redirect && r.status === 401) location.href = j.redirect;
    if (!j.ok) throw new Error(j.error || 'Request failed');
    return j;
  }

  function toast(msg, isErr) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'toast' + (isErr ? ' toast-err' : ''); t.setAttribute('role', isErr ? 'alert' : 'status'); t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  }

  // Clean ?saved= / ?deleted= from the URL so a reload doesn't re-toast.
  try {
    const u = new URL(location.href);
    if (['saved', 'deleted', 'done', 'edited', 'reordered'].some(k => u.searchParams.has(k))) {
      ['saved', 'deleted', 'done', 'edited', 'reordered'].forEach(k => u.searchParams.delete(k));
      history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
    }
  } catch (_) {}

  /* ---------- theme switch (Auto / Light / Dark): instant, persisted in localStorage + cookie ---------- */
  const DARK = '#111113', LIGHT = '#f5f5f7';
  function applyTheme(t) {
    const d = document.documentElement;
    d.setAttribute('data-theme', t);
    try { localStorage.setItem('theme', t); } catch (_) {}
    document.cookie = 'theme=' + t + '; path=/; max-age=34560000; SameSite=Lax';
    document.querySelectorAll('meta[name=theme-color]').forEach(m => m.remove());
    const cs = $('meta[name=color-scheme]'); if (cs) cs.content = t === 'auto' ? 'light dark' : t;
    const add = (c, media) => { const m = document.createElement('meta'); m.name = 'theme-color'; m.content = c; if (media) m.media = media; document.head.appendChild(m); };
    if (t === 'auto') { add(LIGHT, '(prefers-color-scheme: light)'); add(DARK, '(prefers-color-scheme: dark)'); } else add(t === 'dark' ? DARK : LIGHT);
    document.querySelectorAll('.seg-btn[name=theme]').forEach(b => { const on = b.value === t; b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false'); });
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('.seg-btn[name=theme]');
    if (!b) return;
    e.preventDefault();
    applyTheme(b.value);
    post('/theme', { theme: b.value }).catch(() => {});
  });

  /* ---------- menus & sheets (<details>): Escape closes, only one open at a time ---------- */
  const closeAll = except => document.querySelectorAll('details.menu[open], details.fsheet[open]').forEach(d => { if (d !== except) d.open = false; });
  document.addEventListener('toggle', e => { if (e.target.open && e.target.matches && e.target.matches('details.menu, details.fsheet')) closeAll(e.target); }, true);
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const d = document.querySelector('details.menu[open], details.fsheet[open]');
    if (d) { d.open = false; const s = $('summary', d); if (s) s.focus(); }
  });

  /* ---------- gallery: arrow buttons / thumbnails / keyboard, no reload (links work without JS) ---------- */
  const gal = $('#gallery');
  if (gal && +gal.dataset.count > 1) {
    const thumbs = [...document.querySelectorAll('.thumbs a[data-full]')];
    const n = thumbs.length, img = $('#hero-img'), hero = $('#hero'), cnt = $('#gal-count');
    const show = i => {
      i = (i + n) % n; gal.dataset.index = i;
      img.src = thumbs[i].dataset.full; hero.href = thumbs[i].dataset.full;
      img.alt = img.alt.replace(/photo \d+ of/, 'photo ' + (i + 1) + ' of');
      cnt.textContent = (i + 1) + ' / ' + n;
      thumbs.forEach((t, k) => t.setAttribute('aria-current', k === i ? 'true' : 'false'));
      const base = location.pathname; history.replaceState(null, '', base + '?p=' + i);
    };
    document.addEventListener('click', e => {
      const a = e.target.closest('#gal-prev, #gal-next, .thumbs a[data-i]');
      if (!a) return;
      e.preventDefault();
      const i = +gal.dataset.index;
      show(a.id === 'gal-prev' ? i - 1 : a.id === 'gal-next' ? i + 1 : +a.dataset.i);
    });
    document.addEventListener('keydown', e => {
      if (e.target.closest('input,textarea,select')) return;
      if (e.key === 'ArrowLeft') show(+gal.dataset.index - 1);
      if (e.key === 'ArrowRight') show(+gal.dataset.index + 1);
    });
  }

  /* ---------- photo manager: add photos one by one (the file as picked, unless it is over the limit) ---------- */
  const padd = $('#photoadd');
  if (padd) {
    const st = $('#photoadd-status');
    padd.querySelectorAll('input[type=file]').forEach(inp => inp.addEventListener('change', async () => {
      const files = [...(inp.files || [])];
      if (!files.length) return;
      st.hidden = false;
      st.classList.remove('warn');
      try {
        for (let k = 0; k < files.length; k++) {
          if (files[k].size > maxUploadBytes(padd)) { st.textContent = LIMIT_NOTE; st.classList.add('warn'); }
          else st.textContent = 'Uploading ' + (k + 1) + ' of ' + files.length + '…';
          const up = await photoForUpload(files[k], padd);
          if (up.shrunk) { st.textContent = LIMIT_NOTE; st.classList.add('warn'); }
          const fd = new FormData(); fd.append('photos[]', up.blob, up.name);
          await post(padd.action, fd);
        }
        location.href = location.pathname + '?p=999#photos';
      } catch (err) { st.textContent = err.message; toast(err.message, true); inp.value = ''; }
    }));
  }

  /* ---------- tag chips with autocomplete (the comma-separated input stays the form value) ---------- */
  const tagIn = $('#tags');
  if (tagIn) {
    const wrap = document.createElement('div'), entry = document.createElement('input');
    wrap.className = 'tagbox'; wrap.id = 'tagbox';
    entry.type = 'text'; entry.id = 'tag-entry'; entry.setAttribute('list', 'tag-list'); entry.autocomplete = 'off';
    entry.setAttribute('autocapitalize', 'none'); entry.setAttribute('enterkeyhint', 'enter'); entry.placeholder = tagIn.placeholder;
    entry.setAttribute('aria-label', 'Add a tag');
    const list = () => tagIn.value.split(/[,;]+/).map(s => s.trim()).filter(Boolean);
    const key = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const render = () => {
      wrap.querySelectorAll('.tagchip').forEach(c => c.remove());
      list().forEach(t => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'tagchip tagchip-x'; b.textContent = '#' + t; b.dataset.tag = t;
        b.setAttribute('aria-label', 'Remove tag ' + t);
        wrap.insertBefore(b, entry);
      });
    };
    const add = raw => {
      const cur = list();
      raw.split(/[,;]+/).map(s => s.trim().replace(/^#/, '')).filter(Boolean).forEach(t => { if (!cur.some(c => key(c) === key(t))) cur.push(t); });
      tagIn.value = cur.join(', '); entry.value = ''; render();
    };
    tagIn.type = 'hidden';
    tagIn.after(wrap); wrap.appendChild(entry);
    wrap.addEventListener('click', e => {
      const c = e.target.closest('.tagchip-x');
      if (c) { tagIn.value = list().filter(t => t !== c.dataset.tag).join(', '); render(); entry.focus(); } else entry.focus();
    });
    entry.addEventListener('keydown', e => {
      if ((e.key === 'Enter' || e.key === ',') && entry.value.trim()) { e.preventDefault(); add(entry.value); }
      else if (e.key === 'Enter') e.preventDefault();
      else if (e.key === 'Backspace' && !entry.value && list().length) { tagIn.value = list().slice(0, -1).join(', '); render(); }
    });
    // picking a datalist suggestion fires "input" with the full value; commas typed on phones arrive the same way
    entry.addEventListener('input', e => {
      if (/[,;]/.test(entry.value)) add(entry.value);
      else if (e.inputType === 'insertReplacementText' || e.inputType === undefined) {
        const opts = [...document.querySelectorAll('#tag-list option')].map(o => o.value);
        if (opts.includes(entry.value)) add(entry.value);
      }
    });
    entry.addEventListener('blur', () => { if (entry.value.trim()) add(entry.value); });
    const f = tagIn.form; if (f) f.addEventListener('submit', () => { if (entry.value.trim()) add(entry.value); }, true);
    tagIn.addEventListener('af:render', render);   // AI autofill changed the value
    // the user edited the tags (typing or removing a chip): AI autofill leaves them alone from now on
    const touched = e => { if (e.isTrusted && (e.type === 'input' || e.target.closest('.tagchip-x'))) { tagIn.dataset.afTouched = '1'; wrap.classList.remove('af-filled'); tagIn.classList.remove('af-filled'); } };
    entry.addEventListener('input', touched); wrap.addEventListener('click', touched, true);
    render();
  }

  /* ---------- restore: show the chosen file name ---------- */
  const rf = $('#restore-file');
  if (rf) rf.addEventListener('change', () => { $('#restore-name').textContent = rf.files[0] ? rf.files[0].name : 'Choose backup file…'; });

  /* ---------- print button (labels) ---------- */
  document.addEventListener('click', e => { if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); } });

  /* ---------- undo bar: hide when the window closes, drop ?undo= from the address ---------- */
  const ub = $('#undo-bar');
  if (ub) {
    const hide = () => { ub.hidden = true; };
    setTimeout(hide, Math.max(0, parseInt(ub.dataset.left, 10) || 0) * 1000);
    const x = $('#undo-x'); if (x) x.addEventListener('click', hide);
    try {
      const u = new URL(location.href);
      if (u.searchParams.has('undo')) { u.searchParams.delete('undo'); history.replaceState(history.state, '', u.pathname + (u.search || '') + u.hash); }
    } catch (_) { /* old browser */ }
  }

  /* ---------- stocktake: tick / scan in place (the forms work without JS) ---------- */
  const stk = $('#stocktake');
  if (stk) {
    document.addEventListener('submit', async e => {
      const f = e.target.closest('form.stk-line, form#stk-scan');
      if (!f) return;
      e.preventDefault();
      const code = $('#stk-code');
      if (f.id === 'stk-scan' && !code.value.trim()) { code.focus(); return; }
      try {
        const j = await post(f.action, new FormData(f));
        $('#stk-body').innerHTML = j.html;
        toast(j.message, false);
        if (f.id === 'stk-scan') { code.value = ''; code.removeAttribute('aria-invalid'); const er = $('#stk-err'); if (er) er.remove(); code.focus(); }
      } catch (err) { toast(err.message, true); if (f.id === 'stk-scan') code.select(); }
    });
  }

  /* ---------- favourite star (in place; the form still works without JS) ---------- */
  document.addEventListener('submit', async e => {
    const f = e.target.closest('form.favf');
    if (!f) return;
    e.preventDefault();
    const b = $('.favbtn', f), inp = $('input[name=on]', f);
    const on = inp.value === '1';
    b.setAttribute('aria-pressed', on ? 'true' : 'false'); inp.value = on ? '0' : '1';   // optimistic
    try {
      const j = await post(f.action, { on: on ? '1' : '0' });
      const n = $('#fav-chip b'); if (n) n.textContent = j.count;
      toast(j.favourite ? 'Added to favourites' : 'Removed from favourites');
    } catch (err) {
      b.setAttribute('aria-pressed', on ? 'false' : 'true'); inp.value = on ? '1' : '0'; toast(err.message, true);
    }
  });

  /* ---------- quantity +/- (cards + detail) ---------- */
  const pending = new Map();
  document.addEventListener('click', e => {
    const b = e.target.closest('.qty .qbtn[data-delta]');
    if (!b) return;
    e.preventDefault();
    const box = b.closest('.qty'), id = box.dataset.id, out = $('.qval', box);
    const cur = parseInt(out.textContent, 10) || 0;
    const next = Math.max(0, cur + parseInt(b.dataset.delta, 10));
    if (next === cur) return;
    out.textContent = next;                       // optimistic
    $('.qbtn[data-delta="-1"]', box).disabled = next <= 0;
    box.classList.remove('bump'); void box.offsetWidth; box.classList.add('bump');
    // Coalesce rapid taps into one request.
    const p = pending.get(id) || { delta: 0, base: cur };
    p.delta += next - cur;
    clearTimeout(p.timer);
    p.timer = setTimeout(async () => {
      pending.delete(id);
      try {
        const j = await post('/item/' + id + '/qty', { delta: p.delta });
        out.textContent = j.quantity;
        $('.qbtn[data-delta="-1"]', box).disabled = j.quantity <= 0;
        const lowEl = box.closest('.card') || $('#lowbox');
        if (lowEl && 'low' in j) lowEl.classList.toggle('is-low', !!j.low);
      } catch (err) {
        out.textContent = p.base; toast(err.message, true);
      }
    }, 350);
    pending.set(id, p);
  });

  /* ---------- delete confirm ---------- */
  document.addEventListener('submit', e => {
    const f = e.target;
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) e.preventDefault();
  });

  /* ---------- back buttons: use history when we came from inside the app ---------- */
  document.addEventListener('click', e => {
    const a = e.target.closest('a[data-back]');
    if (!a || !document.referrer) return;
    try {
      const r = new URL(document.referrer);
      if (r.origin === location.origin && history.length > 1 && r.pathname !== '/save') { e.preventDefault(); history.back(); }
    } catch (_) {}
  });

  /* ---------- statistics panel: collapsible, remembered, lazy-loaded ---------- */
  const statsBox = $('#stats');
  if (statsBox) {
    const store = (k, v) => { try { localStorage.setItem(k, v); } catch (_) {} };
    const read = k => { try { return localStorage.getItem(k); } catch (_) { return null; } };
    const applyRange = () => {
      const r = read('statsRange');
      const el = r && $('#rg-' + r, statsBox);
      if (el) el.checked = true;
    };
    const load = async () => {
      if (statsBox.dataset.loaded === '1') return;
      statsBox.dataset.loaded = '1';
      try {
        const r = await fetch('/stats', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        if (!r.ok) throw new Error();
        $('#stats-body').innerHTML = await r.text();
        applyRange();
      } catch (_) { statsBox.dataset.loaded = '0'; $('#stats-body').textContent = 'Could not load statistics.'; }
    };
    const saved = read('statsOpen');
    if (saved !== null && (saved === '1') !== statsBox.open) statsBox.open = saved === '1';
    // phones: start collapsed so the items are above the fold, until this device makes its own choice
    let autoClosed = false;
    if (saved === null && !/(^|; )stats_open=/.test(document.cookie) && statsBox.open && matchMedia('(max-width: 640px)').matches) {
      autoClosed = true;
      statsBox.open = false;
    }
    applyRange();
    statsBox.addEventListener('toggle', () => {
      if (autoClosed) { autoClosed = false; return; }
      const v = statsBox.open ? '1' : '0';
      store('statsOpen', v);
      document.cookie = 'stats_open=' + v + '; path=/; max-age=31536000; SameSite=Lax';
      if (statsBox.open) load();
    });
    statsBox.addEventListener('change', e => { if (e.target.name === 'strange') store('statsRange', e.target.value); });
  }

  /* ---------- instant search ---------- */
  const filters = $('#filters');
  if (filters) {
    const grid = $('#grid'), count = $('#count'), empty = $('#empty'), more = $('#more');
    const q = $('#q'), sheet = $('#fsheet'), achips = $('#achips'), fcount = $('#fcount');
    let timer, ctl, page = 1;

    const params = (p) => {
      const sp = new URLSearchParams(new FormData(filters));
      for (const [k, v] of [...sp]) if (!v || (k === 'sort' && v === ($('#sort').dataset.default || 'recent'))) sp.delete(k);
      if (p > 1) sp.set('page', p);
      return sp;
    };

    async function load(append) {
      if (ctl) ctl.abort();
      ctl = new AbortController();
      const p = append ? page + 1 : 1;
      const sp = params(p);
      if (!append) grid.classList.add('loading');
      try {
        const r = await fetch('/?' + sp, { headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }, signal: ctl.signal, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error);
        page = p;
        if (append) grid.insertAdjacentHTML('beforeend', j.html); else { grid.innerHTML = j.html; window.scrollTo({ top: 0 }); }
        document.dispatchEvent(new CustomEvent('grid:update', { detail: sp }));
        count.textContent = j.count;
        empty.hidden = j.total > 0;
        const filtered = !!(q.value.trim() || $('#loc').value || $('#cat').value || ($('#tag') && $('#tag').value) || [...document.querySelectorAll('#fsheet select[data-filter]')].some(s => s.value));
        $('#empty-title').textContent = filtered ? 'Nothing matches.' : 'Your catalog is empty.';
        $('#empty-sub').textContent = filtered ? 'Try another word or clear the filters.' : 'Snap a photo of something, give it a name and a place. That’s it.';
        $('#empty-add').hidden = filtered; $('#empty-clear').hidden = !filtered;
        if (j.chips !== undefined) { achips.innerHTML = j.chips; fcount.textContent = j.nfilters; fcount.hidden = !j.nfilters; }
        more.hidden = !j.more;
        if (j.query !== undefined) syncSaved(j.query);
        if (!append) { sp.delete('page'); history.replaceState(null, '', '/' + (sp.toString() ? '?' + sp : '')); }
        const nq = new URLSearchParams();
        ['loc', 'cat'].forEach(k => { const v = $('#' + k).value; if (/^\d+$/.test(v)) nq.set(k, v); });
        document.querySelectorAll('a.fab, .bar a.btn-primary').forEach(a => a.href = '/new' + (nq.toString() ? '?' + nq : ''));
      } catch (err) {
        if (err.name !== 'AbortError') toast('Search failed – check your connection', true);
      } finally { if (!append) grid.classList.remove('loading'); }
    }

    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(false), 180); });
    filters.addEventListener('change', e => { if (e.target.tagName === 'SELECT') load(false); });
    filters.addEventListener('submit', e => { e.preventDefault(); clearTimeout(timer); load(false); q.blur(); if (sheet) sheet.open = false; });
    const closeSheet = $('#filter-close'); if (closeSheet) closeSheet.addEventListener('click', () => { sheet.open = false; $('#filter-open').focus(); });
    const reset = which => {
      if (which === 'all' || which === 'loc') $('#loc').value = '';
      if (which === 'all' || which === 'cat') $('#cat').value = '';
      if ((which === 'all' || which === 'tag') && $('#tag')) $('#tag').value = '';
      document.querySelectorAll('#fsheet select[data-filter]').forEach(s => { if (which === 'all' || which === s.name) s.value = ''; });
      if (which === 'all' || which === 'sort') $('#sort').value = $('#sort').dataset.default || 'recent';
      if (which === 'all') q.value = '';
      load(false);
    };
    document.addEventListener('click', e => {
      const c = e.target.closest('#achips a[data-clear], #filter-clear, #empty-clear');
      if (!c) return;
      e.preventDefault();
      reset(c.dataset.clear || 'all');
      if (sheet && c.id === 'filter-clear') sheet.open = false;
    });
    more.addEventListener('click', () => load(true));

    // Saved filters: highlight the chip matching the current view; save the current combination.
    const sfDetails = $('#savefilter'), sfForm = $('#sf-form'), sfChips = $('#saved-chips');
    function syncSaved(query) {
      if (sfChips) sfChips.querySelectorAll('a[data-query]').forEach(a => {
        const on = a.dataset.query === query;
        a.classList.toggle('is-on', on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
      if (sfDetails) { sfDetails.hidden = query === ''; if (query === '') sfDetails.open = false; }
    }
    if (sfForm && sfDetails) {
      sfDetails.addEventListener('toggle', () => { if (sfDetails.open) $('#sf-name').focus(); });
      sfForm.addEventListener('submit', async e => {
        e.preventDefault();
        sfForm.querySelectorAll('input[data-sf]').forEach(i => i.remove());
        for (const [k, v] of params(1)) {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = k; i.value = v; i.dataset.sf = '';
          sfForm.append(i);
        }
        const name = $('#sf-name');
        try {
          const r = await fetch('/filters', { method: 'POST', body: new FormData(sfForm), headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }, credentials: 'same-origin' });
          const j = await r.json();
          if (!j.ok) { toast(j.error || 'Could not save the filter', true); name.focus(); return; }
          sfChips.innerHTML = j.html;
          toast('Saved filter “' + name.value.trim() + '”');
          name.value = ''; sfDetails.open = false;
        } catch { toast('Could not save – check your connection', true); }
      });
    }

    // Tapping a location/category chip (or an Overview bar) filters in place.
    document.addEventListener('click', e => {
      // overview tiles that set one of the choice filters: data-fk="stock" data-fv="low"
      const lt = e.target.closest('#stats a[data-fk]');
      const ls = lt && document.querySelector('#fsheet select[data-filter][name="' + lt.dataset.fk + '"]');
      if (ls) { e.preventDefault(); ls.value = lt.dataset.fv; q.value = ''; load(false); return; }
      const c = e.target.closest('#grid a.chip[data-loc], #stats a[data-loc], #grid a.chip[data-cat], #stats a[data-cat]');
      if (!c) return;
      const k = c.dataset.loc !== undefined ? 'loc' : 'cat';
      const sel = $('#' + k), v = c.dataset[k];
      const opt = [...sel.options].find(o => o.value === v);
      if (!opt) return;
      e.preventDefault();
      sel.value = opt.value; q.value = ''; load(false);
    });
  }

  async function decodeImage(file) {
    if ('createImageBitmap' in window) {
      try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (_) {}
      try { return await createImageBitmap(file); } catch (_) {}
    }
    return await new Promise((res, rej) => {
      const img = new Image(), u = URL.createObjectURL(file);
      img.onload = () => { URL.revokeObjectURL(u); res(img); };
      img.onerror = () => { URL.revokeObjectURL(u); rej(new Error('decode')); };
      img.src = u;
    });
  }
  /** Bytes the server will accept for one photo (the form's MAX_FILE_SIZE, else the 20 MB default). */
  function maxUploadBytes(root) {
    const el = root && root.querySelector('input[name="MAX_FILE_SIZE"]');
    const n = el ? parseInt(el.value, 10) : 0;
    return n > 0 ? n : 20 * 1024 * 1024;
  }
  /** The picked file, or a JPEG from shrinkImage when it is over the upload limit. */
  async function photoForUpload(file, root) {
    const name = file.name || 'photo.jpg';
    if (file.size <= maxUploadBytes(root)) return { blob: file, name, shrunk: false };
    try {
      return { blob: await shrinkImage(file), name: 'photo.jpg', shrunk: true };
    } catch (_) {
      return { blob: file, name, shrunk: false };
    }
  }
  function limitNote(form, on) {
    let n = $('#photo-limit', form);
    if (!on) { if (n) n.remove(); return; }
    if (n) return;
    n = document.createElement('p');
    n.id = 'photo-limit'; n.className = 'warnbox'; n.setAttribute('role', 'status');
    n.textContent = LIMIT_NOTE;
    $('#photo', form).after(n);
  }
  async function shrinkImage(file, maxSide = MAX_SIDE) {
    const src = await decodeImage(file);
    const w = src.width, h = src.height, s = Math.min(1, maxSide / Math.max(w, h));
    const cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
    const c = document.createElement('canvas');
    c.width = cw; c.height = ch;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cw, ch);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(src, 0, 0, cw, ch);
    if (src.close) src.close();
    const out = await new Promise(r => c.toBlob(r, 'image/jpeg', QUALITY));
    c.width = c.height = 0; // free memory on iOS
    if (!out) throw new Error('encode');
    return out.size < file.size || file.type !== 'image/jpeg' ? out : file;
  }

  /* ---------- add/edit form: photo capture. The upload is the picked file; shrink only previews, Analyze, and files over the limit. ---------- */
  const form = $('#itemform');
  if (form) {
    const preview = $('#preview'), status = $('#pstatus'), photo = $('#photo'), err = $('#err');
    const inputs = form.querySelectorAll('input[type=file]');
    let upload = null, working = null, objUrl = null;

    const showErr = m => { err.textContent = m; err.hidden = !m; if (m) err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };

    inputs.forEach(inp => inp.addEventListener('change', () => {
      const file = inp.files && inp.files[0];
      if (!file) return;
      inputs.forEach(o => { if (o !== inp) o.value = ''; });
      showErr('');
      if (objUrl) URL.revokeObjectURL(objUrl);
      objUrl = URL.createObjectURL(file);
      preview.src = objUrl; preview.hidden = false;
      photo.classList.add('has-img');
      const tk = $('.photo-take span', form); if (tk) tk.textContent = 'Retake photo';
      const rm = $('#rmimg'); if (rm) rm.checked = false;
      status.hidden = false;
      const over = file.size > maxUploadBytes(form);
      limitNote(form, over);
      status.textContent = over ? 'Preparing a smaller copy…' : (Math.max(1, Math.round(file.size / 1024)) + ' KB');
      upload = null;
      working = photoForUpload(file, form).then(u => {
        upload = u;
        if (u.shrunk) status.textContent = Math.max(1, Math.round(u.blob.size / 1024)) + ' KB';
      }).catch(() => {
        upload = { blob: file, name: file.name || 'photo.jpg', shrunk: false };
        limitNote(form, false);
        status.textContent = Math.round(file.size / 1024) + ' KB';
      });
      const name = form.elements.name;
      if (name && !name.value) setTimeout(() => name.focus(), 50);
      if (form.dataset.autofill === '1') afPicked(file);
    }));

    /* AI autofill, on demand: each photo gets an Analyze button (the photo just picked; on the edit form also every
       stored photo). Only one analysis runs at a time: while it runs, every Analyze button is aria-disabled (still
       focusable, so keyboard focus is not dropped on body) and a click or keypress is ignored. With
       "Analyze photos automatically" (data-af-auto) a picked photo is analyzed right away, as in 2.3.
       The picked photo is resized in the browser to the chosen long edge (data-af-side: JPEG, or PNG when it has
       transparency; never enlarged; EXIF orientation applied) and posted to /autofill/suggest (the server talks to
       the service); a stored photo is sent by id. Only fields that are still empty (or hold an untouched preselected
       default) and that the user has not touched are filled; a second analysis never replaces what an earlier one
       filled. Never blocks saving or typing.
       Feedback: "Uploading…" (real upload progress), "Analyzing photo…" (an estimate from the service's
       avg_latency_ms, linear and held at 95 % until the answer; a spinner when the service gives no average),
       then "Suggestions ready" / "No suggestions" / the reason. A 503 with Retry-After is retried once.
       Debug hook for QA: data-af-upload (what the browser uploaded) and data-af-sent (what the server sent to the
       service), JSON {type, bytes, width, height}; data-af-model-used when the service reports it. */
    const afBox = $('#af-feedback'), afStatus = $('#af-status'), afProg = $('#af-progress');
    const afBar = $('#af-bar'), afPct = $('#af-pct');
    let afSeq = 0, afTick = 0;
    const afFields = () => ({ name: form.elements.name, tags: form.elements.tags, description: form.elements.notes,
      category: $('#catsel', form), newcat: $('#newcatname', form) });
    Object.values(afFields()).forEach(el => el && ['input', 'change'].forEach(ev => el.addEventListener(ev, e => {
      if (!e.isTrusted) return;
      el.dataset.afTouched = '1'; el.classList.remove('af-filled');
    })));
    const afSay = (msg, fail) => {
      if (!afStatus) return;
      if (afBox) afBox.hidden = !msg;
      afStatus.classList.toggle('af-fail', !!fail);
      afStatus.innerHTML = msg ? '<svg class="i" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></svg> ' : '';
      if (msg) afStatus.append(document.createTextNode(msg));
    };
    // the bar: pct null = indeterminate (spinner), a number = determinate; extra = "about 6 s left" etc.
    const afShow = (pct, extra) => {
      if (!afProg) return;
      afProg.hidden = false;
      afProg.classList.toggle('af-indet', pct === null);
      if (pct === null) { afProg.removeAttribute('aria-valuenow'); afBar.style.width = ''; }
      else { const p = Math.round(pct); afProg.setAttribute('aria-valuenow', String(p)); afBar.style.width = p + '%'; }
      const txt = (pct === null ? '' : Math.round(pct) + ' %') + (extra ? (pct === null ? '' : ' · ') + extra : '');
      afPct.textContent = txt;
      if (txt) afProg.setAttribute('aria-valuetext', txt); else afProg.removeAttribute('aria-valuetext');
    };
    const afHide = () => { clearInterval(afTick); afTick = 0; if (afProg) { afProg.hidden = true; afProg.classList.remove('af-indet', 'af-done'); } };
    const afDone = () => {           // jump to 100 %, then hide
      clearInterval(afTick); afTick = 0;
      if (!afProg || afProg.hidden || afProg.classList.contains('af-indet')) { afHide(); return; }
      afShow(100, ''); afProg.classList.add('af-done');
      const seq = afSeq;
      setTimeout(() => { if (seq === afSeq) afHide(); }, 700);
    };
    // "Analyzing": elapsed vs the expected time, linear so percent and seconds agree (10 s of 20 = 50 %, 10 s left);
    // capped at 95 % until the answer, then "still working…" once past the estimate. After AF_HINT_MS held at 95 %
    // the status line explains the wait (a cold model loading, or a queued request) and its upper bound.
    const AF_HINT_MS = 5000;
    const afAnalyze = seq => {
      clearInterval(afTick);
      afSay('Analyzing photo…');
      const ms = parseInt(form.dataset.afLatency || '', 10);
      if (!(ms > 0)) { afShow(null, ''); return; }
      const t0 = performance.now(), cap = parseInt(form.dataset.afTimeout || '', 10);
      let hinted = false;
      const step = () => {
        if (seq !== afSeq) { clearInterval(afTick); return; }
        const t = performance.now() - t0, left = Math.ceil((ms - t) / 1000);
        afShow(Math.min(95, 100 * t / ms), t < ms ? (left > 1 ? 'about ' + left + ' s left' : 'almost done') : 'still working…');
        if (!hinted && t >= 0.95 * ms + AF_HINT_MS) {
          hinted = true;
          afSay('Analyzing photo… The model may be loading or busy' + (cap > 0 ? ' – this can take up to ' + cap + ' s.' : '.'));
        }
      };
      step(); afTick = setInterval(step, 250);
    };
    const afSend = (src, img, seq) => new Promise(resolve => {
      const fd = new FormData();
      if (src.photoId) fd.append('photo_id', src.photoId);
      else fd.append('image', img, img.type === 'image/png' ? 'photo.png' : 'photo.jpg');
      fd.append('csrf', csrf());
      const x = new XMLHttpRequest();
      x.open('POST', '/autofill/suggest');
      x.setRequestHeader('X-Requested-With', 'fetch');
      x.setRequestHeader('Accept', 'application/json');
      x.timeout = ((parseInt(form.dataset.afTimeout || '50', 10) || 30) + 15) * 1000;
      let analyzing = false;
      const sent = () => { if (!analyzing && seq === afSeq) { analyzing = true; afAnalyze(seq); } };
      x.upload.onprogress = e => {
        if (seq !== afSeq || analyzing || !e.lengthComputable || !e.total) return;
        if (e.loaded >= e.total) sent(); else afShow(100 * e.loaded / e.total, '');
      };
      x.upload.onload = sent;
      x.onload = () => { let j = null; try { j = JSON.parse(x.responseText); } catch (_) { j = null; } resolve(j); };
      x.onerror = x.ontimeout = x.onabort = () => resolve(null);
      x.send(fd);
    });
    // "Model is warming up… / Service is busy… retrying in N s" (retry_reason from the service's message, busy by
    // default): resolves true when the wait ran out, false if another photo was picked
    const afWait = (secs, seq, why) => new Promise(resolve => {
      const what = why === 'warming' ? 'Model is warming up…' : 'Service is busy…';
      clearInterval(afTick);
      let left = secs;
      const step = () => {
        if (seq !== afSeq) { clearInterval(afTick); resolve(false); return; }
        if (left <= 0) { clearInterval(afTick); resolve(true); return; }
        afSay(what + ' retrying in ' + left + ' s');
        afShow(null, ''); left--;
      };
      step(); afTick = setInterval(step, 1000);
    });
    // Preselected defaults on a new item (data-af-defaults: the default category, default tags/notes) count as empty
    // while they still hold their initial value and the user has not touched them (afTouched: trusted events only).
    const afInit = new Map();
    {
      const by = { category: $('#catsel', form), tags: form.elements.tags, notes: form.elements.notes };
      (form.dataset.afDefaults || '').split(',').forEach(k => { const el = by[k]; if (el) afInit.set(el, el.value); });
    }
    const afBlank = el => el.value.trim() === '' || (afInit.has(el) && el.value === afInit.get(el));
    // free to fill: empty (or an untouched preselected default) and never touched. What an earlier analysis filled in
    // stays: a second analysis (another photo) only fills what is still empty.
    const afFree = el => el && !el.dataset.afTouched && afBlank(el);
    const afPut = (el, v) => { el.value = v; el.classList.add('af-filled'); };
    // one analysis at a time: aria-disabled (not disabled) so the focused button stays in the tab order.
    // a click or Enter/Space while one runs is ignored. If focus did fall to body, it is put back when the run ends.
    let afFile = null, afRunning = null;          // the photo picked on the form; {src, btn} while one runs
    const afButtons = () => form.querySelectorAll('.af-analyze');
    const afBegin = (btn, src) => {
      afRunning = { src, btn };
      afButtons().forEach(b => {
        b.setAttribute('aria-disabled', 'true');
        if (b === btn) b.setAttribute('aria-busy', 'true');
      });
      form.classList.add('af-busy');
    };
    const afEnd = () => {
      const btn = afRunning && afRunning.btn;
      afRunning = null;
      afButtons().forEach(b => { b.removeAttribute('aria-disabled'); b.removeAttribute('aria-busy'); });
      form.classList.remove('af-busy');
      if (btn && document.activeElement === document.body) btn.focus();
    };
    if (form.dataset.autofill === '1') {
      const acts = $('#af-actions', form);
      if (acts && $('#af-photos', acts)) acts.hidden = false;
      form.addEventListener('click', e => {
        const b = e.target.closest('.af-analyze');
        if (!b || b.getAttribute('aria-disabled') === 'true' || afRunning) return;
        if (b.dataset.afPhoto) autofill({ photoId: b.dataset.afPhoto }, b);
        else if (afFile) autofill({ file: afFile }, b);
      });
    }
    // a photo was picked: it gets the Analyze button (or is analyzed at once with auto-analyze). It replaces the photo
    // an analysis may still be working on, so that one is dropped.
    function afPicked(file) {
      afFile = file;
      const acts = $('#af-actions', form), btn = $('#af-analyze', form);
      if (acts) acts.hidden = false;
      if (btn) btn.hidden = false;
      if (afRunning && afRunning.src === 'picked') { afSeq++; afEnd(); afHide(); afSay(''); }
      if (form.dataset.afAuto === '1' && !afRunning) autofill({ file }, btn);
    }
    // The picked photo at the chosen long edge: never enlarged, EXIF orientation applied by the decoder, JPEG on white
    // unless it has transparency (then PNG, alpha kept). A small JPEG that would only grow is sent as it is.
    async function afShrink(file, side) {
      const src = await decodeImage(file);
      const w = src.width, h = src.height, s = Math.min(1, side / Math.max(w, h));
      const cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
      const c = document.createElement('canvas');
      c.width = cw; c.height = ch;
      const ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(src, 0, 0, cw, ch);
      if (src.close) src.close();
      let alpha = false;
      if (file.type !== 'image/jpeg') {
        const d = ctx.getImageData(0, 0, cw, ch).data;
        for (let i = 3; i < d.length; i += 4) if (d[i] < 255) { alpha = true; break; }
      }
      if (!alpha) { ctx.globalCompositeOperation = 'destination-over'; ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cw, ch); }
      const out = await new Promise(r => c.toBlob(r, alpha ? 'image/png' : 'image/jpeg', QUALITY));
      c.width = c.height = 0;
      if (!out) throw new Error('encode');
      const keep = s === 1 && file.type === 'image/jpeg' && file.size <= out.size;
      return { blob: keep ? file : out, meta: { type: keep ? file.type : out.type, bytes: keep ? file.size : out.size, width: cw, height: ch } };
    }
    async function autofill(src, btn) {
      const seq = ++afSeq;
      afBegin(btn, src.photoId ? 'stored' : 'picked');
      clearInterval(afTick);
      afSay('Uploading…');
      afShow(0, '');
      let img = null;
      if (src.file) {
        const side = parseInt(form.dataset.afSide || '1024', 10) || 1024;
        let r;
        try { r = await afShrink(src.file, side); }
        catch (_) { r = { blob: src.file, meta: { type: src.file.type || 'application/octet-stream', bytes: src.file.size, width: null, height: null } }; }   // e.g. HEIC outside Safari: the server/service decide
        img = r.blob;
        form.dataset.afUpload = JSON.stringify(r.meta);
      }
      if (seq !== afSeq) return;
      let j = await afSend(src, img, seq);
      if (seq !== afSeq) return;           // a newer photo was picked meanwhile
      const cap = parseInt(form.dataset.afTimeout || '50', 10) || 30;
      // One retry, and only for a short Retry-After. A daily cap (until 00:00 UTC) is longer than a minute:
      // show the busy message and leave Save usable. The wait never disables the form.
      if (j && !j.ok && j.code === 'busy' && j.retry_after > 0 && j.retry_after <= RETRY_AFTER_MAX && j.retry_after <= cap) {
        if (!(await afWait(j.retry_after, seq, j.retry_reason))) return;
        afSay('Uploading…'); afShow(0, '');
        j = await afSend(src, img, seq);
        if (seq !== afSeq) return;
      }
      if (j && j.avg_latency_ms > 0) form.dataset.afLatency = String(j.avg_latency_ms);   // better estimate for the next photo
      if (j && j.image) form.dataset.afSent = JSON.stringify(j.image);
      if (j && j.model_used) form.dataset.afModelUsed = j.model_used;
      afEnd();
      if (!j || !j.ok) { afHide(); afSay((j && j.message) || 'No suggestions (autofill is not available right now).', true); return; }
      afDone();
      const s = j.suggestions || {}, f = afFields(), done = [];
      if (s.name && afFree(f.name)) { afPut(f.name, s.name); done.push('name'); }
      if (s.category && f.category && afFree(f.category) && (!f.newcat || afFree(f.newcat))) {
        const want = s.category.toLocaleLowerCase();
        const opt = [...f.category.options].find(o => /^\d+$/.test(o.value) && o.textContent.replace(/ ★$/, '').trim().toLocaleLowerCase() === want);
        // an untouched preselected default (General ★) only gives way to an existing category: a name that is not in
        // the list keeps the default instead of pre-filling "＋ New category…" (saving would create it). A picker
        // with nothing selected still gets "＋ New category…" pre-filled, as before.
        const heldDefault = afInit.has(f.category) && f.category.value === afInit.get(f.category);
        if (opt) { afPut(f.category, opt.value); if (f.newcat) { f.newcat.value = ''; f.newcat.classList.remove('af-filled'); } done.push('category'); }
        else if (f.newcat && !heldDefault) { afPut(f.category, '__new'); afPut(f.newcat, s.category); const row = $('#newcat', form); if (row) row.hidden = false; done.push('category'); }
      }
      if (s.tags && s.tags.length && afFree(f.tags)) {
        afPut(f.tags, s.tags.join(', ')); f.tags.dispatchEvent(new Event('af:render'));
        const tb = $('#tagbox', form); if (tb) tb.classList.add('af-filled');
        done.push('tags');
      }
      if (s.description && afFree(f.description)) { afPut(f.description, s.description); done.push('description'); }
      afSay(done.length ? 'Suggestions ready: ' + done.join(', ') + ' filled in. Check before saving.' : j.code === 'empty' ? 'No suggestions for this photo.' : 'Suggestions ready – nothing to fill in, your entries were kept.');
    }

    // Location / category pickers with inline "＋ New …"
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    form.querySelectorAll('.picker').forEach(pk => {
      const p = pk.dataset.prefix, endpoint = pk.dataset.endpoint;
      const sel = $('#' + p + 'sel', pk), row = $('#new' + p, pk), name = $('#new' + p + 'name', pk), ok = $('#new' + p + 'ok', pk);
      if (!sel || !row) return;
      let prev = sel.value === '__new' ? '' : sel.value;
      const openNew = () => { sel.value = '__new'; row.hidden = false; setTimeout(() => name.focus(), 30); };
      const closeNew = () => { row.hidden = true; name.value = ''; };
      sel.addEventListener('change', () => { if (sel.value === '__new') openNew(); else { prev = sel.value; closeNew(); } });
      $('#' + p + 'add', pk).addEventListener('click', openNew);
      async function add() {
        const v = name.value.trim().replace(/\s+/g, ' ');
        if (!v) { name.focus(); return; }
        ok.disabled = true;
        try {
          const j = await post(endpoint, { name: v });
          let opt = [...sel.options].find(o => o.value === String(j.id));
          if (!opt) {
            opt = new Option(j.name, j.id);
            const real = [...sel.options].filter(o => /^\d+$/.test(o.value));
            const before = real.find(o => collator.compare(o.textContent.replace(/ ★$/, ''), j.name) > 0);
            sel.insertBefore(opt, before || sel.querySelector('option[value="__new"]'));
          }
          sel.value = prev = String(j.id);
          closeNew();
          toast(j.created ? 'Added “' + j.name + '”' : '“' + j.name + '” already exists – selected');
        } catch (ex) {
          showErr(ex.message);
        } finally { ok.disabled = false; }
      }
      ok.addEventListener('click', add);
      name.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); add(); }
        if (e.key === 'Escape') { closeNew(); sel.value = prev; }
      });
    });

    // Quantity stepper
    form.addEventListener('click', e => {
      const b = e.target.closest('[data-step]');
      if (!b) return;
      const i = form.elements.quantity;
      i.value = Math.max(0, (parseInt(i.value, 10) || 0) + parseInt(b.dataset.step, 10));
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const name = form.elements.name;
      if (!name.value.trim()) { showErr('Please give the item a name.'); name.focus(); return; }
      for (const pk of form.querySelectorAll('.picker')) {
        const ls = $('select', pk), nn = $('.newloc input', pk);
        if (ls.value === '__new' && !nn.value.trim()) { showErr('Type a name for the new ' + pk.dataset.kind + ' (or pick one).'); nn.focus(); return; }
      }
      const submitter = e.submitter || $('#save');
      form.classList.add('busy');
      const btnText = submitter.textContent;
      submitter.textContent = 'Saving…';
      try {
        if (working) await working;
        const fd = new FormData(form);
        fd.delete('photo'); fd.delete('photo2');
        if (upload) fd.append('photo', upload.blob, upload.name);
        if (submitter.name) fd.set(submitter.name, submitter.value);
        const j = await post(form.action, fd);
        location.href = j.redirect || '/';
      } catch (ex) {
        showErr(ex.message === 'Failed to fetch' || ex.name === 'TypeError' ? 'Network error – is the server reachable?' : ex.message);
        form.classList.remove('busy');
        submitter.textContent = btnText;
      }
    });
  }

  /* ---------- selection mode + bulk actions ---------- */
  document.addEventListener('grid:update', e => {
    const t = $('#select-toggle'); if (!t) return;
    const sp = new URLSearchParams(e.detail); sp.delete('page');
    const on = sp.get('select') === '1'; sp.delete('select');
    if (!on) sp.set('select', '1');
    const qs = sp.toString();
    t.href = '/' + (qs ? '?' + qs : '');
  });
  const bulk = $('#bulkform');
  if (bulk) {
    const grid = $('#grid'), selcount = $('#selcount'), all = $('#sel-all');
    const picked = new Set($$('.selbox:checked').map(b => b.value));
    const boxes = () => $$('#grid .selbox');
    const sync = () => {
      boxes().forEach(b => { b.checked = picked.has(b.value); b.closest('.card').classList.toggle('is-picked', b.checked); });
      const n = picked.size;
      selcount.textContent = n ? n + ' selected' : 'Select items';
      $$('button[name=action]', bulk).forEach(b => { b.disabled = n === 0; });
      bulk.classList.toggle('has-picked', n > 0);
      const bs = boxes();
      all.textContent = bs.length && bs.every(b => b.checked) ? 'None' : 'All on page';
    };
    grid.addEventListener('change', e => {
      const b = e.target.closest('.selbox'); if (!b) return;
      if (b.checked) picked.add(b.value); else picked.delete(b.value);
      sync();
    });
    // tapping the photo or the name toggles selection instead of opening the item
    grid.addEventListener('click', e => {
      const a = e.target.closest('.selectable .card-img, .selectable .card-title');
      if (!a) return;
      e.preventDefault();
      const b = $('.selbox', a.closest('.card'));
      b.checked = !b.checked;
      b.dispatchEvent(new Event('change', { bubbles: true }));
    });
    all.addEventListener('click', e => {
      e.preventDefault();
      const bs = boxes(), on = !bs.every(b => b.checked);
      bs.forEach(b => { if (on) picked.add(b.value); else picked.delete(b.value); });
      sync();
    });
    // ids of selected items that are not on screen (other searches) still travel with the form
    bulk.addEventListener('submit', () => {
      $$('input.keep', bulk).forEach(i => i.remove());
      const shown = new Set(boxes().map(b => b.value));
      picked.forEach(id => { if (!shown.has(id)) { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; i.className = 'keep'; bulk.appendChild(i); } });
    });
    document.addEventListener('grid:update', e => {
      const sp = new URLSearchParams(e.detail); sp.delete('page'); sp.delete('select');
      sp.set('select', '1');
      $('input[name=back]', bulk).value = '/?' + sp;
      all.href = '/?' + sp + '&all=1';
      sync();
    });
    const close = $('#bulk-close'); if (close) close.addEventListener('click', () => { $('#bulksheet').open = false; });
    sync();
  }
  /* ---------- barcode scanning (BarcodeDetector where available; otherwise the field is typed) ---------- */
  const scanBtns = $$('button[data-scan]'), dlg = $('#scan-dialog');
  if (scanBtns.length && dlg && 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && typeof dlg.showModal === 'function') {
    const video = $('#scan-video'), msg = $('#scan-msg');
    let stream = null, timer = 0, detector = null;
    const stop = () => {
      clearTimeout(timer); timer = 0;
      if (stream) stream.getTracks().forEach(t => t.stop());
      stream = null; video.srcObject = null;
      if (dlg.open) dlg.close();
    };
    async function scan(btn) {
      const field = $(btn.dataset.scan);
      try {
        if (!detector) detector = new window.BarcodeDetector();
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      } catch {
        stop(); toast('Camera not available – type the barcode instead', true); field.focus(); return;
      }
      msg.textContent = 'Point the camera at a barcode';
      dlg.showModal();
      video.srcObject = stream;
      await video.play().catch(() => {});
      const tick = async () => {
        if (!stream) return;
        try {
          const codes = await detector.detect(video);
          const code = codes.length ? String(codes[0].rawValue || '').replace(/\s+/g, '') : '';
          if (code) {
            stop();
            field.value = code;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            if (btn.hasAttribute('data-scan-submit') && field.form) field.form.requestSubmit(); else field.focus();
            return;
          }
        } catch { /* frame not ready yet */ }
        timer = setTimeout(tick, 200);
      };
      tick();
    }
    scanBtns.forEach(b => { b.hidden = false; b.addEventListener('click', () => scan(b)); });
    $('#scan-cancel').addEventListener('click', () => { const f = $(scanBtns[0].dataset.scan); stop(); f.focus(); });
    dlg.addEventListener('close', stop);
  }
  /* ---------- Control Panel: colour fields, accent presets and live contrast preview ---------- */
  $$('input[type=color][data-color-for]').forEach(pick => {
    const txt = $(pick.dataset.colorFor);
    if (!txt) return;
    pick.addEventListener('input', () => { txt.value = pick.value.toUpperCase(); txt.dispatchEvent(new Event('input', { bubbles: true })); });
    txt.addEventListener('input', () => { const v = normHex(txt.value); if (v) pick.value = v.toLowerCase(); });
  });
  function normHex(v) {
    const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec((v || '').trim());
    if (!m) return null;
    let h = m[1];
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    return '#' + h.toUpperCase();
  }
  const accentIn = $('#s-accent'), preview = $('#accent-preview');
  if (accentIn && preview) {
    // same maths as Accent::variants() (modules/Settings/Branding/Accent.php)
    const rgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
    const hex = c => '#' + c.map(v => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
    const lum = h => { const l = rgb(h).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2]; };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const mix = (h, to, t) => { const a = rgb(h), b = rgb(to); return hex(a.map((v, i) => v + (b[i] - v) * t)); };
    const fit = (h, bgs, to) => { for (let i = 0; i <= 50; i++) { const c = mix(h, to, i / 50); if (Math.min(...bgs.map(b => ratio(c, b))) >= 4.5) return c; } return to; };
    const modes = { light: [['#f5f5f7', '#ffffff'], '#000000', 0.10], dark: [['#111113', '#1c1c20'], '#ffffff', 0.16] };
    const update = () => {
      const v = normHex(accentIn.value);
      if (!v) return;
      const warn = [];
      for (const [m, [bgs, to, soft]] of Object.entries(modes)) {
        const raw = Math.min(...bgs.map(b => ratio(v.toLowerCase(), b)));
        const c = m === 'dark' && v === '#4F46E5' ? '#8b93ff' : fit(v.toLowerCase(), bgs, to);
        const ink = ratio(c, '#ffffff') >= ratio(c, '#0b0b10') ? '#ffffff' : '#0b0b10';
        const pane = $('#pv-' + m);
        pane.style.setProperty('--accent', c);
        pane.style.setProperty('--accent-ink', ink);
        pane.style.setProperty('--accent-soft', 'rgba(' + rgb(c).join(',') + ',' + soft + ')');
        const r = Math.min(...bgs.map(b => ratio(c, b)));
        $('.pv-ratio', pane).textContent = r.toFixed(2) + ':1 ' + (r >= 4.5 ? '✓ AA' : '✗');
        if (c !== v.toLowerCase() && v !== '#4F46E5') warn.push(v + ' is ' + raw.toFixed(2) + ':1 on the ' + m + ' background (WCAG AA needs 4.5:1) – ' + m[0].toUpperCase() + m.slice(1) + ' mode uses ' + c + ' instead.');
      }
      const ul = $('#accent-warn');
      ul.textContent = '';
      warn.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.append(li); });
      ul.hidden = !warn.length;
      $$('.preset').forEach(b => b.classList.toggle('on', b.dataset.accent.toUpperCase() === v));
    };
    accentIn.addEventListener('input', update);
    $$('.preset').forEach(b => b.addEventListener('click', () => {
      accentIn.value = b.dataset.accent.toUpperCase();
      const pick = $('#s-accent-pick'); if (pick) pick.value = b.dataset.accent.toLowerCase();
      update();
    }));
    update();
  }
  /* ---------- photo editor: crop + rotate on a canvas (no libraries; the server applies it with GD) ---------- */
  const stage = $('#pe-stage');
  if (stage && $('#pe-canvas')) {
    const cv = $('#pe-canvas'), ctx = cv.getContext('2d'), img = new Image();
    const f = { rot: $('#pe-rotate'), x: $('#pe-x'), y: $('#pe-y'), w: $('#pe-w'), h: $('#pe-h') };
    const st = { rot: 0, c: { x: 0, y: 0, w: 1, h: 1 }, aspect: 0, drag: null };
    const MIN = 0.05, HANDLE = 22;
    const dims = () => (st.rot % 180 ? [img.naturalHeight, img.naturalWidth] : [img.naturalWidth, img.naturalHeight]);
    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
    const sync = () => {
      const c = st.c, r4 = v => (Math.round(v * 10000) / 100).toString();
      f.rot.value = String(st.rot); f.x.value = r4(c.x); f.y.value = r4(c.y); f.w.value = r4(c.w); f.h.value = r4(c.h);
      const [W, H] = dims();
      $('#pe-info').textContent = Math.round(c.w * W) + ' × ' + Math.round(c.h * H) + ' px' + (st.rot ? ' · rotated ' + st.rot + '°' : '');
    };
    const fitAspect = () => {
      if (!st.aspect) return;
      const [W, H] = dims(), c = st.c, cxm = c.x + c.w / 2, cym = c.y + c.h / 2;
      let w = c.w, h = (c.w * W) / (st.aspect * H);
      if (h > 1) { h = 1; w = (st.aspect * H) / W; }
      if (w > 1) { w = 1; h = W / (st.aspect * H); }
      st.c = { w, h, x: clamp(cxm - w / 2, 0, 1 - w), y: clamp(cym - h / 2, 0, 1 - h) };
    };
    const draw = () => {
      const [W, H] = dims();
      const cssW = stage.clientWidth, scale = Math.min(cssW / W, (window.innerHeight * 0.6) / H);
      const dw = Math.max(1, Math.round(W * scale)), dh = Math.max(1, Math.round(H * scale)), dpr = window.devicePixelRatio || 1;
      cv.style.width = dw + 'px'; cv.style.height = dh + 'px';
      cv.width = Math.round(dw * dpr); cv.height = Math.round(dh * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.save();
      ctx.translate(dw / 2, dh / 2); ctx.rotate(st.rot * Math.PI / 180);
      const iw = st.rot % 180 ? dh : dw, ih = st.rot % 180 ? dw : dh;
      ctx.drawImage(img, -iw / 2, -ih / 2, iw, ih);
      ctx.restore();
      const c = st.c, x = c.x * dw, y = c.y * dh, w = c.w * dw, h = c.h * dh;
      ctx.fillStyle = 'rgba(0,0,0,.55)';
      ctx.beginPath(); ctx.rect(0, 0, dw, dh); ctx.rect(x, y, w, h); ctx.fill('evenodd');
      ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.strokeRect(x + 1, y + 1, w - 2, h - 2);
      ctx.strokeStyle = 'rgba(255,255,255,.5)'; ctx.lineWidth = 1;
      for (let i = 1; i < 3; i++) { ctx.beginPath(); ctx.moveTo(x + w * i / 3, y); ctx.lineTo(x + w * i / 3, y + h); ctx.moveTo(x, y + h * i / 3); ctx.lineTo(x + w, y + h * i / 3); ctx.stroke(); }
      ctx.fillStyle = '#fff';
      for (const [hx, hy] of [[x, y], [x + w, y], [x, y + h], [x + w, y + h]]) { ctx.beginPath(); ctx.arc(hx, hy, 9, 0, 2 * Math.PI); ctx.fill(); }
      sync();
    };
    const pt = e => { const r = cv.getBoundingClientRect(); return { x: clamp((e.clientX - r.left) / r.width, 0, 1), y: clamp((e.clientY - r.top) / r.height, 0, 1), r }; };
    cv.addEventListener('pointerdown', e => {
      const p = pt(e), c = st.c, near = (ax, ay) => Math.abs((ax - p.x) * p.r.width) <= HANDLE && Math.abs((ay - p.y) * p.r.height) <= HANDLE;
      const corners = { nw: [c.x, c.y], ne: [c.x + c.w, c.y], sw: [c.x, c.y + c.h], se: [c.x + c.w, c.y + c.h] };
      let mode = Object.keys(corners).find(k => near(...corners[k]));
      // a fresh (whole-photo) frame: dragging draws a new crop instead of moving nothing
      if (!mode) mode = c.w < 0.999 || c.h < 0.999 ? (p.x > c.x && p.x < c.x + c.w && p.y > c.y && p.y < c.y + c.h ? 'move' : 'new') : 'new';
      st.drag = { mode, p0: p, c0: { ...c } };
      cv.setPointerCapture(e.pointerId);
      e.preventDefault();
    });
    cv.addEventListener('pointermove', e => {
      const d = st.drag;
      if (!d) return;
      const p = pt(e), c0 = d.c0;
      if (d.mode === 'move') {
        st.c = { ...c0, x: clamp(c0.x + p.x - d.p0.x, 0, 1 - c0.w), y: clamp(c0.y + p.y - d.p0.y, 0, 1 - c0.h) };
      } else {
        // the fixed corner stays, the dragged one follows the pointer
        const fx = d.mode === 'new' ? d.p0.x : (d.mode.includes('w') ? c0.x + c0.w : c0.x);
        const fy = d.mode === 'new' ? d.p0.y : (d.mode.includes('n') ? c0.y + c0.h : c0.y);
        let w = Math.max(MIN, Math.abs(p.x - fx)), h = Math.max(MIN, Math.abs(p.y - fy));
        if (st.aspect) { const [W, H] = dims(); h = (w * W) / (st.aspect * H); }
        const x = p.x < fx ? fx - w : fx, y = p.y < fy ? fy - h : fy;
        if (x >= -0.0001 && y >= -0.0001 && x + w <= 1.0001 && y + h <= 1.0001) st.c = { x: clamp(x, 0, 1), y: clamp(y, 0, 1), w: Math.min(w, 1), h: Math.min(h, 1) };
      }
      draw();
    });
    const end = () => { st.drag = null; };
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
    cv.addEventListener('keydown', e => {
      const k = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
      if (!k) return;
      e.preventDefault();
      const s = 0.01, c = st.c;
      if (e.shiftKey) st.c = { ...c, w: clamp(c.w + k[0] * s, MIN, 1 - c.x), h: clamp(c.h + k[1] * s, MIN, 1 - c.y) };
      else st.c = { ...c, x: clamp(c.x + k[0] * s, 0, 1 - c.w), y: clamp(c.y + k[1] * s, 0, 1 - c.h) };
      fitAspect(); draw();
    });
    const rotate = dir => {
      const c = st.c;
      // rotate the crop rectangle with the photo
      st.c = dir > 0 ? { x: 1 - c.y - c.h, y: c.x, w: c.h, h: c.w } : { x: c.y, y: 1 - c.x - c.w, w: c.h, h: c.w };
      st.rot = (st.rot + (dir > 0 ? 90 : 270)) % 360;
      fitAspect(); draw();
    };
    $('#pe-rr').addEventListener('click', () => rotate(1));
    $('#pe-rl').addEventListener('click', () => rotate(-1));
    $('#pe-reset').addEventListener('click', () => { st.rot = 0; st.c = { x: 0, y: 0, w: 1, h: 1 }; fitAspect(); draw(); });
    $$('#pe-aspect [data-aspect]').forEach(b => b.addEventListener('click', () => {
      $$('#pe-aspect [data-aspect]').forEach(o => { o.classList.toggle('on', o === b); o.setAttribute('aria-checked', String(o === b)); });
      st.aspect = parseFloat(b.dataset.aspect) || 0;
      fitAspect(); draw();
    }));
    // typing exact values updates the preview
    Object.values(f).forEach(el => el.addEventListener('change', () => {
      const n = el2 => clamp((parseFloat(el2.value) || 0) / 100, 0, 1);
      st.rot = [0, 90, 180, 270].includes(+f.rot.value) ? +f.rot.value : 0;
      const w = Math.max(MIN, n(f.w)), h = Math.max(MIN, n(f.h));
      st.c = { w, h, x: clamp(n(f.x), 0, 1 - w), y: clamp(n(f.y), 0, 1 - h) };
      draw();
    }));
    $('#pe-form').addEventListener('submit', () => { sync(); $('#pe-save').disabled = true; });
    img.onload = () => { stage.classList.add('ready'); $('#pe-tools').hidden = false; draw(); };
    img.src = stage.dataset.src;
    let rt; window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(draw, 100); });
  }

  /* ---------- photo order: drag the photos in the item's photo box ---------- */
  const pml = $('#pm-list');
  if (pml && pml.dataset.order) {
    let dragEl = null;
    const save = async () => {
      const ids = $$('.pm', pml).map(li => li.dataset.id);
      if (ids.join(',') === pml.dataset.before) return;
      try {
        await post(pml.dataset.order, { ids: ids.join(',') });
        location.href = location.pathname + '?p=0&reordered=1#photos';
      } catch (err) { toast(err.message, true); }
    };
    // listen on the document: moving the <li> in the DOM would drop a pointer capture on the handle
    const move = e => {
      if (!dragEl) return;
      const over = document.elementFromPoint(e.clientX, e.clientY);
      const li = over && over.closest ? over.closest('.pm') : null;
      if (!li || li === dragEl || li.parentNode !== pml) return;
      const r = li.getBoundingClientRect(), after = (e.clientX - r.left) > r.width / 2;
      const ref = after ? li.nextSibling : li;
      if (ref !== dragEl && ref !== dragEl.nextSibling) pml.insertBefore(dragEl, ref);
    };
    const done = () => {
      if (!dragEl) return;
      dragEl.classList.remove('dragging'); dragEl = null;
      document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', done); document.removeEventListener('pointercancel', done);
      save();
    };
    $$('.pm-drag', pml).forEach(h => {
      h.addEventListener('pointerdown', e => {
        dragEl = h.closest('.pm'); dragEl.classList.add('dragging');
        pml.dataset.before = $$('.pm', pml).map(li => li.dataset.id).join(',');
        document.addEventListener('pointermove', move); document.addEventListener('pointerup', done); document.addEventListener('pointercancel', done);
        e.preventDefault();
      });
    });
  }

  /* Settings → AI autofill: the test buttons are plain form submits (they work without JS); while the server
     runs the test, say so, so a slow photo test does not look like nothing happened. */
  const afRes = $('#af-test-result');
  if (afRes) {
    afRes.closest('form').addEventListener('submit', e => {
      const b = e.submitter;
      if (!b || (b.id !== 'af-test' && b.id !== 'af-test-photo')) return;
      const to = $('#s-autofill_timeout');
      afRes.innerHTML = '<p class="af-status af-testing" id="af-testing"></p>';
      $('#af-testing').textContent = b.id === 'af-test-photo'
        ? 'Sending the sample photo… this can take up to ' + ((to && to.value) || '50') + ' s.'
        : 'Testing the connection…';
    });
  }
})();
