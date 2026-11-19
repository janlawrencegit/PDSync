// Shared page helpers for the editor (app.js) and the My PDS library (library.js): DOM building, icons, browser
// storage that never throws, corner messages and the styled confirm/prompt dialog (#dlg-ask in the page).
const $ = (sel) => document.querySelector(sel);
const el = (tag, attrs = {}, ...kids) => {
  const n = Object.assign(document.createElement(tag), attrs);
  n.append(...kids.filter((k) => k !== null && k !== undefined && k !== false));
  return n;
};
const store = {
  get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k, v) => { try { localStorage.setItem(k, v); } catch {} },
  del: (k) => { try { localStorage.removeItem(k); } catch {} },
};
const clone = (o) => JSON.parse(JSON.stringify(o));
const icon = (d, cls = 'size-4') => {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('class', cls);
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = `<g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${d}</g>`;
  return s;
};
const ICON = {
  up: '<path d="m18 15-6-6-6 6"/>',
  down: '<path d="m6 9 6 6 6-6"/>',
  copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
  sort: '<path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="M11 4h10M11 8h7M11 12h4"/>',
  pen: '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  open: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  rename: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/>',
  layers: '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
  sheet: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  restore: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
  more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  rows: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  zoomIn: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3M11 8v6M8 11h6"/>',
  zoomOut: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3M8 11h6"/>',
  expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
  shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
};

// Short messages in the corner; "action" adds a button such as Undo or Retry.
const TOAST = { info: 'alert-info', success: 'alert-success', warning: 'alert-warning', error: 'alert-error' };
// The same message is never shown twice (the new one replaces it), and at most three show at once.
function toast(msg, { type = 'info', action, label = 'Undo', ms = 6000 } = {}) {
  const box = $('#toasts');
  [...box.children].filter((x) => x.dataset.msg === msg).forEach((x) => x.remove());
  while (box.children.length >= 3) box.firstElementChild.remove();
  const n = el('div', { className: `alert alert-soft ${TOAST[type]} py-2 text-sm shadow-md`, role: type === 'error' ? 'alert' : 'status' }, el('span', {}, msg));
  n.dataset.msg = msg;
  if (action) n.append(el('button', { className: 'btn btn-sm', textContent: label, onclick: () => { n.remove(); action(); } }));
  n.append(el('button', { className: 'btn btn-ghost btn-xs btn-square', ariaLabel: 'Dismiss', textContent: '✕', onclick: () => n.remove() }));
  $('#toasts').append(n);
  if (ms) setTimeout(() => n.remove(), ms);
  return n;
}

// Styled replacement for confirm()/prompt(): resolves with the clicked button's value (and the typed text), or null.
function ask({ title, body = [], input = null, buttons }) {
  return new Promise((resolve) => {
    const d = $('#dlg-ask'), inp = $('#ask-input');
    $('#ask-title').textContent = title;
    $('#ask-body').replaceChildren(...[].concat(body).map((b) => (typeof b === 'string' ? el('p', { className: 'mt-1' }, b) : b)));
    inp.hidden = input === null;
    inp.value = input ?? '';
    const primary = buttons.find((b) => /btn-primary|btn-error/.test(b.cls || '')) || buttons.at(-1);
    inp.onkeydown = (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      d.close(primary.value);
    };
    $('#ask-actions').replaceChildren(...buttons.map((b) => el('button', { className: `btn ${b.cls || 'btn-ghost'}`, value: b.value, textContent: b.label })));
    d.returnValue = '';
    d.onclose = () => resolve(d.returnValue ? (input !== null ? { value: d.returnValue, text: inp.value } : d.returnValue) : null);
    d.showModal();
    if (input !== null) inp.select();
    else d.querySelector(`#ask-actions button[value="${primary.value}"]`)?.focus();
  });
}

// Counts an event: the built-in anonymous counter (lib/stats.php), plus Umami or GoatCounter when the page has them.
function track(e) {
  try {
    navigator.sendBeacon('stats.php', new Blob([JSON.stringify({ e })], { type: 'application/json' }));
  } catch {}
  if (e === 'visit') return;
  try { window.umami?.track(e); } catch {}
  try { window.goatcounter?.count?.({ path: `event/${e}`, title: e, event: true }); } catch {}
}
