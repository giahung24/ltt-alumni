// LTT Alumni — front-only renderer.
// Reads window.__ALUMNI_DATA__ (inlined by index.php) and renders the roster index + alumni cards.
// Core data logic (dedupByEmail, sorting, filtering, anchors, photo fallback) unchanged.

const LOCAL_IMG_BASE = 'static/img/';
const DEFAULT_IMG = LOCAL_IMG_BASE + 'default.jpg';

const els = {
  loader: document.getElementById('loader'),
  entries: document.getElementById('entries'),
  indexBody: document.getElementById('index-body'),
  yearFilter: document.getElementById('year-filter'),
  toggleEmail: document.getElementById('toggle-email'),
  searchInput: document.getElementById('search-input'),
  activeStats: document.getElementById('active-stats'),
  drawerSearch: document.getElementById('drawer-search'),
  indexDrawer: document.getElementById('index-drawer'),
  drawerBackdrop: document.getElementById('drawer-backdrop'),
  openDrawerBtn: document.getElementById('open-drawer-btn'),
  closeDrawerBtn: document.getElementById('close-drawer-btn'),
  helpModal: document.getElementById('help-modal'),
  helpBackdrop: document.getElementById('help-backdrop'),
  openHelpBtn: document.getElementById('open-help-btn'),
  closeHelpBtn: document.getElementById('close-help-btn'),
  contribModal: document.getElementById('contribute-modal'),
  contribBackdrop: document.getElementById('contribute-backdrop'),
  openContribBtn: document.getElementById('open-contribute-btn'),
  closeContribBtn: document.getElementById('close-contribute-btn'),
  profileModal: document.getElementById('profile-modal'),
  profileBackdrop: document.getElementById('profile-backdrop'),
  closeProfileBtn: document.getElementById('close-profile-btn'),
  profilePhoto: document.getElementById('profile-modal-photo'),
  profileName: document.getElementById('profile-modal-name'),
  profilePromo: document.getElementById('profile-modal-promo'),
  profileEmail: document.getElementById('profile-modal-email'),
  profileSections: document.getElementById('profile-modal-sections'),
  profileUpdated: document.getElementById('profile-modal-updated'),
  profileNav: document.getElementById('profile-modal-nav'),
  profilePos: document.getElementById('profile-modal-pos'),
  profilePrevBtn: document.getElementById('profile-prev-btn'),
  profileNextBtn: document.getElementById('profile-next-btn'),
  profileContent: document.querySelector('#profile-modal .profile-modal-content'),
};

const ICONS = {
  formation: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
  profession: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
  mail: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
};

let allEntries = [];
let currentSubset = [];
let currentProfileIndex = -1;
let profileOpener = null;

function titleCase(name) {
  return String(name)
    .trim()
    .toLowerCase()
    .split(/\s+/)
    .map(w => w ? w[0].toUpperCase() + w.slice(1) : w)
    .join(' ');
}

