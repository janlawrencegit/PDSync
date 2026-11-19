// Admin: form versions (forms.php). The list of versions, and the review of a revised form in five steps:
// page images, lining up the boxes, checking the questions, trying it, and making it active.
const root = $('#fv');
const formId = root.dataset.id;
const STATUS = {
  matched: ['Matched', 'ok'],
  set: ['Set by you', 'info'],
  check: ['Needs a look', 'warn'],
  missing: ['Not found', 'bad'],
  removed: ['Left out', 'muted'],
};

async function api(name, { body, form, method } = {}) {
  const url = `forms.php?api=${name}${formId ? `&id=${encodeURIComponent(formId)}` : ''}`;
  const opts = { method: method || (body || form ? 'POST' : 'GET') };
  if (body) Object.assign(opts, { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (form) opts.body = form;
  const res = await fetch(url, opts);
  const out = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(out.error || 'Something went wrong.');
  return out;
}
const run = (fn) => fn().catch((e) => toast(e.message, { type: 'error', ms: 9000 }));
const card = (...kids) => el('section', { className: 'card-box' }, ...kids);
const chip = (text, tone = 'muted', cls = '') => el('span', { className: `chip ${tone} ${cls}`.trim() }, text);
const badge = (status) => chip(STATUS[status]?.[0] || status, STATUS[status]?.[1]);
// A page image that fails to load (a busy server, a dropped request) is asked for again, up to three times.
const retryImg = (img) => {
  let tries = 0;
  img.addEventListener('error', () => {
    if (++tries > 3) return img.classList.add('broken');
    const u = new URL(img.src, location.href);
    u.searchParams.set('retry', tries);
    setTimeout(() => { img.src = u.href; }, 400 * tries);
  });
  return img;
};
const formTag =(status, cls) => status === 'active' ? chip('Active', 'ok', cls) : status === 'draft' ? chip('Draft', 'warn', cls) : chip('Retired', 'muted', cls);

// ---- List of versions --------------------------------------------------------------------------------------------
async function showList() {
  const { forms } = await api('list');
  const rows = forms.map((f) => {
    const tag = formTag(f.status);
    const actions = el('div', { className: 'flex flex-wrap gap-2' });
    if (!f.builtin) actions.append(el('a', { className: 'btn btn-sm', href: `forms.php?id=${encodeURIComponent(f.id)}` }, f.status === 'draft' ? 'Continue review' : 'Open'));
    if (f.status !== 'active' && (f.builtin || f.images)) actions.append(el('button', { className: 'btn btn-sm btn-ghost', onclick: () => activate(f) }, 'Make active'));
    if (!f.builtin && f.status !== 'active' && !f.pds) actions.append(el('button', { className: 'btn btn-sm btn-ghost text-error', onclick: () => remove(f) }, 'Delete'));
    return el('div', { className: 'fv-row' },
      el('div', { className: 'min-w-0' }, el('div', { className: 'flex flex-wrap items-center gap-2' }, el('b', {}, f.name), tag),
        el('p', { className: 'text-xs text-base-content/60' }, [f.builtin ? 'Built in' : `Added ${new Date(f.created).toLocaleDateString()}`, `${f.pds} PDS`, f.images ? `${f.images} page images` : 'No page images yet'].join(' · '))),
      actions);
  });
  const file = el('input', { type: 'file', accept: '.xlsx', className: 'file-input w-full', required: true });
  const rev = el('input', { className: 'input w-full', placeholder: 'For example: Revised 2027', required: true });
  const go = el('button', { className: 'btn btn-primary', type: 'submit' }, 'Upload and analyze');
  const formEl = el('form', { className: 'mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end', onsubmit: (e) => {
    e.preventDefault();
    run(async () => {
      go.disabled = true;
      go.replaceChildren(el('span', { className: 'loading loading-spinner loading-sm' }), 'Analyzing…');
      try {
        const fd = new FormData();
        fd.append('file', file.files[0]);
        fd.append('revision', rev.value);
        const { id } = await api('create', { form: fd });
        location.href = `forms.php?id=${encodeURIComponent(id)}`;
      } finally {
        go.disabled = false;
        go.replaceChildren('Upload and analyze');
      }
    });
  } },
  el('label', { className: 'grid gap-1.5' }, el('span', { className: 'text-xs font-medium text-base-content/70' }, 'Name of the revision'), rev),
  el('label', { className: 'grid gap-1.5' }, el('span', { className: 'text-xs font-medium text-base-content/70' }, 'Official Excel file (.xlsx)'), file),
  go);
  root.replaceChildren(
    el('div', {}, el('h1', { className: 'text-2xl font-semibold' }, 'Form versions'),
      el('p', { className: 'mt-0.5 max-w-3xl text-sm text-base-content/60' }, 'The version marked Active is the one people fill out. When CSC releases a revised Personal Data Sheet, add it here. It stays a draft until you make it active.')),
    card(el('h2', { className: 'font-semibold' }, 'Versions'), el('div', { className: 'mt-3 grid gap-2' }, ...rows)),
    card(el('h2', { className: 'font-semibold' }, 'Add a revised form'),
      el('ol', { className: 'mt-2 grid list-decimal gap-1 pl-5 text-sm text-base-content/70' },
        el('li', {}, 'Upload the official Excel file. PDSync finds every question in it by its printed label.'),
        el('li', {}, 'Add the official PDF (or save the Excel file as PDF) so the preview shows the new pages.'),
        el('li', {}, 'Check what PDSync could not match, try it with sample answers, then make it active.')),
      formEl));
}

async function activate(f) {
  const ok = await ask({ title: `Make ${f.name || f.revision} the active form?`, body: ['From now on, people fill out and export this version. PDS made on other versions move to it when they are next saved; answers to questions it still asks carry over.'], buttons: [{ label: 'Cancel', value: '' }, { label: 'Make active', value: 'ok', cls: 'btn-primary' }] });
  if (ok !== 'ok') return;
  run(async () => {
    await fetch(`forms.php?api=activate&id=${encodeURIComponent(f.id)}`, { method: 'POST' }).then(async (r) => { if (!r.ok) throw new Error((await r.json()).error); });
    toast(`${f.name || f.revision} is now the active form.`, { type: 'success' });
    formId ? load() : showList();
  });
}
async function remove(f) {
  if (await ask({ title: `Delete ${f.name}?`, body: 'The draft and its files are removed.', buttons: [{ label: 'Cancel', value: '' }, { label: 'Delete', value: 'del', cls: 'btn-error' }] }) !== 'del') return;
  run(async () => {
    const r = await fetch(`forms.php?api=delete&id=${encodeURIComponent(f.id)}`, { method: 'POST' });
    if (!r.ok) throw new Error((await r.json()).error);
    if (formId) location.href = 'forms.php';
    else showList();
  });
}

// ---- Review of one version ---------------------------------------------------------------------------------------
let D = null;
let page = 1;
let calib = {};
let boxes = {};
let selected = null;
let dirty = false;
let painting = false;
let tryHtml = null;

async function load() {
  D = await api('data');
  calib = {};
  for (const n of Object.keys(D.geometry)) calib[n] = { ...(D.calib[n] || D.geometry[n].calib) };
  boxes = { ...D.boxes };
  dirty = false;
  tryHtml = null;
  draw();
  // A new draft has no page images yet: draw them from the Excel file so it can be seen and tested right away.
  if (!D.images && !D.builtin && !painting) run(paintFromExcel);
}

// Status of a preview box from the report item that placed it (table rows take their column's first row).
function statusOf(id) {
  const p = id.split(':');
  const key = p[0] === 't' ? `t:${p[1]}:0:${p[3]}` : p[0] === 'e' ? null : id;
  const it = D.items.find((x) => x.id === key) || (p[0] === 'e' && D.items.find((x) => x.id === `e:${p[1]}:row`));
  return it?.status || 'matched';
}

// Layout boxes of the page, moved live to the calibration being edited (the server built them with `base`).
function pageItems(n) {
  const base = D.calib[n] || D.geometry[n]?.calib, now = calib[n];
  return (D.layout.items || []).filter((i) => i.page === +n).map((i) => {
    const o = boxes[`${i.id}|${i.page}`];
    if (o) return { ...i, ...o, own: true };
    if (!base || !now) return i;
    const kx = now.sx / base.sx, ky = now.sy / base.sy;
    return { ...i, x: (i.x - base.ox) * kx + now.ox, y: (i.y - base.oy) * ky + now.oy, w: i.w * kx, h: i.h * ky };
  });
}

// Where each step stands: done, to do, or waiting for an earlier one.
function progress() {
  const look = D.items.filter((i) => i.status === 'check' || i.status === 'missing').length;
  const pages = D.images > 0;
  return [
    { title: 'Page images', state: pages ? 'done' : painting ? 'busy' : 'todo' },
    { title: 'Line up the boxes', state: !pages ? 'wait' : Object.keys(D.calib).length || D.images_from === 'excel' ? 'done' : 'todo' },
    { title: 'Check the questions', state: look ? 'todo' : 'done', note: look ? `${look} to check` : '' },
    { title: 'Try it', state: !pages ? 'wait' : 'todo' },
    { title: D.status === 'active' ? 'Active' : 'Make active', state: D.status === 'active' ? 'done' : !pages ? 'wait' : 'todo' },
  ];
}

function draw() {
  const counts = D.items.reduce((a, i) => ((a[i.status] = (a[i.status] || 0) + 1), a), {});
  const steps = progress();
  const tile = (label, n, tone) => el('div', { className: `fv-tile ${tone}` }, el('span', { className: 'text-2xl font-semibold tabular-nums' }, String(n)), el('span', { className: 'text-xs' }, label));
  const head = card(
    el('a', { href: 'forms.php', className: 'text-sm text-base-content/60 hover:underline' }, '← All versions'),
    el('div', { className: 'mt-1 flex flex-wrap items-start justify-between gap-3' },
      el('div', { className: 'min-w-0' },
        el('h1', { className: 'flex flex-wrap items-center gap-2 text-2xl font-semibold' }, D.name,
          formTag(D.status, 'chip-lg')),
        el('p', { className: 'mt-0.5 break-words text-sm text-base-content/60' }, `Uploaded file: ${D.source || 'the uploaded file'}`)),
      el('div', { className: 'flex gap-1' },
        el('button', { className: 'btn btn-sm btn-ghost', onclick: rename }, 'Rename'),
        D.status !== 'active' && !D.pds ? el('button', { className: 'btn btn-sm btn-ghost text-error', onclick: () => remove({ id: D.id, name: D.name }) }, 'Delete draft') : null)),
    el('div', { className: 'mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4' },
      tile('Matched automatically', (counts.matched || 0) + (counts.set || 0), 'good'),
      tile('Need a look', counts.check || 0, counts.check ? 'warn' : ''),
      tile('Not found', counts.missing || 0, counts.missing ? 'bad' : ''),
      tile('New labels on the form', D.added.length, D.added.length ? 'info' : '')),
    el('ol', { className: 'fv-progress mt-4' }, ...steps.map((s, i) => el('li', { className: s.state },
      el('span', { className: 'dot' }, s.state === 'done' ? icon(ICON.check, 'size-3.5') : String(i + 1)),
      el('span', { className: 'min-w-0' }, el('b', { className: 'block truncate text-xs' }, s.title),
        el('small', {}, { done: 'Done', todo: s.note || 'To do', wait: 'Waiting', busy: 'Working…' }[s.state]))))));
  root.replaceChildren(head, stepImages(steps[0]), stepLineUp(steps[1]), stepQuestions(steps[2]), stepTry(steps[3]), stepActivate(steps[4]));
  if (D.images && steps[3].state !== 'wait' && !tryHtml) loadTry();
}

const STEP_STATE = { done: ['Done', 'ok'], todo: ['To do', 'warn'], wait: ['Waiting for page images', 'muted'], busy: ['Working…', 'info busy'] };
const step = (n, s, ...kids) => card(
  el('div', { className: 'flex flex-wrap items-center gap-3' },
    el('span', { className: `fv-step ${s.state}` }, s.state === 'done' ? icon(ICON.check, 'size-4') : String(n)),
    el('h2', { className: 'font-semibold' }, s.title),
    chip(s.note && s.state === 'todo' ? s.note : STEP_STATE[s.state][0], STEP_STATE[s.state][1], 'ml-auto')),
  el('div', { className: 'mt-3' }, ...kids));

function rename() {
  run(async () => {
    const r = await ask({ title: 'Name of this revision', input: D.revision, buttons: [{ label: 'Cancel', value: '' }, { label: 'Save', value: 'ok', cls: 'btn-primary' }] });
    if (!r) return;
    await api('rename', { body: { revision: r.text } });
    await load();
  });
}

// ---- Drawing pages from the Excel file ----------------------------------------------------------------------------
// Draws one page from the server's description (lib/form-paint.php) at the size of the official page images.
// It uses the same calibration as the answer boxes, so they line up exactly.
function paintPage(pg, aspect) {
  const W = 1700, H = Math.round(W * aspect);
  const cv = el('canvas', { width: W, height: H }), g = cv.getContext('2d');
  const c = pg.calib, X = (pt) => ((c.sx * pt + c.ox) / 100) * W, Y = (pt) => ((c.sy * pt + c.oy) / 100) * H;
  const kx = (c.sx / 100) * W, ky = (c.sy / 100) * H, k = (kx + ky) / 2;
  g.fillStyle = '#fff';
  g.fillRect(0, 0, W, H);
  g.textBaseline = 'alphabetic';
  const fontOf = (f, size = f.size) => `${f.italic ? 'italic ' : ''}${f.bold ? 'bold ' : ''}${Math.max(4, size * k).toFixed(1)}px ${/narrow/i.test(f.face || '') ? '"Arial Narrow", "PDS Narrow", Arial' : 'Arial, Helvetica'}, sans-serif`;
  // Text in a box: wrapped when the cell wraps, aligned like the cell; one line otherwise.
  const text = (str, x, y, w, h, f, align, valign, wrap) => {
    g.font = fontOf(f);
    g.fillStyle = f.color && f.color !== '#ffffff' ? f.color : '#000';
    const pad = 2 * kx, lh = f.size * k * 1.15, maxW = Math.max(1, w - 2 * pad);
    const lines = [];
    for (const para of String(str).split(/\r?\n/)) {
      if (!wrap) { lines.push(para); continue; }
      let line = '';
      for (const word of para.split(/\s+/)) {
        const next = line ? `${line} ${word}` : word;
        if (g.measureText(next).width > maxW && line) { lines.push(line); line = word; } else line = next;
      }
      lines.push(line);
    }
    const total = lines.length * lh;
    let ty = valign === 'top' ? y + pad + lh * 0.8 : valign === 'center' ? y + (h - total) / 2 + lh * 0.8 : y + h - total - pad + lh * 0.8;
    for (const line of lines) {
      const lw = g.measureText(line).width;
      const tx = align === 'center' ? x + (w - lw) / 2 : align === 'right' ? x + w - pad - lw : x + pad;
      g.fillText(line, tx, ty);
      ty += lh;
    }
  };
  for (const s of pg.cells) {
    if (s.fill && s.fill !== '#ffffff') {
      g.fillStyle = s.fill;
      g.fillRect(X(s.x), Y(s.y), s.w * kx, s.h * ky);
    }
  }
  for (const s of pg.shapes) {
    if (s.fill) {
      g.fillStyle = s.fill;
      g.fillRect(X(s.x), Y(s.y), s.w * kx, s.h * ky);
    }
  }
  for (const s of pg.cells) {
    if (!s.text) continue;
    g.save();
    // Text stays inside its cell unless it is a one-line label that Excel lets run into empty neighbours.
    if (s.wrap || s.align !== 'left') {
      g.beginPath();
      g.rect(X(s.x), Y(s.y), s.w * kx, s.h * ky);
      g.clip();
    }
    if (s.rotate === 90) {
      g.translate(X(s.x) + (s.w * kx) / 2, Y(s.y) + (s.h * ky) / 2);
      g.rotate(-Math.PI / 2);
      text(s.text, -(s.h * ky) / 2, -(s.w * kx) / 2, s.h * ky, s.w * kx, s.font, 'center', 'center', true);
    } else {
      text(s.text, X(s.x), Y(s.y), s.w * kx, s.h * ky, s.font, s.align, s.valign, s.wrap);
    }
    g.restore();
  }
  g.strokeStyle = '#000';
  for (const s of pg.cells) {
    const [t, r, b, l] = s.b, x0 = X(s.x), y0 = Y(s.y), x1 = X(s.x + s.w), y1 = Y(s.y + s.h);
    const side = (wd, ax, ay, bx, by) => {
      if (!wd) return;
      g.lineWidth = Math.max(1, wd * 0.75 * k);
      g.beginPath();
      g.moveTo(ax, ay);
      g.lineTo(bx, by);
      g.stroke();
    };
    side(t, x0, y0, x1, y0); side(b, x0, y1, x1, y1); side(l, x0, y0, x0, y1); side(r, x1, y0, x1, y1);
  }
  for (const s of pg.shapes) {
    if (s.stroke) {
      g.strokeStyle = s.stroke;
      g.lineWidth = Math.max(1, s.width * k);
      if (s.line) {
        g.beginPath();
        g.moveTo(X(s.x), Y(s.y));
        g.lineTo(X(s.x + s.w), Y(s.y + s.h));
        g.stroke();
      } else g.strokeRect(X(s.x), Y(s.y), s.w * kx, s.h * ky);
    }
    let ty = 0;
    for (const p of s.paras) {
      const f = { size: p.size, bold: p.bold, face: 'Arial' };
      text(p.text, X(s.x), Y(s.y) + ty, s.w * kx, p.size * k * 1.3 + 4 * ky, f, p.align, 'top', true);
      ty += p.size * k * 1.3 * Math.max(1, Math.ceil((g.measureText(p.text).width + 8) / Math.max(1, s.w * kx)));
    }
  }
  // Checkboxes and dropdowns. The square uses the same size and place as the answer box (lib/form-layout.php).
  for (const s of pg.controls) {
    g.strokeStyle = '#000';
    g.lineWidth = Math.max(1, 0.75 * k);
    if (s.type === 'Drop') {
      g.strokeRect(X(s.x), Y(s.y), s.w * kx, s.h * ky);
      const bw = Math.min(s.h, 14) * kx, bx = X(s.x + s.w) - bw;
      g.strokeRect(bx, Y(s.y), bw, s.h * ky);
      g.fillStyle = '#000';
      g.beginPath();
      g.moveTo(bx + bw * 0.3, Y(s.y) + s.h * ky * 0.4);
      g.lineTo(bx + bw * 0.7, Y(s.y) + s.h * ky * 0.4);
      g.lineTo(bx + bw * 0.5, Y(s.y) + s.h * ky * 0.65);
      g.fill();
      continue;
    }
    const size = Math.min(9, Math.max(5, s.h - 2));
    const sx = X(s.x + 2.5), sy = Y(s.y + s.h / 2 - size / 2);
    if (s.type === 'Radio') {
      g.beginPath();
      g.arc(sx + (size * kx) / 2, sy + (size * ky) / 2, (size * k) / 2, 0, Math.PI * 2);
      g.stroke();
    } else g.strokeRect(sx, sy, size * kx, size * ky);
    if (s.label) text(s.label.trim(), X(s.x + 2.5 + size + 1.5), Y(s.y), (s.w - size - 4) * kx, s.h * ky, { size: s.size || 8, face: 'Tahoma' }, 'left', 'center', false);
  }
  return cv;
}

async function paintFromExcel() {
  painting = true;
  draw();
  try {
    const paint = await api('paint');
    const fd = new FormData();
    for (const n of Object.keys(paint.pages)) {
      const cv = paintPage(paint.pages[n], paint.aspect);
      fd.append(`page${n}`, await new Promise((r) => cv.toBlob(r, 'image/png')), `page${n}.png`);
    }
    fd.append('source', 'excel');
    await api('pages', { form: fd });
  } finally {
    painting = false;
  }
  await load();
  toast('Pages drawn from the Excel file. The boxes already line up with them.', { type: 'success' });
}

// Step 1: page images, drawn from the Excel file or rendered from the official PDF (pdf.js), or image files.
function stepImages(s) {
  const need = Object.keys(D.geometry).length;
  const input = el('input', { type: 'file', accept: '.pdf,image/png,image/jpeg', multiple: true, hidden: true });
  const status = el('p', { className: 'text-sm text-base-content/60' });
  input.onchange = () => run(async () => {
    const files = [...input.files];
    if (!files.length) return;
    const blobs = [];
    if (files[0].type === 'application/pdf' || /\.pdf$/i.test(files[0].name)) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'vendor/pdfjs-3.11.174/pdf.worker.min.js';
      const pdf = await pdfjsLib.getDocument({ data: await files[0].arrayBuffer() }).promise;
      if (pdf.numPages < need) throw new Error(`The PDF has ${pdf.numPages} pages; the form needs ${need}.`);
      for (let n = 1; n <= need; n++) {
        status.textContent = `Reading page ${n} of ${need}…`;
        const p = await pdf.getPage(n);
        const vp = p.getViewport({ scale: 1700 / p.getViewport({ scale: 1 }).width });
        const cv = el('canvas', { width: Math.round(vp.width), height: Math.round(vp.height) });
        const g = cv.getContext('2d');
        g.fillStyle = '#fff';
        g.fillRect(0, 0, cv.width, cv.height);
        await p.render({ canvasContext: g, viewport: vp }).promise;
        blobs.push(await new Promise((r) => cv.toBlob(r, 'image/png')));
      }
    } else {
      if (files.length < need) throw new Error(`Choose ${need} page images, one per page, in order.`);
      files.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
      blobs.push(...files.slice(0, need));
    }
    status.textContent = 'Uploading…';
    const fd = new FormData();
    blobs.forEach((b, i) => fd.append(`page${i + 1}`, b, `page${i + 1}.png`));
    fd.append('source', 'pdf');
    await api('pages', { form: fd });
    status.textContent = '';
    await load();
    await autoLineUp();
  });
  const fromExcel = D.images_from === 'excel';
  const body = [];
  if (painting) {
    body.push(el('div', { className: 'flex items-center gap-3 rounded-box bg-base-200 p-4 text-sm' }, el('span', { className: 'loading loading-spinner loading-sm' }), 'Drawing the pages from the Excel file…'));
  } else if (D.images) {
    body.push(el('div', { className: 'flex flex-wrap gap-3' }, ...D.pages.map((u, i) => el('button', { className: 'fv-thumb', title: `Show page ${i + 1}`, onclick: () => { page = i + 1; draw(); document.querySelector('.fv-viewer')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); } },
      retryImg(el('img', { src: u, alt: `Page ${i + 1}`, loading: 'lazy' })), el('span', {}, `Page ${i + 1}`)))));
    body.push(el('p', { className: 'mt-3 text-sm text-base-content/70' }, fromExcel
      ? 'These pages were drawn from the Excel file, so you can review and test right away. For the exact official look in people’s preview and printouts, add the official PDF.'
      : 'These pages come from the PDF you added.'));
  } else {
    body.push(el('p', { className: 'text-sm text-base-content/70' }, 'No page images yet.'));
  }
  body.push(el('div', { className: 'mt-3 flex flex-wrap gap-2' },
    el('label', { className: `btn btn-sm${fromExcel || !D.images ? ' btn-primary' : ''}` }, icon(ICON.download, 'size-4'), fromExcel || !D.images ? 'Add the official PDF' : 'Replace with another PDF', input),
    !painting ? el('button', { className: 'btn btn-sm btn-ghost', onclick: () => run(paintFromExcel) }, D.images ? 'Draw again from the Excel file' : 'Draw from the Excel file') : null),
    el('p', { className: 'mt-2 text-xs text-base-content/60' }, `No PDF? Open the Excel file in Excel and save it as PDF. The first ${need} pages are used; ${need} PNG or JPG images also work.`), status);
  return step(1, s, ...body);
}

