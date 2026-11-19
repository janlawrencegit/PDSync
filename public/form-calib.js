// Fits a form version's page geometry to its page image: finds the printed grid lines in the image and searches the
// scale and offset (per axis) that put the template's column and row edges on them. Used by the admin page
// (forms.js); also loadable in Node for tests (module.exports).
//   img:  { w, h, gray(x, y) -> 0..255 }
//   geo:  { cols: [points], rows: [points], calib: { sx, ox, sy, oy } }  (percent of page per point, offset in percent)
// Returns { sx, ox, sy, oy, score: { x, y } }, score being the share of edges that landed on a line.
(function (root) {
  // Ink along each image column (vertical lines) and row (horizontal lines), counting only pixels that continue the
  // line on both sides, so text adds little.
  function profiles(img) {
    const { w, h } = img;
    const dark = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) dark[y * w + x] = img.gray(x, y) < 140 ? 1 : 0;
    const V = new Float32Array(w), H = new Float32Array(h);
    for (let y = 1; y < h - 1; y++) {
      for (let x = 1; x < w - 1; x++) {
        const i = y * w + x;
        if (!dark[i]) continue;
        if (dark[i - w] && dark[i + w]) V[x]++;
        if (dark[i - 1] && dark[i + 1]) H[y]++;
      }
    }
    // Normalized and widened by a pixel each way, since edges round to whole pixels.
    const norm = (P, n) => {
      const max = Math.max(...P) || 1, out = new Float32Array(n);
      for (let i = 0; i < n; i++) out[i] = Math.max(P[i], P[i - 1] || 0, P[i + 1] || 0) / max;
      return out;
    };
    return { V: norm(V, w), H: norm(H, h) };
  }

  // Best (scale, offset) for one axis: coarse grid around the start, then finer passes.
  function fitAxis(edges, prof, size, s0, o0) {
    const score = (s, o) => {
      let t = 0;
      for (const e of edges) {
        const px = Math.round(((s * e + o) / 100) * size);
        if (px >= 0 && px < size) t += prof[px];
      }
      return t;
    };
    let best = { s: s0, o: o0, v: score(s0, o0) };
    const passes = [
      [0.15, 0.002, 12, 0.1],
      [0.004, 0.0002, 0.4, 0.01],
      [0.0004, 0.00002, 0.04, 0.002],
    ];
    for (const [sr, ss, or, os] of passes) {
      const cs = best.s, co = best.o;
      for (let f = -sr; f <= sr + 1e-9; f += ss) {
        const s = cs * (1 + f);
        for (let o = co - or; o <= co + or + 1e-9; o += os) {
          const v = score(s, o);
          if (v > best.v) best = { s, o, v };
        }
      }
    }
    return { s: best.s, o: best.o, hit: best.v / edges.length };
  }

  function calibrate(img, geo) {
    const { V, H } = profiles(img);
    const c = geo.calib;
    const x = fitAxis(geo.cols, V, img.w, c.sx, c.ox);
    const y = fitAxis(geo.rows, H, img.h, c.sy, c.oy);
    return { sx: x.s, ox: x.o, sy: y.s, oy: y.o, score: { x: Math.round(x.hit * 100) / 100, y: Math.round(y.hit * 100) / 100 } };
  }

  // Moves checkbox boxes onto the squares printed on the page. The positions stored in a template's controls are not
  // always where Excel draws them, so each row of checkboxes (same question) is searched in a band around its expected
  // place: the squares with dark edges and a light inside are taken, as many as the row has boxes, left to right.
  //   items: layout items (page percentages); returns { id: { x, y, w, h } } for the boxes it found.
  function snapChecks(img, items) {
    const { w, h } = img;
    const dark = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) dark[y * w + x] = img.gray(x, y) < 150 ? 1 : 0;
    // Running sums along rows, along columns, and over areas, so any edge or inside costs O(1).
    const rowSum = new Int32Array((w + 1) * h), colSum = new Int32Array(w * (h + 1)), area = new Int32Array((w + 1) * (h + 1));
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const d = dark[y * w + x];
        rowSum[y * (w + 1) + x + 1] = rowSum[y * (w + 1) + x] + d;
        colSum[(y + 1) * w + x] = colSum[y * w + x] + d;
        area[(y + 1) * (w + 1) + x + 1] = area[y * (w + 1) + x + 1] + area[(y + 1) * (w + 1) + x] - area[y * (w + 1) + x] + d;
      }
    }
    const hRun = (y, x0, x1) => rowSum[y * (w + 1) + x1] - rowSum[y * (w + 1) + x0];
    const vRun = (x, y0, y1) => colSum[y1 * w + x] - colSum[y0 * w + x];
    const box = (x0, y0, x1, y1) => area[y1 * (w + 1) + x1] - area[y0 * (w + 1) + x1] - area[y1 * (w + 1) + x0] + area[y0 * (w + 1) + x0];
    // How much a square at (x, y) of side s looks like an empty checkbox: dark border, light inside.
    const squareScore = (x, y, s) => {
      if (x < 1 || y < 1 || x + s >= w - 1 || y + s >= h - 1) return -1;
      const edge = (hRun(y, x, x + s) + hRun(y + s - 1, x, x + s) + vRun(x, y, y + s) + vRun(x + s - 1, y, y + s)) / (4 * s);
      const inner = s > 6 ? box(x + 3, y + 3, x + s - 3, y + s - 3) / ((s - 6) * (s - 6)) : 0;
      // Lines continuing past the square's corners mean it is part of a table border, not a checkbox.
      const outside = (hRun(y, Math.max(0, x - 4), x) + hRun(y, x + s, Math.min(w, x + s + 4))) / 8;
      return edge - inner - 0.5 * outside;
    };

    // Groups: one question's boxes, split into lines when they sit on different rows (e.g. civil status).
    const byKey = {};
    for (const it of items) {
      if (!it.control || !it.id.startsWith('c:')) continue;
      (byKey[`${it.page}|${it.id.split(':')[1]}`] ||= []).push(it);
    }
    const rows = [];
    for (const list of Object.values(byKey)) {
      list.sort((a, b) => a.y - b.y);
      let line = [];
      for (const it of list) {
        if (line.length && it.y - line[0].y > 0.6) {
          rows.push(line);
          line = [];
        }
        line.push(it);
      }
      rows.push(line);
    }
    const out = {};
    for (const group of rows) {
      group.sort((a, b) => a.x - b.x);
      const s0 = Math.round((((group[0].w / 100) * w) + ((group[0].h / 100) * h)) / 2);
      const x0 = Math.max(0, Math.floor(((group[0].x - 3) / 100) * w)), x1 = Math.min(w - 1, Math.ceil(((group.at(-1).x + 8) / 100) * w));
      const yc = ((group[0].y + group[0].h / 2) / 100) * h, y0 = Math.max(0, Math.floor(yc - 0.012 * h)), y1 = Math.min(h - 1, Math.ceil(yc + 0.012 * h));
      const found = [];
      for (let s = Math.max(6, Math.round(s0 * 0.75)); s <= Math.round(s0 * 1.6); s++) {
        for (let y = y0; y <= y1 - s; y++) {
          for (let x = x0; x <= x1 - s; x++) {
            const v = squareScore(x, y, s);
            if (v > 0.55) found.push({ x, y, s, v });
          }
        }
      }
      // Best squares that do not overlap, as many as the row has boxes, then left to right.
      found.sort((a, b) => b.v - a.v);
      const picked = [];
      for (const f of found) {
        if (picked.length === group.length) break;
        if (picked.some((p) => f.x < p.x + p.s && p.x < f.x + f.s && f.y < p.y + p.s && p.y < f.y + f.s)) continue;
        picked.push(f);
      }
      if (picked.length !== group.length) continue;
      picked.sort((a, b) => a.x - b.x);
      picked.forEach((p, i) => {
        out[group[i].id] = { x: (100 * p.x) / w, y: (100 * p.y) / h, w: (100 * p.s) / w, h: (100 * p.s) / h };
      });
    }
    return out;
  }

  const api = { calibrate, profiles, snapChecks };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.FormCalib = api;
})(this);
