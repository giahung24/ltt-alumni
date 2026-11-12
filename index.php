<?php
// No session, no config: a warm cache means zero MySQL connections per page view.
require_once __DIR__ . '/includes/repository.php';

try {
    $entries = get_public_entries();
} catch (Throwable $e) {
    error_log('[index] ' . $e->getMessage());
    http_response_code(503);
    $entries = null;
}
?><!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kỷ yếu lớp Pháp — THPT Chuyên Lý Tự Trọng Cần Thơ</title>
  <meta name="description" content="Kỷ yếu cựu học sinh lớp song ngữ tiếng Pháp THPT Chuyên Lý Tự Trọng, Cần Thơ.">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Kỷ yếu lớp Pháp — THPT Chuyên Lý Tự Trọng Cần Thơ">
  <meta property="og:description" content="Kỷ yếu cựu học sinh lớp song ngữ tiếng Pháp THPT Chuyên Lý Tự Trọng, Cần Thơ.">
  <meta property="og:url" content="https://12p-ltt.page.gd/">
  <meta property="og:image" content="https://12p-ltt.page.gd/static/img/default.jpg">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2380182a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M22 10v6M2 10l10-5 10 5-10 5z'/%3E%3Cpath d='M6 12v5c3 3 9 3 12 0v-5'/%3E%3C/svg%3E">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600&family=Source+Serif+4:ital,opsz,wght@0,8..60,600;1,8..60,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
  <header class="site-header">
    <div class="top-bar">
      <div class="institution-tag">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        <span>THPT Chuyên Lý Tự Trọng · Cần Thơ</span>
      </div>
    </div>

    <div class="masthead">
      <h1 class="masthead-title">Kỷ yếu lớp Pháp</h1>
      <p class="masthead-subtitle">Cựu học sinh lớp Pháp ngữ, THPT Chuyên Lý Tự Trọng Cần Thơ</p>
      <figure class="masthead-epigraph">
        <blockquote class="masthead-quote">« Combien je suis chanceux d'avoir quelque chose qui me fait dire au revoir très difficilement. »</blockquote>
        <figcaption class="masthead-author">A.A. Milne</figcaption>
      </figure>
    </div>
  </header>

  <div class="sticky-filter-wrapper">
    <div class="filter-bar">
      <div class="select-wrapper">
        <select id="year-filter" class="styled-select" aria-label="Lọc theo năm tốt nghiệp"></select>
        <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
      </div>

      <div class="search-wrapper">
        <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="search-input" class="search-input" placeholder="Tìm theo tên, trường, công việc…" aria-label="Tìm kiếm cựu học sinh">
      </div>

      <span id="active-stats" class="active-stats" aria-live="polite">Đang tải…</span>

      <div class="filter-actions">
        <button id="toggle-email" class="btn-ghost" type="button" aria-pressed="false">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          <span class="email-toggle-text">Hiện email</span>
        </button>
        <button id="open-drawer-btn" class="btn-ghost" type="button" aria-controls="index-drawer">
          <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          <span>Danh sách</span>
        </button>
        <button id="open-help-btn" class="btn-circle-help" type="button" data-tooltip="Hướng dẫn" aria-label="Hướng dẫn" aria-haspopup="dialog" aria-controls="help-modal" aria-expanded="false">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.25 9a2.85 2.85 0 0 1 5.55.95c0 1.9-2.8 2.55-2.8 4.3"/><circle cx="12" cy="18" r="0.65" fill="currentColor"/></svg>
        </button>
        <button type="button" class="btn-contribute" id="open-contribute-btn" aria-label="Thêm hoặc cập nhật hồ sơ" aria-haspopup="dialog" aria-controls="contribute-modal">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
          <span class="btn-contribute-label">Thêm / cập nhật hồ sơ</span>
        </button>
      </div>
    </div>
  </div>

  <main class="main-content">
    <div id="loader" class="loader">Đang tải dữ liệu…</div>
    <div id="entries" class="entries-grid"></div>
  </main>

  <footer class="site-footer">
    <p>Kỷ yếu lớp Pháp · THPT Chuyên Lý Tự Trọng Cần Thơ</p>
  </footer>

  <div id="profile-backdrop" class="modal-backdrop"></div>
  <div id="profile-modal" class="help-modal profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-name" aria-hidden="true" tabindex="-1">
    <div class="profile-modal-layout">
      <div class="profile-modal-media">
        <div class="profile-modal-frame">
          <img id="profile-modal-photo" class="photo" src="static/img/default.jpg" alt="">
        </div>
      </div>
      <div class="profile-modal-content">
        <div class="profile-modal-header">
          <div class="profile-modal-identity">
            <h2 id="profile-modal-name" class="fullname profile-modal-title"></h2>
            <p id="profile-modal-promo" class="promo"></p>
            <a id="profile-modal-email" class="email" href="" hidden></a>
          </div>
          <button id="close-profile-btn" class="drawer-close-btn profile-close-btn" type="button" aria-label="Đóng hồ sơ">
            <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div id="profile-modal-sections" class="profile-modal-sections"></div>
        <div class="profile-modal-footer">
          <small id="profile-modal-updated" class="last-update"></small>
          <div id="profile-modal-nav" class="profile-modal-nav" role="group" aria-label="Điều hướng hồ sơ">
            <button id="profile-prev-btn" class="btn-ghost profile-nav-btn" type="button" aria-label="Hồ sơ trước">
              <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
              <span>Trước</span>
            </button>
            <span id="profile-modal-pos" class="profile-modal-pos" aria-live="polite"></span>
            <button id="profile-next-btn" class="btn-ghost profile-nav-btn" type="button" aria-label="Hồ sơ tiếp theo">
              <span>Tiếp</span>
              <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="help-backdrop" class="modal-backdrop"></div>
  <div id="help-modal" class="help-modal" role="dialog" aria-modal="true" aria-labelledby="guidance-heading" aria-hidden="true" tabindex="-1">
    <div class="modal-header">
      <h2 id="guidance-heading">Hướng dẫn &amp; liên hệ</h2>
      <button id="close-help-btn" class="drawer-close-btn" type="button" aria-label="Đóng hướng dẫn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="guide">
        <h3>Thêm hoặc cập nhật hồ sơ</h3>
        <p>Điền thông tin học tập và công việc của bạn bằng nút <button type="button" class="link-btn" data-open-contribute>Thêm / cập nhật hồ sơ</button>. Bạn sẽ nhận mã xác thực qua email.</p>
      </div>
      <div class="guide">
        <h3>Sửa thông tin đã có</h3>
        <p>Mở lại biểu mẫu trên và dùng đúng email đã dùng lần trước. Hệ thống chỉ hiển thị bản ghi sau cùng của mỗi email.</p>
      </div>
      <div class="guide">
        <h3>Góp ý</h3>
        <p>Mọi chỉnh sửa, góp ý vui lòng <a href="mailto:giahung24@gmail.com?subject=[LTT%20alumni%20fran%C3%A7ais]">gửi email cho mình</a>.</p>
      </div>
    </div>
  </div>

  <div id="contribute-backdrop" class="modal-backdrop"></div>
  <div id="contribute-modal" class="help-modal contribute-modal" role="dialog" aria-modal="true" aria-labelledby="contribute-heading" aria-hidden="true" tabindex="-1">
    <div class="modal-header">
      <h2 id="contribute-heading">Thêm / cập nhật hồ sơ</h2>
      <button id="close-contribute-btn" class="drawer-close-btn" type="button" aria-label="Đóng biểu mẫu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <p id="contribute-error" class="form-error" role="alert" aria-live="assertive" hidden></p>

      <form id="step-email" class="form-pane" novalidate>
        <p class="form-hint">Nhập email của bạn, chúng mình sẽ gửi mã xác thực 6 số. Dùng lại email cũ để sửa hồ sơ đã có.</p>
        <div class="form-field">
          <label for="cf-email">Email</label>
          <input type="email" id="cf-email" name="email" autocomplete="email" required maxlength="191">
        </div>
        <div class="form-field">
          <label for="cf-captcha">Phép tính xác minh (chống spam)</label>
          <p id="cf-captcha-q" class="captcha-q" aria-live="polite">Đang tải…</p>
          <input type="text" id="cf-captcha" name="captcha" inputmode="numeric" autocomplete="off" required aria-describedby="cf-captcha-q">
        </div>
        <div class="hp-field" aria-hidden="true">
          <label for="cf-website">Website</label>
          <input type="text" id="cf-website" name="website_url" tabindex="-1" autocomplete="off">
        </div>
        <button type="submit" class="btn-contribute btn-block">Gửi mã xác thực</button>
      </form>

      <form id="step-otp" class="form-pane" novalidate hidden>
        <p class="form-hint">Mã xác thực đã được gửi tới <strong id="otp-email-label"></strong>. Mã có hiệu lực trong 10 phút.</p>
        <div class="form-field">
          <label for="cf-otp">Mã xác thực (6 số)</label>
          <input type="text" id="cf-otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        </div>
        <button type="submit" class="btn-contribute btn-block">Xác thực</button>
        <div class="form-links">
          <button type="button" class="link-btn" id="otp-resend">Gửi lại mã</button>
          <button type="button" class="link-btn" id="otp-change">Đổi email</button>
        </div>
      </form>

      <form id="step-profile" class="form-pane" novalidate hidden>
        <p id="profile-note" class="form-hint"></p>
        <div class="form-field">
          <label for="cf-fullname">Họ và tên</label>
          <input type="text" id="cf-fullname" name="fullname" autocomplete="name" required minlength="2" maxlength="120">
        </div>
        <div class="form-field">
          <label for="cf-year">Năm tốt nghiệp</label>
          <input type="number" id="cf-year" name="graduation_year" inputmode="numeric" min="1990" required>
        </div>
        <div class="form-field">
          <label for="cf-education">Quá trình học tập</label>
          <textarea id="cf-education" name="education" rows="5" required maxlength="3000"></textarea>
        </div>
        <div class="form-field">
          <label for="cf-work">Công việc</label>
          <textarea id="cf-work" name="work" rows="4" maxlength="3000"></textarea>
        </div>
        <div class="form-field">
          <label for="cf-photo">Ảnh chân dung</label>
          <div class="photo-pick">
            <img id="cf-photo-preview" class="photo-preview" src="static/img/default.jpg" alt="Ảnh hiện tại" hidden>
            <input type="file" id="cf-photo" name="photo" accept="image/jpeg,image/png,image/webp" aria-describedby="cf-photo-note">
          </div>
          <p id="cf-photo-note" class="form-hint">Giữ nguyên ảnh hiện tại nếu bạn không chọn ảnh mới. JPG, PNG hoặc WebP, tối đa 5 MB.</p>
        </div>
        <button type="submit" class="btn-contribute btn-block">Lưu hồ sơ</button>
      </form>
    </div>
  </div>

  <div id="drawer-backdrop" class="drawer-backdrop"></div>
  <aside id="index-drawer" class="index-drawer" aria-label="Danh sách cựu học sinh" aria-hidden="true">
    <div class="drawer-header">
      <h2>Danh sách</h2>
      <button id="close-drawer-btn" class="drawer-close-btn" type="button" aria-label="Đóng danh sách">
        <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="drawer-search-box">
      <input type="search" id="drawer-search" placeholder="Lọc nhanh theo tên hoặc năm…" aria-label="Lọc nhanh trong danh sách">
    </div>
    <div class="drawer-body tableFixHead">
      <table>
        <thead><tr><th class="col-num">#</th><th class="col-year">Năm</th><th>Họ và tên</th></tr></thead>
        <tbody id="index-body"></tbody>
      </table>
    </div>
  </aside>

  <script>window.__ALUMNI_DATA__ = <?= json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script src="assets/js/text.js?v=<?= filemtime(__DIR__ . '/assets/js/text.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