// Loads a page image into a canvas and returns the pixel accessor the calibration needs.
async function pixels(url) {
  const img = new Image();
  img.src = url;
  await img.decode();
  const cv = el('canvas', { width: img.naturalWidth, height: img.naturalHeight });
  const g = cv.getContext('2d', { willReadFrequently: true });
  g.drawImage(img, 0, 0);
  const d = g.getImageData(0, 0, cv.width, cv.height).data, w = cv.width;
  return { w, h: cv.height, gray: (x, y) => { const i = (y * w + x) * 4; return d[i] * 0.3 + d[i + 1] * 0.59 + d[i + 2] * 0.11; } };
}

// Fits every page to its image, snaps the checkboxes onto their printed squares, and saves.
async function autoLineUp() {
  const t = toast('Lining up the boxes with the pages…', { ms: 0 });
  try {
    const pages = Object.keys(D.geometry);
    for (const n of pages) {
      const img = await pixels(D.pages[n - 1]);
      const fit = FormCalib.calibrate(img, D.geometry[n]);
      calib[n] = { sx: fit.sx, ox: fit.ox, sy: fit.sy, oy: fit.oy };
      for (const k of Object.keys(boxes)) if (k.endsWith(`|${n}`) && k.startsWith('c:')) delete boxes[k];
      const snapped = FormCalib.snapChecks(img, pageItems(n));
      for (const [id, b] of Object.entries(snapped)) boxes[`${id}|${n}`] = b;
    }
    await api('calib', { body: { calib, boxes } });
    await load();
    toast(`Boxes lined up on ${pages.length} pages. Check each page below, and fine-tune any box that is off.`, { type: 'success', ms: 9000 });
  } finally {
    t.remove();
  }
}

