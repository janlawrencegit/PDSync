// PDS editor: renders the schema, autosaves on this device and on the server, and drives the live preview and exports.
const S = window.SCHEMA;
const DRAFT = 'pds-draft';
const CURRENT = 'pds-current';
const IMAGE_KEYS = ['signature', 'photo', 'thumbmark'];

let state = { fields: {}, tables: {} };
// After sign-in, #open=id names the PDS just moved into the account; otherwise the last one opened on this PC.
const openId = new URLSearchParams(location.hash.slice(1)).get('open');
// The PDS on the server: its id and the "updated" time of the copy this page is based on.
let auth = {};
let step = 0;

const isIso = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v || '');
const isNA = (v) => /^n\/?a$/i.test(String(v || '').trim());
const filled = (v) => String(v ?? '').trim() !== '';
// Converts d/m/yyyy (also with - or .) to stored yyyy-mm-dd; other text like N/A or PRESENT is kept as typed.
const toIso = (v) => {
  const m = v.trim().match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
  if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  return /^present$/i.test(v.trim()) ? 'PRESENT' : v.trim();
};
const toDmy = (v) => (v || '').replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1');

// Formats a numeric amount as pesos (38413 -> ₱38,413.00); other text like N/A is kept.
const toPeso = (v) => {
  const n = (v || '').replace(/[₱,\s]|PHP/gi, '');
  return n !== '' && !isNaN(n) ? `₱${Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : (v || '').trim();
};


// Save status next to the file name: idle, unsaved, saving, saved, offline or error.
function setSave(kind, text) {
  const p = $('#save-pill');
  p.dataset.state = kind;
  p.querySelector('.txt').textContent = text || {
    idle: 'Not saved yet', unsaved: 'Unsaved changes', saving: 'Saving…', saved: 'Saved',
    offline: 'Offline · kept on this device', error: 'Not saved · Retry', local: 'On this device only',
  }[kind];
  p.title = kind === 'saved' ? `Saved ${new Date().toLocaleTimeString()}` : p.querySelector('.txt').textContent;
}

// Opens the cropper on an image; resolves with the cropped region on a canvas, or null when cancelled.
// With an aspect (width / height) the crop box keeps that shape; otherwise it is free.
function cropImage(bmp, aspect, guide = false) {
  return new Promise((resolve) => {
    const dlg = $('#dlg-crop'), cv = $('#crop-canvas'), g = cv.getContext('2d');
    const k = Math.min(760 / bmp.width, 460 / bmp.height);
    cv.width = Math.round(bmp.width * k);
    cv.height = Math.round(bmp.height * k);
    const W = cv.width, H = cv.height, HANDLE = 10;
    let r;
    if (aspect) {
      const w = Math.min(W, H * aspect) * 0.9;
      r = { x: (W - w) / 2, y: (H - w / aspect) / 2, w, h: w / aspect };
    } else r = { x: W * 0.05, y: H * 0.05, w: W * 0.9, h: H * 0.9 };
    const draw = () => {
      g.drawImage(bmp, 0, 0, W, H);
      g.fillStyle = 'rgba(0,0,0,.5)';
      g.beginPath();
      g.rect(0, 0, W, H);
      g.rect(r.x, r.y, r.w, r.h);
      g.fill('evenodd');
      g.strokeStyle = '#fff';
      g.lineWidth = 2;
      g.strokeRect(r.x, r.y, r.w, r.h);
      g.fillStyle = '#1f4fa3';
      [[r.x, r.y], [r.x + r.w, r.y], [r.x, r.y + r.h], [r.x + r.w, r.y + r.h]].forEach(([x, y]) => g.fillRect(x - HANDLE / 2, y - HANDLE / 2, HANDLE, HANDLE));
      if (guide) {
        // Passport framing: the face fills the oval, eyes near the line, with room above the head and the shoulders below.
        g.save();
        g.setLineDash([6, 5]);
        g.strokeStyle = 'rgba(255,255,255,.9)';
        g.lineWidth = 1.5;
        g.beginPath();
        g.ellipse(r.x + r.w / 2, r.y + r.h * 0.43, r.w * 0.3, r.h * 0.29, 0, 0, Math.PI * 2);
        g.stroke();
        g.beginPath();
        g.moveTo(r.x + r.w * 0.2, r.y + r.h * 0.4);
        g.lineTo(r.x + r.w * 0.8, r.y + r.h * 0.4);
        g.stroke();
        g.restore();
      }
    };
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
    // Pointer position in canvas pixels, since CSS may shrink the canvas on small screens.
    const at = (e) => [e.offsetX * (W / cv.clientWidth), e.offsetY * (H / cv.clientHeight)];
    let drag = null;
    cv.onpointerdown = (e) => {
      const p = at(e);
      const corners = [[r.x, r.y], [r.x + r.w, r.y], [r.x, r.y + r.h], [r.x + r.w, r.y + r.h]];
      const hit = corners.findIndex(([x, y]) => Math.abs(p[0] - x) < 12 && Math.abs(p[1] - y) < 12);
      if (hit >= 0) drag = { mode: 'resize', anchor: corners[3 - hit] };
      else if (p[0] > r.x && p[0] < r.x + r.w && p[1] > r.y && p[1] < r.y + r.h) drag = { mode: 'move', dx: p[0] - r.x, dy: p[1] - r.y };
      else if (!aspect) drag = { mode: 'resize', anchor: p };
      if (drag) cv.setPointerCapture(e.pointerId);
    };
    cv.onpointermove = (e) => {
      const [ox, oy] = at(e), px = clamp(ox, 0, W), py = clamp(oy, 0, H);
      cv.style.cursor = drag?.mode === 'move' ? 'grabbing' : 'crosshair';
      if (!drag) return;
      if (drag.mode === 'move') {
        r.x = clamp(px - drag.dx, 0, W - r.w);
        r.y = clamp(py - drag.dy, 0, H - r.h);
      } else {
        const [ax, ay] = drag.anchor;
        let w = Math.abs(px - ax), h = Math.abs(py - ay);
        if (aspect) {
          // Keeps the shape and stops at the image edge on the side being dragged.
          const sx = px < ax ? -1 : 1, sy = py < ay ? -1 : 1;
          const maxW = sx < 0 ? ax : W - ax, maxH = sy < 0 ? ay : H - ay;
          w = Math.min(Math.max(w, h * aspect), maxW, maxH * aspect);
          h = w / aspect;
          r = { x: sx < 0 ? ax - w : ax, y: sy < 0 ? ay - h : ay, w, h };
        } else r = { x: Math.min(px, ax), y: Math.min(py, ay), w, h };
      }
      draw();
    };
    cv.onpointerup = () => {
      drag = null;
      if (r.w < 10 || r.h < 10) r = { x: 0, y: 0, w: W, h: H };
      draw();
    };
    dlg.onclose = () => {
      if (dlg.returnValue !== 'use') return resolve(null);
      const out = el('canvas', { width: Math.round(r.w / k), height: Math.round(r.h / k) });
      out.getContext('2d').drawImage(bmp, r.x / k, r.y / k, r.w / k, r.h / k, 0, 0, out.width, out.height);
      resolve(out);
    };
    dlg.returnValue = '';
    $('#crop-tip').textContent = guide ? 'Fit your face inside the oval, with your eyes near the line and your shoulders showing.' : 'Drag the box to move it, or drag a corner to resize.';
    draw();
    dlg.showModal();
  });
}

// Checks a passport photo in the browser (nothing is uploaded for this): print sharpness, a plain light background,
// lighting of the face area, and blur. Resolves with [{ tone: 'ok'|'warn'|'bad', text }].
function photoCheck(src) {
  return new Promise((resolve) => {
    const im = new Image();
    im.onload = () => {
      const W = 200, H = Math.round((200 * im.naturalHeight) / im.naturalWidth);
      const c = el('canvas', { width: W, height: H }), g = c.getContext('2d', { willReadFrequently: true });
      g.drawImage(im, 0, 0, W, H);
      const d = g.getImageData(0, 0, W, H).data;
      const lum = (x, y) => { const i = (y * W + x) * 4; return 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; };
      const stats = (pts) => { const v = pts.map(([x, y]) => lum(x, y)); const m = v.reduce((a, b) => a + b, 0) / v.length; return [m, Math.sqrt(v.reduce((a, b) => a + (b - m) ** 2, 0) / v.length)]; };
      const bg = [], face = [];
      for (let y = 0; y < H; y += 2) for (let x = 0; x < W; x += 2) {
        if (y < H * 0.12 || (y < H * 0.45 && (x < W * 0.1 || x > W * 0.9))) bg.push([x, y]);
        if (x > W * 0.3 && x < W * 0.7 && y > H * 0.22 && y < H * 0.62) face.push([x, y]);
      }
      const [bgL, bgS] = stats(bg), [fL] = stats(face);
      let lap = 0, n = 0;
      for (let y = Math.round(H * 0.2); y < H * 0.7; y++) for (let x = Math.round(W * 0.25); x < W * 0.75; x++) {
        const v = 4 * lum(x, y) - lum(x - 1, y) - lum(x + 1, y) - lum(x, y - 1) - lum(x, y + 1);
        lap += v * v; n++;
      }
      const sharp = lap / n, px = im.naturalWidth, out = [];
      out.push(px >= 413 ? { tone: 'ok', text: 'Sharp enough to print at 3.5 × 4.5 cm' }
        : px >= 260 ? { tone: 'warn', text: 'Small image: it may print a little soft. Use a larger photo if you have one.' }
          : { tone: 'bad', text: 'Very small image: it will print blurry. Use a larger photo.' });
      out.push(bgS > 30 ? { tone: 'warn', text: 'The background looks busy. Stand in front of a plain wall.' }
        : bgL < 150 ? { tone: 'warn', text: 'The background is dark. A plain white or light background is usual for ID photos.' }
          : { tone: 'ok', text: 'Plain, light background' });
      out.push(fL < 60 ? { tone: 'warn', text: 'The face looks too dark. Use more light, facing a window.' }
        : fL > 215 ? { tone: 'warn', text: 'The face looks washed out. Avoid direct flash or strong light.' }
          : { tone: 'ok', text: 'Face is well lit' });
      if (sharp < 25) out.push({ tone: 'warn', text: 'The photo looks blurry. Hold the camera steady or use a clearer copy.' });
      resolve(out);
    };
    im.onerror = () => resolve([]);
    im.src = src;
  });
}
function photoReport(list, src) {
  list.replaceChildren();
  if (!src) return;
  photoCheck(src).then((rows) => list.replaceChildren(
    ...rows.map((r) => el('li', { className: r.tone }, icon(r.tone === 'ok' ? ICON.check : ICON.more, 'size-3.5'), r.text)),
    el('li', { className: 'info' }, icon(ICON.calendar, 'size-3.5'), 'Use a photo taken within the last 6 months.')));
}

// Which signature the form accepts, from what the Revised 2026 form prints in its signature boxes.
const signatureGuide = () => el('details', { className: 'sig-guide' },
  el('summary', {}, 'Which signature can I use?'),
  el('ul', {},
    el('li', {}, el('b', {}, 'E-signature: '), 'the Revised 2026 PDS prints "e-signature/digital certificate" in its signature boxes, so a clear image of your signature is accepted there.'),
    el('li', {}, el('b', {}, 'Digital certificate: '), 'if you have a PNPKI certificate from DICT, export the PDF here, then sign it with your certificate (for example in Adobe Acrobat Reader).'),
    el('li', {}, el('b', {}, 'Handwritten: '), 'leave this empty and sign the printout with a pen. This is always accepted.'),
    el('li', {}, el('b', {}, 'Oath box: '), 'the person administering the oath signs their own box; the form says an e-signature or digital certificate is allowed there except for a notary public.'),
    el('li', { className: 'opacity-70' }, 'When unsure, ask the HR office receiving your PDS which one they prefer.')));


// Shrinks a cropped image; "clean" makes near-white paper transparent and trims margins (signature, thumbmark).
function encodeImage(src, clean) {
  const s = Math.min(1, 700 / src.width, 700 / src.height);
  const c = el('canvas', { width: Math.max(1, Math.round(src.width * s)), height: Math.max(1, Math.round(src.height * s)) });
  const g = c.getContext('2d');
  g.drawImage(src, 0, 0, c.width, c.height);
  if (!clean) return c.toDataURL('image/jpeg', 0.88);
  const img = g.getImageData(0, 0, c.width, c.height), d = img.data;
  let [x0, y0, x1, y1] = [c.width, c.height, -1, -1];
  for (let i = 0; i < d.length; i += 4) {
    if (d[i] > 220 && d[i + 1] > 220 && d[i + 2] > 220) d[i + 3] = 0;
    if (d[i + 3] > 30) {
      const x = (i / 4) % c.width, y = Math.floor(i / 4 / c.width);
      [x0, y0, x1, y1] = [Math.min(x0, x), Math.min(y0, y), Math.max(x1, x), Math.max(y1, y)];
    }
  }
  if (x1 < 0) throw new Error('The image looks empty after removing the white background.');
  g.putImageData(img, 0, 0);
  const out = el('canvas', { width: x1 - x0 + 1, height: y1 - y0 + 1 });
  out.getContext('2d').drawImage(c, x0, y0, out.width, out.height, 0, 0, out.width, out.height);
  return out.toDataURL('image/png');
}

// Signature pad: resolves with a trimmed transparent PNG, or null when cancelled or left empty.
function drawSignature() {
  return new Promise((resolve) => {
    const dlg = $('#dlg-sign'), cv = $('#sign-canvas'), g = cv.getContext('2d');
    let inked = false, last = null;
    const clear = () => {
      g.clearRect(0, 0, cv.width, cv.height);
      inked = false;
    };
    const at = (e) => {
      const r = cv.getBoundingClientRect();
      return [(e.clientX - r.left) * (cv.width / r.width), (e.clientY - r.top) * (cv.height / r.height)];
    };
    g.lineCap = g.lineJoin = 'round';
    g.strokeStyle = '#111827';
    g.lineWidth = 6;
    clear();
    cv.onpointerdown = (e) => {
      cv.setPointerCapture(e.pointerId);
      last = at(e);
      g.beginPath();
      g.arc(last[0], last[1], 3, 0, Math.PI * 2);
      g.fillStyle = g.strokeStyle;
      g.fill();
      inked = true;
    };
    cv.onpointermove = (e) => {
      if (!last) return;
      const p = at(e), mid = [(last[0] + p[0]) / 2, (last[1] + p[1]) / 2];
      g.beginPath();
      g.moveTo(last[0], last[1]);
      g.quadraticCurveTo(last[0], last[1], mid[0], mid[1]);
      g.lineTo(p[0], p[1]);
      g.stroke();
      last = p;
    };
    cv.onpointerup = cv.onpointercancel = () => (last = null);
    $('#sign-clear').onclick = clear;
    dlg.onclose = () => {
      if (dlg.returnValue !== 'use') return resolve(null);
      if (!inked) {
        toast('The signature pad was empty.', { type: 'warning' });
        return resolve(null);
      }
      resolve(encodeImage(cv, true));
    };
    dlg.returnValue = '';
    dlg.showModal();
  });
}

// One shared calendar popover for every date field: the Cally web component (public/vendor), styled by daisyUI's .cally.
// It sits in the top layer, so it is placed next to its field by hand and closes on outside click, Escape or page scroll.
const datePicker = (() => {
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const arrow = (slot, d) => `<svg slot="${slot}" aria-label="${slot === 'previous' ? 'Previous month' : 'Next month'}" class="size-4 fill-none stroke-current stroke-2" viewBox="0 0 24 24"><path d="${d}"/></svg>`;
  const box = el('div', { className: 'cal-pop' });
  box.setAttribute('popover', 'auto');
  box.innerHTML = `<calendar-date class="cally" first-day-of-week="0" locale="en-US" show-outside-days>
      ${arrow('previous', 'M15 18l-6-6 6-6')}${arrow('next', 'M9 6l6 6-6 6')}
      <calendar-select-month slot="heading"></calendar-select-month>
      <calendar-select-year slot="heading" max-years="240"></calendar-select-year>
      <calendar-month></calendar-month>
    </calendar-date>
    <div class="cal-foot">
      <button type="button" class="btn btn-ghost btn-xs" data-act="clear">Clear</button>
      <span class="flex gap-1">
        <button type="button" class="btn btn-ghost btn-xs text-primary" data-act="present">Present</button>
        <button type="button" class="btn btn-ghost btn-xs text-primary" data-act="today">Today</button>
      </span>
    </div>`;
  document.body.append(box);
  const cal = box.querySelector('calendar-date');
  const presentBtn = box.querySelector('[data-act=present]');
  let owner = null, done = null, closedAt = 0, closedOwner = null;

  const close = () => box.matches(':popover-open') && box.hidePopover();
  const place = () => {
    const r = owner.getBoundingClientRect(), w = box.offsetWidth, h = box.offsetHeight;
    const below = innerHeight - r.bottom >= h + 8 || r.top < h + 8;
    box.style.left = `${Math.max(8, Math.min(r.right - w, innerWidth - w - 8))}px`;
    box.style.top = `${below ? r.bottom + 6 : r.top - h - 6}px`;
  };
  const pick = (v) => {
    const cb = done;
    close();
    cb?.(v);
  };

  cal.addEventListener('change', () => pick(cal.value));
  box.querySelector('[data-act=today]').onclick = () => pick(iso(new Date()));
  box.querySelector('[data-act=clear]').onclick = () => pick('');
  presentBtn.onclick = () => pick('PRESENT');
  box.addEventListener('toggle', (e) => {
    if (e.newState === 'open') return;
    closedAt = performance.now();
    closedOwner = owner;
    owner = done = null;
  });
  // A scroll outside the popover would leave it floating away from its field.
  addEventListener('scroll', (e) => owner && !box.contains(e.target) && close(), { capture: true, passive: true });
  addEventListener('resize', close);

  return {
    // Opens below (or above) the field; a second click on the same field's button closes it.
    toggle(anchor, value, { birth = false, present = false } = {}, onPick) {
      if (closedOwner === anchor && performance.now() - closedAt < 300) return;
      const today = new Date();
      const max = new Date(today.getFullYear() + (birth ? 0 : 30), today.getMonth(), today.getDate());
      cal.min = '1900-01-01';
      cal.max = iso(birth ? today : max);
      cal.value = isIso(value) ? value : '';
      // Birthdays open around a typical adult age instead of this month, so fewer clicks are needed.
      cal.focusedDate = isIso(value) ? value : birth ? `${today.getFullYear() - 30}-01-01` : iso(today);
      presentBtn.hidden = !present;
      owner = anchor;
      done = onPick;
      box.showPopover();
      place();
      requestAnimationFrame(() => cal.focus?.());
    },
  };
})();

// Philippine places from the PSA's PSGC list (public/psgc, built by tools/build-psgc.py): provinces, their cities and
// municipalities, and barangays per province, loaded only when an address field is used.
const places = {
  list: null,
  loading: null,
  brgy: {},
  load() {
    this.loading ??= fetch('psgc/places.json').then((r) => r.json()).then((l) => (this.list = l)).catch(() => (this.loading = null));
    return this.loading;
  },
  prov(name) {
    const n = String(name || '').trim().toLowerCase();
    return n ? this.list?.find((p) => p.n.toLowerCase() === n) : null;
  },
  // City or municipality by name, inside the given province when it is known.
  city(name, prov) {
    const n = String(name || '').trim().toLowerCase();
    if (!n || !this.list) return null;
    for (const p of prov ? [prov] : this.list) {
      const c = p.c.find((x) => x[0].toLowerCase() === n || x[0].toLowerCase() === `city of ${n}`);
      if (c) return { prov: p, name: c[0], code: c[1] };
    }
    return null;
  },
  loadBrgy(pid) {
    this.brgy[pid] ??= fetch(`psgc/b/${pid}.json`).then((r) => r.json()).catch(() => { delete this.brgy[pid]; return {}; });
    return this.brgy[pid];
  },
};
const placeCache = {};
// Suggestions for a province, city or barangay field, narrowed by the fields above it.
function placeOptions(def) {
  const pre = def.k.split('_')[0];
  if (!places.list) return [];
  const prov = places.prov(state.fields[`${pre}_prov`]);
  if (def.place === 'prov') return places.list.map((p) => p.n);
  if (def.place === 'city') return prov ? prov.c.map((c) => c[0]) : [...new Set(places.list.flatMap((p) => p.c.map((c) => c[0])))].sort();
  const city = places.city(state.fields[`${pre}_city`], prov);
  if (!city) return [];
  const got = placeCache[city.prov.id];
  if (!got) {
    places.loadBrgy(city.prov.id).then((b) => {
      placeCache[city.prov.id] = b;
      document.activeElement?._comboShow?.();
    });
    return [];
  }
  return got[city.code] || [];
}
// After a city is chosen, an empty province is filled from it.
// Gentle checks for values that look wrong: shown under the field and in "Check my PDS", never blocking a save.
const digits = (v) => String(v || '').replace(/\D/g, '');
const ID_DIGITS = {
  philhealth: [[12], 'A PhilHealth number has 12 digits.'],
  pagibig: [[12], 'A Pag-IBIG MID number has 12 digits.'],
  umid: [[12], 'A UMID CRN has 12 digits.'],
  psn: [[16], 'A PhilSys Card Number has 16 digits.'],
  tin: [[9, 12, 14], 'A TIN has 9 digits, plus a 3- or 5-digit branch code if any.'],
};
// ---- Checks across work experience entries -------------------------------------------------------------------------
// A position still marked Present after a later one started has usually ended (a promotion or a new job), and a
// position that ends on or after the next one's start date overlaps it. Concurrent jobs exist, so these are notes.
const isoToday = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const dayBefore = (iso) => { const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() - 1); return d.toISOString().slice(0, 10); };
const posName = (r) => (filled(r.position) && !isNA(r.position) ? `"${r.position}"` : 'a later position');
function workConflict(rows, row, key) {
  if (!row || !isIso(row.from)) return null;
  // A start date in the future is its own mistake (flagged on that entry), so it does not make others look ended.
  const later = rows.filter((r) => r !== row && isIso(r.from) && r.from > row.from && r.from <= isoToday()).sort((a, b) => a.from.localeCompare(b.from));
  const next = later[0];
  if (!next) return null;
  if (key === 'to' && row.to === 'PRESENT') {
    return { msg: `Still marked Present, but ${posName(next)} started on ${toDmy(next.from)}. This position probably ended on ${toDmy(dayBefore(next.from))}.`, fix: dayBefore(next.from) };
  }
  if (key === 'to' && isIso(row.to) && row.to >= next.from) {
    return { msg: `Ends on or after the start of ${posName(next)} (${toDmy(next.from)}). If it ended when that one began, the end date is ${toDmy(dayBefore(next.from))}.`, fix: dayBefore(next.from) };
  }
  return null;
}

function check(def, v, ctx = {}) {
  v = String(v ?? '').trim();
  if (!v || isNA(v)) return '';
  const k = ctx.table ? `${ctx.table}.${def.k}` : def.k;
  if (def.t === 'date') {
    const across = ctx.table === 'work' && ctx.rows ? workConflict(ctx.rows, ctx.row, def.k) : null;
    if (across) return across.msg;
    if (v === 'PRESENT' && def.present) return '';
    if (!isIso(v)) return 'Use dd/mm/yyyy, for example 15/01/1992.';
    const d = new Date(v), now = new Date();
    if (def.k === 'dob' && !ctx.table) {
      if (d > now) return 'This date is in the future.';
      if (now.getFullYear() - d.getFullYear() < 15) return 'Check the year of birth.';
    }
    if (ctx.row && def.k === 'to' && isIso(ctx.row.from) && v < ctx.row.from) return '"To" is earlier than "From".';
    if (d.getFullYear() < 1900) return 'Check the year.';
    // Validity dates of licenses may lie ahead; the dates of things that happened may not.
    if (ctx.table && def.k !== 'valid_until' && v > isoToday()) return 'This date is in the future.';
    return '';
  }
  if (ctx.table === 'education' && ['from', 'to', 'year_grad'].includes(def.k)) {
    if (!/^\d{4}$/.test(v)) return 'Use a 4-digit year, for example 2014.';
    if (def.k === 'to' && /^\d{4}$/.test(ctx.row.from || '') && v < ctx.row.from) return '"To" is earlier than "From".';
    return '';
  }
  if (k === 'height') {
    const n = parseFloat(v.replace(',', '.'));
    if (isNaN(n)) return 'Write the height in meters, for example 1.65.';
    if (n > 3) return `Height is in meters: ${n} cm would be ${(n / 100).toFixed(2)}.`;
    if (n < 0.5) return 'Check the height.';
    return '';
  }
  if (k === 'weight') {
    const n = parseFloat(v.replace(',', '.'));
    if (isNaN(n)) return 'Write the weight in kilograms, for example 58.';
    return n < 2 || n > 300 ? 'Check the weight.' : '';
  }
  if (ID_DIGITS[k]) return ID_DIGITS[k][0].includes(digits(v).length) ? '' : ID_DIGITS[k][1];
  if (k === 'mobile') {
    const d = digits(v);
    return (d.length === 11 && d.startsWith('09')) || (d.length === 12 && d.startsWith('639')) ? '' : 'A mobile number has 11 digits, for example 0917 123 4567.';
  }
  if (k === 'email') return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'This does not look like an email address.';
  if (k === 'res_zip' || k === 'perm_zip') return /^\d{4}$/.test(v) ? '' : 'A ZIP code has 4 digits.';
  return '';
}

let fid = 0;
// Builds the control for one field; the returned node carries ._ctrl, the element that holds the typed value.
function input(def, value, onChange) {
  const t = def.t || 'text';
  let n;
  if (t === 'date') {
    const text = el('input', { type: 'text', className: 'input join-item w-full', placeholder: def.present ? 'dd/mm/yyyy or PRESENT' : 'dd/mm/yyyy', value: toDmy(value), autocomplete: 'off' });
    const wrap = el('span', { className: 'join w-full' }, text);
    const btn = el('button', { type: 'button', className: 'btn join-item px-3', title: 'Pick a date', ariaLabel: 'Pick a date', onclick: (e) => {
      e.preventDefault();
      datePicker.toggle(wrap, toIso(text.value), { birth: def.k === 'dob', present: !!def.present }, (iso) => {
        text.value = toDmy(iso);
        onChange(iso);
        text.dispatchEvent(new Event('change', { bubbles: true }));
        text.focus();
      });
    } }, icon(ICON.calendar));
    wrap.append(btn);
    text.addEventListener('input', () => onChange(toIso(text.value)));
    text.addEventListener('blur', () => (text.value = toDmy(toIso(text.value))));
    text.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' && e.altKey) btn.click();
    });
    wrap._ctrl = text;
    return wrap;
  }
  if (t === 'image') {
    const img = el('img', { alt: def.l, hidden: !value });
    if (value) img.src = value;
    const file = el('input', { type: 'file', accept: 'image/png,image/jpeg', hidden: true });
    const rm = el('button', { type: 'button', textContent: 'Remove', className: 'btn btn-ghost btn-sm text-error', hidden: !value });
    const up = el('button', { type: 'button', className: 'btn btn-sm', textContent: value ? 'Change' : 'Upload (PNG/JPG)' });
    const checks = def.k === 'photo' ? el('ul', { className: 'photo-checks' }) : null;
    const set = (v) => {
      img.src = v;
      img.hidden = rm.hidden = !v;
      up.textContent = v ? 'Change' : 'Upload (PNG/JPG)';
      onChange(v);
      refreshPreview();
      if (checks) photoReport(checks, v);
    };
    if (checks && value) photoReport(checks, value);
    up.onclick = (e) => { e.preventDefault(); file.click(); };
    rm.onclick = (e) => { e.preventDefault(); set(''); };
    file.onchange = async () => {
      try {
        const cropped = file.files[0] && (await cropImage(await createImageBitmap(file.files[0]), def.aspect, def.k === 'photo'));
        if (cropped) set(encodeImage(cropped, def.clean));
      } catch (err) {
        toast(err.message || 'This image could not be read.', { type: 'error' });
      }
      file.value = '';
    };
    const draw = def.k === 'signature' && el('button', { type: 'button', className: 'btn btn-sm', onclick: async (e) => {
      e.preventDefault();
      const png = await drawSignature();
      if (png) set(png);
    } }, icon(ICON.pen), 'Draw');
    const box = el('div', { className: 'img-box' }, img);
    if (def.aspect) Object.assign(box.style, { width: '110px', height: `${Math.round(110 / def.aspect)}px` });
    return el('div', { className: 'img-field' }, box, up, draw, rm, file,
      el('small', {}, def.clean
        ? `Crop after choosing the file. White background is removed automatically.${def.k === 'signature' ? ' Or draw it on screen.' : ''}`
        : 'Crop after choosing the file. The crop box is fixed at 3.5 cm x 4.5 cm (passport size).'),
      checks, def.k === 'signature' ? signatureGuide() : null);
  }
  if (t === 'money') {
    n = el('input', { type: 'text', className: 'input w-full', placeholder: '₱0.00', inputMode: 'decimal', value: toPeso(value) });
    n.addEventListener('input', () => onChange(n.value));
    n.addEventListener('blur', () => onChange((n.value = toPeso(n.value))));
    n._ctrl = n;
    return n;
  }
  if (t === 'yn') {
    const name = `yn-${def.k}-${++fid}`;
    return el('span', { className: 'join' }, ...['Yes', 'No'].map((o) => {
      const r = el('input', { type: 'radio', className: 'join-item btn btn-sm w-20 checked:btn-primary', name, value: o, checked: value === o });
      r.setAttribute('aria-label', o);
      r.addEventListener('change', () => onChange(o));
      return r;
    }));
  }
  if (t === 'select') {
    n = el('select', { className: 'select w-full' }, el('option', { value: '' }, 'Select...'));
    def.o.forEach((o) => n.append(el('option', { value: o }, o)));
  } else {
    n = el('input', { type: t, className: 'input w-full' });
  }
  n.value = value || '';
  n.addEventListener('input', () => onChange(n.value));
  n._ctrl = t === 'select' ? null : n;
  if (def.place) {
    n.addEventListener('focus', () => places.load().then(() => document.activeElement === n && n._comboShow?.()), { once: true });
    const box = combo(n, () => placeOptions(def));
    box._ctrl = n;
    return box;
  }
  if (!def.s) return n;
  const box = combo(n, def.s);
  box._ctrl = n;
  if (!def.sw) return box;
  // Warns while a typed value is outside the list (e.g. a country missing from the official Excel list); hidden by CSS when empty.
  const warn = el('small', { className: 'warn' }, def.sw);
  const sync = () => (warn.hidden = def.s.some((o) => o.toLowerCase() === n.value.trim().toLowerCase()));
  n.addEventListener('input', sync);
  sync();
  const out = el('span', { className: 'flex flex-col gap-1.5' }, box, warn);
  out._ctrl = n;
  return out;
}

// Pick-or-type box: the list opens on focus or click, typing filters it, arrows/Enter/Escape work from the keyboard.
// "opts" is a list, or a function for lists that depend on other fields (addresses).
function combo(n, opts) {
  const menu = el('ul', { className: 'combo-menu', hidden: true, role: 'listbox' });
  let items = [], active = -1;
  n.placeholder = 'Type or choose';
  n.autocomplete = 'off';
  n.setAttribute('role', 'combobox');
  n.setAttribute('aria-autocomplete', 'list');
  const pick = (o) => {
    n.value = o;
    n.dispatchEvent(new Event('input', { bubbles: true }));
    n.dispatchEvent(new Event('change', { bubbles: true }));
    menu.hidden = true;
  };
  const mark = (i) => {
    active = i;
    [...menu.children].forEach((li, j) => li.classList.toggle('on', j === i));
    menu.children[i]?.scrollIntoView({ block: 'nearest' });
  };
  const show = (filter) => {
    const q = filter ? n.value.trim().toLowerCase() : '';
    const list = typeof opts === 'function' ? opts() : opts;
    items = list.filter((o) => o.toLowerCase().includes(q)).slice(0, 200);
    menu.replaceChildren(...items.map((o) => el('li', { className: o === n.value ? 'sel' : '', role: 'option', onmousedown: (e) => { e.preventDefault(); pick(o); } }, o)));
    menu.hidden = !items.length;
    n.setAttribute('aria-expanded', String(!menu.hidden));
    mark(-1);
  };
  n._comboShow = () => show(false);
  n.addEventListener('focus', () => show(false));
  n.addEventListener('click', () => menu.hidden && show(false));
  n.addEventListener('input', (e) => e.isTrusted && show(true));
  n.addEventListener('blur', () => (menu.hidden = true));
  n.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') menu.hidden = true;
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (menu.hidden) show(false);
      const dir = e.key === 'ArrowDown' ? 1 : -1;
      mark(Math.max(0, Math.min(items.length - 1, active + dir)));
    } else if (e.key === 'Enter' && !menu.hidden && active >= 0) {
      e.preventDefault();
      pick(items[active]);
    }
  });
  return el('span', { className: 'combo' }, n, menu);
}

// Field types where "N/A" is a valid answer (typed text, dates and amounts).
// Items marked "req" (names, birth date, height...) always have a real answer.
const canNA = (def) => (!def.t || def.t === 'date' || def.t === 'money') && !def.place && !def.req;

// One labeled field with its hint, N/A button and check note; ctx = { table, row } for table cells.
function field(def, value, onChange, ctx = {}) {
  if (def.t === 'check') {
    const n = el('input', { type: 'checkbox', className: 'checkbox checkbox-primary checkbox-sm', checked: value === 'Yes' });
    n.dataset.k = def.k;
    n.addEventListener('change', () => onChange(n.checked ? 'Yes' : ''));
    return el('label', { className: `f check w${def.w || 1}` }, n, el('span', {}, def.l));
  }
  const id = `f${++fid}`;
  const current = () => (ctx.row ? ctx.row[def.k] : state.fields[def.k]);
  let na;
  const n = input(def, value, (v) => {
    onChange(v);
    if (na) na.hidden = filled(v);
  });
  n.dataset.k = def.k;
  if (n._ctrl) n._ctrl.id = id;
  const head = el('div', { className: 'f-head' }, el(n._ctrl ? 'label' : 'span', n._ctrl ? { htmlFor: id } : {}, def.l));
  if (canNA(def) && n._ctrl) {
    na = el('button', { type: 'button', className: 'na', textContent: 'N/A', title: 'Not applicable', hidden: filled(value), onclick: () => {
      const c = n._ctrl;
      c.value = 'N/A';
      c.dispatchEvent(new Event('input', { bubbles: true }));
      c.dispatchEvent(new Event('change', { bubbles: true }));
      c.focus();
    } });
    head.append(na);
  }
  const warn = el('small', { className: 'fwarn', hidden: true, role: 'note' });
  const wrap = el('div', { className: `f w${def.w || 1}${def.t === 'yn' ? ' q' : ''}` }, head, n,
    def.h ? el('small', { className: 'hint' }, def.h) : null, warn);
  // Re-checked on change and when focus leaves, so notes do not flicker while typing.
  wrap._check = () => {
    const m = check(def, current(), ctx);
    warn.textContent = m;
    warn.hidden = !m;
  };
  wrap._check();
  return wrap;
}

// Short section labels for the stepper.
const TAB = { personal: 'Personal Info', family: 'Family', education: 'Education', eligibility: 'Eligibility', work: 'Work Experience', learning: 'L&D', voluntary: 'Voluntary Work', other: 'Other Info', questions: 'Questions 34-40', references: 'References & ID' };

const visible = (f) => !f.if || (state.fields[f.if[0]] || '') === f.if[1];
const rowFilled = (r) => Object.values(r || {}).some(filled);

// How complete a section is. Every shown item must be answered (N/A counts), and every table needs an entry or an
// N/A entry; photos and signatures are optional here because many people attach printed ones.
function sectionInfo(sec) {
  const fields = (sec.fields || []).filter((f) => f.t !== 'check' && f.t !== 'image' && visible(f));
  const missing = fields.filter((f) => !filled(state.fields[f.k]));
  const emptyTables = (sec.tables || []).filter((t) => !(state.tables[t.k] || []).some(rowFilled));
  const total = fields.length + (sec.tables || []).length;
  const done = total - missing.length - emptyTables.length;
  const started = done > 0 || (sec.fields || []).some((f) => f.t === 'image' && filled(state.fields[f.k]));
  return { missing, emptyTables, total, done, state: done === total ? 'done' : started ? 'part' : 'empty' };
}

function renderSteps() {
  const infos = S.map(sectionInfo);
  $('#tabs').replaceChildren(...S.map((s, i) => {
    const info = infos[i];
    const label = `${TAB[s.id] || s.title}: ${info.state === 'done' ? 'complete' : `${info.done} of ${info.total} answered`}`;
    return el('button', {
      className: `step ${info.state}${i === step ? ' on' : ''}`, title: label, ariaLabel: label, onclick: () => go(i),
      ariaCurrent: i === step ? 'step' : null,
    }, el('span', { className: 'num' }, info.state === 'done' && i !== step ? icon(ICON.check, 'size-3.5') : String(i + 1)), el('span', {}, TAB[s.id] || s.title));
  }));
  const done = infos.filter((x) => x.state === 'done').length;
  const items = infos.reduce((a, x) => [a[0] + x.done, a[1] + x.total], [0, 0]);
  const pct = Math.round((100 * items[0]) / (items[1] || 1));
  $('#progress').max = 100;
  $('#progress').value = pct;
  $('#progress-text').textContent = `${pct}% · ${done} of ${S.length} sections complete`;
  const cur = infos[step];
  $('#status').textContent = cur.state === 'done' ? 'Every item in this section is answered' : `${cur.done} of ${cur.total} items answered in this section`;
  scrollTabs();
}

// Keeps the current step in view in the horizontal tab row, and fades the edges that have more tabs behind them.
function scrollTabs() {
  const tabs = $('#tabs'), on = tabs.querySelector('.on');
  if (on && tabs.scrollWidth > tabs.clientWidth) {
    const left = on.offsetLeft - tabs.offsetLeft, right = left + on.offsetWidth;
    if (left < tabs.scrollLeft || right > tabs.scrollLeft + tabs.clientWidth) tabs.scrollTo({ left: left - tabs.clientWidth / 2 + on.offsetWidth / 2 });
  }
  fadeTabs();
}
function fadeTabs() {
  const tabs = $('#tabs'), w = tabs.parentElement;
  w.classList.toggle('fl', tabs.scrollLeft > 2);
  w.classList.toggle('fr', tabs.scrollLeft + tabs.clientWidth < tabs.scrollWidth - 2);
}
$('#tabs').addEventListener('scroll', fadeTabs, { passive: true });
document.querySelectorAll('.tabs-arrow').forEach((b) => b.addEventListener('click', () => { const t = $('#tabs'); t.scrollBy({ left: Number(b.dataset.dir) * t.clientWidth * 0.7, behavior: 'smooth' }); }));
addEventListener('resize', fadeTabs);

function render() {
  const sec = S[step];
  renderSteps();
  // A field with "g" starts a titled group; fields without one join the current group.
  const body = [];
  let grid;
  (sec.fields || []).forEach((f) => {
    if (f.g || !grid) {
      grid = el('div', { className: 'fields' });
      body.push(f.g ? el('section', { className: 'grp' }, el('h3', {}, f.g), f.gd ? el('p', { className: 'gd' }, f.gd) : '', grid) : grid);
    }
    grid.append(field(f, state.fields[f.k], (v) => {
      state.fields[f.k] = v;
      syncConditions();
      syncPermanent();
      changed();
    }));
  });
  const fillNA = (sec.fields || []).some((f) => canNA(f) && visible(f)) && el('button', {
    className: 'btn btn-ghost btn-sm text-primary', title: 'Writes N/A in every empty item of this section that you can type in', onclick: () => fillEmptyNA(),
  }, 'Fill empty with N/A');
  $('#form').replaceChildren(
    el('div', { className: 'sec-head' },
      el('div', {}, el('p', { className: 'text-xs font-medium text-base-content/50' }, `Section ${step + 1} of ${S.length}`),
        el('h2', { className: 'text-2xl font-semibold' }, sec.title)),
      sec.id === 'work' ? el('button', { className: 'btn btn-sm btn-primary btn-soft gap-1.5', title: 'The CS Form No. 212 attachment for your duties and accomplishments', onclick: openWes }, icon(ICON.layers, 'size-4'), 'Work Experience Sheet') : null,
      fillNA),
    ...body, ...(sec.tables || []).map(renderTable));
  syncConditions();
  syncPermanent();
  $('#inp-name').placeholder = autoName();
  $('#btn-prev').disabled = step === 0;
  $('#btn-next').textContent = step === S.length - 1 ? 'Export' : 'Next →';
  refreshPreview();
}

// Writes N/A in this section's empty, shown items that accept typing.
function fillEmptyNA(i = step) {
  const sec = S[i];
  const empty = (sec.fields || []).filter((f) => canNA(f) && visible(f) && !filled(state.fields[f.k]));
  if (!empty.length) return toast('Every item you can type in is already answered.');
  const before = clone(state);
  empty.forEach((f) => (state.fields[f.k] = 'N/A'));
  changed();
  render();
  toast(`N/A written in ${empty.length} empty item${empty.length > 1 ? 's' : ''}.`, { action: () => restore(before) });
}

// Puts back an earlier copy of the answers (Undo) and saves it.
function restore(data) {
  state = clone(data);
  changed();
  render();
}

// Tables with dates in "from" can be sorted newest first, as the form asks for work experience.
const sortable = (t) => t.c.some((c) => c.k === 'from' && c.t === 'date');
const newest = (r) => (r.to === 'PRESENT' ? '9999' : isIso(r.from) ? r.from : '');

function renderTable(t) {
  const rows = (state.tables[t.k] ||= []);
  const cap = t.max + (t.more || 0);
  const redraw = () => { changed(); render(); };
  const box = el('section', { className: 'grp' }, el('h3', {}, t.l, el('span', { className: 'count' }, `${rows.length} of ${cap}`)));
  box.dataset.table = t.k;
  if (t.h) box.append(el('p', { className: 'gd' }, t.h));
  if (sortable(t) && rows.length > 1) {
    box.append(el('button', { className: 'btn btn-sm btn-ghost mb-3', onclick: () => {
      const sorted = [...rows].sort((a, b) => newest(b).localeCompare(newest(a)));
      if (sorted.every((r, i) => r === rows[i])) return toast('Already in order, most recent first.');
      const before = clone(state);
      state.tables[t.k] = sorted;
      redraw();
      toast('Sorted, most recent first.', { action: () => restore(before) });
    } }, icon(ICON.sort), 'Sort newest first'));
  }
  let dragFrom = null;
  rows.forEach((row, i) => {
    const grid = el('div', { className: 'fields' });
    t.c.forEach((c) => grid.append(field(c, row[c.k], (v) => { row[c.k] = v; changed(); }, { table: t.k, row, rows })));
    const btn = (d, label, fn, extra = '') => el('button', { type: 'button', className: `btn btn-ghost btn-xs btn-square ${extra}`, title: label, ariaLabel: `${label}, entry ${i + 1}`, onclick: fn }, icon(d, 'size-3.5'));
    const move = (to) => {
      rows.splice(to, 0, rows.splice(i, 1)[0]);
      redraw();
    };
    const cont = t.k !== 'education' && i >= t.max;
    const grip = el('span', { className: 'grip', title: 'Drag to reorder', draggable: true }, icon(ICON.grip, 'size-3.5'));
    const head = el('div', { className: 'entry-h' },
      el('span', { className: 'flex items-center gap-1.5' }, grip, `Entry ${i + 1}`, cont ? el('span', { className: 'badge badge-ghost badge-sm' }, 'Continuation sheet') : null),
      el('span', { className: 'flex items-center gap-0.5' },
        btn(ICON.up, 'Move up', () => move(i - 1), i === 0 ? 'invisible' : ''),
        btn(ICON.down, 'Move down', () => move(i + 1), i === rows.length - 1 ? 'invisible' : ''),
        rows.length < cap ? btn(ICON.copy, 'Duplicate', () => { rows.splice(i + 1, 0, { ...row }); redraw(); }) : null,
        btn(ICON.trash, 'Remove', () => {
          const before = clone(state);
          rows.splice(i, 1);
          redraw();
          toast(`Entry ${i + 1} removed.`, { action: () => restore(before) });
        }, 'text-error')));
    const entry = el('div', { className: 'entry' }, head, grid);
    grip.ondragstart = (e) => {
      dragFrom = i;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setDragImage(entry, 20, 20);
    };
    entry.ondragover = (e) => {
      if (dragFrom === null) return;
      e.preventDefault();
      entry.classList.add('drop');
    };
    entry.ondragleave = () => entry.classList.remove('drop');
    entry.ondrop = (e) => {
      e.preventDefault();
      if (dragFrom === null || dragFrom === i) return;
      rows.splice(i, 0, rows.splice(dragFrom, 1)[0]);
      dragFrom = null;
      redraw();
    };
    box.append(entry);
  });
  const actions = el('div', { className: 'flex flex-wrap gap-2' });
  if (rows.length < cap) {
    actions.append(el('button', { className: 'btn btn-outline btn-primary flex-1 border-dashed', textContent: '+ Add entry', onclick: () => { rows.push({}); redraw(); } }));
  }
  if (!rows.some(rowFilled)) {
    // The form asks for N/A when a part does not apply; one entry with N/A in its first text column says so.
    const first = t.c.find((c) => c.t !== 'select') || t.c[0];
    actions.append(el('button', { className: 'btn btn-ghost', title: 'Adds one entry that says N/A', onclick: () => { state.tables[t.k] = [{ [first.k]: 'N/A' }]; redraw(); } }, 'None (N/A)'));
  }
  box.append(actions);
  if (rows.length >= cap) box.append(el('p', { className: 'mt-2 text-xs text-base-content/60' }, 'This is the most the form and its continuation sheet have room for.'));
  else if (t.more && rows.length >= t.max && t.k !== 'education') box.append(el('p', { className: 'mt-2 text-xs text-base-content/60' }, `Entries after ${t.max} go on the official continuation sheet, which is added to your PDF and Excel file.`));
  return box;
}

// Hides and blanks a field whose schema condition ("if": [key, value]) does not hold, as the server does.
function syncConditions() {
  S.forEach((sec) => (sec.fields || []).forEach((f) => {
    if (!f.if) return;
    const on = visible(f);
    if (!on) state.fields[f.k] = '';
    const n = document.querySelector(`[data-k="${f.k}"]`);
    if (!n) return;
    n.closest('.f').hidden = !on;
    if (!on) [n, ...n.querySelectorAll('input, select')].forEach((i) => {
      if (i.type === 'radio') i.checked = false;
      else if (i.tagName !== 'SPAN') i.value = '';
    });
  }));
}

// Copies the residential address into the hidden permanent fields while "same as residential" is ticked.
const ADDRESS = ['house', 'street', 'subd', 'brgy', 'city', 'prov', 'zip'];
function syncPermanent() {
  if (state.fields.perm_same !== 'Yes') return;
  ADDRESS.forEach((s) => {
    state.fields[`perm_${s}`] = state.fields[`res_${s}`] || '';
    const n = document.querySelector(`[data-k="perm_${s}"]`);
    const c = n?.matches('input') ? n : n?.querySelector('input');
    if (c) c.value = state.fields[`perm_${s}`];
  });
}

// Official PDS page that holds each form section.
const SECTION_PAGE = { personal: 1, family: 1, education: 1, eligibility: 2, work: 2, learning: 3, voluntary: 3, other: 3, questions: 4, references: 4 };
let jumpTo = 1;

function go(i, focusKey) {
  step = Math.max(0, Math.min(S.length - 1, i));
  jumpTo = SECTION_PAGE[S[step].id];
  render();
  scrollTo(0, 0);
  if (focusKey) {
    const n = document.querySelector(`#form [data-k="${focusKey}"]`);
    const c = n?.matches('input, select') ? n : n?.querySelector('input, select');
    c?.scrollIntoView({ block: 'center' });
    c?.focus({ preventScroll: true });
  } else if (matchMedia('(max-width: 1023px)').matches) {
    $('#form').focus({ preventScroll: true });
  }
}