function foldSearch(s) {
  return String(s || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd').replace(/Đ/g, 'D')
    .toLowerCase();
}

function hasMeaningfulText(s) {
  return Boolean(s && !/^[\s\-–—._]*$|^n\/?a$/i.test(String(s)));
}

// Portrait from the server-provided path; a single onerror fallback to default.jpg.
function buildPhoto(src, alt) {
  const img = document.createElement('img');
  img.className = 'photo';
  img.alt = alt;
  img.loading = 'lazy';
  img.classList.toggle('is-default', !src || src === DEFAULT_IMG);
  img.src = src || DEFAULT_IMG;
  img.onerror = () => {
    img.onerror = null;
    img.classList.add('is-default');
    img.src = DEFAULT_IMG;
  };
  return img;
}

function uniqueSortedYears(entries) {
  const set = new Set(entries.map(e => Number(e.year)).filter(n => Number.isFinite(n)));
  return [...set].sort((a, b) => a - b);
}

// Replicates Python: sort by [year asc, timestamp desc] for "All"; by timestamp desc when filtered.
// Optional keyword narrows the subset (accent-insensitive across name, education, work, year).
function filterAndSort(entries, year, keyword = '') {
  let subset = year === 'all' ? entries : entries.filter(e => Number(e.year) === Number(year));
  const q = foldSearch(keyword.trim());
  if (q) {
    subset = subset.filter(e =>
      [e.fullname, e.education, e.work, e.year].some(v => foldSearch(v).includes(q)));
  }
  return subset.slice().sort((a, b) => {
    if (year === 'all' && a.year !== b.year) return Number(a.year) - Number(b.year);
    return String(b.timestamp).localeCompare(String(a.timestamp));
  });
}

function renderYearFilter(years) {
  const opts = ['<option value="all">Tất cả khóa</option>']
    .concat(years.map(y => `<option value="${y}">Khóa ${y}</option>`));
  els.yearFilter.innerHTML = opts.join('');
}

function renderIndex(entries) {
  const rows = entries.map((e, i) => {
    const anchor = makeAnchor(`${e.year}-${e.fullname}`);
    return `<tr>
      <td>${i + 1}</td>
      <td>${escapeHtml(String(e.year))}</td>
      <td><a href="#${anchor}" class="drawer-jump-link">${escapeHtml(e.fullname)}</a></td>
    </tr>`;
  });
  els.indexBody.innerHTML = rows.join('');
}

function formatDate(ts) {
  const d = String(ts || '').split(' ')[0];
  const m = d.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : d;
}

function buildSection(kind, fr, vn, text) {
  const html = formatLinesWithYears(text);
  if (!html) return null;
  const section = document.createElement('section');
  section.className = `entry-section ${kind}`;
  section.innerHTML =
    `<h3 class="section-label">${ICONS[kind]}<span class="section_fr">${fr}</span>` +
    `<span class="section_vn">${vn}</span></h3>` +
    `<div class="body">${html}</div>`;
  return section;
}

function renderEntries(entries) {
  els.entries.innerHTML = '';

  if (entries.length === 0) {
    els.entries.innerHTML = `<div class="empty-notice">
      <h3>Không tìm thấy hồ sơ phù hợp</h3>
      <p>Thử chọn khóa khác hoặc xóa bớt từ khóa tìm kiếm.</p>
    </div>`;
    return;
  }

  const frag = document.createDocumentFragment();

  for (let i = 0; i < entries.length; i++) {
    const e = entries[i];
    const year = Number(e.year);
    const anchor = makeAnchor(`${year}-${e.fullname}`);

    const card = document.createElement('article');
    card.className = 'entry';
    card.id = anchor;

    // Head: portrait + vertically centered identity
    const head = document.createElement('header');
    head.className = 'entry-head';

    const frame = document.createElement('button');
    frame.type = 'button';
    frame.className = 'photo-frame';
    frame.dataset.profileIndex = String(i);
    frame.setAttribute('aria-label', `Xem hồ sơ của ${e.fullname}`);
    frame.setAttribute('aria-haspopup', 'dialog');
    frame.appendChild(buildPhoto(e.photo, e.fullname));
    head.appendChild(frame);

    const identity = document.createElement('div');
    identity.className = 'entry-identity';

    const name = document.createElement('h2');
    name.className = 'fullname';
    const nameBtn = document.createElement('button');
    nameBtn.type = 'button';
    nameBtn.className = 'fullname-trigger';
    nameBtn.dataset.profileIndex = String(i);
    nameBtn.setAttribute('aria-haspopup', 'dialog');
    nameBtn.textContent = e.fullname;
    name.appendChild(nameBtn);
    identity.appendChild(name);

    const promo = document.createElement('p');
    promo.className = 'promo';
    promo.textContent = `Promo ${year - 3} – ${year}`;
    identity.appendChild(promo);

    if (e.email) {
      const email = document.createElement('a');
      email.className = 'email';
      email.href = `mailto:${e.email}`;
      email.innerHTML = `${ICONS.mail}<span>${escapeHtml(e.email)}</span>`;
      identity.appendChild(email);
    }

    head.appendChild(identity);
    card.appendChild(head);

    const eduSection = buildSection('formation', 'Formation', 'Học vấn', e.education);
    if (eduSection) card.appendChild(eduSection);

    if (hasMeaningfulText(e.work)) {
      const workSection = buildSection('profession', 'Profession', 'Công việc', e.work);
      if (workSection) card.appendChild(workSection);
    }

    const ts = formatDate(e.timestamp);
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

function updateStats(count, total) {
  els.activeStats.textContent = count === total
    ? `${total} cựu học sinh`
    : `${count} / ${total} cựu học sinh`;
}

function rerender() {
  const year = els.yearFilter.value;
  currentSubset = filterAndSort(allEntries, year, els.searchInput.value);
  renderIndex(currentSubset);
  renderEntries(currentSubset);
  updateStats(currentSubset.length, allEntries.length);
  if (els.drawerSearch.value) filterDrawerRows(els.drawerSearch.value);
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

function syncBodyLock() {
  const anyOpen =
    els.indexDrawer.classList.contains('active') ||
    els.helpModal.classList.contains('active') ||
    els.contribModal.classList.contains('active') ||
    els.profileModal.classList.contains('active');
  document.body.style.overflow = anyOpen ? 'hidden' : '';
}

// ---------- Profile modal ----------
function renderProfileModal(idx) {
  const e = currentSubset[idx];
  if (!e) return;
  currentProfileIndex = idx;

  const year = Number(e.year);
  const src = e.photo || DEFAULT_IMG;
  els.profilePhoto.onerror = null;
  els.profilePhoto.classList.toggle('is-default', !e.photo || src === DEFAULT_IMG);
  els.profilePhoto.alt = e.fullname;
  els.profilePhoto.src = src;
  els.profilePhoto.onerror = () => {
    els.profilePhoto.onerror = null;
    els.profilePhoto.classList.add('is-default');
    els.profilePhoto.src = DEFAULT_IMG;
  };

  els.profileName.textContent = e.fullname;
  els.profilePromo.textContent = `Promo ${year - 3} – ${year}`;

  if (e.email) {
    els.profileEmail.hidden = false;
    els.profileEmail.href = `mailto:${e.email}`;
    els.profileEmail.innerHTML = `${ICONS.mail}<span>${escapeHtml(e.email)}</span>`;
  } else {
    els.profileEmail.hidden = true;
    els.profileEmail.removeAttribute('href');
    els.profileEmail.innerHTML = '';
  }

  els.profileSections.innerHTML = '';
  const eduSection = buildSection('formation', 'Formation', 'Học vấn', e.education);
  if (eduSection) els.profileSections.appendChild(eduSection);
  if (hasMeaningfulText(e.work)) {
    const workSection = buildSection('profession', 'Profession', 'Công việc', e.work);
    if (workSection) els.profileSections.appendChild(workSection);
  }

  const ts = formatDate(e.timestamp);
  els.profileUpdated.textContent = ts ? `Cập nhật lần cuối: ${ts}` : '';
  els.profileUpdated.hidden = !ts;

  const total = currentSubset.length;
  els.profileNav.hidden = total <= 1;
  if (total > 1) {
    els.profilePos.textContent = `${idx + 1} / ${total}`;
    els.profilePrevBtn.disabled = idx <= 0;
    els.profileNextBtn.disabled = idx >= total - 1;
  }

  if (els.profileContent) els.profileContent.scrollTop = 0;
  els.profileModal.scrollTop = 0;
}

function openProfileModal(idx, opener = null) {
  if (idx < 0 || idx >= currentSubset.length) return;
  profileOpener = opener;
  if (els.indexDrawer.classList.contains('active')) closeDrawer();
  if (els.helpModal.classList.contains('active')) closeHelp(false);
  renderProfileModal(idx);
  els.profileModal.classList.add('active');
  els.profileBackdrop.classList.add('active');
  els.profileModal.setAttribute('aria-hidden', 'false');
  syncBodyLock();
  els.profileModal.focus({ preventScroll: true });
}

function closeProfileModal(restoreFocus = true) {
  els.profileModal.classList.remove('active');
  els.profileBackdrop.classList.remove('active');
  els.profileModal.setAttribute('aria-hidden', 'true');
  syncBodyLock();
  if (restoreFocus) {
    const currentCardTrigger = els.entries.querySelector(
      `.fullname-trigger[data-profile-index="${currentProfileIndex}"]`
    );
    const focusTarget = (profileOpener && profileOpener.isConnected &&
      Number(profileOpener.dataset.profileIndex) === currentProfileIndex)
      ? profileOpener
      : currentCardTrigger;
    if (focusTarget) focusTarget.focus({ preventScroll: true });
  }
}

function stepProfile(delta) {
  const nextIdx = currentProfileIndex + delta;
  if (nextIdx >= 0 && nextIdx < currentSubset.length) {
    renderProfileModal(nextIdx);
  }
}

// ---------- Roster drawer ----------
function openDrawer() {
  if (els.helpModal.classList.contains('active')) closeHelp(false);
  if (els.profileModal.classList.contains('active')) closeProfileModal(false);
  els.indexDrawer.classList.add('active');
  els.drawerBackdrop.classList.add('active');
  els.indexDrawer.setAttribute('aria-hidden', 'false');
  syncBodyLock();
  setTimeout(() => els.drawerSearch.focus({ preventScroll: true }), 50);
}

function closeDrawer() {
  els.indexDrawer.classList.remove('active');
  els.drawerBackdrop.classList.remove('active');
  els.indexDrawer.setAttribute('aria-hidden', 'true');
  syncBodyLock();
}

// ---------- Help modal ----------
function openHelp() {
  if (els.indexDrawer.classList.contains('active')) closeDrawer();
  if (els.profileModal.classList.contains('active')) closeProfileModal(false);
  els.helpModal.classList.add('active');
  els.helpBackdrop.classList.add('active');
  els.helpModal.setAttribute('aria-hidden', 'false');
  els.openHelpBtn.classList.add('active');
  els.openHelpBtn.setAttribute('aria-expanded', 'true');
  syncBodyLock();
  els.helpModal.focus({ preventScroll: true });
}

function closeHelp(restoreFocus = true) {
  els.helpModal.classList.remove('active');
  els.helpBackdrop.classList.remove('active');
  els.helpModal.setAttribute('aria-hidden', 'true');
  els.openHelpBtn.classList.remove('active');
  els.openHelpBtn.setAttribute('aria-expanded', 'false');
  syncBodyLock();
  if (restoreFocus) els.openHelpBtn.focus({ preventScroll: true });
}

function filterDrawerRows(raw) {
  const val = foldSearch(raw.trim());
  els.indexBody.querySelectorAll('tr').forEach(r => {
    r.style.display = !val || foldSearch(r.textContent).includes(val) ? '' : 'none';
  });
}

els.entries.addEventListener('click', evt => {
  const trigger = evt.target.closest('[data-profile-index]');
  if (!trigger) return;
  const idx = Number(trigger.dataset.profileIndex);
  if (Number.isInteger(idx)) openProfileModal(idx, trigger);
});

els.closeProfileBtn.addEventListener('click', () => closeProfileModal(true));
els.profileBackdrop.addEventListener('click', () => closeProfileModal(true));
els.profilePrevBtn.addEventListener('click', () => stepProfile(-1));
els.profileNextBtn.addEventListener('click', () => stepProfile(1));

els.openDrawerBtn.addEventListener('click', openDrawer);
els.closeDrawerBtn.addEventListener('click', closeDrawer);
els.drawerBackdrop.addEventListener('click', closeDrawer);

els.openHelpBtn.addEventListener('click', openHelp);
els.closeHelpBtn.addEventListener('click', () => closeHelp(true));
els.helpBackdrop.addEventListener('click', () => closeHelp(true));

document.addEventListener('keydown', evt => {
  if (evt.key === 'Escape') {
    if (els.contribModal.classList.contains('active')) closeContribute();
    else if (els.profileModal.classList.contains('active')) closeProfileModal(true);
    else if (els.helpModal.classList.contains('active')) closeHelp(true);
    else if (els.indexDrawer.classList.contains('active')) closeDrawer();
  } else if (els.profileModal.classList.contains('active')) {
    if (evt.key === 'ArrowLeft' && currentProfileIndex > 0) {
      evt.preventDefault();
      stepProfile(-1);
    } else if (evt.key === 'ArrowRight' && currentProfileIndex < currentSubset.length - 1) {
      evt.preventDefault();
      stepProfile(1);
    }
  }
});

els.indexBody.addEventListener('click', evt => {
  if (evt.target.closest('.drawer-jump-link')) closeDrawer();
});

els.drawerSearch.addEventListener('input', e => filterDrawerRows(e.target.value));

// ---------- Controls ----------
els.yearFilter.addEventListener('change', rerender);
els.searchInput.addEventListener('input', rerender);

els.toggleEmail.addEventListener('click', () => {
  const on = document.body.classList.toggle('show-emails');
  els.toggleEmail.classList.toggle('active', on);
  els.toggleEmail.setAttribute('aria-pressed', String(on));
  els.toggleEmail.querySelector('.email-toggle-text').textContent =
    on ? 'Ẩn email' : 'Hiện email';
});

function init() {
  const data = window.__ALUMNI_DATA__;
  if (!Array.isArray(data)) {
    els.loader.textContent = 'Không tải được dữ liệu, vui lòng thử lại sau.';
    return;
  }

  allEntries = dedupByEmail(data).map(e => ({
    ...e,
    fullname: titleCase(e.fullname || ''),
  }));

  renderYearFilter(uniqueSortedYears(allEntries));
  els.yearFilter.value = 'all';
  rerender();
  els.loader.style.display = 'none';

  if (window.location.hash) {
    const target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
    if (target) target.scrollIntoView({ behavior: 'instant' });
  }
}

// ---------- Contribute modal (OTP flow) ----------
const MAX_UPLOAD = 5 * 1024 * 1024;
const cf = {
  error: document.getElementById('contribute-error'),
  email: document.getElementById('step-email'),
  otp: document.getElementById('step-otp'),
  profile: document.getElementById('step-profile'),
  captchaQ: document.getElementById('cf-captcha-q'),
  otpEmail: document.getElementById('otp-email-label'),
  note: document.getElementById('profile-note'),
  preview: document.getElementById('cf-photo-preview'),
};
let cfEmail = '';
let cfOpener = null;

// Downscale + re-encode as JPEG (EXIF orientation applied by createImageBitmap).
// Any failure resolves the original file; the server re-validates everything.
async function resizeImageClientSide(file, maxEdge = 1600, quality = 0.9) {
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, maxEdge / Math.max(bmp.width, bmp.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff'; // JPEG has no alpha
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
    bmp.close();
    const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', quality));
    return blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
  } catch (_) {
    return file;
  }
}

function showError(msg) {
  cf.error.textContent = msg || '';
  cf.error.hidden = !msg;
}

function showStep(form) {
  for (const f of [cf.email, cf.otp, cf.profile]) f.hidden = f !== form;
  showError('');
  const first = form.querySelector('input:not([tabindex="-1"]), textarea');
  if (first) first.focus({ preventScroll: true });
}

function setBusy(form, busy) {
  form.querySelectorAll('button[type="submit"], .link-btn').forEach(b => { b.disabled = busy; });
}

// Always resolves {status, ok, ...json}; network/parse failures become a Vietnamese error.
async function callApi(url, init) {
  try {
    const res = await fetch(url, init);
    const json = await res.json();
    return { ...json, status: res.status, ok: res.ok && json.ok === true };
  } catch (_) {
    return { ok: false, status: 0, error: 'Không kết nối được máy chủ, vui lòng thử lại.' };
  }
}

function postJson(url, payload) {
  return callApi(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
}

async function loadCaptcha() {
  cf.captchaQ.textContent = 'Đang tải…';
  cf.email.captcha.value = '';
  const r = await callApi('api/request_otp.php');
  if (r.ok) {
    cf.captchaQ.textContent = r.question;
  } else {
    cf.captchaQ.textContent = '';
    showError(r.error);
  }
}

function openContribute(evt) {
  cfOpener = evt.currentTarget;
  if (els.indexDrawer.classList.contains('active')) closeDrawer();
  if (els.helpModal.classList.contains('active')) closeHelp(false);
  if (els.profileModal.classList.contains('active')) closeProfileModal(false);
  els.contribModal.classList.add('active');
  els.contribBackdrop.classList.add('active');
  els.contribModal.setAttribute('aria-hidden', 'false');
  syncBodyLock();
  cf.email.reset();
  cf.profile.reset();
  showStep(cf.email);
  loadCaptcha();
}

function closeContribute() {
  els.contribModal.classList.remove('active');
  els.contribBackdrop.classList.remove('active');
  els.contribModal.setAttribute('aria-hidden', 'true');
  syncBodyLock();
  if (cfOpener && cfOpener.isConnected) cfOpener.focus({ preventScroll: true });
}

// Back to step 1 with a fresh captcha (every step-1 POST consumes the previous one).
function backToStart(msg) {
  showStep(cf.email);
  showError(msg);
  loadCaptcha();
}

async function submitEmail(evt) {
  evt.preventDefault();
  const form = cf.email;
  setBusy(form, true);
  const email = form.email.value.trim();
  const r = await postJson('api/request_otp.php', {
    email, captcha: form.captcha.value.trim(), website_url: form.website_url.value,
  });
  setBusy(form, false);
  if (!r.ok) {
    showError(r.error);
    loadCaptcha();
    return;
  }
  cfEmail = email;
  cf.otpEmail.textContent = email;
  cf.otp.reset();
  showStep(cf.otp);
}

async function submitOtp(evt) {
  evt.preventDefault();
  setBusy(cf.otp, true);
  const r = await postJson('api/verify_otp.php', { email: cfEmail, otp: cf.otp.otp.value.trim() });
  setBusy(cf.otp, false);
  if (!r.ok) { showError(r.error); return; }

  const p = r.existing_profile;
  cf.profile.reset();
  if (p) {
    cf.profile.fullname.value = p.fullname || '';
    cf.profile.graduation_year.value = p.year || '';
    cf.profile.education.value = p.education || '';
    cf.profile.work.value = p.work || '';
  }
  cf.preview.src = p && p.photo ? p.photo : DEFAULT_IMG;
  cf.preview.hidden = !(p && p.photo);
  cf.note.textContent = p
    ? 'Đã xác thực. Hồ sơ hiện tại của bạn được điền sẵn bên dưới, chỉnh sửa rồi lưu.'
    : 'Đã xác thực. Hãy điền hồ sơ mới của bạn.';
  showStep(cf.profile);
}

async function submitProfile(evt) {
  evt.preventDefault();
  const form = cf.profile;
  setBusy(form, true);
  try {
    const fd = new FormData(form);
    const file = form.photo.files[0];
    fd.delete('photo');
    if (file) {
      const out = await resizeImageClientSide(file);
      if (out.size > MAX_UPLOAD) {
        showError('Ảnh vẫn lớn hơn 5 MB sau khi thu nhỏ, vui lòng chọn ảnh khác.');
        return;
      }
      fd.append('photo', out, out.name);
    }
    const r = await callApi('api/submit_profile.php', { method: 'POST', body: fd });
    if (r.ok) {
      location.href = `?updated=${Date.now()}#${makeAnchor(`${r.year}-${r.fullname}`)}`;
      return;
    }
    if (r.status === 401) backToStart(r.error);
    else showError(r.error);
  } finally {
    setBusy(form, false);
  }
}

cf.email.addEventListener('submit', submitEmail);
cf.otp.addEventListener('submit', submitOtp);
cf.profile.addEventListener('submit', submitProfile);
document.getElementById('otp-change').addEventListener('click', () => backToStart(''));
document.getElementById('otp-resend').addEventListener('click', () => backToStart('Hãy nhập lại phép tính để nhận mã mới.'));
els.openContribBtn.addEventListener('click', openContribute);
document.querySelectorAll('[data-open-contribute]').forEach(b => b.addEventListener('click', openContribute));
els.closeContribBtn.addEventListener('click', closeContribute);
els.contribBackdrop.addEventListener('click', closeContribute);

init();
