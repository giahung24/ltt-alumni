# PHP/MySQL Yearbook (`12p-ltt.page.gd`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the self-hosted PHP/MySQL edition of the THPT Chuyên Lý Tự Trọng French-class alumni yearbook for InfinityFree (`12p-ltt.page.gd`) with inline cached data, OTP-verified profile creation/editing, client+server portrait processing, and a hardened admin panel.

**Architecture:** `index.php` reads deduplicated alumni entries from an atomic PHP array cache (`storage/cache/alumni_latest.php`, backed by MySQL `alumni_entries`) and embeds `window.__ALUMNI_DATA__` directly into HTML, so normal page loads open zero MySQL connections, zero PHP sessions, and zero extra JSON requests. Profile submissions go through three session-backed endpoints (`api/request_otp.php`, `api/verify_otp.php`, `api/submit_profile.php`) with an arithmetic anti-bot challenge, PHPMailer SMTP (`587` STARTTLS), and GD JPEG normalization. `admin.php` provides brute-force-throttled moderation, cache invalidation, and JSON backups that `scripts/build_seed.py` can restore.

**Tech Stack:** PHP 8.x (PDO MySQL `utf8mb4`, GD, standalone PHPMailer), MySQL/MariaDB (`InnoDB`), vanilla ES6 JavaScript, CSS3, Python 3.12 (`scripts/build_seed.py`).

**Spec:** `docs/superpowers/specs/2026-11-08-php-mysql-yearbook-design.md`

## Verification Strategy (no ceremony)

- **No grep-the-source tests.** Checks only exist where they exercise real logic:
  - `tests/test_build_seed.py` (Python `unittest`) — seed/restore generation.
  - `tests/check_repository.php` — dedup, cache hit/miss/corruption, atomic write (PDO SQLite in memory).
  - `tests/check_image.php` — 25 MP guard, transparent → white, resize bounds.
- **Every PHP file** is linted with `php -l` before it is committed.
- **Everything MySQL/SMTP/session-specific** (OTP flow, rate limits, admin lockout) is verified on InfinityFree with the smoke checklist in Task 7 — it cannot be reproduced faithfully locally.
- **Local PHP:** static PHP 8.3.32 CLI (gd, pdo_sqlite, pdo_mysql, exif, mbstring, fileinfo, openssl) is installed in WSL Ubuntu at `~/.local/bin/php`. Run every PHP command from the repo root in Windows as:
  `wsl -e bash -lc "<command>"` (WSL inherits the repo directory as `/mnt/c/Users/giahung.nguyen/projects/ltt-alumni`).
  Lint helper (covers untracked files too): `wsl -e bash -lc 'find . -name "*.php" -not -path "./.git/*" -exec php -l {} \; | grep -v "^No syntax errors"; true'`

## Global Constraints