// Field notes are refreshed when focus leaves a field, for the whole entry so From/To pairs update together.
function recheck(e) {
  const entry = e.target.closest?.('.entry');
  const scope = entry?.parentElement || e.target.closest?.('.f');
  if (!scope) return;
  (scope.matches('.f') ? [scope] : scope.querySelectorAll('.f')).forEach((f) => f._check?.());
}
$('#form').addEventListener('focusout', recheck);
$('#form').addEventListener('change', recheck);

// Live preview. It can be hidden (remembered on wide screens); on phones it is a separate view switched from the bottom
// bar. While hidden it is not refreshed, which saves requests; it is redrawn when shown again or before printing.
const wide = matchMedia('(min-width: 1024px)');
let pvOpen = false;
let pvStale = true;
function setPreview(open, remember = true) {
  pvOpen = open;
  document.body.classList.toggle('pv-on', open);
  document.body.classList.toggle('pv-off', !open);
  document.querySelectorAll('#mbar button').forEach((b) => b.classList.toggle('on', (b.dataset.view === 'preview') === open));
  if (remember && wide.matches) store.set('pds-preview', open ? 'shown' : 'hidden');
  if (open && pvStale) refreshPreview();
  if (!wide.matches) scrollTo(0, 0);
}
wide.addEventListener('change', () => setPreview(wide.matches && store.get('pds-preview') !== 'hidden', false));
$('#pv-hide').onclick = () => setPreview(false);
$('#pv-show').onclick = () => setPreview(true);
document.querySelectorAll('#mbar button').forEach((b) => (b.onclick = () => setPreview(b.dataset.view === 'preview', false)));