// Step 2: the page with every box drawn on it, with fine-tuning.
function stepLineUp(s) {
  if (!D.images) return step(2, s, el('p', { className: 'text-sm text-base-content/60' }, 'This step opens when the page images are ready.'));
  const n = String(page);
  const view = el('div', { className: 'fv-page' }, el('img', { src: D.pages[page - 1], alt: `Page ${page}`, draggable: false }));
  view.style.aspectRatio = `1 / ${D.layout.aspect || 1.414}`;
  for (const it of pageItems(n)) {
    const st = statusOf(it.id);
    const b = el('button', { className: `fv-box ${st}${it.own ? ' own' : ''}${selected === `${it.id}|${it.page}` ? ' sel' : ''}`, title: `${it.id}${it.own ? ' (moved by hand)' : ''}`, onclick: () => { selected = `${it.id}|${it.page}`; draw(); } });
    Object.assign(b.style, { left: `${it.x}%`, top: `${it.y}%`, width: `${it.w}%`, height: `${it.h}%` });
    view.append(b);
  }
  const nudge = (k, d) => { calib[n][k] += d; dirty = true; draw(); };
  const c = calib[n];
  const ctl = (label, k, d) => el('div', { className: 'fv-ctl' }, el('span', {}, label),
    el('button', { className: 'btn btn-xs btn-square', ariaLabel: `${label}: less`, onclick: () => nudge(k, -d) }, '−'),
    el('button', { className: 'btn btn-xs btn-square', ariaLabel: `${label}: more`, onclick: () => nudge(k, d) }, '+'));
  const it = selected ? pageItems(n).find((i) => `${i.id}|${i.page}` === selected) : null;
  const move = (dx, dy, dw = 0) => {
    boxes[selected] = { x: it.x + dx, y: it.y + dy, w: Math.max(0.2, it.w + dw), h: Math.max(0.2, it.h + dw / (D.layout.aspect || 1.414)) };
    dirty = true;
    draw();
  };
  const sel = it ? el('div', { className: 'fv-sel' },
    el('p', { className: 'break-all text-sm' }, el('b', {}, it.id), it.own ? ' (moved by hand)' : ''),
    el('div', { className: 'grid grid-cols-4 gap-1' },
      el('button', { className: 'btn btn-xs', ariaLabel: 'Move left', onclick: () => move(-0.1, 0) }, '←'), el('button', { className: 'btn btn-xs', ariaLabel: 'Move right', onclick: () => move(0.1, 0) }, '→'),
      el('button', { className: 'btn btn-xs', ariaLabel: 'Move up', onclick: () => move(0, -0.1) }, '↑'), el('button', { className: 'btn btn-xs', ariaLabel: 'Move down', onclick: () => move(0, 0.1) }, '↓')),
    el('div', { className: 'flex flex-wrap gap-1' },
      el('button', { className: 'btn btn-xs', onclick: () => move(0, 0, -0.1) }, 'Smaller'), el('button', { className: 'btn btn-xs', onclick: () => move(0, 0, 0.1) }, 'Larger'),
      it.own ? el('button', { className: 'btn btn-xs btn-ghost', onclick: () => { delete boxes[selected]; dirty = true; draw(); } }, 'Undo move') : null)) : null;
  return step(2, s,
    el('p', { className: 'mb-3 text-sm text-base-content/70' }, D.images_from === 'excel'
      ? 'Each box is where an answer will be printed. These pages were drawn from the same Excel file, so the boxes already sit in place. Green means matched automatically, yellow needs a look, blue was set by you.'
      : 'Each box is where an answer will be printed. Green means matched automatically, yellow needs a look, blue was set by you. Use "Line up automatically" after adding a PDF, then fine-tune if needed.'),
    el('div', { className: 'mb-3 flex flex-wrap items-center gap-2' },
      el('div', { className: 'flex flex-wrap gap-1' }, ...Object.keys(D.geometry).map((k) => el('button', { className: `btn btn-sm${+k === page ? ' btn-active' : ' btn-ghost'}`, onclick: () => { page = +k; selected = null; draw(); } }, `Page ${k}`))),
      el('span', { className: 'flex-1' }),
      D.images_from !== 'excel' ? el('button', { className: 'btn btn-sm', onclick: () => run(autoLineUp) }, 'Line up automatically') : null,
      dirty ? el('button', { className: 'btn btn-sm btn-primary', onclick: () => run(async () => { await api('calib', { body: { calib, boxes } }); await load(); toast('Saved.', { type: 'success' }); }) }, 'Save changes') : null),
    el('div', { className: 'grid gap-4 lg:grid-cols-[minmax(0,1fr)_240px]' }, el('div', { className: 'fv-viewer' }, view),
      el('div', { className: 'grid content-start gap-3' },
        el('div', { className: 'rounded-box bg-base-200 p-3' }, el('p', { className: 'mb-1 text-xs font-semibold' }, `All boxes on page ${page}`),
          ctl('Left / right', 'ox', 0.1), ctl('Up / down', 'oy', 0.1), ctl('Wider / narrower', 'sx', c.sx * 0.002), ctl('Taller / shorter', 'sy', c.sy * 0.002)),
        sel ? el('div', { className: 'rounded-box bg-base-200 p-3' }, el('p', { className: 'mb-1 text-xs font-semibold' }, 'Selected box'), sel)
          : el('p', { className: 'text-xs text-base-content/60' }, 'Click a box on the page to move just that one.'))));
}

