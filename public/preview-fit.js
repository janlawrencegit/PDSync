// Text wraps at 10pt and shrinks only when the wrapped lines still overflow the box.
// Sizes stay in cqw (relative to page width) so the fit holds at any zoom and in print.
document.fonts.ready.then(() => document.querySelectorAll('.ov > span').forEach((s) => {
  const box = s.parentElement;
  const base = parseFloat(box.style.fontSize);
  const over = () => s.offsetWidth > box.clientWidth + 1 || s.offsetHeight > box.clientHeight + 1;
  const fit = () => { for (let f = 0.97; over() && f >= 0.35; f -= 0.03) box.style.fontSize = `${(base * f).toFixed(4)}cqw`; };
  fit();
  // Last resort for text too long even when small: allow breaks inside words and fit again.
  if (over()) {
    box.classList.add('breakall');
    box.style.fontSize = `${base}cqw`;
    fit();
  }
}));