const frameDoc = () => $('#preview').contentDocument;
const pages = () => [...(frameDoc()?.querySelectorAll('.pg') || [])];
// Page number of a preview page; all continuation sheets count as page 5.
const pageNo = (p, i) => (p.classList.contains('cont') ? 5 : i + 1);

// Scrolls only inside the preview frame (scrollIntoView would also scroll this page).
function showPage(n) {
  const pg = pages().find((p, i) => pageNo(p, i) === +n);
  if (pg) $('#preview').contentWindow.scrollTo(0, pg.offsetTop - 12);
}

// Highlights the page button of the page currently in view.
function markPage() {
  const mid = $('#preview').clientHeight / 2;
  const all = pages(), i = all.findIndex((p) => p.getBoundingClientRect().bottom > mid);
  const n = i < 0 ? 0 : pageNo(all[i], i);
  document.querySelectorAll('#pv-pages button').forEach((b) => b.classList.toggle('on', +b.dataset.page === n));
}

// ---- Preview zoom, panning, click-to-edit and highlighting ---------------------------------------------------------
// Zoom is "width" or "page" (fit the frame), or a number: the page width as a share of real A4 size (794 px = 100%).
const A4PX = 794;
const ZOOMS = [0.5, 0.67, 0.75, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4];
let zoom = (() => {
  const z = store.get('pds-zoom');
  return z === 'page' ? 'page' : z && !isNaN(z) ? +z : 'width';
})();
const pageWidthPx = () => frameDoc()?.querySelector('.pg')?.getBoundingClientRect().width || A4PX;