// Step 3: what was matched, what needs a look, and labels that are new on this form.
let filter = 'look';
function stepQuestions(s) {
  const look = D.items.filter((i) => i.status === 'check' || i.status === 'missing');
  const lists = { look, all: D.items };
  const tabs = el('div', { className: 'mb-3 flex flex-wrap gap-1.5', role: 'tablist' },
    ...[['look', 'Needs a look', look.length], ['new', 'New labels', D.added.length], ['gone', 'No longer on the form', D.gone.length], ['all', 'All items', D.items.length]]
      .map(([k, t, n]) => el('button', { className: `btn btn-sm rounded-full${filter === k ? ' btn-active' : ' btn-ghost'}`, role: 'tab', ariaSelected: String(filter === k), onclick: () => { filter = k; draw(); } },
        t, el('span', { className: 'badge badge-sm' }, String(n)))));
  let body;
  if (filter === 'new') {
    body = D.added.length ? el('div', { className: 'grid gap-2' }, ...D.added.map((l) => el('div', { className: 'fv-row' },
      el('div', { className: 'min-w-0' }, el('b', { className: 'block truncate' }, l.text), el('span', { className: 'text-xs text-base-content/60' }, `at ${l.where}`)),
      el('button', { className: 'btn btn-sm', onclick: () => addQuestion(l) }, 'Add as a question'))))
      : el('p', { className: 'fv-empty' }, 'No new labels: every label on this form is also on the current one.');
  } else if (filter === 'gone') {
    body = D.gone.length ? el('ul', { className: 'grid list-disc gap-1 pl-5 text-sm' }, ...D.gone.map((g) => el('li', {}, g)))
      : el('p', { className: 'fv-empty' }, 'Every label of the current form is still there.');
  } else {
    const list = lists[filter];
    body = list.length ? el('div', { className: 'grid gap-2' }, ...list.map((it) => {
      const inp = el('input', { className: 'input input-sm w-full font-mono sm:w-40', value: it.new || '', placeholder: 'Sheet!Cell', ariaLabel: `Cell for ${it.label}` });
      const key = it.id.startsWith('f:') ? it.id.slice(2) : null;
      return el('div', { className: 'fv-row' },
        el('div', { className: 'min-w-0 flex-1' },
          el('div', { className: 'flex flex-wrap items-center gap-2' }, el('b', {}, it.label), badge(it.status)),
          el('p', { className: 'text-xs text-base-content/60' }, `${it.how}. Current form: ${it.old || '–'}`)),
        el('div', { className: 'flex w-full flex-wrap items-center gap-1.5 sm:w-auto' }, inp,
          el('button', { className: 'btn btn-sm', onclick: () => run(async () => { await api('ref', { body: { index: it.index, ref: inp.value } }); await load(); toast('Saved.', { type: 'success' }); }) }, 'Save'),
          key && it.status === 'missing' ? el('button', { className: 'btn btn-sm btn-ghost', onclick: () => run(async () => { await api('drop', { body: { key } }); await load(); }) }, 'Leave out') : null));
    })) : el('p', { className: 'fv-empty' }, filter === 'look' ? 'Nothing needs a look: every item was matched.' : 'No items.');
  }
  return step(3, s, el('p', { className: 'mb-3 text-sm text-base-content/70' }, 'PDSync found each question by its printed label. Fix anything marked "Needs a look" by writing the cell where the answer goes (for example C1!D12), and add questions the new form asks.'), tabs, body);
}

