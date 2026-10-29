// PDS editor: renders the schema, autosaves locally, and saves to the server for sharing.
const S = window.SCHEMA;
const DRAFT = 'pds-draft';
const CURRENT = 'pds-current';
const $ = (sel) => document.querySelector(sel);
const el = (tag, attrs = {}, ...kids) => {
  const n = Object.assign(document.createElement(tag), attrs);
  n.append(...kids);
  return n;
};

let state = { fields: {}, tables: {} };
// An edit link (#id=..&key=..) or #open=id (signed in) opens that PDS; otherwise the last one opened on this PC.
let link = Object.fromEntries(new URLSearchParams(location.hash.slice(1)));
let auth = {};
let step = 0;

// Converts d/m/yyyy (also with - or .) to stored yyyy-mm-dd; other text like N/A is kept as typed.
const toIso = (v) => {
  const m = v.trim().match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
  return m ? `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}` : v.trim();
};
const toDmy = (v) => (v || '').replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1');

// Formats a numeric amount as pesos (38413 -> ₱38,413.00); other text like N/A is kept.
const toPeso = (v) => {
  const n = (v || '').replace(/[₱,\s]|PHP/gi, '');
  return n !== '' && !isNaN(n) ? `₱${Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : (v || '').trim();
};

// Opens the cropper on an image; resolves with the cropped region on a canvas, or null when cancelled.
// With an aspect (width / height) the crop box keeps that shape; otherwise it is free.
function cropImage(bmp, aspect) {
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
    draw();
    dlg.showModal();
  });
}

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

function input(def, value, onChange) {
  const t = def.t || 'text';
  let n;
  if (t === 'date') {
    const isIso = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v || '');
    const text = el('input', { type: 'text', className: 'input join-item w-full', placeholder: 'dd/mm/yyyy', value: toDmy(value) });
    const pick = el('input', { type: 'date', className: 'absolute bottom-0 right-0 size-px opacity-0 pointer-events-none', tabIndex: -1, value: isIso(value) ? value : '' });
    const btn = el('button', { type: 'button', className: 'btn join-item px-3', title: 'Pick a date', onclick: (e) => {
      e.preventDefault();
      pick.showPicker ? pick.showPicker() : pick.click();
    } });
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2zm-2 8h14v10H5z"/></svg>';
    text.addEventListener('input', () => {
      const iso = toIso(text.value);
      pick.value = isIso(iso) ? iso : '';
      onChange(iso);
    });
    text.addEventListener('blur', () => (text.value = toDmy(toIso(text.value))));
    pick.addEventListener('change', () => {
      text.value = toDmy(pick.value);
      onChange(pick.value);
    });
    return el('span', { className: 'join relative w-full' }, text, btn, pick);
  }
  if (t === 'image') {
    const img = el('img', { alt: def.l, hidden: !value });
    if (value) img.src = value;
    const file = el('input', { type: 'file', accept: 'image/png,image/jpeg', hidden: true });
    const rm = el('button', { type: 'button', textContent: 'Remove', className: 'btn btn-ghost btn-sm text-error', hidden: !value });
    const up = el('button', { type: 'button', className: 'btn btn-sm', textContent: value ? 'Change' : 'Upload (PNG/JPG)' });
    const set = (v) => {
      img.src = v;
      img.hidden = rm.hidden = !v;
      up.textContent = v ? 'Change' : 'Upload (PNG/JPG)';
      onChange(v);
      refreshPreview();
    };
    up.onclick = (e) => { e.preventDefault(); file.click(); };
    rm.onclick = (e) => { e.preventDefault(); set(''); };
    file.onchange = async () => {
      try {
        const cropped = file.files[0] && (await cropImage(await createImageBitmap(file.files[0]), def.aspect));
        if (cropped) set(encodeImage(cropped, def.clean));
      } catch (err) {
        $('#status').textContent = err.message;
      }
      file.value = '';
    };
    const box = el('div', { className: 'img-box' }, img);
    if (def.aspect) Object.assign(box.style, { width: '110px', height: `${Math.round(110 / def.aspect)}px` });
    return el('div', { className: 'img-field' }, box, up, rm, file,
      el('small', {}, def.clean
        ? 'Crop after choosing the file. White background is removed automatically.'
        : 'Crop after choosing the file. The crop box is fixed at 3.5 cm x 4.5 cm (passport size).'));
  }
  if (t === 'money') {
    n = el('input', { type: 'text', className: 'input w-full', placeholder: '₱0.00', inputMode: 'decimal', value: toPeso(value) });
    n.addEventListener('input', () => onChange(n.value));
    n.addEventListener('blur', () => onChange((n.value = toPeso(n.value))));
    return n;
  }
  if (t === 'yn') {
    const name = `yn-${def.k}`;
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
  if (def.s) {
    n.setAttribute('list', datalist(def.s));
    n.placeholder = 'Type or choose';
  }
  if (!def.sw) return n;
  // Warns while a typed value is outside the list (e.g. a country missing from the official Excel list); hidden by CSS when empty.
  const warn = el('small', { className: 'warn' }, def.sw);
  const check = () => (warn.hidden = def.s.some((o) => o.toLowerCase() === n.value.trim().toLowerCase()));
  n.addEventListener('input', check);
  check();
  return el('span', { className: 'flex flex-col gap-1.5' }, n, warn);
}

// One <datalist> per suggestion list, shared by every input that uses it.
const lists = new Map();
function datalist(opts) {
  if (!lists.has(opts)) {
    const id = `dl-${lists.size}`;
    document.body.append(el('datalist', { id }, ...opts.map((o) => el('option', { value: o }))));
    lists.set(opts, id);
  }
  return lists.get(opts);
}

function field(def, value, onChange) {
  if (def.t === 'check') {
    const n = el('input', { type: 'checkbox', className: 'checkbox checkbox-primary checkbox-sm', checked: value === 'Yes' });
    n.dataset.k = def.k;
    n.addEventListener('change', () => onChange(n.checked ? 'Yes' : ''));
    return el('label', { className: `f check w${def.w || 1}` }, n, el('span', {}, def.l));
  }
  const n = input(def, value, onChange);
  n.dataset.k = def.k;
  // Groups of buttons or radios get a div, since a label would forward clicks to its first control.
  const tag = def.t === 'yn' || def.t === 'image' ? 'div' : 'label';
  return el(tag, { className: `f w${def.w || 1}${def.t === 'yn' ? ' q' : ''}` }, el('span', {}, def.l), n);
}

function renderTable(t) {
  const rows = (state.tables[t.k] ||= []);
  const box = el('section', { className: 'grp' }, el('h3', {}, t.l, el('span', { className: 'count' }, `${rows.length} of ${t.max || 50}`)));
  rows.forEach((row, i) => {
    const grid = el('div', { className: 'fields' });
    t.c.forEach((c) => grid.append(field(c, row[c.k], (v) => { row[c.k] = v; changed(); })));
    const rm = el('button', { className: 'btn btn-ghost btn-xs text-error', textContent: 'Remove', onclick: () => { rows.splice(i, 1); changed(); render(); } });
    box.append(el('div', { className: 'entry' }, el('div', { className: 'entry-h' }, el('span', {}, `Entry ${i + 1}`), rm), grid));
  });
  if (rows.length < (t.max || 50)) {
    box.append(el('button', { className: 'btn btn-block btn-outline btn-primary border-dashed', textContent: '+ Add entry', onclick: () => { rows.push({}); changed(); render(); } }));
  } else {
    box.append(el('p', { className: 'text-xs text-base-content/60' }, 'This is the most the official form has room for.'));
  }
  return box;
}

// Short section labels for the stepper.
const TAB = { personal: 'Personal Info', family: 'Family', education: 'Education', eligibility: 'Eligibility', work: 'Work Experience', learning: 'L&D', voluntary: 'Voluntary Work', other: 'Other Info', questions: 'Questions 34-40', references: 'References & ID' };

// A section counts as started once any of its fields or table rows has a value.
const started = (sec) => (sec.fields || []).some((f) => f.t !== 'check' && state.fields[f.k])
  || (sec.tables || []).some((t) => (state.tables[t.k] || []).some((r) => Object.values(r).some(Boolean)));

function renderSteps() {
  $('#tabs').replaceChildren(...S.map((s, i) => el('button', {
    className: `step${i === step ? ' on' : ''}${started(s) ? ' done' : ''}`, title: s.title, onclick: () => go(i),
  }, el('span', { className: 'num' }, started(s) && i !== step ? '\u2713' : String(i + 1)), el('span', {}, TAB[s.id] || s.title))));
  const n = S.filter(started).length;
  $('#progress').max = S.length;
  $('#progress').value = n;
  $('#progress-text').textContent = `${n} of ${S.length} sections started`;
}

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
  $('#form').replaceChildren(el('p', { className: 'text-xs font-medium text-base-content/50' }, `Section ${step + 1} of ${S.length}`),
    el('h2', { className: 'mb-2 text-2xl font-semibold' }, sec.title), ...body, ...(sec.tables || []).map(renderTable));
  syncConditions();
  syncPermanent();
  $('#inp-name').placeholder = autoName();
  $('#btn-prev').disabled = step === 0;
  $('#btn-next').textContent = step === S.length - 1 ? 'Export' : 'Next \u2192';
  refreshPreview();
}

// Hides and blanks a field whose schema condition ("if": [key, value]) does not hold, as the server does.
function syncConditions() {
  S.forEach((sec) => (sec.fields || []).forEach((f) => {
    if (!f.if) return;
    const on = (state.fields[f.if[0]] || '') === f.if[1];
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
    if (n) n.value = state.fields[`perm_${s}`];
  });
}

// Official PDS page that holds each form section.
const SECTION_PAGE = { personal: 1, family: 1, education: 1, eligibility: 2, work: 2, learning: 3, voluntary: 3, other: 3, questions: 4, references: 4 };
let fit = localStorage.getItem('pds-fit') || 'width';
let jumpTo = 1;

function go(i) {
  step = Math.max(0, Math.min(S.length - 1, i));
  jumpTo = SECTION_PAGE[S[step].id];
  render();
  scrollTo(0, 0);
}

const frameDoc = () => $('#preview').contentDocument;
const pages = () => [...(frameDoc()?.querySelectorAll('.pg') || [])];

// Scrolls only inside the preview frame (scrollIntoView would also scroll this page).
function showPage(n) {
  const pg = pages()[n - 1];
  if (pg) $('#preview').contentWindow.scrollTo(0, pg.offsetTop - 12);
}

// Highlights the page button of the page currently in view.
function markPage() {
  const mid = $('#preview').clientHeight / 2;
  const n = pages().findIndex((p) => p.getBoundingClientRect().bottom > mid) + 1;
  document.querySelectorAll('#pv-pages button').forEach((b) => b.classList.toggle('on', +b.dataset.page === n));
}

function applyFit() {
  frameDoc()?.body.classList.toggle('fit-page', fit === 'page');
  document.querySelectorAll('#pv-fit button').forEach((b) => b.classList.toggle('on', b.dataset.fit === fit));
}

let previewTimer;
function refreshPreview() {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(async () => {
    const frame = $('#preview');
    const y = frame.contentWindow?.scrollY || 0;
    const res = await fetch('view.php?embed=1', { method: 'POST', body: JSON.stringify(state) });
    frame.onload = () => {
      applyFit();
      if (jumpTo) showPage(jumpTo);
      else frame.contentWindow.scrollTo(0, y);
      jumpTo = 0;
      frame.contentWindow.addEventListener('scroll', markPage);
      markPage();
    };
    frame.srcdoc = await res.text();
  }, 150);
}

document.querySelectorAll('#pv-pages button').forEach((b) => (b.onclick = () => showPage(+b.dataset.page)));
document.querySelectorAll('#pv-fit button').forEach((b) => (b.onclick = () => {
  fit = b.dataset.fit;
  localStorage.setItem('pds-fit', fit);
  const n = document.querySelector('#pv-pages .on')?.dataset.page || 1;
  applyFit();
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
    pages: [...document.querySelectorAll('#pr-pages input:checked')].map((i) => +i.value),
  };
}

// Width of the form page on paper so it fits inside the margins without distortion.
function pageWidth({ paper, margin }) {
  const [w, h] = PAPER[paper];
  return Math.min(w - 2 * margin, (h - 2 * margin) / ASPECT);
}

// Shows each selected page, with its filled-in data, on the chosen paper and margins.
function drawPaper() {
  const s = printSettings(), [w, h] = PAPER[s.paper], filled = pages();
  $('#pr-view').replaceChildren(...s.pages.map((n) => {
    const sheet = filled[n - 1]?.cloneNode(true) || el('img', { src: `pds/page${n}.png`, alt: `Page ${n}` });
    sheet.classList.remove('skip', 'last');
    Object.assign(sheet.style, { width: `${(pageWidth(s) / (w - 2 * s.margin)) * 100}%`, maxWidth: 'none', margin: '0 auto', boxShadow: 'none' });
    const paper = el('div', { className: 'pr-paper' }, sheet);
    Object.assign(paper.style, { aspectRatio: `${w} / ${h}`, padding: `${(s.margin / w) * 100}%` });
    return el('figure', {}, paper, el('figcaption', {}, `Page ${n}`));
  }));
  $('#pr-go').disabled = !s.pages.length;
  localStorage.setItem('pds-print', JSON.stringify(s));
}

function openPrint() {
  const s = JSON.parse(localStorage.getItem('pds-print') || 'null');
  if (s) {
    $('#pr-paper').value = s.paper;
    $('#pr-margin').value = s.margin;
  }
  $('#pr-name').textContent = fileName();
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
  const visible = pages().filter((p, i) => {
    p.classList.toggle('skip', !s.pages.includes(i + 1));
    p.classList.remove('last');
    return s.pages.includes(i + 1);
  });
  visible.at(-1)?.classList.add('last');
  document.title = doc.title = fileName();
  win.addEventListener('afterprint', () => (document.title = title), { once: true });
  win.print();
}

async function exportXlsx() {
  try {
    const res = await fetch('xlsx.php', { method: 'POST', body: JSON.stringify(state) });
    if (!res.ok) throw new Error((await res.json()).error);
    el('a', { href: URL.createObjectURL(await res.blob()), download: `${fileName()}.xlsx` }).click();
  } catch (e) {
    $('#status').textContent = e.message;
  }
}

function openExport() {
  $('#dlg-name').textContent = fileName();
  $('#dlg-export').showModal();
}

$('#pv-print').onclick = openPrint;
$('#btn-export-open').onclick = openExport;
$('#exp-pdf').onclick = () => { $('#dlg-export').close(); openPrint(); };
['#pr-paper', '#pr-margin', '#pr-pages'].forEach((s) => $(s).addEventListener('change', drawPaper));
$('#dlg-print').addEventListener('close', () => {
  if ($('#dlg-print').returnValue === 'print') exportPdf();
  $('#dlg-print').returnValue = '';
});
$('#exp-xlsx').onclick = () => { $('#dlg-export').close(); exportXlsx(); };
$('#inp-name').oninput = changed;

let timer;
function changed() {
  clearTimeout(timer);
  $('#inp-name').placeholder = autoName();
  renderSteps();
  $('#status').textContent = 'Unsaved changes...';
  timer = setTimeout(() => {
    timer = null;
    localStorage.setItem(DRAFT, JSON.stringify({ state }));
    autoSave();
  }, 800);
}

// Saves to the server one request at a time so the first save's new id is reused.
let saving = false;
let again = false;
async function autoSave() {
  if (saving) return (again = true);
  saving = true;
  $('#status').textContent = 'Saving...';
  try {
    await save();
    $('#status').textContent = `All changes saved ${new Date().toLocaleTimeString()}`;
  } catch (e) {
    $('#status').textContent = `Auto-save failed (kept on this device): ${e.message}`;
  }
  saving = false;
  if (again) {
    again = false;
    autoSave();
  }
}

addEventListener('beforeunload', (e) => {
  if (timer || saving) e.preventDefault();
});

async function save() {
  const res = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...(auth.id ? auth : link), data: state, name: $('#inp-name').value }),
  });
  const out = await res.json();
  if (!res.ok) throw new Error(out.error || 'Save failed');
  auth = { id: out.id, key: out.key ?? auth.key };
  if (window.USER) localStorage.setItem(CURRENT, out.id);
  localStorage.setItem(DRAFT, JSON.stringify({ state }));
  if (!$('#share').hidden) showShare();
}

function showShare() {
  const base = location.origin + location.pathname.replace(/[^/]*$/, '');
  const view = `${base}view.php?id=${auth.id}`;
  const edit = `${base}#id=${auth.id}&key=${auth.key}`;
  const link = (label, url, note) => el('div', {}, el('b', {}, label), ' ',
    el('input', { className: 'input input-sm w-full max-w-md', value: url, readOnly: true, onclick: (e) => e.target.select() }), ' ', el('span', { className: 'text-xs opacity-70' }, note));
  $('#share').replaceChildren(
    link('View link:', view, 'read-only, safe to share'),
    auth.key ? link('Edit link:', edit, 'keep private, anyone with it can edit')
      : el('div', { className: 'text-xs opacity-70' }, 'To edit on another device, sign in there with the same Google account.'));
  $('#share').hidden = false;
}