- **InfinityFree limits:** 5 GB disk, 80,000 inodes (no Composer `vendor/`; only standalone `PHPMailer.php`, `SMTP.php`, `Exception.php` + `LICENSE`), 50,000 daily hits, 10 MB max file size.
- **Zero MySQL & zero session on cached page views:** `index.php` never calls `session_start()` and never opens PDO when `storage/cache/alumni_latest.php` is valid.
- **Timezone:** `date_default_timezone_set('Asia/Ho_Chi_Minh')`; every PDO connection runs `SET time_zone = '+07:00'`; all OTP/rate-limit windows use MySQL `NOW() ± INTERVAL ...`.
- **Single JSON data contract (spec §5.2):** `{ "timestamp": "YYYY-MM-DD HH:MM:SS", "fullname", "year": int, "education", "work", "email", "photo": "static/img/<file>" }` everywhere (cache, `window.__ALUMNI_DATA__`, `existing_profile`, backups — backups add `"id"` and `"is_deleted"`).
- **Photo path:** `'static/img/' . ($photo_filename ?: 'default.jpg')`, no `file_exists()` per row; `buildPhoto()` uses `e.photo` with one `onerror` fallback to `static/img/default.jpg`.
- **Image safety:** reject > 5 MB, non-`image/{jpeg,png,webp}`, or `width * height > 25_000_000` before any `imagecreatefrom*`; composite onto white `#ffffff`; resize to ≤ 800×1000 (never upscale); JPEG quality 85; name `u_<time>_<random8>.jpg`.
- **Output escaping:** every user-supplied value rendered by PHP (`admin.php`, emails) goes through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`; `index.php` embeds JSON with `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP`.
- **Sessions:** one helper `start_session(string $name)` — cookie `Secure` (always; site is HTTPS-only and browsers treat `localhost` as secure), `HttpOnly`, `SameSite=Strict`. Public flow uses name `ltt_pub`, admin uses `ltt_admin`.
- **API errors:** JSON `{ "ok": false, "error": "<Vietnamese message>" }` with status `400` (invalid input), `401` (not verified/expired), `429` (rate limited), `503` (mail/DB failure).
- **No raw dataset on server:** `data/alumni.json`, `seed.sql`, `sql/`, `scripts/`, `tests/`, `docs/` are never uploaded. `includes/config.php`, `seed.sql`, `storage/cache/*.php` are gitignored.
- **Deploy manifest (only these go to `htdocs/`):** `.htaccess`, `index.php`, `admin.php`, `api/`, `includes/` (incl. server-side `config.php`), `storage/cache/` (empty, writable), `assets/`, `static/img/`.
- **SMTP:** port `587` + `STARTTLS` only, `Timeout = 15`.

## Review Focus

- **Restore from backup JSON in `scripts/build_seed.py`:** preserves explicit `id`, `is_deleted`, and resolved `photo`, while still handling legacy `picture_link` entries; MySQL string escaping covers both `'` and `\`. (Task 1)
- **Missing or corrupted cache in `get_public_entries()`:** non-array cache → query DB, atomic rewrite (temp + `rename()` + `opcache_invalidate()`); valid cache → PDO factory never called. (Task 2)
- **Decompression bomb & transparency in `includes/image.php`:** a 6000×5000 header is rejected before `imagecreatefrom*`; transparent pixels become white. (Task 3)
- **OTP enumeration & replay:** `request_otp.php` returns the same payload whether or not the email exists (and silently fakes success on honeypot); captcha answer is unset on every POST; `verify_otp.php` sets `consumed_at` on success or on the 5th failure. (Task 4)
- **Admin lockout alert dedup:** lock checks run *before* `password_verify()` and locked attempts are *not* recorded, so the alert fires exactly when the count reaches 5 (IP) or 20 (global), not on every later attempt. (Task 5)
- **opcache staleness:** after a submit, the redirected page must show the new data immediately (relies on `opcache_invalidate()` in `rebuild_cache()`). (Task 2, verified in Task 7)

---

### Task 0: InfinityFree Spike (gate — do this before writing anything else)

**Files:**
- Create: `scripts/spike_check.php`
- Create: `includes/config.example.php`
- Modify: `.gitignore`

- [ ] **Step 1: Write `includes/config.example.php`**

Returns an array: `db_host` (`sqlXXX.infinityfree.com`), `db_name`, `db_user`, `db_pass`, `smtp_host` (`smtp.gmail.com`), `smtp_port` = `587`, `smtp_user`, `smtp_pass` (Gmail App Password), `smtp_from`, `smtp_from_name`, `admin_email`, `admin_password_hash` (comment: generate with `php -r "echo password_hash('…', PASSWORD_DEFAULT);"`), `otp_daily_cap` = `100`, `site_url` = `https://12p-ltt.page.gd`.

- [ ] **Step 2: Write `scripts/spike_check.php`** (throwaway diagnostic, ~40 lines)

Loads `includes/config.php`, prints: PHP version, `memory_limit`, `upload_max_filesize`, `post_max_size`, `gd_info()` (JPEG/PNG/WebP), `extension_loaded('exif')`, `opcache` status (`opcache_get_status(false)['opcache_enabled']` and `ini_get('opcache.revalidate_freq')`), `$_SERVER['REMOTE_ADDR']`, `$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-'`, `$_SERVER['HTTPS'] ?? '-'`, MySQL `SELECT VERSION(), NOW()` after `SET time_zone = '+07:00'`. With `?mail=1`, sends one test email to `admin_email` through PHPMailer 587 STARTTLS (PHPMailer files from Task 3 Step 1 — download them now). No auth gate: the file is deleted right after use.

- [ ] **Step 3: Append to `.gitignore`:** `includes/config.php`, `seed.sql`, `storage/cache/*.php`.

- [ ] **Step 4: Run the spike on InfinityFree (manual)**

1. Install the free SSL certificate for `12p-ltt.page.gd` in the control panel; create the MySQL database.
2. Upload `includes/config.php` (filled), `includes/PHPMailer/`, and `scripts/spike_check.php` → `htdocs/spike_check.php`.
3. Open `https://12p-ltt.page.gd/spike_check.php` from two networks (Wi-Fi + mobile data), then `?mail=1`.
4. Paste `https://12p-ltt.page.gd/` into the Facebook Sharing Debugger.
5. **Delete `htdocs/spike_check.php`.**

Gate (spec §6): mail arrives; `REMOTE_ADDR` differs between the two networks (else drop the per-IP OTP limit and rely on email + global limits — note it in the plan before continuing); GD has JPEG+PNG (drop `image/webp` from the whitelist if WebP is missing); `memory_limit` ≥ 128M (else lower the megapixel cap to `memory_limit_bytes / 5`); record MySQL version and opcache `revalidate_freq`.

**Spike results (2026-11-09, host `12p-ltt.page.gd`):** PHP 8.4.27; `memory_limit` 512M; `upload_max_filesize` 20M / `post_max_size` 30M; GD JPEG/PNG/WebP + exif available; opcache on (`validate_timestamps=1`, `revalidate_freq=2`); `REMOTE_ADDR` is the real client IP (differs per network, equals `X-Forwarded-For`) → per-IP limits stay; HTTPS on; MariaDB 11.4.13, `NOW()` correct at `+07:00`; Facebook crawler passes the browser challenge (HTTP 206, preview rendered) → add Open Graph tags in Task 6. SMTP test mail via Gmail 587 STARTTLS: delivered. **Gate passed.**

- [ ] **Step 5: Lint and commit**

```bash
wsl -e bash -lc "php -l scripts/spike_check.php && php -l includes/config.example.php"
rtk git add scripts/spike_check.php includes/config.example.php .gitignore
rtk git commit -m "chore: add InfinityFree spike probe and config template"
```

---

### Task 1: Schema & Seed Generator (`sql/schema.sql`, `scripts/build_seed.py`)

**Files:**
- Create: `sql/schema.sql`
- Create: `scripts/build_seed.py`
- Create: `tests/test_build_seed.py`

**Interfaces:**
- `sql/schema.sql`: exact `CREATE TABLE IF NOT EXISTS` for `alumni_entries`, `email_otps`, `admin_login_attempts` from spec §5.2.
- `scripts/build_seed.py` (stdlib only — do **not** import `export_sheet.py`, it pulls Google deps):
  - `resolve_photo_filename(entry: dict, img_dir: Path) -> str | None` — if `entry["photo"]` exists: basename, or `None` when it is `default.jpg`; else extract the Drive ID from `picture_link` (`…?id=<ID>`) and return the first existing `<ID>.<ext>` for `("jpg", "jpeg", "png", "JPG", "webp")`; else `None`.
  - `sql_str(value) -> str` — `NULL` for `None`, otherwise `'…'` with `\` → `\\` then `'` → `''`.
  - `build_seed_sql(payload: dict, img_dir: Path) -> str` — header `SET NAMES utf8mb4;\nSET time_zone = '+07:00';`. If every entry has `id` (backup restore): sort by `id`, columns `(`id`, `email`, `fullname`, `graduation_year`, `education`, `work`, `photo_filename`, `submitted_at`, `is_deleted`)`. Otherwise (legacy): sort by `timestamp` ascending, same columns without `id` (and `is_deleted` = 0). `email = email.strip().lower()`. One `INSERT … VALUES (…);` per row.
  - CLI: `python scripts/build_seed.py [input_json=data/alumni.json] [output_sql=seed.sql]` → prints `Wrote N entries to <output>`.

- [ ] **Step 1: Implement `sql/schema.sql` and `scripts/build_seed.py`**

- [ ] **Step 2: Write `tests/test_build_seed.py`**

```python
import tempfile
import unittest
from pathlib import Path

from scripts.build_seed import build_seed_sql, resolve_photo_filename


class BuildSeedTests(unittest.TestCase):
    def test_resolve_photo_from_picture_link_and_backup_photo(self):
        with tempfile.TemporaryDirectory() as tmp:
            img_dir = Path(tmp)
            (img_dir / "1abcDEF.jpeg").write_bytes(b"jpg")
            legacy = {"picture_link": "https://drive.google.com/open?id=1abcDEF"}
            self.assertEqual(resolve_photo_filename(legacy, img_dir), "1abcDEF.jpeg")
            self.assertIsNone(resolve_photo_filename({"picture_link": "https://drive.google.com/open?id=missing"}, img_dir))
            self.assertEqual(resolve_photo_filename({"photo": "static/img/u_1700000000_deadbeef.jpg"}, img_dir),
                             "u_1700000000_deadbeef.jpg")
            self.assertIsNone(resolve_photo_filename({"photo": "static/img/default.jpg"}, img_dir))

    def test_legacy_sorted_by_timestamp_emails_normalized_and_escaped(self):
        payload = {"entries": [
            {"timestamp": "2020-05-01 12:00:00", "fullname": "Bui Van B", "year": 2015, "picture_link": "",
             "education": "2015-2019: L'Université d'Angers", "work": "C:\\temp", "email": "  BUI.B@Example.com "},
            {"timestamp": "2019-11-14 10:56:01", "fullname": "Nguyen Minh A", "year": 2010, "picture_link": "",
             "education": "2010-2014: Can Tho", "work": "Dev", "email": "NGUYEN.A@Example.com"},
        ]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertTrue(sql.startswith("SET NAMES utf8mb4;"))
        self.assertLess(sql.find("nguyen.a@example.com"), sql.find("bui.b@example.com"))
        self.assertIn("L''Université d''Angers", sql)
        self.assertIn("'C:\\\\temp'", sql)
        self.assertNotIn("`id`", sql)

    def test_backup_restore_preserves_id_and_is_deleted(self):
        payload = {"exported_at": "2026-11-08", "entries": [
            {"id": 42, "timestamp": "2026-11-08 09:00:00", "fullname": "Tran C", "year": 2018,
             "education": "2018-2022: Paris", "work": "Engineer", "email": "tran.c@example.com",
             "photo": "static/img/default.jpg", "is_deleted": 1},
        ]}
        with tempfile.TemporaryDirectory() as tmp:
            sql = build_seed_sql(payload, Path(tmp))
        self.assertIn("(`id`, `email`, `fullname`, `graduation_year`, `education`, `work`, `photo_filename`, `submitted_at`, `is_deleted`)", sql)
        self.assertIn("(42, 'tran.c@example.com'", sql)
        self.assertIn(", NULL, '2026-11-08 09:00:00', 1);", sql)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Run tests and a real dry run**

Run: `python -m unittest tests/test_build_seed.py -v && python scripts/build_seed.py`
Expected: 3 tests PASS; `Wrote 84 entries to seed.sql`; spot-check that `seed.sql` has 84 `INSERT` lines and that photo_filename is `NULL` only for entries whose image is genuinely missing in `static/img/`.

- [ ] **Step 4: Commit**

```bash
rtk git add sql/schema.sql scripts/build_seed.py tests/test_build_seed.py
rtk git commit -m "feat: add MySQL schema and seed/restore SQL generator"
```

---

### Task 2: Core Includes, Cache Repository & `.htaccess`

**Files:**
- Create: `.htaccess`
- Create: `storage/cache/.gitkeep`
- Create: `includes/db.php`
- Create: `includes/http.php`
- Create: `includes/repository.php`
- Create: `tests/check_repository.php`
- Create: `scripts/dev_cache.php`

**Interfaces:**
- `includes/db.php`: `get_config(): array` (requires `includes/config.php` once); `get_pdo(): PDO` (lazy static; sets PHP timezone, DSN `charset=utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, runs `SET time_zone = '+07:00'`).
- `includes/http.php`: `start_session(string $name): void` (cookie params per Global Constraints); `json_out(array $data, int $status = 200): never` (sets `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, echoes `json_encode(..., JSON_UNESCAPED_UNICODE)`, exits); `json_error(string $msg, int $status): never`; `client_ip(): string` (`$_SERVER['REMOTE_ADDR'] ?? ''`, never `X-Forwarded-For`); `read_input(): array` (JSON body or `$_POST`).
- `includes/repository.php`:
  - `const CACHE_FILE = __DIR__ . '/../storage/cache/alumni_latest.php';`
  - `format_entry_row(array $row): array` → contract (`timestamp` = `submitted_at`, `year` = `(int) graduation_year`, `photo` path rule).
  - `fetch_latest_rows(PDO $pdo): array` → `SELECT e.* FROM alumni_entries e JOIN (SELECT MAX(id) AS id FROM alumni_entries WHERE is_deleted = 0 GROUP BY email) m USING (id) ORDER BY e.id DESC`, mapped through `format_entry_row`.
  - `rebuild_cache(PDO $pdo, string $cacheFile = CACHE_FILE): array` → writes `"<?php return " . var_export($rows, true) . ";\n"` to `$cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp'`, `rename()` onto `$cacheFile`, then `if (function_exists('opcache_invalidate')) opcache_invalidate($cacheFile, true);` Returns rows.
  - `get_public_entries(?callable $pdoFactory = null, string $cacheFile = CACHE_FILE): array` → `$cached = is_file($cacheFile) ? (include $cacheFile) : null; if (is_array($cached)) return $cached;` else `rebuild_cache(($pdoFactory ?? 'get_pdo')(), $cacheFile)`.
  - `get_latest_row_by_email(PDO $pdo, string $email): ?array` → raw DB row (`MAX(id)` where `email = ? AND is_deleted = 0`), used by submit to inherit `photo_filename`; callers wrap with `format_entry_row()` for `existing_profile`.
  - `export_backup_payload(PDO $pdo): array` → `['exported_at' => date('Y-m-d'), 'entries' => [...]]`, all rows `ORDER BY id`, contract + `id` + `is_deleted` (no `submitter_ip`).
- `.htaccess`:
  ```apache
  AddDefaultCharset UTF-8
  Options -Indexes
  RewriteEngine On
  RewriteCond %{HTTP:X-Forwarded-Proto} !https
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  RewriteRule ^(includes|storage)/ - [F,L]
  <IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|JPG|png|webp|css|js)$">
      Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    <FilesMatch "\.php$">
      Header set Cache-Control "no-store"
    </FilesMatch>
  </IfModule>
  ```
- `scripts/dev_cache.php` (local only): creates an in-memory SQLite `alumni_entries` table (same column names, `id INTEGER PRIMARY KEY AUTOINCREMENT`), executes every statement of `seed.sql` except the `SET …` lines, then calls `rebuild_cache($pdo)` → real `storage/cache/alumni_latest.php` for local preview.

- [ ] **Step 1: Implement the files above.**

- [ ] **Step 2: Write `tests/check_repository.php`** — one assert-based script, PDO SQLite in memory, temp cache file:
  1. Insert: `a@x.com` twice (second row newer, `photo_filename = 'u_1.jpg'`); `b@x.com` active row then a newer row with `is_deleted = 1`; `c@x.com` with `photo_filename = NULL`.
  2. `rebuild_cache()` → 3 rows; `a@x.com` is the newer row with `photo = 'static/img/u_1.jpg'`; `b@x.com` is its older active row; `c@x.com` has `photo = 'static/img/default.jpg'`; `year` is `int`; file starts with `<?php return`; `include` returns the same array; no `*.tmp` left in the dir.
  3. `get_public_entries(fn() => throw new Exception('DB must not be touched'), $cacheFile)` returns the cached rows.
  4. Overwrite the cache with `<?php return 'garbage';` → `get_public_entries(fn() => $pdo, $cacheFile)` returns 3 rows and the file is valid again.
  5. Print `OK check_repository`.

- [ ] **Step 3: Run checks and lint**

Run: `wsl -e bash -lc "php tests/check_repository.php"` → `OK check_repository`
Run the lint helper → no output.

- [ ] **Step 4: Commit**

```bash
rtk git add .htaccess storage/cache/.gitkeep includes/db.php includes/http.php includes/repository.php tests/check_repository.php scripts/dev_cache.php
rtk git commit -m "feat: add PDO/session helpers, atomic cache repository, and .htaccess"
```

---

### Task 3: Image Processing & Mailer (`includes/image.php`, `includes/mailer.php`, `includes/PHPMailer/*`)

**Files:**
- Create: `includes/PHPMailer/{Exception,PHPMailer,SMTP}.php`, `includes/PHPMailer/LICENSE`
- Create: `includes/image.php`
- Create: `includes/mailer.php`
- Create: `tests/check_image.php`

**Interfaces:**
- PHPMailer: download `src/Exception.php`, `src/PHPMailer.php`, `src/SMTP.php` and `LICENSE` from the **latest release tag** of `github.com/PHPMailer/PHPMailer` (never hand-write); record the tag in `README.md`.
- `includes/image.php` — `process_uploaded_photo(array $file, string $targetDir): string`:
  - `$file['error'] === UPLOAD_ERR_OK` (map `UPLOAD_ERR_INI_SIZE`/`FORM_SIZE` to the "quá 5 MB" message) and `$file['size'] <= 5 * 1024 * 1024`.
  - `getimagesize($file['tmp_name'])`; MIME in `image/jpeg|png|webp`; reject `w <= 0 || h <= 0 || w * h > 25_000_000` **before** decoding.
  - Decode with the matching `imagecreatefrom*`; for JPEG, apply EXIF orientation (3/6/8) only if `function_exists('exif_read_data')`.
  - Fit within 800×1000 keeping ratio, never upscale; `imagecreatetruecolor` + `imagefilledrectangle` white, then `imagecopyresampled`; `imagejpeg(..., 85)` to `u_<time()>_<bin2hex(random_bytes(4))>.jpg`; return the filename.
  - Throws `InvalidArgumentException` (Vietnamese user message) on validation errors, `RuntimeException` on GD failure. Does not call `is_uploaded_file()` — the endpoint does.
- `includes/mailer.php`:
  - `send_mail(string $to, string $subject, string $html, string $text = ''): bool` — PHPMailer `isSMTP()`, `Port = 587`, `SMTPSecure = ENCRYPTION_STARTTLS`, `SMTPAuth`, `CharSet = 'UTF-8'`, `Timeout = 15`; catches exceptions → `error_log()` + `false`.
  - `send_otp_email(string $to, string $code): bool` — subject `[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn: {$code}`, short bilingual FR/VI body, states 10-minute validity.
  - `send_admin_notification(string $subject, string $html): bool` — to `admin_email`; never throws.

- [ ] **Step 1: Download PHPMailer, implement `image.php` and `mailer.php`.**

- [ ] **Step 2: Write `tests/check_image.php`** — assert-based, temp dir:
  1. Build a 1200×900 fully transparent PNG with GD (`imagesavealpha`, `imagealphablending(false)`, fill `imagecolorallocatealpha(..., 127)`), save to temp, call `process_uploaded_photo(['tmp_name' => $p, 'size' => filesize($p), 'error' => 0], $dir)` → output matches `/^u_\d+_[0-9a-f]{8}\.jpg$/`, is 800×600 JPEG, pixel (10,10) has R,G,B ≥ 250.
  2. A 300×200 JPEG is not upscaled (output 300×200).
  3. A hand-built PNG header (8-byte signature + IHDR chunk 6000×5000, correct `crc32`) with no image data → throws `InvalidArgumentException`, and the 25 MP check runs before decoding (no GD warning emitted).
  4. A text file renamed `.jpg` → `InvalidArgumentException`.
  5. Print `OK check_image`.

- [ ] **Step 3: Run checks and lint**

Run: `wsl -e bash -lc "php tests/check_image.php"` → `OK check_image`
Run the lint helper → no output.

- [ ] **Step 4: Commit**

```bash
rtk git add includes/PHPMailer includes/image.php includes/mailer.php tests/check_image.php
rtk git commit -m "feat: add GD portrait processor and PHPMailer SMTP wrapper"
```

---

### Task 4: OTP & Submission Endpoints (`api/*.php`)

**Files:**
- Create: `api/request_otp.php`, `api/verify_otp.php`, `api/submit_profile.php`

**Behaviour** (all use `start_session('ltt_pub')`, `json_out`/`json_error`, `client_ip()`):

- `GET api/request_otp.php`: random `a + b` or `a - b` (positive result, operands 1–20), store `$_SESSION['captcha_answer']`, return `{"ok": true, "question": "7 + 5 = ?"}`.
- `POST api/request_otp.php` `{email, captcha, website_url}`:
  1. Read then **always** `unset($_SESSION['captcha_answer'])`.
  2. Honeypot non-empty → return the normal success payload without inserting or mailing.
  3. Wrong/missing captcha → `400`; invalid email (`FILTER_VALIDATE_EMAIL` after `strtolower(trim())`) → `400`.
  4. `DELETE FROM email_otps WHERE created_at < NOW() - INTERVAL 1 DAY`.
  5. Limits → `429`: email ≥ 3 in `INTERVAL 10 MINUTE`; IP ≥ 10 in `INTERVAL 15 MINUTE` (skip if Task 0 showed `REMOTE_ADDR` is not the real IP); table ≥ `otp_daily_cap` in `INTERVAL 1 DAY`.
  6. `random_int(100000, 999999)`, insert `password_hash()`, `expires_at = NOW() + INTERVAL 10 MINUTE`; `send_otp_email()` false → `503` (row kept so it still counts toward limits).
  7. Success payload identical for known and unknown emails: `{"ok": true, "message": "Mã xác thực đã được gửi tới email của bạn."}`.
- `POST api/verify_otp.php` `{email, otp}`: latest row for normalized email with `consumed_at IS NULL AND expires_at > NOW()`; none → `400` ("Mã đã hết hạn, vui lòng lấy mã mới"). Wrong code → `attempts = attempts + 1`, and `consumed_at = NOW()` when the new value reaches 5 → `400`. Correct → `consumed_at = NOW()`, `session_regenerate_id(true)`, `$_SESSION['verified_email']`, `$_SESSION['verified_until'] = time() + 1800`, return `{"ok": true, "verified": true, "existing_profile": format_entry_row(row) | null}`.
- `POST api/submit_profile.php` (multipart `fullname`, `graduation_year`, `education`, `work`, optional `photo`):
  1. Email only from session; missing/expired → `401`.
  2. `fullname = mb_convert_case(preg_replace('/\s+/u', ' ', trim(...)), MB_CASE_TITLE, 'UTF-8')`, 2–120 chars (`mb_strlen`); `graduation_year` int in `[1990, date('Y') + 3]`; `education` required ≤ 3000; `work` ≤ 3000; normalize `\r\n` → `\n`. Failures → `400` with the field's message.
  3. Photo present (`error !== UPLOAD_ERR_NO_FILE`): require `is_uploaded_file()`, then `process_uploaded_photo($_FILES['photo'], __DIR__ . '/../static/img')`; else inherit `photo_filename` from `get_latest_row_by_email()` (or `NULL`).
  4. `INSERT` (email, fullname, graduation_year, education, work, photo_filename, submitter_ip); unset `verified_email`/`verified_until`; `rebuild_cache($pdo)`.
  5. `send_admin_notification()` — subject "Hồ sơ mới" or "Cập nhật hồ sơ" (based on whether a previous row existed), escaped fields, link to `site_url` + `#anchor` and `admin.php`; failure only logs.
  6. Return `{"ok": true, "fullname": $fullname, "year": $year}`.

- [ ] **Step 1: Implement the three endpoints.**
- [ ] **Step 2: Lint** (lint helper → no output). Behaviour is verified on the host in Task 7.
- [ ] **Step 3: Commit**

```bash
rtk git add api/request_otp.php api/verify_otp.php api/submit_profile.php
rtk git commit -m "feat: add OTP verification and profile submission endpoints"
```

---

### Task 5: Admin Panel (`admin.php`)

**Files:**
- Create: `admin.php`

**Behaviour** (`start_session('ltt_admin')`):

- **Login** (order matters):
  1. Prune `admin_login_attempts` older than `INTERVAL 30 DAY`.
  2. If `success = 0` count in last `INTERVAL 1 HOUR` ≥ 20 → show "Tạm khóa đăng nhập" and stop. If count for `client_ip()` in last `INTERVAL 15 MINUTE` ≥ 5 → same. **Locked attempts are neither verified nor recorded.** (Windows are rolling: a lock lifts when the oldest failure in the window ages out.)
  3. `password_verify()` fails → insert `success = 0`; recount; if IP count `=== 5` or global count `=== 20` → one `send_admin_notification()` (IP, count, approximate unlock time); then `sleep(min(8, 1 << max(0, $ipCount - 1)))`.
  4. Success → insert `success = 1`, `session_regenerate_id(true)`, `$_SESSION['admin'] = true`, `$_SESSION['csrf'] = bin2hex(random_bytes(32))`, login-alert email (IP + time).
- **Actions** (POST + `hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')`): `toggle_delete` (`id`, `is_deleted ∈ {0,1}`) → update + `rebuild_cache`; `rebuild_cache`; `download_backup` → `Content-Disposition: attachment; filename="alumni-backup-YYYY-MM-DD.json"` with `export_backup_payload()` (pretty-printed, unescaped Unicode); `logout`. Redirect after POST (PRG).
- **History table**: all rows `ORDER BY id DESC` (id, submitted_at, email, fullname, year, photo thumb, IP, status), badge `Đang hiển thị` / `Bản cũ` / `Đã ẩn` (current = `MAX(id)` per email among active rows), toggle button per row. Every value through `htmlspecialchars`. Styled with `assets/css/style.css` tokens.

- [ ] **Step 1: Implement `admin.php`.**
- [ ] **Step 2: Lint** (lint helper → no output). Behaviour is verified on the host in Task 7.
- [ ] **Step 3: Commit**

```bash
rtk git add admin.php
rtk git commit -m "feat: add admin panel with login throttling, moderation, and JSON backup"
```

---

### Task 6: Frontend (`index.php`, `assets/js/app.js`, `assets/css/style.css`, `README.md`)

**Files:**
- Create: `index.php` (from `index.html`), Delete: `index.html`
- Modify: `assets/js/app.js`, `assets/css/style.css`, `README.md`

**Behaviour:**
- `index.php`: no `session_start()`. Top: `try { $entries = get_public_entries(); } catch (Throwable $e) { error_log(...); http_response_code(503); $entries = null; }`. Embed `<script>window.__ALUMNI_DATA__ = <?= json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>` before `text.js`. Asset URLs get `?v=<?= filemtime(__DIR__ . '/…') ?>`. Add Open Graph tags (`og:title`, `og:description`, `og:url` = `site_url`, `og:image` = absolute URL of an existing image, e.g. `static/img/default.jpg`, `og:type=website`) — the Facebook crawler reaches the site (spike result). The toolbar CTA and the help-modal paragraph open `#contribute-modal` instead of linking to Google Form. Add `#contribute-backdrop` + `#contribute-modal` (`role="dialog"`, `aria-modal`, labelled) with three panes: (1) email, captcha question, hidden honeypot `website_url`; (2) 6-digit OTP (`inputmode="numeric"`, `autocomplete="one-time-code"`), "Gửi lại mã" / "Đổi email"; (3) fullname, graduation_year, education, work, photo (`accept="image/jpeg,image/png,image/webp"`) with current portrait preview and note *"Giữ nguyên ảnh hiện tại nếu bạn không chọn ảnh mới"*.
- `app.js` (minimal diff, keep all existing rendering/filter code):
  - Remove `IMG_EXTS`, `getPictureId`, Drive URLs; `buildPhoto(src, alt)` sets `src || DEFAULT_IMG`, toggles `.is-default`, single `onerror` → `DEFAULT_IMG` (then `onerror = null`).
  - `init()` becomes synchronous: if `!Array.isArray(window.__ALUMNI_DATA__)` → loader text "Không tải được dữ liệu, vui lòng thử lại sau." and return; otherwise unchanged (`dedupByEmail`, `titleCase`, render, hash scroll).
  - `resizeImageClientSide(file, maxEdge = 1600, quality = 0.9)` → `createImageBitmap(file)` (respects EXIF orientation) → canvas → `toBlob('image/jpeg')`; on any error resolve the original file. Reject > 5 MB client-side after resizing.
  - Modal controller: open → `GET api/request_otp.php`; step 1 POST → step 2; step 2 POST → fill step 3 from `existing_profile`; step 3 `FormData` POST → `location.href = '?updated=' + Date.now() + '#' + makeAnchor(`${res.year}-${res.fullname}`)`. On any `{ok:false}` show `error` in the pane and, on step 1, refetch a new captcha. Esc/backdrop close; reuse `syncBodyLock()` (extend it to include the new modal).
- `style.css`: append modal/form styles using existing tokens (`--color-primary`, `--font-serif`, `--font-sans`); no new colors.
- `README.md`: rewrite for the PHP edition — deploy manifest, config, seed via phpMyAdmin, backup/restore, local preview, PHPMailer tag.

- [ ] **Step 1: Implement the four files and delete `index.html`.**
- [ ] **Step 2: Local preview (no MySQL needed)**

```bash
python scripts/build_seed.py
wsl -e bash -lc "php scripts/dev_cache.php && php -S 0.0.0.0:8000"
```
Open `http://localhost:8000/` and check: 84 cards render with correct portraits and no 404s in DevTools Network; year filter, accent-insensitive search, drawer, email toggle, help modal unchanged; contribute modal opens, steps render, Esc closes it (API calls will fail locally — expected). Then lint helper → no output.

- [ ] **Step 3: Commit**

```bash
rtk git add index.php assets/js/app.js assets/css/style.css README.md
rtk git rm index.html
rtk git commit -m "feat: serve yearbook from index.php with inline data and OTP contribute modal"
```

---

### Task 7: Deploy, Smoke Test & Cutover (manual, spec §5.7)

- [ ] **Step 1: Database** — phpMyAdmin: run `sql/schema.sql`.
- [ ] **Step 2: Upload** the deploy manifest via FTP (Global Constraints); make sure `storage/cache/` is writable and `includes/config.php` is filled.
- [ ] **Step 3: Smoke test on `https://12p-ltt.page.gd`** (still before cutover, with a test seed or empty DB):
  - [ ] `http://` redirects to `https://`; `/includes/config.php` and `/storage/cache/alumni_latest.php` → 403.
  - [ ] New profile with an email you own: captcha wrong → error + new question; OTP mail arrives; wrong OTP ×5 → code dead; correct OTP → empty form; submit with a transparent PNG → page reloads to the new card **immediately** (opcache), portrait white-backed; admin got a "Hồ sơ mới" mail.
  - [ ] Update the same email: form pre-filled; submit without a photo → card keeps the old portrait; admin got "Cập nhật hồ sơ".
  - [ ] 4th OTP request for one email within 10 minutes → 429.
  - [ ] Admin: 5 wrong passwords → locked, exactly **one** lockout mail; correct password while locked → still refused; after the window → login works + login-alert mail; hide the latest row → previous version reappears on the homepage; download backup; `python scripts/build_seed.py <backup.json> restore.sql` produces matching rows.
  - [ ] Truncate the test rows (`TRUNCATE alumni_entries; TRUNCATE email_otps;`) and delete test images.
- [ ] **Step 4: Cutover**
  1. Close the Google Form (or set its confirmation message to the new URL).
  2. `python scripts/export_sheet.py` (final export) → `python scripts/build_seed.py` → run `seed.sql` once in phpMyAdmin.
  3. Upload any new `static/img/` files; in `admin.php` click "Làm mới cache"; verify 84 cards.
  4. Disable `.github/workflows/refresh-data.yml` (remove the `schedule` trigger) on `main`, switch the repo to Private, point the README/links to `https://12p-ltt.page.gd`.
  5. Take the first backup (admin JSON + FTP copy of `static/img/`).
