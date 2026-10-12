/* Apotheke – vanilla JS, progressive enhancement (everything works without it). */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const csrf = () => ($('meta[name=csrf]') || {}).content || '';
  const MAX_SIDE = 1600, QUALITY = 0.8;

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
    if (['saved', 'deleted', 'done'].some(k => u.searchParams.has(k))) {
      ['saved', 'deleted', 'done'].forEach(k => u.searchParams.delete(k));
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

  /* ---------- photo manager: add photos (downscaled on the device, uploaded one by one) ---------- */
  const padd = $('#photoadd');
  if (padd) {
    const st = $('#photoadd-status');
    padd.querySelectorAll('input[type=file]').forEach(inp => inp.addEventListener('change', async () => {
      const files = [...(inp.files || [])];
      if (!files.length) return;
      st.hidden = false;
      try {
        for (let k = 0; k < files.length; k++) {
          st.textContent = 'Uploading ' + (k + 1) + ' of ' + files.length + '…';
          let blob = files[k];
          try { blob = await shrinkImage(files[k]); } catch (_) { /* server validates */ }
          const fd = new FormData(); fd.append('photos[]', blob, 'photo.jpg');
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
    render();
  }

  /* ---------- restore: show the chosen file name ---------- */
  const rf = $('#restore-file');
  if (rf) rf.addEventListener('change', () => { $('#restore-name').textContent = rf.files[0] ? rf.files[0].name : 'Choose backup file…'; });

  /* ---------- print button (labels) ---------- */
  document.addEventListener('click', e => { if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); } });

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
    applyRange();
    statsBox.addEventListener('toggle', () => {
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
      for (const [k, v] of [...sp]) if (!v || (k === 'sort' && v === 'recent')) sp.delete(k);
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
        count.textContent = j.count;
        empty.hidden = j.total > 0;
        const filtered = !!(q.value.trim() || $('#loc').value || $('#cat').value || ($('#tag') && $('#tag').value) || ($('#stock') && $('#stock').value));
        $('#empty-title').textContent = filtered ? 'Nothing matches.' : 'Your catalog is empty.';
        $('#empty-sub').textContent = filtered ? 'Try another word or clear the filters.' : 'Snap a photo of something, give it a name and a place. That’s it.';
        $('#empty-add').hidden = filtered; $('#empty-clear').hidden = !filtered;
        if (j.chips !== undefined) { achips.innerHTML = j.chips; fcount.textContent = j.nfilters; fcount.hidden = !j.nfilters; }
        more.hidden = !j.more;
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
      if ((which === 'all' || which === 'stock') && $('#stock')) $('#stock').value = '';
      if (which === 'all' || which === 'sort') $('#sort').value = 'recent';
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

    // Tapping a location/category chip (or an Overview bar) filters in place.
    document.addEventListener('click', e => {
      const lt = e.target.closest('#stats a[data-stock]');
      if (lt && $('#stock')) { e.preventDefault(); $('#stock').value = 'low'; q.value = ''; load(false); return; }
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
  async function shrinkImage(file) {
    const src = await decodeImage(file);
    const w = src.width, h = src.height, s = Math.min(1, MAX_SIDE / Math.max(w, h));
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

  /* ---------- add/edit form: photo capture, client-side downscale, async upload ---------- */
  const form = $('#itemform');
  if (form) {
    const preview = $('#preview'), status = $('#pstatus'), photo = $('#photo'), err = $('#err');
    const inputs = form.querySelectorAll('input[type=file]');
    let blob = null, working = null, objUrl = null;

    const showErr = m => { err.textContent = m; err.hidden = !m; if (m) err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };

    const shrink = shrinkImage;

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
      status.hidden = false; status.textContent = 'Optimizing…';
      blob = null;
      working = shrink(file).then(b => {
        blob = b.size < file.size || file.type !== 'image/jpeg' ? b : file;
        status.textContent = Math.max(1, Math.round(blob.size / 1024)) + ' KB';
      }).catch(() => {
        blob = file; // let the server try (it validates)
        status.textContent = Math.round(file.size / 1024) + ' KB';
      });
      const name = form.elements.name;
      if (name && !name.value) setTimeout(() => name.focus(), 50);
    }));

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
        if (blob) fd.append('photo', blob, 'photo.jpg');
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
})();