async function load() {
  const draft = JSON.parse(localStorage.getItem(DRAFT) || 'null');
  const byLink = (a) => fetch(`api.php?${new URLSearchParams(a.key ? { id: a.id, key: a.key } : { id: a.id })}`);
  if (link.open) {
    localStorage.setItem(CURRENT, link.open);
    link = {};
    history.replaceState(null, '', location.pathname);
  }
  const current = window.USER && localStorage.getItem(CURRENT);
  try {
    let res = await (link.id ? byLink(link) : current ? byLink({ id: current }) : fetch('api.php'));
    if (!res.ok && current) res = await fetch('api.php');
    // On a new device, a signed-in user continues their most recent PDS.
    if (!res.ok && window.USER && !link.id) {
      const latest = (await (await fetch('account.php')).json()).docs[0];
      if (latest) res = await byLink({ id: latest.id });
    }
    // Adopts a PDS saved before per-PC sessions existed.
    if (!res.ok && !link.id && draft?.auth?.id) res = await byLink(draft.auth);
    if (res.ok) {
      const out = await res.json();
      state = out.data;
      auth = { id: out.id, key: out.key };
      $('#inp-name').value = out.name || localStorage.getItem('pds-name') || '';
      if (window.USER && out.own) localStorage.setItem(CURRENT, out.id);
      $('#status').textContent = 'Loaded your saved PDS';
      // Once a link's PDS is this PC's own, hide the key from the address bar.
      if (out.own && link.id) {
        link = {};
        history.replaceState(null, '', location.pathname);
      }
    } else if (link.id) {
      $('#status').textContent = 'Saved PDS not found or link is invalid';
    } else if (draft?.state) {
      state = draft.state;
    }
  } catch {
    if (draft?.state) state = draft.state;
    $('#status').textContent = 'Server unreachable, showing the copy on this device';
  }
  render();
}