// Applies the zoom; "anchor" (a point inside the frame) stays under the pointer while zooming.
function applyZoom(anchor) {
  const doc = frameDoc(), win = $('#preview').contentWindow;
  if (!doc?.body) return;
  const before = pageWidthPx(), fx = win.scrollX + (anchor?.[0] || 0), fy = win.scrollY + (anchor?.[1] || 0);
  doc.body.classList.toggle('fit-page', zoom === 'page');
  doc.body.classList.toggle('zoomed', typeof zoom === 'number');
  if (typeof zoom === 'number') doc.body.style.setProperty('--pgw', `${Math.round(A4PX * zoom)}px`);
  if (anchor) {
    const k = pageWidthPx() / before;
    win.scrollTo(fx * k - anchor[0], fy * k - anchor[1]);
  }
  $('#pv-zoom-label').textContent = `${Math.round((pageWidthPx() / A4PX) * 100)}%`;
  document.querySelectorAll('#pv-fit button').forEach((b) => b.classList.toggle('on', b.dataset.fit === zoom));
}
function setZoom(z, anchor) {
  zoom = typeof z === 'number' ? Math.min(4, Math.max(0.4, z)) : z;
  store.set('pds-zoom', String(zoom));
  applyZoom(anchor);
}
function stepZoom(dir, anchor) {
  const cur = pageWidthPx() / A4PX;
  setZoom(dir > 0 ? ZOOMS.find((z) => z > cur + 0.01) ?? 4 : [...ZOOMS].reverse().find((z) => z < cur - 0.01) ?? 0.5, anchor);
}
document.querySelectorAll('#pv-zoom [data-zoom]').forEach((b) => (b.onclick = () => stepZoom(b.dataset.zoom === 'in' ? 1 : -1)));
$('#pv-zoom-label').onclick = () => setZoom('width');
addEventListener('resize', () => applyZoom());

// Where each field lives: its section index and definition.
const FIELD_AT = {}, TABLE_AT = {};
S.forEach((sec, i) => {
  (sec.fields || []).forEach((f) => (FIELD_AT[f.k] = [i, f]));
  (sec.tables || []).forEach((t) => (TABLE_AT[t.k] = [i, t]));
});

// Label of a preview box, shown as its tooltip.
function itemLabel(id) {
  const [kind, a, b, c] = id.split(':');
  if (kind === 'e') return `${a}: ${TABLE_AT.education[1].c.find((x) => x.k === b)?.l || b}`;
  if (kind === 't') {
    const t = TABLE_AT[a]?.[1];
    return t ? `${t.l}, entry ${+b + 1}: ${t.c.find((x) => x.k === c)?.l || c}` : id;
  }
  const f = FIELD_AT[a]?.[1];
  return f ? (kind === 'c' ? `${f.l}: ${b}` : f.l) : id;
}

function focusIn(scope, key) {
  const n = scope?.querySelector(`[data-k="${key}"]`);
  const c = n && (n.matches('input, select') ? n : n.querySelector('input, select, button'));
  if (!c) return false;
  c.scrollIntoView({ block: 'center', behavior: 'smooth' });
  c.focus({ preventScroll: true });
  return true;
}

// Opens the field behind a preview box: its section, entry and input.
function jumpToItem(id) {
  if (document.body.classList.contains('pv-full')) setFull(false);
  if (!wide.matches) setPreview(false, false);
  const [kind, a, b, c] = id.split(':');
  if (kind === 'f' || kind === 'c' || kind === 's') {
    const [i, f] = FIELD_AT[a] || [];
    if (i === undefined) return;
    if (step !== i) go(i);
    if (!visible(f)) {
      focusIn($('#form'), f.if[0]);
      return toast(`Answer "${FIELD_AT[f.if[0]][1].l}" first to fill this box.`);
    }
    return focusIn($('#form'), a);
  }
  const table = kind === 'e' ? 'education' : a;
  const [i] = TABLE_AT[table] || [];
  if (i === undefined) return;
  if (step !== i) go(i);
  const rows = state.tables[table] || [];
  const r = kind === 'e' ? rows.findIndex((x) => x.level === a) : +b;
  const box = $(`#form [data-table="${table}"]`);
  const entry = r >= 0 ? box?.querySelectorAll('.entry')[r] : null;
  if (entry) return focusIn(entry, kind === 'e' ? b : c);
  // An empty row of the form: offer to add the entry it belongs to and go straight to it.
  box?.querySelector('.btn-outline')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  const t = TABLE_AT[table][1], cap = t.max + (t.more || 0);
  if (kind !== 'e' && rows.length >= cap) return toast('This table is full.');
  toast(kind === 'e' ? `No ${a} entry yet.` : 'This row has no entry yet.', {
    label: 'Add entry',
    ms: 8000,
    action: () => {
      const list = (state.tables[table] ||= []);
      if (kind === 'e') list.push({ level: a });
      else list.push({});
      changed();
      render();
      const at = $(`#form [data-table="${table}"]`)?.querySelectorAll('.entry');
      focusIn(at?.[at.length - 1], kind === 'e' ? b : c);
    },
  });
}

