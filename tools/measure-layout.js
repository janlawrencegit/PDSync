// Finds each color-painted cell in the rendered pages and writes overlay boxes as page percentages.
const fs = require('fs');
const zlib = require('zlib');
const [work, outFile] = process.argv.slice(2);

// Decodes an 8-bit, non-interlaced RGB or RGBA PNG.
function readPng(file) {
  const buf = fs.readFileSync(file);
  let p = 8, w, h, type, idat = [];
  while (p < buf.length) {
    const len = buf.readUInt32BE(p), kind = buf.toString('ascii', p + 4, p + 8), data = buf.subarray(p + 8, p + 8 + len);
    if (kind === 'IHDR') [w, h, type] = [data.readUInt32BE(0), data.readUInt32BE(4), data[9]];
    if (kind === 'IDAT') idat.push(data);
    p += 12 + len;
  }
  const bpp = type === 6 ? 4 : 3, stride = w * bpp, raw = zlib.inflateSync(Buffer.concat(idat)), px = Buffer.alloc(h * stride);
  for (let y = 0; y < h; y++) {
    const f = raw[y * (stride + 1)], src = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1)), row = y * stride;
    for (let x = 0; x < stride; x++) {
      const a = x >= bpp ? px[row + x - bpp] : 0, b = y ? px[row - stride + x] : 0, c = x >= bpp && y ? px[row - stride + x - bpp] : 0;
      const pa = Math.abs(b - c), pb = Math.abs(a - c), pc = Math.abs(a + b - 2 * c);
      const pred = [0, a, b, (a + b) >> 1, pa <= pb && pa <= pc ? a : pb <= pc ? b : c][f];
      px[row + x] = (src[x] + pred) & 255;
    }
  }
  return { w, h, at: (x, y) => { const i = y * stride + x * bpp; return (px[i] << 16) | (px[i + 1] << 8) | px[i + 2]; } };
}

const geo = JSON.parse(fs.readFileSync(`${work}/geometry.json`, 'utf8').replace(/^﻿/, ''));
const sheets = ['C1', 'C2', 'C3', 'C4'];
const items = [];
const ptScale = {};
let size;