$('#form').addEventListener('focusout', refreshPreview);
$('#form').addEventListener('change', refreshPreview);
$('#btn-prev').onclick = () => go(step - 1);
$('#btn-next').onclick = () => (step === S.length - 1 ? openExport() : go(step + 1));
$('#btn-save').onclick = async () => {
  if (!$('#share').hidden) return ($('#share').hidden = true);
  try {
    if (!auth.id) await save();
    showShare();
  } catch (e) {
    $('#status').textContent = e.message;
  }
};
$('#inp-xlsx').onchange = async (e) => {
  const body = new FormData();
  body.append('file', e.target.files[0]);
  e.target.value = '';
  const res = await fetch('xlsx.php', { method: 'POST', body });
  const out = await res.json();
  if (!res.ok) return ($('#status').textContent = out.error);
  const filled = Object.values(out.data.fields).filter(Boolean).length + Object.values(out.data.tables).flat().length;
  if (!filled) return ($('#status').textContent = 'No data found. Use a filled-up CS Form 212 (Revised 2026) .xlsx.');
  ['signature', 'photo', 'thumbmark'].forEach((k) => (out.data.fields[k] = state.fields[k] || ''));
  state = out.data;
  render();
  changed();
};
// Starts a blank PDS; the current one stays saved (in My PDS when signed in, else via its edit link).
async function newPds() {
  await fetch('api.php', { method: 'DELETE' });
  [DRAFT, CURRENT, 'pds-name'].forEach((k) => localStorage.removeItem(k));
  location.href = location.pathname;
}
$('#btn-clear').onclick = () => {
  const where = window.USER ? 'in My PDS' : 'and opens again with its edit link';
  if (confirm(`Start a new blank PDS? The current one stays saved ${where}.`)) newPds();
};