// Preview boxes of the field being edited.
let hlIds = [];
function idsFor(target) {
  const k = target.closest('[data-k]')?.dataset.k;
  if (!k) return [];
  const tb = target.closest('[data-table]');
  if (!tb) return [`f:${k}`, `s:${k}`, `c:${k}:`];
  const table = tb.dataset.table, r = [...tb.querySelectorAll('.entry')].indexOf(target.closest('.entry'));
  if (table === 'education') {
    const rows = state.tables.education || [], level = rows[r]?.level;
    return level && rows.findIndex((x) => x.level === level) === r ? [`e:${level}:${k}`] : [`t:education:${r}:${k}`];
  }
  return [`t:${table}:${r}:${k}`];
}
function highlight(ids, scroll) {
  hlIds = ids;
  const doc = frameDoc();
  if (!doc?.body) return;
  doc.querySelectorAll('.hl').forEach((n) => n.classList.remove('hl'));
  const hits = ids.length ? [...doc.querySelectorAll('[data-id]')].filter((n) => ids.some((id) => (id.endsWith(':') ? n.dataset.id.startsWith(id) : n.dataset.id === id))) : [];
  hits.forEach((n) => n.classList.add('hl'));
  if (!scroll || !hits[0] || !pvOpen || !wide.matches) return;
  const r = hits[0].getBoundingClientRect(), win = $('#preview').contentWindow;
  if (r.top < 0 || r.bottom > win.innerHeight || r.left < 0 || r.right > win.innerWidth) {
    win.scrollTo({ top: win.scrollY + r.top - win.innerHeight / 3, left: win.scrollX + r.left - 40, behavior: 'smooth' });
  }
}
$('#form').addEventListener('focusin', (e) => highlight(idsFor(e.target), true));

// Mouse: drag to move around, Ctrl + wheel to zoom, click a box to edit it. Touch: two fingers pinch to zoom.
function wireFrame() {
  const doc = frameDoc(), win = $('#preview').contentWindow;
  doc.body.style.touchAction = 'pan-x pan-y';
  const pts = new Map();
  let drag = null, pinch = null, dragged = false;
  doc.addEventListener('wheel', (e) => {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    stepZoom(e.deltaY < 0 ? 1 : -1, [e.clientX, e.clientY]);
  }, { passive: false });
  doc.addEventListener('pointerdown', (e) => {
    pts.set(e.pointerId, [e.clientX, e.clientY]);
    if (pts.size === 2) {
      const [p, q] = [...pts.values()];
      pinch = { d: Math.hypot(p[0] - q[0], p[1] - q[1]) || 1, w: pageWidthPx() };
      drag = null;
      return;
    }
    dragged = false;
    if (e.button === 0 && e.pointerType === 'mouse') drag = { x: e.clientX, y: e.clientY, sx: win.scrollX, sy: win.scrollY };
  });
  doc.addEventListener('pointermove', (e) => {
    if (pts.has(e.pointerId)) pts.set(e.pointerId, [e.clientX, e.clientY]);
    if (pinch && pts.size === 2) {
      const [p, q] = [...pts.values()];
      zoom = Math.min(4, Math.max(0.4, (pinch.w * (Math.hypot(p[0] - q[0], p[1] - q[1]) / pinch.d)) / A4PX));
      applyZoom([(p[0] + q[0]) / 2, (p[1] + q[1]) / 2]);
      return;
    }
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!dragged && Math.hypot(dx, dy) < 5) return;
    dragged = true;
    doc.body.classList.add('panning');
    win.scrollTo(drag.sx - dx, drag.sy - dy);
  });
  const end = (e) => {
    pts.delete(e.pointerId);
    if (pinch && pts.size < 2) {
      pinch = null;
      dragged = true;
      store.set('pds-zoom', String(zoom));
    }
    drag = null;
    doc.body.classList.remove('panning');
  };
  doc.addEventListener('pointerup', end);
  doc.addEventListener('pointercancel', end);
  doc.addEventListener('click', (e) => {
    if (dragged) return (dragged = false);
    const t = e.target.closest('[data-id]');
    if (t) jumpToItem(t.dataset.id);
  });
  doc.addEventListener('mouseover', (e) => {
    const t = e.target.closest('[data-id]');
    if (t && !t.title) t.title = `${itemLabel(t.dataset.id)} \u00b7 click to edit`;
  });
  doc.addEventListener('keydown', (e) => {
    if (e.key === '+' || e.key === '=') stepZoom(1);
    else if (e.key === '-') stepZoom(-1);
    else if (e.key === '0') setZoom('width');
    else if (e.key === 'Escape' && document.body.classList.contains('pv-full')) setFull(false);
  });
}

// Full-screen preview, closed with Esc or the same button.
function setFull(on) {
  document.body.classList.toggle('pv-full', on);
  $('#pv-full').replaceChildren(icon(on ? ICON.shrink : ICON.expand, 'size-3.5'));
  $('#pv-full').title = on ? 'Exit full screen (Esc)' : 'Full screen (Esc to close)';
  if (on && !pvOpen) setPreview(true, false);
  requestAnimationFrame(() => applyZoom());
}
$('#pv-full').onclick = () => setFull(!document.body.classList.contains('pv-full'));
addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && document.body.classList.contains('pv-full') && !document.querySelector('dialog[open]')) setFull(false);
});
$('#pv-tip button').onclick = () => {
  $('#pv-tip').hidden = true;
  store.set('pds-pv-tip', '1');
};

let previewTimer, previewSeq = 0, previewWaiters = [];
// Redraws the preview (debounced); resolves once it is drawn. "force" draws it even while hidden, for printing.
function refreshPreview(force = false) {
  if (!pvOpen && !force) {
    pvStale = true;
    return Promise.resolve();
  }
  return new Promise((resolve) => {
    previewWaiters.push(resolve);
    clearTimeout(previewTimer);
    previewTimer = setTimeout(drawPreview, 150);
  });
}
async function drawPreview() {
  const waiters = previewWaiters.splice(0), seq = ++previewSeq, frame = $('#preview');
  $('#pv-busy').hidden = false;
  try {
    const res = await fetch('view.php', { method: 'POST', body: JSON.stringify(state) });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const html = await res.text();
    if (seq !== previewSeq) return;
    const y = frame.contentWindow?.scrollY || 0;
    await new Promise((done) => {
      frame.onload = () => {
        applyZoom();
        wireFrame();
        highlight(hlIds, false);
        if (!store.get('pds-pv-tip')) $('#pv-tip').hidden = false;
        if (jumpTo) showPage(jumpTo);
        else frame.contentWindow.scrollTo(0, y);
        jumpTo = 0;
        frame.contentWindow.addEventListener('scroll', markPage);
        markPage();
        const hasCont = !!frameDoc().querySelector('.pg.cont');
        $('#pv-pages [data-page="5"]').hidden = !hasCont;
        $('#pr-cont').hidden = !hasCont;
        done();
      };
      frame.srcdoc = html;
    });
    pvStale = false;
    $('#pv-skeleton').hidden = true;
    $('#pv-dot').className = 'size-2 rounded-full bg-success';
    $('#pv-dot').title = '';
  } catch {
    $('#pv-dot').className = 'size-2 rounded-full bg-error';
    $('#pv-dot').title = 'The preview could not be updated. It will try again on your next change.';
    pvStale = true;
  } finally {
    if (seq === previewSeq) $('#pv-busy').hidden = true;
    waiters.forEach((w) => w());
  }
}

document.querySelectorAll('#pv-pages button').forEach((b) => (b.onclick = () => showPage(+b.dataset.page)));
document.querySelectorAll('#pv-fit button').forEach((b) => (b.onclick = () => {
  const n = document.querySelector('#pv-pages .on')?.dataset.page || 1;
  setZoom(b.dataset.fit);
  showPage(n);
}));
// File name from the header box, or SURNAME_FIRSTNAME_PDS when it is empty; unsafe characters are removed.
const autoName = () => [state.fields.surname, state.fields.first_name, 'PDS'].filter(Boolean).join('_');
function fileName() {
  return ($('#inp-name').value.trim() || autoName()).replace(/[\\/:*?"<>|]+/g, '').trim() || 'PDS';
}

// Paper sizes in mm, portrait.
const PAPER = { a4: [210, 297], letter: [215.9, 279.4], long: [215.9, 330.2], legal: [215.9, 355.6] };
const ASPECT = 1.41412;

function printSettings() {
  return {
    paper: $('#pr-paper').value,
    margin: +$('#pr-margin').value,
    pages: [...document.querySelectorAll('#pr-pages input:checked')].filter((i) => !i.closest('[hidden]')).map((i) => +i.value),
  };
}

// Width of the form page on paper so it fits inside the margins without distortion.
function pageWidth({ paper, margin }) {
  const [w, h] = PAPER[paper];
  return Math.min(w - 2 * margin, (h - 2 * margin) / ASPECT);
}

// Shows each selected page, with its filled-in data, on the chosen paper and margins.
function drawPaper() {
  const s = printSettings(), [w, h] = PAPER[s.paper];
  const chosen = pages().map((p, i) => [p, pageNo(p, i)]).filter(([, n]) => s.pages.includes(n));
  $('#pr-view').replaceChildren(...chosen.map(([pg, n]) => {
    const sheet = pg.cloneNode(true);
    sheet.classList.remove('skip', 'last');
    Object.assign(sheet.style, { width: `${(pageWidth(s) / (w - 2 * s.margin)) * 100}%`, maxWidth: 'none', margin: '0 auto', boxShadow: 'none' });
    const paper = el('div', { className: 'pr-paper' }, sheet);
    Object.assign(paper.style, { aspectRatio: `${w} / ${h}`, padding: `${(s.margin / w) * 100}%` });
    return el('figure', {}, paper, el('figcaption', {}, n === 5 ? 'Continuation sheet' : `Page ${n}`));
  }));
  $('#pr-go').disabled = !chosen.length;
  store.set('pds-print', JSON.stringify(s));
}

async function openPrint() {
  const s = JSON.parse(store.get('pds-print') || 'null');
  if (s) {
    $('#pr-paper').value = s.paper;
    $('#pr-margin').value = s.margin;
  }
  $('#pr-name').textContent = fileName();
  if (pvStale) {
    const t = toast('Preparing the pages…', { ms: 0 });
    await refreshPreview(true);
    t.remove();
  }
  drawPaper();
  $('#dlg-print').showModal();
}

// Applies the chosen paper, margins and pages to the preview frame, then opens the browser print window.
function exportPdf() {
  const s = printSettings(), [w, h] = PAPER[s.paper], doc = frameDoc(), win = $('#preview').contentWindow, title = document.title;
  let css = doc.getElementById('print-cfg');
  if (!css) {
    css = Object.assign(doc.createElement('style'), { id: 'print-cfg' });
    doc.head.append(css);
  }
  css.textContent = `@page { size: ${w}mm ${h}mm; margin: ${s.margin}mm; }
    @media print { .official { padding: 0 !important; } .pg { width: ${pageWidth(s)}mm !important; margin: 0 auto !important; }
      .pg.skip { display: none !important; } .pg.last { break-after: auto !important; } }`;
  const shown = pages().filter((p, i) => {
    const on = s.pages.includes(pageNo(p, i));
    p.classList.toggle('skip', !on);
    p.classList.remove('last');
    return on;
  });
  shown.at(-1)?.classList.add('last');
  document.title = doc.title = fileName();
  win.addEventListener('afterprint', () => (document.title = title), { once: true });
  win.print();
  track('export_pdf');
}

async function exportXlsx() {
  const t = toast('Creating the Excel file…', { ms: 0 });
  try {
    const res = await fetch('xlsx.php', { method: 'POST', body: JSON.stringify(state) });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The Excel file could not be created.');
    el('a', { href: URL.createObjectURL(await res.blob()), download: `${fileName()}.xlsx` }).click();
    toast('Excel file downloaded.', { type: 'success' });
    track('export_xlsx');
  } catch (e) {
    toast(e.message === 'Failed to fetch' ? 'You seem to be offline. Try again when you are connected.' : e.message, { type: 'error' });
  } finally {
    t.remove();
  }
}

// ---- Work Experience Sheet ------------------------------------------------------------------------------------------
// The CS Form No. 212 attachment where duties and accomplishments go (the form says "Description of duties should be
// indicated in the attached Work Experience Sheet"). Its own list: people include only the work relevant to the
// position, starting from Section V entries, most recent first.
const WES_FIELDS = [
  ['position', 'Position', 'text'],
  ['unit', 'Name of Office/Unit', 'text'],
  ['supervisor', 'Immediate Supervisor', 'text'],
  ['agency', 'Name of Agency/Organization and Location', 'text'],
  ['accomplishments', 'List of Accomplishments and Contributions (if any)', 'area', 'One per line, e.g. Reduced processing time of permits from 5 days to 2 days.'],
  ['duties', 'Summary of Actual Duties', 'area', 'One per line, e.g. Prepares monthly payroll for 120 employees.'],
];
const wesList = () => (state.wes ||= []);
// "Jan 2019" style for durations, as the sheet's instructions ask (month abbreviated, year in full).
const monYear = (v) => (v === 'PRESENT' ? 'Present' : isIso(v) ? new Date(`${v}T00:00`).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }) : v || '');
const wesKey = (e) => `${e.from}|${e.position}`.toLowerCase();