sheets.forEach((sheet, n) => {
  const img = readPng(`${work}/colored${n + 1}.png`);
  size = [img.w, img.h];
  const cells = geo.filter((g) => g.sheet === sheet && g.kind === 'cell');
  const want = new Map(cells.map((g) => [(g.rgb[0] << 16) | (g.rgb[1] << 8) | g.rgb[2], { g, xs: [], ys: [] }]));
  for (let y = 0; y < img.h - 1; y++) {
    for (let x = 0; x < img.w - 1; x++) {
      const c = img.at(x, y), hit = want.get(c);
      if (hit && img.at(x + 1, y) === c && img.at(x, y + 1) === c) hit.xs.push(x), hit.ys.push(y);
    }
  }
  // Fits page pixels = a * points + b per axis from measured cells, used for controls.
  const pairs = { x: [], y: [] };
  for (const { g, xs, ys } of want.values()) {
    if (xs.length < 20) { console.warn(`Not found: ${g.id} on ${sheet}`); continue; }
    const trim = (v) => { v.sort((a, b) => a - b); const k = Math.floor(v.length * 0.002); return [v[k], v[v.length - 1 - k] + 1]; };
    const [x0, x1] = trim(xs), [y0, y1] = trim(ys);
    pairs.x.push([g.left + g.width / 2, (x0 + x1) / 2]);
    pairs.y.push([g.top + g.height / 2, (y0 + y1) / 2]);
    items.push({ id: g.id, page: n + 1, x: x0, y: y0, w: x1 - x0, h: y1 - y0, font: g.font, face: g.face, align: g.align, valign: g.valign, wrap: g.wrap,
      ...(g.id.startsWith('s:') && { pt: [g.left, g.top, g.width, g.height], anchor: g.anchor }) });
  }
  const fit = (ps) => {
    const n = ps.length, sx = ps.reduce((s, p) => s + p[0], 0), sy = ps.reduce((s, p) => s + p[1], 0);
    const sxx = ps.reduce((s, p) => s + p[0] * p[0], 0), sxy = ps.reduce((s, p) => s + p[0] * p[1], 0);
    const a = (n * sxy - sx * sy) / (n * sxx - sx * sx);
    return [a, (sy - a * sx) / n];
  };
  const [ax, bx] = fit(pairs.x), [ay, by] = fit(pairs.y);
  ptScale[n + 1] = Math.round((ax / img.w) * 1e6) / 1e4;
  items.filter((i) => i.page === n + 1 && i.font).forEach((i) => (i.font *= ax));
  // Checkboxes use the pixels that differ between the blank and all-checked renders inside the control's area.
  const blank = readPng(`${work}/blank${n + 1}.png`), checked = readPng(`${work}/checked${n + 1}.png`);
  const diff = (a, b) => Math.abs((a >> 16) - (b >> 16)) + Math.abs(((a >> 8) & 255) - ((b >> 8) & 255)) + Math.abs((a & 255) - (b & 255));
  for (const g of geo.filter((g) => g.sheet === sheet && g.kind !== 'cell')) {
    const box = { x: ax * g.left + bx, y: ay * g.top + by, w: ax * g.width, h: ay * g.height };
    if (g.id.startsWith('c:')) {
      const pts = [];
      for (let y = Math.max(0, Math.floor(box.y)); y < Math.min(img.h, box.y + box.h); y++) {
        for (let x = Math.max(0, Math.floor(box.x)); x < Math.min(img.w, box.x + box.w); x++) {
          if (diff(blank.at(x, y), checked.at(x, y)) > 90) pts.push([x, y]);
        }
      }
      // Overlapping controls can catch a neighbour's tick, so keep the cluster nearest this box's left middle.
      const ax0 = box.x + 6, ay0 = box.y + box.h / 2, d = (p, q) => Math.hypot(p[0] - q[0], p[1] - q[1]);
      const seed = pts.sort((p, q) => d(p, [ax0, ay0]) - d(q, [ax0, ay0]))[0];
      const near = seed ? pts.filter((p) => d(p, seed) < 14) : [];
      if (!near.length) console.warn(`Checkmark not found: ${g.id} on ${sheet}`);
      else {
        const xs = near.map((p) => p[0]), ys = near.map((p) => p[1]);
        const cx = Math.round((Math.min(...xs) + Math.max(...xs)) / 2), cy = Math.round((Math.min(...ys) + Math.max(...ys)) / 2);
        // Walks from the tick's center to the dark square border in the blank page to get the box interior.
        const dark = (x, y) => { const c = blank.at(x, y); return (c >> 16) + ((c >> 8) & 255) + (c & 255) < 384; };
        const walk = (dx, dy) => { let k = 1; while (k < 30 && !dark(cx + dx * k, cy + dy * k)) k++; return k; };
        const [l, r, u, b] = [walk(-1, 0), walk(1, 0), walk(0, -1), walk(0, 1)];
        if ([l, r, u, b].every((k) => k < 30)) {
          // Boxes are square; when one side overshoots through a gap in the border, trust the nearer side.
          const s = Math.min(l + r, u + b) - 1;
          const x = l + r - 1 > s ? (l < r ? cx - l + 1 : cx + r - s) : cx - l + 1;
          const y = u + b - 1 > s ? (u < b ? cy - u + 1 : cy + b - s) : cy - u + 1;
          Object.assign(box, { x, y, w: s, h: s });
        }
        else Object.assign(box, { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs) + 1, h: Math.max(...ys) - Math.min(...ys) + 1 });
      }
    }
    items.push({ id: g.id, page: n + 1, ...box, control: true,
      ...(g.kind === 'shape' && { pt: [g.left, g.top, g.width, g.height], anchor: g.anchor }) });
  }
});

// Converts pixel boxes to percentages of the page size.
const pct = (v, d) => Math.round((v / d) * 100000) / 1000;
const out = items.map((i) => ({
  id: i.id, page: i.page, x: pct(i.x, size[0]), y: pct(i.y, size[1]), w: pct(i.w, size[0]), h: pct(i.h, size[1]),
  ...(i.control ? { control: true } : { font: pct(i.font, size[0]), face: i.face, align: i.align, valign: i.valign, wrap: i.wrap }),
  ...(i.pt && { pt: i.pt, anchor: i.anchor }),
}));
// ptScale is one Excel point as a percentage of page width, per page.
fs.writeFileSync(outFile, JSON.stringify({ aspect: size[1] / size[0], pt: ptScale, items: out }));
console.log(`${out.length} boxes written to ${outFile}`);
