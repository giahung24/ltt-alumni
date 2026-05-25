// LTT Alumni — front-only renderer.
// Loads data/alumni.json and renders the sidebar index + alumni cards.

const IMG_EXTS = ['jpg', 'jpeg', 'png', 'JPG'];
const LOCAL_IMG_BASE = 'static/img/';
const DEFAULT_IMG = LOCAL_IMG_BASE + 'default.jpg';

const els = {
  loader: document.getElementById('loader'),
  entries: document.getElementById('entries'),
  indexBody: document.getElementById('index-body'),
  yearFilter: document.getElementById('year-filter'),
  toggleEmail: document.getElementById('toggle-email'),
};

let allEntries = [];

function titleCase(name) {
  return String(name)
    .toLowerCase()
    .split(/\s+/)
    .map(w => w ? w[0].toUpperCase() + w.slice(1) : w)
    .join(' ');
}

function getPictureId(link) {
  if (!link) return '';
  const parts = String(link).split('?id=');
  return parts.length > 1 ? parts[1] : '';
}

// Build an <img> that probes local extensions, then Drive thumbnail, then default.jpg.
function buildPhoto(pictureId, alt) {
  const img = document.createElement('img');
  img.className = 'photo';
  img.alt = alt;
  img.loading = 'lazy';

  const candidates = [];
  if (pictureId) {
    for (const ext of IMG_EXTS) candidates.push(`${LOCAL_IMG_BASE}${pictureId}.${ext}`);
    candidates.push(`https://drive.google.com/thumbnail?id=${encodeURIComponent(pictureId)}&sz=w500`);
  }
  candidates.push(DEFAULT_IMG);

  let i = 0;
  img.src = candidates[i];
  img.onerror = () => {
    i += 1;
    if (i < candidates.length) {
      img.src = candidates[i];
    } else {
      img.onerror = null;
    }
  };
  return img;
}

function uniqueSortedYears(entries) {
  const set = new Set(entries.map(e => Number(e.year)).filter(n => Number.isFinite(n)));
  return [...set].sort((a, b) => a - b);
}

// Replicates Python: sort by [year asc, timestamp desc] for "All"; by timestamp desc when filtered.
function filterAndSort(entries, year) {
  const subset = year === 'all' ? entries : entries.filter(e => Number(e.year) === Number(year));
  return subset.slice().sort((a, b) => {
    if (year === 'all' && a.year !== b.year) return Number(a.year) - Number(b.year);
    return String(b.timestamp).localeCompare(String(a.timestamp));
  });
}

function renderYearFilter(years) {
  const opts = ['<option value="all">Tất cả</option>']
    .concat(years.map(y => `<option value="${y}">${y}</option>`));
  els.yearFilter.innerHTML = opts.join('');
}

function renderIndex(entries) {
  const rows = entries.map((e, i) => {
    const anchor = makeAnchor(`${e.year}-${e.fullname}`);
    return `<tr>
      <td>${i + 1}</td>
      <td>${escapeHtml(String(e.year))}</td>
      <td><a href="#${anchor}">${escapeHtml(e.fullname)}</a></td>
    </tr>`;
  });
  els.indexBody.innerHTML = rows.join('');
}

function renderEntries(entries) {
  els.entries.innerHTML = '';
  const frag = document.createDocumentFragment();

  for (const e of entries) {
    const year = Number(e.year);
    const anchor = makeAnchor(`${year}-${e.fullname}`);

    const card = document.createElement('article');
    card.className = 'entry';
    card.id = anchor;

    const promo = document.createElement('h3');
    promo.className = 'promo';
    promo.textContent = `${year - 3} - ${year}`;
    card.appendChild(promo);

    const name = document.createElement('h2');
    name.className = 'fullname';
    name.textContent = e.fullname;
    card.appendChild(name);

    if (e.email) {
      const email = document.createElement('div');
      email.className = 'email';
      email.textContent = e.email;
      card.appendChild(email);
    }

    card.appendChild(buildPhoto(getPictureId(e.picture_link), e.fullname));

    const eduLabel = document.createElement('div');
    eduLabel.innerHTML =
      '<span class="section section_fr increase-spacing-2">FORMATION </span>' +
      '<span class="section section_vn increase-spacing-1"> NGÀNH HỌC</span>';
    card.appendChild(eduLabel);

    const eduBody = document.createElement('div');
    eduBody.className = 'body';
    eduBody.innerHTML = '<p>' + formatLinesWithYears(e.education) + '</p>';
    card.appendChild(eduBody);

    if (e.work) {
      const workLabel = document.createElement('div');
      workLabel.innerHTML =
        '<span class="section section_fr increase-spacing-2">PROFESSION </span>' +
        '<span class="section section_vn increase-spacing-1"> CÔNG VIỆC</span>';
      card.appendChild(workLabel);

      const workBody = document.createElement('div');
      workBody.className = 'body';
      workBody.innerHTML = '<p>' + formatLinesWithYears(e.work) + '</p>';
      card.appendChild(workBody);
    }

    const ts = String(e.timestamp || '').split(' ')[0];
    if (ts) {
      const cap = document.createElement('small');
      cap.className = 'last-update';
      cap.textContent = `Cập nhật lần cuối: ${ts}`;
      card.appendChild(cap);
    }

    frag.appendChild(card);
  }

  els.entries.appendChild(frag);
}

function rerender() {
  const year = els.yearFilter.value;
  const subset = filterAndSort(allEntries, year);
  renderIndex(subset);
  renderEntries(subset);
}

// Replicates utils.keep_last_entry_by_email: per email, keep latest by timestamp.
function dedupByEmail(entries) {
  const byEmail = new Map();
  for (const e of entries) {
    const key = e.email || `__noemail_${Math.random()}`;
    const prev = byEmail.get(key);
    if (!prev || String(e.timestamp) > String(prev.timestamp)) {
      byEmail.set(key, e);
    }
  }
  return [...byEmail.values()];
}

async function init() {
  try {
    const res = await fetch('data/alumni.json', { cache: 'no-cache' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    const entries = Array.isArray(json.entries) ? json.entries : [];

    allEntries = dedupByEmail(entries).map(e => ({
      ...e,
      fullname: titleCase(e.fullname || ''),
    }));

    renderYearFilter(uniqueSortedYears(allEntries));
    els.yearFilter.value = 'all';
    rerender();
    els.loader.style.display = 'none';
  } catch (err) {
    els.loader.textContent = `Không tải được dữ liệu: ${err.message}`;
  }
}

els.yearFilter.addEventListener('change', rerender);

els.toggleEmail.addEventListener('click', () => {
  const on = document.body.classList.toggle('show-emails');
  els.toggleEmail.classList.toggle('active', on);
  els.toggleEmail.textContent = on
    ? '📧 Ẩn email của mọi người'
    : '📧 Hiển thị email của mọi người';
});

init();