function addQuestion(l) {
  const dlg = $('#dlg-add'), f = $('#add-form');
  f.label.value = l.text.replace(/^\s*\d+\s*[.)]\s*/, '');
  f.section.replaceChildren(...D.sections.map((s) => el('option', { value: s.id }, s.title)));
  f.ref.value = '';
  dlg.returnValue = '';
  dlg.onclose = () => {
    if (dlg.returnValue !== 'add') return;
    run(async () => {
      await api('add', { body: { label: f.label.value, section: f.section.value, type: f.type.value, ref: f.ref.value } });
      await load();
      toast('Question added.', { type: 'success' });
    });
  };
  dlg.showModal();
}

// Step 4: the form filled with sample answers, shown right here, and as the Excel file.
async function loadTry() {
  tryHtml = 'loading';
  try {
    const { data } = await api('sample');
    const res = await fetch(`view.php?form=${encodeURIComponent(D.id)}`, { method: 'POST', body: JSON.stringify(data) });
    tryHtml = await res.text();
  } catch {
    tryHtml = null;
  }
  const fr = $('#try-inline');
  if (fr && tryHtml && tryHtml !== 'loading') fr.srcdoc = tryHtml;
}
function stepTry(s) {
  if (!D.images) return step(4, s, el('p', { className: 'text-sm text-base-content/60' }, 'This step opens when the page images are ready.'));
  const excel = () => run(async () => {
    const { data } = await api('sample');
    const res = await fetch(`xlsx.php?form=${encodeURIComponent(D.id)}`, { method: 'POST', body: JSON.stringify(data) });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The test file could not be made.');
    el('a', { href: URL.createObjectURL(await res.blob()), download: `${D.revision || D.id} test.xlsx` }).click();
  });
  const frame = el('iframe', { id: 'try-inline', title: 'Preview with sample answers', className: 'fv-try' });
  if (tryHtml && tryHtml !== 'loading') frame.srcdoc = tryHtml;
  return step(4, s,
    el('p', { className: 'mb-3 text-sm text-base-content/70' }, 'This is the new form filled with sample answers, exactly as people will see it. Scroll through every page and check that each answer sits in its box.'),
    el('div', { className: 'mb-3 flex flex-wrap gap-2' },
      el('button', { className: 'btn btn-sm', onclick: () => { $('#try-frame').srcdoc = tryHtml || ''; $('#dlg-try').showModal(); } }, icon(ICON.expand, 'size-4'), 'Full screen'),
      el('button', { className: 'btn btn-sm', onclick: () => { tryHtml = null; loadTry(); } }, 'Refresh'),
      el('button', { className: 'btn btn-sm', onclick: excel }, icon(ICON.sheet, 'size-4'), 'Download a test Excel file')),
    frame);
}

// Step 5.
function stepActivate(s) {
  if (D.status === 'active') return step(5, s, el('p', { className: 'text-sm text-base-content/70' }, 'This is the form people fill out now.'));
  return step(5, s,
    el('p', { className: 'mb-3 text-sm text-base-content/70' }, 'When everything looks right, make this version the one people fill out. You can switch back to the previous version from the list at any time.'),
    el('button', { className: 'btn btn-primary', disabled: !D.images, onclick: () => activate({ id: D.id, name: D.name }) }, 'Make active'));
}

addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });
run(formId ? load : showList);
