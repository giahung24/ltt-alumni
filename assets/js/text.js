// Text helpers ported from utils.py.

// make_anchor: unidecode + lowercase + non-word -> "-".
// Equivalent to: unidecode(s).lower(), replace \W with space, join words with "-".
function makeAnchor(s) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/g, 'd').replace(/Đ/g, 'D')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(/\s+/)
    .join('-');
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// format_lines_with_years: wrap leading year-range token in <span class="year_range">,
// add <br/> between subsequent lines. Mirrors utils.format_lines_with_years.
const YEAR_RANGE_RE = /^((?:19|20)\d{2} ?[\-_]? ?(?:(?:19|20)\d{2}|now|nay|pr[ée]sent|en cours|maintenant)?)/;

function formatLinesWithYears(text) {
  if (!text) return '';
  const lines = String(text).split('\n');
  const out = lines.map((raw, i) => {
    const line = raw.trim();
    const m = line.match(YEAR_RANGE_RE);
    if (m && m[1]) {
      const yearRange = m[1].replace(/\s+/g, '');
      const tail = line.slice(m[0].length);
      return `\n<span class="year_range">${escapeHtml(yearRange)} </span>${escapeHtml(tail)}`;
    }
    if (i > 0) return '<br/>' + escapeHtml(line);
    return escapeHtml(line);
  });
  return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}
