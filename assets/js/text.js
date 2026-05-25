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

// format_lines_with_years: render raw text where each line may start with a
// year range (e.g. "2010-2014", "2015-now", "2018-maintenant"). Year-range
// lines get a <span class="year_range"> wrapper and a paragraph break (<br><br>)
// before them; non-year lines join with a single <br>. The output is HTML —
// the caller injects it via innerHTML inside a <p>.
const YEAR_RANGE_RE = /^((?:19|20)\d{2} ?[\-_]? ?(?:(?:19|20)\d{2}|now|nay|pr[ée]sent|en cours|maintenant)?)/;

function formatLinesWithYears(text) {
  if (!text) return '';
  const lines = String(text).split('\n');
  const parts = [];
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const m = line.match(YEAR_RANGE_RE);
    if (m && m[1]) {
      const yearRange = m[1].replace(/\s+/g, '');
      const tail = line.slice(m[0].length);
      const head = `<span class="year_range">${escapeHtml(yearRange)} </span>`;
      if (parts.length > 0) parts.push('<br><br>');
      parts.push(head + escapeHtml(tail));
    } else {
      if (parts.length > 0) parts.push('<br>');
      parts.push(escapeHtml(line));
    }
  }
  return parts.join('');
}
