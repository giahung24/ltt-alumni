// make_anchor: unidecode + lowercase + non-word -> "-".
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
// year range (e.g. "2010-2014", "2015-now", "2018-Présent", "2017 - hiện nay").
// Each year-range line opens a timeline item (year label on top, text below)
// and following non-year lines continue that item.
const YEAR_RANGE_RE = /^((?:19|20)\d{2}\s*[\-_–—]?\s*(?:(?:19|20)\d{2}|now|hiện\s*nay|hien\s*nay|nay|pr[ée]sent|en\s*cours|maintenant)?)(?=\s*$|\s*[:\-–—,.]|\s+)/i;

// Typographic tidy-up of free text typed into the Google Form (display only).
function tidyLine(s) {
  return String(s)
    .trim()
    .replace(/^[-–—]{2,}\s*/, '– ')            // leading "-- Cử nhân..." -> "– Cử nhân..."
    .replace(/^-\s+/, '– ')                    // leading "- Dược học..." -> "– Dược học..."
    .replace(/\s*--+\s*/g, ' – ')              // inline "A -- B" -> "A – B"
    .replace(/\s+_\s+/g, ' – ')                // inline "USSH _ HCM" -> "USSH – HCM"
    .replace(/\(\s+/g, '(')                    // "( Sư phạm" -> "(Sư phạm"
    .replace(/\s+\)/g, ')')                    // "UIC )" -> "UIC)"
    .replace(/\s+([:;,!?])/g, '$1')            // remove stray space before : ; , ! ?
    .replace(/:([^\s/0-9)])/g, ': $1')         // ensure single space after colon
    .replace(/([^\s\-–—(])\s+-\s+/g, '$1 – ')  // "Tho - Uni" -> "Tho – Uni"
    .replace(/([^\s\-–—(])-\s+/g, '$1 – ')     // "Pháp- Đại học" -> "Pháp – Đại học"
    .replace(/\s{2,}/g, ' ')
    .trim();
}

// "2007-2011" -> "2007 – 2011", "2018- Présent" -> "2018 – présent"
function formatYearRange(raw) {
  const cleaned = String(raw).trim().replace(/\s+/g, ' ');
  const m = cleaned.match(/^(\d{4})\s*[\-_–—]?\s*(.*)$/);
  if (!m) return cleaned;
  const tail = m[2].trim().toLowerCase();
  return tail ? `${m[1]} – ${tail}` : m[1];
}

function formatLinesWithYears(text) {
  if (!text) return '';
  const items = [];
  let current = null;
  for (const rawLine of String(text).split('\n')) {
    const line = rawLine.trim();
    if (!line) continue;
    const m = line.match(YEAR_RANGE_RE);
    if (m && m[1]) {
      const tail = line.slice(m[0].length).replace(/^\s*[:\-–—,.]\s*/, '');
      current = { year: formatYearRange(m[1]), lines: [] };
      if (tail.trim()) current.lines.push(tidyLine(tail));
      items.push(current);
    } else if (current) {
      current.lines.push(tidyLine(line));
    } else {
      const last = items[items.length - 1];
      if (last && last.year === null) last.lines.push(tidyLine(line));
      else items.push({ year: null, lines: [tidyLine(line)] });
    }
  }
  return items.map(it => {
    const body = it.lines.filter(Boolean).map(escapeHtml).join('<br>');
    if (it.year === null) return body ? `<p class="tl-plain">${body}</p>` : '';
    return `<div class="tl-item"><span class="year_range">${escapeHtml(it.year)}</span>` +
      (body ? `<p class="tl-text">${body}</p>` : '') + `</div>`;
  }).join('');
}