function renderWes() {
  const list = wesList(), box = $('#wes-list');
  const work = (state.tables.work || []).filter((r) => filled(r.position) && !isNA(r.position));
  const used = new Set(list.map(wesKey));
  const fresh = work.filter((r) => !used.has(wesKey(r)));
  $('#wes-from').replaceChildren(...(fresh.length ? fresh.map((r) => el('li', {}, el('button', { type: 'button', onclick: () => addWes(r) },
    el('span', { className: 'min-w-0' }, el('b', { className: 'block truncate' }, r.position), el('small', { className: 'block truncate opacity-60' }, `${monYear(r.from)} – ${monYear(r.to)} · ${r.office || ''}`)))))
    : [el('li', { className: 'px-3 py-2 text-xs opacity-60' }, work.length ? 'All your positions are already added.' : 'Add positions in Section V first.')]));
  $('#wes-count').textContent = list.length ? `${list.length} entr${list.length === 1 ? 'y' : 'ies'}` : '';
  if (!list.length) {
    box.replaceChildren(el('div', { className: 'wes-empty' }, el('b', {}, 'No entries yet'),
      el('p', {}, 'Add the positions relevant to the job you are applying for. Your dates, position and office are copied from Section V; then write your duties and accomplishments.')));
    return;
  }
  box.replaceChildren(...list.map((e, i) => {
    const set = (k) => (v) => { e[k] = v; changed(); };
    const inp = ([k, label, t, hint]) => {
      const ctl = t === 'area'
        ? el('textarea', { className: 'textarea w-full', rows: 4, value: e[k] || '', placeholder: hint || '', oninput: (ev) => set(k)(ev.target.value) })
        : el('input', { className: 'input w-full', value: e[k] || '', oninput: (ev) => set(k)(ev.target.value) });
      return el('label', { className: `f${t === 'area' ? ' wes-wide' : ''}` }, el('span', { className: 'f-head' }, el('span', {}, label)), ctl);
    };
    const date = (k, label) => el('label', { className: 'f' }, el('span', { className: 'f-head' }, el('span', {}, label)),
      el('input', { className: 'input w-full', value: e[k] === 'PRESENT' ? 'Present' : isIso(e[k]) ? toDmy(e[k]) : e[k] || '', placeholder: 'dd/mm/yyyy or Present',
        oninput: (ev) => { const v = ev.target.value.trim(); set(k)(/^present$/i.test(v) ? 'PRESENT' : toIso(v) || v); } }));
    const btn = (ic, title, fn, cls = '') => el('button', { type: 'button', className: `btn btn-ghost btn-xs btn-square ${cls}`, title, ariaLabel: title, onclick: fn }, icon(ic, 'size-4'));
    const move = (to) => { list.splice(to, 0, list.splice(i, 1)[0]); changed(); renderWes(); };
    return el('article', { className: 'wes-entry' },
      el('div', { className: 'wes-entry-h' }, el('b', {}, `Entry ${i + 1}`), el('span', { className: 'text-xs opacity-60 truncate' }, e.position || ''),
        el('span', { className: 'ml-auto flex gap-0.5' },
          btn(ICON.up, 'Move up', () => move(i - 1), i ? '' : 'invisible'),
          btn(ICON.down, 'Move down', () => move(i + 1), i < list.length - 1 ? '' : 'invisible'),
          btn(ICON.trash, 'Remove', () => { const before = clone(state); list.splice(i, 1); changed(); renderWes(); toast('Entry removed.', { action: () => { restore(before); renderWes(); } }); }, 'text-error'))),
      el('div', { className: 'fields' }, date('from', 'From'), date('to', 'To'), ...WES_FIELDS.map(inp)));
  }));
}
function addWes(r) {
  wesList().push(r
    ? { from: r.from || '', to: r.to || '', position: r.position || '', unit: '', supervisor: '', agency: r.office || '', accomplishments: '', duties: '' }
    : { from: '', to: '', position: '', unit: '', supervisor: '', agency: '', accomplishments: '', duties: '' });
  document.activeElement?.blur();
  changed();
  renderWes();
  $('#wes-list').lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function openWes() {
  renderWes();
  $('#dlg-wes').showModal();
  checkWesFormats();
}

$('#wes-add').onclick = () => addWes(null);
// The official CSC template filled in on the server (wes.php), as PDF (converted from that Word file, so it matches)
// or as the Word file itself. Nothing is stored there.
async function downloadWes(format) {
  const list = wesList();
  if (!list.length) return toast('Add at least one entry first.');
  const f = state.fields;
  const name = [f.first_name, f.middle_name && !isNA(f.middle_name) ? `${f.middle_name[0]}.` : '', f.surname, f.name_ext && !isNA(f.name_ext) ? f.name_ext : ''].filter(Boolean).join(' ');
  const d = isIso(f.date_accomplished) ? f.date_accomplished : todayIso();
  const date = new Date(`${d}T00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  const btn = $(format === 'pdf' ? '#wes-pdf' : '#wes-docx');
  btn.disabled = true;
  btn.classList.add('loading');
  try {
    const res = await fetch('wes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ entries: list, name, date, file: fileName(), format }) });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The Work Experience Sheet could not be made.');
    const a = el('a', { href: URL.createObjectURL(await res.blob()), download: `${fileName()} - Work Experience Sheet.${format}` });
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    track('export_wes');
  } catch (e) {
    toast(e.message, { type: 'error', ms: 9000 });
  } finally {
    btn.disabled = false;
    btn.classList.remove('loading');
  }
}
$('#wes-pdf').onclick = () => downloadWes('pdf');
$('#wes-docx').onclick = () => downloadWes('docx');
// PDF needs LibreOffice on the server; where it is missing, the PDF button says so and Word remains.
let wesFormats = null;
async function checkWesFormats() {
  wesFormats ||= fetch('wes.php?formats').then((r) => r.json()).catch(() => ({ pdf: false }));
  const { pdf } = await wesFormats;
  $('#wes-pdf').disabled = !pdf;
  $('#wes-pdf').title = pdf ? 'The official CSC template, filled in, as a PDF' : 'PDF is not available on this server. Download the Word file and save it as PDF from Word.';
}
$('#exp-wes').onclick = () => { $('#dlg-export').close(); openWes(); };

// ---- Merge PDFs ------------------------------------------------------------------------------------------------------
// Combines the PDS, the Work Experience Sheet and supporting documents into one PDF for submission. Everything happens
// in the browser with pdf-lib (loaded only when needed); the files are never sent anywhere.
let mergeFiles = [];
let pdfLibReady = null;
const loadPdfLib = () => (pdfLibReady ||= new Promise((resolve, reject) => {
  if (window.PDFLib) return resolve(window.PDFLib);
  const s = el('script', { src: 'vendor/pdf-lib-1.17.1/pdf-lib.min.js' });
  s.onload = () => resolve(window.PDFLib);
  s.onerror = () => { pdfLibReady = null; reject(new Error('The PDF tool could not be loaded. Check your connection and try again.')); };
  document.head.append(s);
}));
const fileSize = (n) => (n < 1048576 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1048576).toFixed(1)} MB`);
const MERGE_MAX = 60 * 1048576;

async function addMergeFiles(list) {
  const files = [...list].filter((f) => /pdf|png|jpe?g/i.test(f.type) || /\.(pdf|png|jpe?g)$/i.test(f.name));
  if (files.length < list.length) toast('Only PDF, JPG and PNG files can be merged.', { type: 'warning' });
  if (!files.length) return;
  let lib;
  try {
    lib = await loadPdfLib();
  } catch (e) {
    return toast(e.message, { type: 'error' });
  }
  for (const f of files) {
    if (mergeFiles.reduce((a, x) => a + x.size, 0) + f.size > MERGE_MAX) {
      toast(`${f.name} was not added: the files together can be at most 60 MB.`, { type: 'warning' });
      continue;
    }
    const bytes = new Uint8Array(await f.arrayBuffer());
    const isPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name);
    let pages = 1;
    if (isPdf) {
      try {
        const doc = await lib.PDFDocument.load(bytes);
        pages = doc.getPageCount();
      } catch (e) {
        toast(/encrypt/i.test(String(e?.message)) ? `${f.name} is password-protected. Remove the password, then add it again.` : `${f.name} could not be read as a PDF.`, { type: 'error', ms: 8000 });
        continue;
      }
    }
    mergeFiles.push({ id: Math.random().toString(36).slice(2), name: f.name, size: f.size, type: isPdf ? 'pdf' : /png$/i.test(f.type) || /\.png$/i.test(f.name) ? 'png' : 'jpg', bytes, pages });
  }
  renderMerge();
}

function renderMerge() {
  const list = $('#merge-list');
  const total = mergeFiles.reduce((a, x) => a + x.pages, 0);
  $('#merge-empty').hidden = mergeFiles.length > 0;
  $('#merge-count').textContent = mergeFiles.length ? `${mergeFiles.length} file${mergeFiles.length > 1 ? 's' : ''} · ${total} page${total > 1 ? 's' : ''}` : '';
  $('#merge-go').disabled = !mergeFiles.length;
  const btn = (ic, title, fn, cls = '') => el('button', { type: 'button', className: `btn btn-ghost btn-xs btn-square ${cls}`, title, ariaLabel: title, onclick: fn }, icon(ic, 'size-4'));
  const move = (i, to) => { mergeFiles.splice(to, 0, mergeFiles.splice(i, 1)[0]); renderMerge(); };
  list.replaceChildren(...mergeFiles.map((f, i) => {
    const li = el('li', { className: 'merge-item', draggable: true },
      el('span', { className: 'merge-no' }, String(i + 1)),
      el('span', { className: `merge-type ${f.type}` }, f.type.toUpperCase()),
      el('span', { className: 'min-w-0 flex-1' }, el('b', { className: 'block truncate' }, f.name), el('small', {}, `${f.pages} page${f.pages > 1 ? 's' : ''} · ${fileSize(f.size)}`)),
      el('span', { className: 'flex gap-0.5' },
        btn(ICON.up, 'Move up', () => move(i, i - 1), i ? '' : 'invisible'),
        btn(ICON.down, 'Move down', () => move(i, i + 1), i < mergeFiles.length - 1 ? '' : 'invisible'),
        btn(ICON.trash, 'Remove', () => { mergeFiles.splice(i, 1); renderMerge(); }, 'text-error')));
    // Drag to reorder.
    li.ondragstart = (e) => { e.dataTransfer.setData('text/x-merge', String(i)); li.classList.add('dragging'); };
    li.ondragend = () => li.classList.remove('dragging');
    li.ondragover = (e) => { if (e.dataTransfer.types.includes('text/x-merge')) { e.preventDefault(); li.classList.add('over'); } };
    li.ondragleave = () => li.classList.remove('over');
    li.ondrop = (e) => {
      const from = Number(e.dataTransfer.getData('text/x-merge'));
      if (Number.isNaN(from) || !e.dataTransfer.types.includes('text/x-merge')) return;
      e.preventDefault();
      e.stopPropagation();
      move(from, i);
    };
    return li;
  }));
}

async function mergeAndDownload() {
  if (!mergeFiles.length) return;
  const btn = $('#merge-go');
  btn.disabled = true;
  btn.classList.add('loading');
  try {
    const lib = await loadPdfLib();
    const out = await lib.PDFDocument.create();
    const A4 = [595.28, 841.89], M = 28;
    for (const f of mergeFiles) {
      if (f.type === 'pdf') {
        const src = await lib.PDFDocument.load(f.bytes);
        (await out.copyPages(src, src.getPageIndices())).forEach((p) => out.addPage(p));
      } else {
        // A picture (a scanned certificate, a photo of a document) on an A4 page, as large as fits, centered;
        // landscape pictures get a landscape page.
        const img = f.type === 'png' ? await out.embedPng(f.bytes) : await out.embedJpg(f.bytes);
        const [w, h] = img.width > img.height ? [A4[1], A4[0]] : A4;
        const s = Math.min((w - 2 * M) / img.width, (h - 2 * M) / img.height);
        const page = out.addPage([w, h]);
        page.drawImage(img, { x: (w - img.width * s) / 2, y: (h - img.height * s) / 2, width: img.width * s, height: img.height * s });
      }
    }
    const name = `${fileName()} - documents`;
    out.setTitle(name);
    out.setProducer('PDSync');
    out.setCreator('PDSync');
    const blob = new Blob([await out.save()], { type: 'application/pdf' });
    const a = el('a', { href: URL.createObjectURL(blob), download: `${name}.pdf` });
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    toast(`Merged ${mergeFiles.length} files into one PDF (${fileSize(blob.size)}).`, { type: 'success' });
    track('merge_pdf');
  } catch (e) {
    toast(`The files could not be merged: ${e.message}`, { type: 'error', ms: 9000 });
  } finally {
    btn.disabled = !mergeFiles.length;
    btn.classList.remove('loading');
  }
}

function openMerge() {
  renderMerge();
  $('#dlg-merge').showModal();
  loadPdfLib().catch(() => {});
}
$('#merge-input').onchange = (e) => { addMergeFiles(e.target.files); e.target.value = ''; };
$('#merge-go').onclick = mergeAndDownload;
{
  const zone = $('#merge-drop');
  zone.ondragover = (e) => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); zone.classList.add('drop'); } };
  zone.ondragleave = (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('drop'); };
  zone.ondrop = (e) => {
    zone.classList.remove('drop');
    if (!e.dataTransfer.files.length) return;
    e.preventDefault();
    addMergeFiles(e.dataTransfer.files);
  };
}
$('#exp-merge').onclick = () => { $('#dlg-export').close(); openMerge(); };

// ---- Ready to submit ------------------------------------------------------------------------------------------------
// The things HR usually sends a PDS back for, in three levels: must fix (empty or malformed), worth a look (order,
// incomplete entries, references, photo, an old date), and good to know. Each item can jump to its place, and some
// can be fixed in one click.
const TABLE_KEYS = {
  education: ['level', 'school'],
  eligibility: ['name'],
  work: ['from', 'to', 'position', 'office'],
  learning: ['title', 'from', 'to', 'hours'],
  voluntary: ['org', 'from', 'to'],
  references: ['name', 'address', 'tel'],
  children: ['name', 'dob'],
};
const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const listNames = (a, n = 3) => a.slice(0, n).join(', ') + (a.length > n ? ` and ${a.length - n} more` : '');