// Opens another saved PDS from the account list.
function openPds(id) {
  localStorage.setItem(CURRENT, id);
  localStorage.removeItem(DRAFT);
  location.href = location.pathname;
}

async function account(op, body) {
  const res = await fetch('account.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op, ...body }) });
  const out = await res.json();
  if (!res.ok) throw new Error(out.error);
  return out;
}

const docLabel = (d) => d.name || (d.person || '').trim() || 'Untitled PDS';

async function showDocs() {
  const { docs } = await (await fetch('account.php')).json();
  const act = (label, fn, cls = '') => el('button', { className: `btn btn-sm ${cls}`, onclick: () => fn().catch((e) => alert(e.message)) }, label);
  $('#docs-list').replaceChildren(...docs.map((d) => el('li', { className: d.id === auth.id ? 'on' : '' },
    el('div', {}, el('strong', {}, docLabel(d)), el('span', { className: 'text-xs text-base-content/60' },
      `${d.id === auth.id ? 'Open now - ' : ''}${d.form} - updated ${new Date(d.updated.replace(' ', 'T')).toLocaleString()}`)),
    el('div', { className: 'flex flex-wrap gap-1.5' },
      act('Open', async () => openPds(d.id)),
      act('Rename', async () => {
        const name = prompt('New name', docLabel(d));
        if (name === null) return;
        await account('rename', { id: d.id, name });
        if (d.id === auth.id) $('#inp-name').value = name;
        showDocs();
      }),
      act('Copy to new', async () => openCopy(d)),
      act('Delete', async () => {
        if (!confirm(`Delete "${docLabel(d)}"? This cannot be undone.`)) return;
        await account('delete', { id: d.id });
        if (d.id === auth.id) return newPds();
        showDocs();
      }, 'btn-ghost text-error')))));
  if (!docs.length) $('#docs-list').append(el('li', { className: 'text-sm text-base-content/60' }, 'No saved PDS yet. Your current PDS is saved here automatically.'));
  if (!$('#dlg-docs').open) $('#dlg-docs').showModal();
}

// Copy dialog: picks which sections of a saved PDS go into a new one.
let copyFrom;
function openCopy(d) {
  copyFrom = d;
  $('#copy-name').value = `${docLabel(d)} (copy)`;
  $('#copy-secs').replaceChildren(el('legend', { className: 'px-1 text-xs font-medium text-base-content/70' }, 'Sections to copy'),
    ...S.map((sec) => el('label', { className: 'flex items-center gap-2' },
      el('input', { type: 'checkbox', className: 'checkbox checkbox-primary checkbox-sm', value: sec.id, checked: sec.id !== 'questions' }), sec.title)));
  $('#dlg-copy').showModal();
}

if (window.USER) {
  $('#btn-docs').onclick = () => showDocs();
  $('#dlg-docs').addEventListener('close', () => {
    if ($('#dlg-docs').returnValue === 'new') newPds();
    $('#dlg-docs').returnValue = '';
  });
  $('#dlg-copy').addEventListener('close', async () => {
    if ($('#dlg-copy').returnValue !== 'copy') return;
    $('#dlg-copy').returnValue = '';
    const sections = [...document.querySelectorAll('#copy-secs input:checked')].map((i) => i.value);
    try {
      openPds((await account('copy', { id: copyFrom.id, name: $('#copy-name').value, sections })).id);
    } catch (e) {
      alert(e.message);
    }
  });
  // Signing out leaves nothing of the account's PDS on this PC.
  $('#form-logout').onsubmit = () => [DRAFT, CURRENT, 'pds-name'].forEach((k) => localStorage.removeItem(k));
}

load();
