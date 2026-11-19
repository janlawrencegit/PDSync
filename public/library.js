// My PDS library: lists the account's PDS (or this browser's one) as cards or rows, with search, filters, sorting,
// a menu per PDS (also on right-click) and the Trash. Uses ui.js for icons, dialogs and messages.
const L = window.LIB;
let docs = [];
let view = 'all';
let trashDays = 30;
let mode = store.get('pds-lib-mode') === 'list' ? 'list' : 'grid';

// "3 days ago", "just now"
const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
// t: Unix seconds from the server, or a date string (this browser's PDS).
function ago(t) {
  const ms = typeof t === 'number' || /^\d+$/.test(String(t)) ? t * 1000 : new Date(String(t).replace(' ', 'T')).getTime();
  const s = (ms - Date.now()) / 1000;
  for (const [unit, n] of [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]]) {
    if (Math.abs(s) >= n) return rtf.format(Math.round(s / n), unit);
  }
  return 'just now';
}
const label = (d) => d.name || d.person || 'Untitled PDS';
const daysLeft = (d) => Math.max(0, trashDays - Math.floor((Date.now() - d.deleted * 1000) / 86400000));

async function account(op, body = {}) {
  const res = await fetch('account.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op, ...body }) });
  const out = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(out.error || 'Something went wrong. Please try again.');
  return out;
}

async function load() {
  $('#lib').replaceChildren(...Array.from({ length: 4 }, () => el('div', { className: 'lib-skel' })));
  try {
    if (!L.user) return loadAnon();
    const out = await (await fetch(`account.php${view === 'trash' ? '?trash' : ''}`)).json();
    docs = out.docs || [];
    trashDays = out.trash_days || 30;
    if (view === 'all') $('#count-all').textContent = docs.length || '';
    draw();
  } catch {
    $('#lib').replaceChildren(el('p', { className: 'lib-empty' }, 'Your PDS could not be loaded. Check your connection and try again.'));
  }
}

// Without an account: this browser's one PDS, and what signing in adds.
async function loadAnon() {
  const out = await (await fetch('api.php')).json();
  const f = out.data?.fields || {};
  docs = out.id ? [{ id: out.id, name: out.name, person: [f.first_name, f.surname].filter(Boolean).join(' '), surname: f.surname, first_name: f.first_name, middle_name: f.middle_name, updated: out.updated_ts, progress: out.progress || 0, local: true }] : [];
  draw();
  const box = $('#lib-signin');
  box.hidden = false;
  box.className = 'lib-signin';
  box.replaceChildren(
    el('h3', { className: 'text-lg font-semibold' }, 'Keep more than one PDS'),
    el('p', { className: 'mt-1 text-sm text-base-content/70' }, 'Without signing in, this browser keeps one PDS. Sign in with Google to keep several, open them on any device, copy details into a new PDS, and restore deleted ones from the Trash.'),
    L.google ? el('a', { className: 'btn btn-primary mt-4', href: 'auth.php?login' }, 'Sign in with Google') : el('p', { className: 'mt-3 text-xs text-base-content/60' }, 'Sign-in is not set up on this server.'));
}
function visibleDocs() {
  const q = $('#lib-q').value.trim().toLowerCase(), status = $('#lib-status').value, sort = $('#lib-sort').value;
  return docs
    .filter((d) => !q || `${label(d)} ${d.person || ''}`.toLowerCase().includes(q))
    .filter((d) => status === 'all' || (status === 'done' ? d.progress >= 100 : d.progress < 100))
    .sort((a, b) => {
      if (sort === 'name') return label(a).localeCompare(label(b));
      const k = sort === 'created' ? 'created' : view === 'trash' ? 'deleted' : 'updated';
      return (+b[k] || 0) - (+a[k] || 0);
    });
}

// Page 1 of the form, faded, with the person's name on a label so PDS are easy to tell apart.
function thumb(d) {
  const page = el('div', { className: 'lib-page' }, el('img', { src: L.thumb.page, alt: '', loading: 'lazy', draggable: false }));
  page.style.aspectRatio = `1 / ${L.thumb.aspect}`;
  const v = (k) => (d[k] && d[k] !== 'null' && d[k] !== 'N/A' ? d[k] : '');
  const surname = v('surname'), first = [v('first_name'), v('middle_name')].filter(Boolean).join(' ');
  const tag = el('div', { className: 'lib-tag' },
    el('b', {}, surname ? surname.toUpperCase() : 'NO NAME YET'),
    first ? el('span', {}, first) : null);
  return el('div', { className: 'contents' }, page, tag);
}

const openUrl = (d) => (d.local ? './' : `./#open=${encodeURIComponent(d.id)}`);
function progressBar(d) {
  return el('div', { className: 'lib-bar', title: `${d.progress}% answered` }, el('span', { style: `width:${d.progress}%` }));
}
// The bar with its percentage beside it, under each card.
const progressRow = (d) => el('div', { className: `lib-prog${d.progress >= 100 ? ' done' : ''}` }, progressBar(d), el('span', {}, d.progress >= 100 ? 'Complete' : `${d.progress}%`));
function meta(d) {
  if (view === 'trash') return `Deleted ${ago(d.deleted)} · ${daysLeft(d)} days left`;
  return `Edited ${ago(d.updated)}`;
}

function card(d) {
  const more = el('button', { className: 'lib-more', title: 'More options', ariaLabel: `More options for ${label(d)}`, onclick: (e) => { e.preventDefault(); openMenu(d, more); } }, icon(ICON.more, 'size-5'));
  const link = el(view === 'trash' ? 'div' : 'a', { className: 'lib-thumb', ...(view === 'trash' ? {} : { href: openUrl(d), ariaLabel: `Open ${label(d)}` }) }, thumb(d),
    d.progress >= 100 ? el('span', { className: 'lib-badge done' }, icon(ICON.check, 'size-3'), 'Complete') : null);
  const c = el('article', { className: `lib-card${view === 'trash' ? ' trashed' : ''}` }, link,
    el('div', { className: 'lib-info' },
      el('div', { className: 'min-w-0 flex-1' },
        el(view === 'trash' ? 'span' : 'a', { className: 'lib-name', textContent: label(d), title: label(d), ...(view === 'trash' ? {} : { href: openUrl(d) }) }),
        el('p', { className: 'lib-meta' }, meta(d))),
      more),
    view === 'trash' ? null : progressRow(d));
  c.oncontextmenu = (e) => {
    e.preventDefault();
    openMenu(d, null, [e.clientX, e.clientY]);
  };
  return c;
}

function row(d) {
  const more = el('button', { className: 'lib-more', title: 'More options', ariaLabel: `More options for ${label(d)}`, onclick: () => openMenu(d, more) }, icon(ICON.more, 'size-5'));
  const r = el('div', { className: `lib-row${view === 'trash' ? ' trashed' : ''}` },
    el(view === 'trash' ? 'div' : 'a', { className: 'lib-row-thumb', ...(view === 'trash' ? {} : { href: openUrl(d), tabIndex: -1 }) }, thumb(d)),
    el('div', { className: 'min-w-0 flex-1' },
      el(view === 'trash' ? 'span' : 'a', { className: 'lib-name', textContent: label(d), ...(view === 'trash' ? {} : { href: openUrl(d) }) }),
      el('p', { className: 'lib-meta' }, meta(d))),
    view === 'trash' ? null : el('div', { className: 'hidden w-48 sm:block' }, progressRow(d)),
    more);
  r.oncontextmenu = (e) => {
    e.preventDefault();
    openMenu(d, null, [e.clientX, e.clientY]);
  };
  return r;
}

function draw() {
  const list = visibleDocs(), box = $('#lib');
  box.className = mode === 'grid' ? 'lib-grid' : 'lib-list';
  document.querySelectorAll('[data-mode]').forEach((b) => b.classList.toggle('btn-active', b.dataset.mode === mode));
  $('#lib-heading').textContent = view === 'trash' ? 'Trash' : L.user ? 'All PDS' : 'Your PDS on this browser';
  $('#lib-sub').textContent = view === 'trash' ? `Deleted forever after ${trashDays} days` : docs.length ? `${list.length} of ${docs.length}` : '';
  $('#lib-empty-trash').hidden = view !== 'trash' || !docs.length;
  if (!list.length) {
    const text = docs.length ? 'No PDS match your search.' : view === 'trash' ? 'The Trash is empty.' : 'No PDS yet. Start one and it appears here.';
    box.replaceChildren(el('div', { className: 'lib-empty' }, el('p', {}, text),
      !docs.length && view === 'all' ? el('a', { className: 'btn btn-primary btn-sm mt-3', href: L.user ? './#new' : './' }, 'New PDS') : null));
    stats();
    return;
  }
  const items = list.map(mode === 'grid' ? card : row);
  // A "New PDS" tile leads the grid, like a blank design in a design library.
  if (mode === 'grid' && view === 'all' && L.user && !$('#lib-q').value.trim()) {
    items.unshift(el('a', { className: 'lib-new', href: './#new' }, el('span', { className: 'lib-new-ico' }, icon(ICON.plus, 'size-6')), el('b', {}, 'New PDS'), el('small', {}, 'Start a blank form')));
  }
  box.replaceChildren(...items);
  stats();
}

// Greeting and the counts in the header.
function stats() {
  const h = new Date().getHours();
  const hello = `Good ${h < 12 ? 'morning' : h < 18 ? 'afternoon' : 'evening'}${L.first ? `, ${L.first}` : ''}`;
  $('#lib-hello').textContent = view === 'trash' ? 'Deleted PDS' : hello;
  $('#lib-lead').textContent = view === 'trash' ? `Restore a PDS, or let it go. Items here are deleted forever after ${trashDays} days.` : 'Every Personal Data Sheet you are working on, in one place.';
  const box = $('#lib-stats');
  box.hidden = view === 'trash' || !docs.length;
  if (box.hidden) return;
  const done = docs.filter((d) => d.progress >= 100).length;
  const stat = (n, t, cls = '') => el('div', { className: `lib-stat ${cls}` }, el('dt', {}, t), el('dd', {}, String(n)));
  box.replaceChildren(stat(docs.length, 'Total'), stat(docs.length - done, 'In progress', 'warn'), stat(done, 'Complete', 'ok'));
}

// ---- Menu per PDS ------------------------------------------------------------------------------------------------
const menu = $('#lib-menu');
function openMenu(d, anchor, point) {
  const item = (ic, text, fn, cls = '') => el('button', { className: `lib-mi ${cls}`, role: 'menuitem', onclick: () => { menu.hidePopover(); fn(); } }, icon(ic), text);
  const items = view === 'trash' ? [
    item(ICON.restore, 'Restore', () => restore(d)),
    item(ICON.trash, 'Delete forever', () => purge(d), 'text-error'),
  ] : [
    item(ICON.open, 'Open', () => (location.href = openUrl(d))),
    item(ICON.open, 'Open in a new tab', () => window.open(openUrl(d), '_blank')),
    ...(d.local ? [] : [item(ICON.rename, 'Rename', () => rename(d))]),
    el('hr'),
    ...(d.local ? [] : [item(ICON.copy, 'Make a copy', () => copy(d)), item(ICON.layers, 'Copy sections to a new PDS…', () => copySections(d))]),
    item(ICON.sheet, 'Download Excel (.xlsx)', () => downloadXlsx(d)),
    item(ICON.download, 'Download a copy (.json)', () => downloadJson(d)),
    ...(d.local ? [] : [el('hr'), item(ICON.trash, 'Move to Trash', () => trash(d), 'text-error')]),
  ];
  menu.replaceChildren(el('p', { className: 'lib-mt' }, el('b', {}, label(d)), el('small', {}, meta(d))), ...items);
  menu.showPopover();
  const w = menu.offsetWidth, h = menu.offsetHeight;
  let [x, y] = point || [];
  if (anchor) {
    const r = anchor.getBoundingClientRect();
    x = r.right - w;
    y = innerHeight - r.bottom > h + 8 ? r.bottom + 4 : r.top - h - 4;
  }
  menu.style.left = `${Math.max(8, Math.min(x, innerWidth - w - 8))}px`;
  menu.style.top = `${Math.max(8, Math.min(y, innerHeight - h - 8))}px`;
  menu.querySelector('button')?.focus();
}
menu.addEventListener('keydown', (e) => {
  if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
  e.preventDefault();
  const b = [...menu.querySelectorAll('button')], i = b.indexOf(document.activeElement);
  b[(i + (e.key === 'ArrowDown' ? 1 : -1) + b.length) % b.length]?.focus();
});
addEventListener('scroll', () => menu.matches(':popover-open') && menu.hidePopover(), { passive: true });

// ---- Actions --------------------------------------------------------------------------------------------------------
const run = (fn) => fn().catch((e) => toast(e.message, { type: 'error' }));

function rename(d) {
  run(async () => {
    const r = await ask({ title: 'Rename PDS', input: label(d), buttons: [{ label: 'Cancel', value: '' }, { label: 'Rename', value: 'ok', cls: 'btn-primary' }] });
    if (!r) return;
    await account('rename', { id: d.id, name: r.text });
    d.name = r.text.trim();
    draw();
  });
}
function copy(d) {
  run(async () => {
    const out = await account('copy', { id: d.id, name: `${label(d)} (copy)` });
    await load();
    toast('Copy created.', { type: 'success', action: () => (location.href = `./#open=${out.id}`), label: 'Open' });
  });
}
let copyFrom = null;
function copySections(d) {
  copyFrom = d;
  $('#copy-name').value = `${label(d)} (new)`;
  $('#copy-secs').replaceChildren(el('legend', { className: 'px-1 text-xs font-medium text-base-content/70' }, 'Sections to copy'),
    ...L.sections.map((s) => el('label', { className: 'flex items-center gap-2' },
      el('input', { type: 'checkbox', className: 'checkbox checkbox-primary checkbox-sm', value: s.id, checked: s.id !== 'questions' }), s.title)));
  $('#dlg-copy').returnValue = '';
  $('#dlg-copy').showModal();
}
$('#dlg-copy').addEventListener('close', () => {
  if ($('#dlg-copy').returnValue !== 'copy') return;
  const sections = [...document.querySelectorAll('#copy-secs input:checked')].map((i) => i.value);
  run(async () => {
    const out = await account('copy', { id: copyFrom.id, name: $('#copy-name').value, sections });
    await load();
    toast('New PDS created from the chosen sections.', { type: 'success', action: () => (location.href = `./#open=${out.id}`), label: 'Open' });
  });
});

async function pdsData(d) {
  const res = await fetch(d.local ? 'api.php' : `api.php?id=${encodeURIComponent(d.id)}`);
  const out = await res.json();
  if (!res.ok || !out.data) throw new Error('This PDS could not be opened.');
  return out.data;
}
const safeName = (d) => label(d).replace(/[\\/:*?"<>|]+/g, '').trim() || 'PDS';
function downloadXlsx(d) {
  run(async () => {
    const t = toast('Creating the Excel file…', { ms: 0 });
    try {
      const res = await fetch('xlsx.php', { method: 'POST', body: JSON.stringify(await pdsData(d)) });
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The Excel file could not be created.');
      el('a', { href: URL.createObjectURL(await res.blob()), download: `${safeName(d)}.xlsx` }).click();
      track('export_xlsx');
    } finally {
      t.remove();
    }
  });
}
function downloadJson(d) {
  run(async () => {
    const data = await pdsData(d);
    const blob = new Blob([JSON.stringify({ app: 'PDSync', form: d.form || '', saved: new Date().toISOString(), name: d.name || '', data }, null, 1)], { type: 'application/json' });
    el('a', { href: URL.createObjectURL(blob), download: `${safeName(d)}.json` }).click();
  });
}
function trash(d) {
  run(async () => {
    await account('trash', { id: d.id });
    docs = docs.filter((x) => x !== d);
    $('#count-all').textContent = docs.length || '';
    draw();
    toast(`"${label(d)}" moved to the Trash.`, { action: () => restore(d), label: 'Undo' });
  });
}
function restore(d) {
  run(async () => {
    await account('restore', { id: d.id });
    await load();
    toast(`"${label(d)}" restored.`, { type: 'success', action: () => (location.href = `./#open=${d.id}`), label: 'Open' });
  });
}
function purge(d) {
  run(async () => {
    if (await ask({ title: `Delete "${label(d)}" forever?`, body: 'It cannot be recovered.', buttons: [{ label: 'Cancel', value: '' }, { label: 'Delete forever', value: 'del', cls: 'btn-error' }] }) !== 'del') return;
    await account('purge', { id: d.id });
    await load();
  });
}
$('#lib-empty-trash').onclick = () => run(async () => {
  if (await ask({ title: 'Empty the Trash?', body: `${docs.length} PDS will be deleted forever.`, buttons: [{ label: 'Cancel', value: '' }, { label: 'Empty Trash', value: 'del', cls: 'btn-error' }] }) !== 'del') return;
  await account('empty-trash');
  await load();
});

// ---- Controls -----------------------------------------------------------------------------------------------------
document.querySelectorAll('#lib-nav [data-view]').forEach((b) => (b.onclick = () => {
  view = b.dataset.view;
  document.querySelectorAll('#lib-nav [data-view]').forEach((x) => x.classList.toggle('on', x === b));
  $('#lib-title').textContent = view === 'trash' ? 'Trash' : 'My PDS';
  load();
}));
document.querySelectorAll('[data-mode]').forEach((b) => (b.onclick = () => {
  mode = b.dataset.mode;
  store.set('pds-lib-mode', mode);
  draw();
}));
['#lib-q', '#lib-status', '#lib-sort'].forEach((s) => $(s).addEventListener('input', draw));
addEventListener('keydown', (e) => {
  if (e.key === '/' && document.activeElement?.tagName !== 'INPUT') {
    e.preventDefault();
    $('#lib-q').focus();
  }
});
$('#form-logout')?.addEventListener('submit', () => ['pds-draft', 'pds-current', 'pds-name'].forEach(store.del));

// Coming from the editor's "Move to Trash": offer Undo.
const trashed = new URLSearchParams(location.hash.slice(1)).get('trashed');
if (trashed) {
  history.replaceState(null, '', location.pathname);
  toast('Moved to the Trash.', { action: () => restore({ id: trashed, name: 'Your PDS' }), label: 'Undo', ms: 10000 });
}
load();
track('visit');