function readiness() {
  const out = [];
  const add = (level, i, title, hint, extra = {}) => out.push({ level, i, title, hint, ...extra });
  S.forEach((sec, i) => {
    const name = TAB[sec.id] || sec.title;
    const info = sectionInfo(sec);
    if (info.missing.length) {
      const yn = info.missing.filter((f) => f.t === 'yn');
      const rest = info.missing.filter((f) => f.t !== 'yn');
      if (yn.length) add('fix', i, `${name}: ${yn.length} question${yn.length > 1 ? 's' : ''} not answered`, 'Every question needs Yes or No.', { k: yn[0].k });
      if (rest.length) {
        const na = rest.filter(canNA);
        add('fix', i, `${name}: ${rest.length} empty item${rest.length > 1 ? 's' : ''}`, `${listNames(rest.map((f) => f.l))}. Answer them, or write N/A if they do not apply.`,
          { k: rest[0].k, act: na.length ? { label: 'Write N/A', fn: () => fillEmptyNA(i) } : null });
      }
    }
    info.emptyTables.forEach((t) => add('fix', i, `${name}: ${t.l.replace(/\s*\(.*\)/, '')} is empty`, `Add your ${t.l.replace(/\s*\(.*\)/, '').toLowerCase()}, or one entry with N/A if you have none.`));
    (sec.fields || []).filter(visible).forEach((f) => {
      const m = check(f, state.fields[f.k]);
      if (m) add('fix', i, `${name}: ${f.l}`, m, { k: f.k });
    });
    (sec.tables || []).forEach((t) => {
      const rows = state.tables[t.k] || [];
      rows.forEach((row, r) => t.c.forEach((c) => {
        // Work entries that overlap the next one: a stale "Present" must be fixed, a short overlap is worth a look.
        const across = t.k === 'work' && c.k === 'to' ? workConflict(rows, row, 'to') : null;
        if (across) {
          return add(row.to === 'PRESENT' ? 'fix' : 'check', i, `${name}: entry ${r + 1}${filled(row.position) ? ` (${row.position})` : ''}, ${c.l}`, across.msg,
            { act: { label: `End on ${toDmy(across.fix)}`, fn: () => setCell(t.k, row, 'to', across.fix) } });
        }
        const m = check(c, row[c.k], { table: t.k, row, rows });
        if (m) add('fix', i, `${name}: entry ${r + 1}, ${c.l}`, m);
      }));
      // Entries that are started but miss the details the form needs; an all-N/A entry is fine.
      const need = TABLE_KEYS[t.k] || [];
      rows.forEach((row, r) => {
        if (!rowFilled(row) || Object.values(row).filter(filled).every(isNA)) return;
        const blank = need.filter((k) => !filled(row[k])).map((k) => t.c.find((c) => c.k === k)?.l || k);
        if (blank.length) add('check', i, `${name}: entry ${r + 1} is incomplete`, `${listNames(blank)} ${blank.length > 1 ? 'are' : 'is'} blank.`);
      });
      if (sortable(t)) {
        const real = rows.filter((r) => newest(r));
        if (real.some((r, j) => j && newest(r) > newest(real[j - 1]))) {
          add('check', i, `${name}: not in order`, 'The form lists the most recent first.', { act: { label: 'Sort newest first', fn: () => sortNewest(t) } });
        }
      }
    });
  });
  const ri = S.findIndex((sec) => (sec.tables || []).some((t) => t.k === 'references'));
  if (ri >= 0) {
    const n = (state.tables.references || []).filter((r) => filled(r.name) && !isNA(r.name)).length;
    if (n && n < 3) add('check', ri, `References: ${n} of 3`, 'The form asks for three people who are not your relatives.');
  }
  const pi = S.findIndex((sec) => (sec.fields || []).some((f) => f.k === 'photo'));
  if (pi >= 0) {
    if (!filled(state.fields.photo)) add('check', pi, 'No ID photo', 'Add a passport-size photo taken within the last 6 months, or paste a printed one on the form.', { k: 'photo' });
    const d = state.fields.date_accomplished;
    if (isIso(d)) {
      const days = Math.round((new Date(todayIso()) - new Date(d)) / 86400000);
      if (days > 30) add('check', pi, `Date accomplished is ${days} days ago`, 'Use the date you will sign and submit the form.', { k: 'date_accomplished', act: { label: 'Use today', fn: () => setField('date_accomplished', todayIso()) } });
    }
    const hand = [!filled(state.fields.signature) && 'signature', !filled(state.fields.thumbmark) && 'right thumbmark'].filter(Boolean);
    if (hand.length) add('tip', pi, `No e-${hand.join(' or ')}`, `That is fine: put your ${hand.join(' and ')} on the printout by hand.`, { k: hand[0] === 'signature' ? 'signature' : 'thumbmark' });
  }
  return out;
}

function setField(k, v) {
  const before = clone(state);
  state.fields[k] = v;
  changed();
  render();
  toast('Updated.', { action: () => restore(before) });
}
// Sets one value of a table entry (a one-click fix), with Undo.
function setCell(table, row, key, value) {
  const before = clone(state);
  row[key] = value;
  changed();
  render();
  toast('Updated.', { action: () => restore(before) });
}
function sortNewest(t) {
  const before = clone(state);
  const rows = state.tables[t.k] || [];
  state.tables[t.k] = [...rows].sort((a, b) => newest(b).localeCompare(newest(a)));
  changed();
  render();
  toast('Sorted, most recent first.', { action: () => restore(before) });
}

const LEVELS = { fix: ['Must fix', 'bad'], check: ['Worth a look', 'warn'], tip: ['Good to know', 'muted'] };
function readinessSummary(items = readiness()) {
  const n = { fix: 0, check: 0, tip: 0 };
  items.forEach((it) => n[it.level]++);
  const infos = S.map(sectionInfo);
  const all = infos.reduce((a, x) => [a[0] + x.done, a[1] + x.total], [0, 0]);
  const pct = Math.round((100 * all[0]) / (all[1] || 1));
  const title = n.fix ? (n.fix <= 3 ? 'Almost ready' : 'Keep going') : n.check ? 'Nearly there' : 'Ready to submit';
  const sub = n.fix || n.check ? [n.fix && `${n.fix} to fix`, n.check && `${n.check} to check`].filter(Boolean).join(' · ') : 'Nothing HR would send back.';
  return { n, pct, title, sub, tone: n.fix ? 'bad' : n.check ? 'warn' : 'ok' };
}
const ring = (pct, tone) => el('span', { className: `ready-ring ${tone}`, style: `--p:${pct}`, ariaHidden: 'true' }, el('b', {}, `${pct}%`));

function renderCheck() {
  const items = readiness(), sum = readinessSummary(items);
  $('#chk-head').replaceChildren(ring(sum.pct, sum.tone), el('div', { className: 'min-w-0' }, el('h3', { className: 'title-serif text-2xl' }, sum.title), el('p', { className: 'text-sm text-base-content/65' }, sum.sub)));
  const body = $('#chk-body');
  if (!sum.n.fix && !sum.n.check && !sum.n.tip) {
    body.replaceChildren(el('div', { className: 'chk-done' }, el('span', { className: 'chk-done-ico' }, icon(ICON.check, 'size-6')),
      el('b', {}, 'Everything looks complete'), el('p', {}, 'Every item is answered, entries are in order and nothing looks unusual. Export it and sign the printout.')));
    return;
  }
  body.replaceChildren(...Object.entries(LEVELS).filter(([lv]) => sum.n[lv]).map(([lv, [label, tone]]) => el('section', { className: 'chk-group' },
    el('h4', {}, el('span', { className: `chip ${tone}` }, label), el('small', {}, String(sum.n[lv]))),
    el('ul', {}, ...items.filter((it) => it.level === lv).map((it) => el('li', { className: 'chk-item' },
      el('div', { className: 'min-w-0 flex-1' }, el('b', {}, it.title), el('p', {}, it.hint)),
      el('div', { className: 'chk-acts' },
        it.act ? el('button', { className: 'btn btn-xs btn-primary btn-soft', onclick: () => { it.act.fn(); renderCheck(); } }, it.act.label) : null,
        el('button', { className: 'btn btn-xs btn-ghost', onclick: () => { $('#dlg-check').close(); go(it.i, it.k); } }, 'Go', icon(ICON.down, 'size-3 -rotate-90')))))))));
}
function openCheck() {
  renderCheck();
  $('#dlg-check').showModal();
}

function openExport() {
  $('#dlg-name').textContent = fileName();
  const sum = readinessSummary();
  $('#review').replaceChildren(el('button', { className: `ready-card ${sum.tone}`, onclick: () => { $('#dlg-export').close(); openCheck(); } },
    ring(sum.pct, sum.tone),
    el('span', { className: 'min-w-0 flex-1 text-left' }, el('b', {}, sum.title), el('small', {}, sum.n.fix || sum.n.check ? `${sum.sub}. You can still export now and fix these later.` : 'Every item is answered and nothing looks unusual.')),
    el('span', { className: 'text-xs font-semibold text-primary whitespace-nowrap' }, sum.n.fix || sum.n.check ? 'Review' : 'Details')));
  $('#oath-ok').checked = false;
  syncOath();
  $('#dlg-export').showModal();
}
// The form's own declaration must be read before exporting (CSC: the PDS is accomplished and signed under oath).
function syncOath() {
  const ok = $('#oath-ok').checked;
  document.querySelectorAll('#exp-choices .choice').forEach((b) => (b.disabled = !ok));
  $('#exp-choices').classList.toggle('locked', !ok);
}
$('#oath-ok').onchange = syncOath;
$('#btn-check')?.addEventListener('click', openCheck);
$('#chk-export').onclick = () => { $('#dlg-check').close(); openExport(); };

$('#btn-export-open').onclick = openExport;
$('#exp-pdf').onclick = () => { $('#dlg-export').close(); openPrint(); };
['#pr-paper', '#pr-margin', '#pr-pages'].forEach((s) => $(s).addEventListener('change', drawPaper));
$('#dlg-print').addEventListener('close', () => {
  if ($('#dlg-print').returnValue === 'print') exportPdf();
  $('#dlg-print').returnValue = '';
});
$('#exp-xlsx').onclick = () => { $('#dlg-export').close(); exportXlsx(); };

// ---- Consent (Data Privacy Act) --------------------------------------------------------------------------------------
// Nothing is saved on the server until the person agrees to the current privacy notice (window.CONSENT). Without it
// the PDS stays in this browser only; the save status says so and opens this dialog again when clicked.
const consented = () => store.get('pds-consent') === window.CONSENT;
function askConsent() {
  const dlg = $('#dlg-consent');
  if (dlg.open) return Promise.resolve();
  $('#consent-ok').checked = false;
  $('#consent-yes').disabled = true;
  dlg.showModal();
  return new Promise((done) => dlg.addEventListener('close', done, { once: true }));
}
$('#consent-ok').onchange = (e) => ($('#consent-yes').disabled = !e.target.checked);
$('#consent-yes').onclick = () => {
  store.set('pds-consent', window.CONSENT);
  $('#dlg-consent').close();
  toast('Thank you. Your PDS is now saved securely online.');
  if (hasAnswers()) autoSave(true);
};
$('#consent-no').onclick = () => {
  $('#dlg-consent').close();
  setSave('local');
  toast('Your PDS stays on this device only. Click the save status any time to save it online.', { ms: 7000 });
};
// Escape counts as "Not now".
$('#dlg-consent').addEventListener('cancel', () => setSave('local'));

// ---- Saving --------------------------------------------------------------------------------------------------------
// Every change is kept in this browser at once and sent to the server shortly after. Unchanged images are sent as "="
// so a save stays small, nothing is sent when nothing changed, and a copy saved elsewhere in the meantime is not
// overwritten without asking.
let timer;
let lastSent = '';
let sentImages = {};
const saveKey = () => JSON.stringify([state, $('#inp-name').value]);

function changed() {
  clearTimeout(timer);
  $('#inp-name').placeholder = autoName();
  renderSteps();
  setSave('unsaved');
  store.set(DRAFT, JSON.stringify({ state }));
  timer = setTimeout(() => {
    timer = null;
    autoSave();
  }, 800);
}

let saving = false;
let again = false;
let conflict = false;
let errorShown = false;
async function autoSave(force = false) {
  if (conflict) return;
  if (!consented()) return setSave('local');
  if (saving) return (again = true);
  saving = true;
  setSave('saving');
  try {
    await save(force);
    setSave('saved');
    errorShown = false;
  } catch (e) {
    if (e.conflict) {
      saving = false;
      return onConflict();
    }
    if (!navigator.onLine || e instanceof TypeError) {
      setSave('offline');
    } else {
      setSave('error');
      if (!errorShown) toast(`Not saved: ${e.message}. Your changes are kept on this device.`, { type: 'error', action: () => autoSave(), label: 'Retry', ms: 10000 });
      errorShown = true;
    }
  }
  saving = false;
  if (again) {
    again = false;
    autoSave();
  }
}
addEventListener('online', () => autoSave());
addEventListener('offline', () => setSave('offline'));
$('#save-pill').onclick = () => (consented() ? autoSave() : askConsent());

addEventListener('beforeunload', (e) => {
  if (timer || saving) e.preventDefault();
});

async function save(force = false) {
  const key = saveKey();
  if (key === lastSent && !force) return;
  const fields = { ...state.fields };
  if (auth.id) IMAGE_KEYS.forEach((k) => { if (filled(fields[k]) && fields[k] === sentImages[k]) fields[k] = '='; });
  const res = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    // Signed-in users save by id (ownership); anonymous saves go to this browser's cookie PDS.
    body: JSON.stringify({
      ...(window.USER && auth.id ? { id: auth.id } : {}),
      data: { fields, tables: state.tables, wes: state.wes || [] },
      name: $('#inp-name').value,
      ...(auth.updated ? { base: auth.updated } : {}),
      ...(force ? { force: true } : {}),
      consent: window.CONSENT,
    }),
  });
  const out = await res.json().catch(() => ({}));
  if (res.status === 409) throw Object.assign(new Error(out.error), { conflict: true });
  if (res.status === 428) {
    store.set('pds-consent', '');
    askConsent();
    throw new Error('Agree to the privacy notice to save online');
  }
  if (!res.ok) throw new Error(out.error || 'Save failed');
  const was = auth.id;
  auth = { id: out.id, updated: out.updated };
  if (window.USER) store.set(CURRENT, out.id);
  if (was && was !== out.id) {
    // A new record was made (the old one expired), so images sent as "=" were not stored: send everything again.
    sentImages = {};
    lastSent = '';
    again = true;
    return;
  }
  lastSent = key;
  IMAGE_KEYS.forEach((k) => (sentImages[k] = state.fields[k] || ''));
}

// Another tab or device saved this PDS after this page loaded it.
async function onConflict() {
  conflict = true;
  setSave('error', 'Changed elsewhere');
  const pick = await ask({
    title: 'This PDS was changed somewhere else',
    body: ['It was saved from another tab or device after you opened it here. Which copy do you want to keep?',
      'If you load the other copy, you can still undo and get yours back.'],
    buttons: [{ label: 'Load the other copy', value: 'theirs' }, { label: 'Keep my copy', value: 'mine', cls: 'btn-primary' }],
  });
  conflict = false;
  if (pick === 'theirs') {
    const mine = clone(state);
    const res = await fetch(auth.id && window.USER ? `api.php?id=${encodeURIComponent(auth.id)}` : 'api.php');
    const out = res.ok ? await res.json() : null;
    if (out?.id) {
      useServerCopy(out);
      render();
      setSave('saved');
      toast('Loaded the other copy.', { action: () => { state = mine; render(); autoSave(true); }, ms: 15000 });
      return;
    }
  }
  autoSave(true);
}

// Takes a copy from the server as the current state.
function useServerCopy(out) {
  state = out.data;
  auth = { id: out.id, updated: out.updated };
  $('#inp-name').value = out.name || '';
  sentImages = Object.fromEntries(IMAGE_KEYS.map((k) => [k, state.fields[k] || '']));
  lastSent = saveKey();
  store.set(DRAFT, JSON.stringify({ state }));
  if (window.USER) store.set(CURRENT, out.id);
}

async function load() {
  if (window.USER && new URLSearchParams(location.hash.slice(1)).has('new')) {
    history.replaceState(null, '', location.pathname);
    [DRAFT, CURRENT].forEach(store.del);
    setSave('idle');
    render();
    return;
  }
  const draft = JSON.parse(store.get(DRAFT) || 'null');
  const byId = (id) => fetch(`api.php?id=${encodeURIComponent(id)}`);
  if (openId) {
    store.set(CURRENT, openId);
    history.replaceState(null, '', location.pathname);
  }
  const current = window.USER && store.get(CURRENT);
  const get = async (req) => {
    const res = await req;
    return res.ok ? res.json() : null;
  };
  try {
    let out = current ? await get(byId(current)) : null;
    if (!out?.id) out = await get(fetch('api.php'));
    // On a new device, a signed-in user continues their most recent PDS.
    if (!out?.id && window.USER) {
      const latest = (await (await fetch('account.php')).json()).docs[0];
      if (latest) out = await get(byId(latest.id));
    }
    if (out?.id) {
      useServerCopy(out);
      setSave('saved');
    } else if (draft?.state) {
      state = draft.state;
      setSave('unsaved');
      autoSave();
    }
  } catch {
    if (draft?.state) state = draft.state;
    setSave('offline');
  }
  state.fields ||= {};
  state.tables ||= {};
  render();
}

// ---- Import, new, delete, backups, versions ---------------------------------------------------------------------------
const hasAnswers = () => Object.entries(state.fields).some(([k, v]) => !IMAGE_KEYS.includes(k) && filled(v)) || Object.values(state.tables).some((r) => r.some(rowFilled));

// Starts over from a fresh, unsaved PDS on this page (used for "Import as a new PDS").
function startFresh(data, name = '') {
  auth = {};
  sentImages = {};
  lastSent = '';
  store.del(CURRENT);
  state = data;
  $('#inp-name').value = name;
  step = 0;
  render();
  changed();
}

async function importXlsx(file) {
  if (!file) return;
  const t = toast('Reading the Excel file…', { ms: 0 });
  let out;
  try {
    const body = new FormData();
    body.append('file', file);
    const res = await fetch('xlsx.php', { method: 'POST', body });
    out = await res.json();
    if (!res.ok) throw new Error(out.error);
  } catch (e) {
    return toast(e.message || 'The file could not be read.', { type: 'error' });
  } finally {
    t.remove();
  }
  const count = Object.values(out.data.fields).filter(filled).length + Object.values(out.data.tables).flat().length;
  if (!count) return toast(`No answers found. Use a filled-out ${window.FORM.name} .xlsx file.`, { type: 'warning' });
  let how = 'replace';
  if (hasAnswers()) {
    how = await ask({
      title: 'Replace your current answers?',
      body: [`The Excel file has ${count} answers. Importing replaces what you entered here; photos and signatures you added stay.`],
      buttons: [{ label: 'Cancel', value: '' }, ...(window.USER ? [{ label: 'Import as a new PDS', value: 'new' }] : []), { label: 'Replace', value: 'replace', cls: 'btn-primary' }],
    });
    if (!how) return;
  }
  IMAGE_KEYS.forEach((k) => (out.data.fields[k] = how === 'new' ? '' : state.fields[k] || ''));
  if (how === 'new') {
    startFresh(out.data);
    return toast('Imported into a new PDS. Your previous one stays in My PDS.', { type: 'success', action: null });
  }
  track('import_xlsx');
  const before = clone(state);
  state = out.data;
  render();
  changed();
  toast('Imported from Excel.', { type: 'success', action: () => restore(before), ms: 15000 });
}
$('#inp-xlsx').onchange = (e) => { importXlsx(e.target.files[0]); e.target.value = ''; };
$('#inp-xlsx-m').onchange = (e) => { importXlsx(e.target.files[0]); e.target.value = ''; };

// Starts a blank PDS. Signed in, the current one stays in My PDS; otherwise this browser's PDS is deleted.
async function newPds() {
  try {
    await fetch('api.php', { method: 'DELETE' });
  } catch {}
  [DRAFT, CURRENT, 'pds-name'].forEach(store.del);
  location.href = location.pathname;
}
function downloadCopy() {
  const blob = new Blob([JSON.stringify({ app: 'PDSync', form: window.FORM.id, saved: new Date().toISOString(), name: $('#inp-name').value, data: state }, null, 1)], { type: 'application/json' });
  el('a', { href: URL.createObjectURL(blob), download: `${fileName()}.json` }).click();
}
async function askNew() {
  if (window.USER) {
    if (!await ask({ title: 'Start a new blank PDS?', body: 'The current one stays saved in My PDS.', buttons: [{ label: 'Cancel', value: '' }, { label: 'New PDS', value: 'new', cls: 'btn-primary' }] })) return;
    startFresh({ fields: {}, tables: {} });
    store.del(DRAFT);
    toast('New PDS started. Your other PDS are in My PDS.', { type: 'success' });
    return;
  }
  const pick = await ask({
    title: 'Start a new blank PDS?',
    body: ['Without signing in, this browser keeps one PDS. Starting a new one deletes the current one from this PC and our server.',
      'Download a copy first if you want to keep it.'],
    buttons: [{ label: 'Cancel', value: '' }, { label: 'Download a copy', value: 'copy' }, { label: 'Delete and start new', value: 'new', cls: 'btn-error' }],
  });
  if (pick === 'copy') downloadCopy();
  if (pick === 'new') newPds();
}
async function askDelete() {
  if (window.USER) {
    if (!auth.id) return toast('This PDS is not saved yet.');
    if (await ask({ title: 'Move this PDS to the Trash?', body: 'You can restore it from My PDS within 30 days.', buttons: [{ label: 'Cancel', value: '' }, { label: 'Move to Trash', value: 'del', cls: 'btn-error' }] }) !== 'del') return;
    try {
      await account('trash', { id: auth.id });
      [DRAFT, CURRENT].forEach(store.del);
      location.href = `library.php#trashed=${auth.id}`;
    } catch (e) {
      toast(e.message, { type: 'error' });
    }
    return;
  }
  const pick = await ask({
    title: 'Delete your PDS from this PC?',
    body: ['Your answers, photo and signature are deleted from this browser and from our server. Do this when you finish on a shared computer.',
      'Download a copy first if you want to keep it.'],
    buttons: [{ label: 'Cancel', value: '' }, { label: 'Download a copy', value: 'copy' }, { label: 'Delete', value: 'del', cls: 'btn-error' }],
  });
  if (pick === 'copy') downloadCopy();
  if (pick === 'del') newPds();
}
$('#btn-clear').onclick = askNew;

// Opens a copy saved with "Download a copy".
$('#inp-backup').onchange = async (e) => {
  const file = e.target.files[0];
  e.target.value = '';
  if (!file) return;
  let copy;
  try {
    copy = JSON.parse(await file.text());
    if (!copy?.data?.fields || !copy?.data?.tables) throw new Error();
  } catch {
    return toast('This is not a PDSync copy (.json).', { type: 'error' });
  }
  if (hasAnswers() && await ask({ title: 'Open this copy?', body: `It replaces your current answers with the copy saved ${copy.saved ? new Date(copy.saved).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'earlier'}.`, buttons: [{ label: 'Cancel', value: '' }, { label: 'Open copy', value: 'ok', cls: 'btn-primary' }] }) !== 'ok') return;
  const before = clone(state);
  state = copy.data;
  if (copy.name) $('#inp-name').value = copy.name;
  render();
  changed();
  toast('Copy opened.', { type: 'success', action: () => restore(before), ms: 15000 });
};

async function showVersions() {
  if (!auth.id) return toast('Nothing is saved yet, so there are no earlier versions.');
  const q = window.USER ? `&id=${encodeURIComponent(auth.id)}` : '';
  let list;
  try {
    list = (await (await fetch(`api.php?versions${q}`)).json()).versions || [];
  } catch {
    return toast('Version history needs a connection.', { type: 'warning' });
  }
  const when = (t) => new Date(t * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  $('#versions-list').replaceChildren(...list.map((v) => el('li', {},
    el('div', {}, el('strong', {}, when(v.saved))),
    el('button', { className: 'btn btn-sm', onclick: async () => {
      const out = await (await fetch(`api.php?version=${v.id}${q}`)).json();
      if (!out.data) return toast('This version could not be opened.', { type: 'error' });
      $('#dlg-versions').close();
      const before = clone(state);
      state = out.data;
      render();
      changed();
      toast(`Restored the version from ${when(v.saved)}.`, { type: 'success', action: () => restore(before), ms: 15000 });
    } }, 'Restore'))));
  if (!list.length) $('#versions-list').append(el('li', { className: 'text-sm text-base-content/60' }, 'No earlier versions yet. A version is kept every 10 minutes while you edit.'));
  $('#dlg-versions').showModal();
}

// ---- Account (signed in) ----------------------------------------------------------------------------------------------
async function account(op, body) {
  const res = await fetch('account.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op, ...body }) });
  const out = await res.json();
  if (!res.ok) throw new Error(out.error);
  return out;
}
// Signing out leaves nothing of the account's PDS on this PC.
if (window.USER) $('#form-logout').onsubmit = () => [DRAFT, CURRENT, 'pds-name'].forEach(store.del);

// ---- Menus, theme, help, keyboard ----------------------------------------------------------------------------------
const MENU = {
  new: askNew,
  help: () => $('#dlg-help').showModal(),
  versions: showVersions,
  wes: () => openWes(),
  merge: () => openMerge(),
  backup: downloadCopy,
  delete: askDelete,
  'rename-file': async () => {
    const r = await ask({ title: 'File name', body: 'Used as the name of your PDF and Excel files.', input: $('#inp-name').value || autoName(), buttons: [{ label: 'Cancel', value: '' }, { label: 'Save', value: 'ok', cls: 'btn-primary' }] });
    if (!r) return;
    $('#inp-name').value = r.text;
    changed();
  },
};
document.querySelectorAll('[data-act]').forEach((b) => {
  if (!MENU[b.dataset.act]) return;
  b.addEventListener('click', () => {
    document.activeElement?.blur();
    MENU[b.dataset.act]();
  });
});
$('#btn-help').onclick = MENU.help;
$('#inp-name').oninput = changed;

function applyTheme(t) {
  if (t) document.documentElement.dataset.theme = t;
  else delete document.documentElement.dataset.theme;
  document.querySelectorAll('[data-theme-pick]').forEach((b) => b.classList.toggle('btn-active', b.dataset.themePick === (t || '')));
}
document.querySelectorAll('[data-theme-pick]').forEach((b) => (b.onclick = () => {
  const t = b.dataset.themePick;
  if (t) store.set('pds-theme', t);
  else store.del('pds-theme');
  applyTheme(t);
}));
applyTheme(store.get('pds-theme') || '');

// Alt+PageUp / Alt+PageDown move between sections from anywhere.
addEventListener('keydown', (e) => {
  if (!e.altKey || (e.key !== 'PageDown' && e.key !== 'PageUp') || document.querySelector('dialog[open]')) return;
  e.preventDefault();
  go(step + (e.key === 'PageDown' ? 1 : -1));
});

$('#btn-prev').onclick = () => go(step - 1);
$('#btn-next').onclick = () => (step === S.length - 1 ? openExport() : go(step + 1));
$('#form').addEventListener('change', () => refreshPreview());
$('#form').addEventListener('focusout', () => refreshPreview());

// Offline support: the app shell is cached so the page opens without a connection; saving resumes when back online.
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
  addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}

setPreview(wide.matches && store.get('pds-preview') !== 'hidden', false);
load().then(async () => {
  if (!consented()) {
    await askConsent();
    if (!consented()) setSave('local');
  }
  if (!store.get('pds-welcomed')) {
    $('#dlg-welcome').addEventListener('close', () => store.set('pds-welcomed', '1'), { once: true });
    $('#dlg-welcome').showModal();
  }
});
track('visit');
