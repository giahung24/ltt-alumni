# PHP/MySQL Yearbook (`ltt-alumni.page.gd`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the self-hosted PHP/MySQL edition of the THPT Chuyên Lý Tự Trọng French-class alumni yearbook for InfinityFree (`ltt-alumni.page.gd`) with inline cached data, OTP-verified profile creation/editing, client+server portrait processing, and a hardened admin panel.

**Architecture:** `index.php` reads deduplicated alumni entries from an atomic PHP array cache (`storage/cache/alumni_latest.php`, backed by MySQL `alumni_entries`) and embeds `window.__ALUMNI_DATA__` directly into HTML so normal page loads open zero MySQL connections, zero PHP sessions, and zero extra JSON HTTP requests. Profile submissions go through three session-backed endpoints (`api/request_otp.php`, `api/verify_otp.php`, `api/submit_profile.php`) with arithmetic anti-bot challenges, PHPMailer SMTP (`587` STARTTLS), and GD JPEG normalization, while `admin.php` provides brute-force-throttled moderation, cache invalidation, and full JSON backups compatible with `scripts/build_seed.py`.

**Tech Stack:** PHP 8.x (PDO MySQL `utf8mb4`, GD, standalone PHPMailer), MySQL/MariaDB (`InnoDB`), Vanilla ES6 JavaScript (`assets/js/app.js`, `assets/js/text.js`), CSS3 (`assets/css/style.css`), Python 3.12 (`scripts/build_seed.py`), Node.js (`node --test`) & Python `unittest` for automated verification.

**Spec:** `docs/superpowers/specs/2026-11-08-php-mysql-yearbook-design.md`

## Global Constraints

- **Target Hosting Limits (InfinityFree):** 5 GB disk, 80,000 inodes (no Composer `vendor/`; bundle only standalone `PHPMailer.php`, `SMTP.php`, `Exception.php`), 50,000 daily hits, 10 MB max file size.
- **Zero MySQL & Zero Session on Cached Page Views:** `index.php` MUST NOT call `session_start()` or open a PDO connection when `storage/cache/alumni_latest.php` exists.
- **Timezone:** PHP sets `date_default_timezone_set('Asia/Ho_Chi_Minh')`; every PDO connection executes `SET time_zone = '+07:00'`; all OTP/rate-limit windows use MySQL `NOW() + INTERVAL ...` / `NOW() - INTERVAL ...`.
- **Single JSON Data Contract (§5.2):** Every entry in cache, `window.__ALUMNI_DATA__`, `existing_profile`, and backup exports uses `{ "timestamp": "YYYY-MM-DD HH:MM:SS", "fullname": "...", "year": 2010, "education": "...", "work": "...", "email": "...", "photo": "static/img/<file>.jpg" }` (plus `"id"` and `"is_deleted"` in admin backup exports).
- **Photo Path Resolution:** Server resolves `photo` to `'static/img/' . ($photo_filename ?: 'default.jpg')` without calling `file_exists()` per row at cache build time; browser `buildPhoto()` uses `e.photo` directly with a single `onerror` fallback to `static/img/default.jpg` (zero multi-extension 404 probing).
- **Image Safety:** Reject uploads $> 5\text{ MB}$, non-`image/{jpeg,png,webp}`, or pixel dimensions where `width * height > 25_000_000` before GD decompression; composite PNG/WebP transparency onto a white `#ffffff` canvas before resizing ($\le 800\times 1000\text{px}$) and saving as JPEG quality `85` named `u_<timestamp>_<random8>.jpg`.
- **No Raw Dataset on Server:** `data/alumni.json` and `seed.sql` are never uploaded to InfinityFree; `includes/config.php` and `seed.sql` are gitignored.
- **SMTP Transport:** Port `587` with `STARTTLS` via standalone PHPMailer (`includes/PHPMailer/`).

## Review Focus

- **Restore from backup JSON (`alumni-backup-YYYY-MM-DD.json`) in `scripts/build_seed.py`:** Must preserve explicit `id`, `is_deleted`, and already-resolved `photo` (`static/img/<filename>`) in addition to handling legacy `data/alumni.json` entries with `picture_link`. (Pinned in Task 1)
- **Corrupted or missing cache file (`storage/cache/alumni_latest.php`):** If the cache file is missing or returns a non-array, `Repository::getPublicEntries()` must transparently query MySQL, atomically write `alumni_latest.php` via temp-file + `rename()`, and return the array without opening a DB connection when the cache file is valid. (Pinned in Task 2)
- **Decompression bomb & transparent PNG/WebP upload in `includes/image.php`:** An image header reporting $6000 \times 5000 = 30\text{ MP} > 25\text{ MP}$ must be rejected before `imagecreatefrom*` is called, and transparent pixels must be filled with white (`255, 255, 255`) rather than turning black in JPEG output. (Pinned in Task 3)
- **OTP enumeration & replay in `api/request_otp.php` / `api/verify_otp.php`:** `request_otp.php` must return the exact same response payload whether the email exists in `alumni_entries` or not and clear `$_SESSION['captcha_answer']` on every POST attempt (pass or fail); `verify_otp.php` must mark `consumed_at = NOW()` on the 5th failed attempt or immediately on success so no OTP can be verified twice. (Pinned in Task 4)
- **Admin lockout alert deduplication in `admin.php`:** Triggering the 5th failed IP attempt (or 20th global failure) sends the lockout warning email to `ADMIN_EMAIL` once when crossing the threshold, not repeatedly on attempts 6, 7, 8 while already locked out. (Pinned in Task 5)

---

### Task 1: Database Schema & Local Seed Generator (`sql/schema.sql`, `scripts/build_seed.py`)

**Files:**
- Create: `sql/schema.sql`
- Create: `scripts/build_seed.py`
- Create: `tests/test_build_seed.py`
- Modify: `.gitignore`

**Interfaces:**
- Produces:
  - `sql/schema.sql`: DDL for `alumni_entries`, `email_otps`, `admin_login_attempts` (exact columns and indexes from Spec §5.2).
  - `scripts/build_seed.py`:
    - `resolve_photo_filename(entry: dict, img_dir: Path) -> str | None`
    - `build_seed_sql(payload: dict, img_dir: Path) -> str`
    - CLI: `python scripts/build_seed.py [input_json] [output_sql]` (defaults to `data/alumni.json` $\rightarrow$ `seed.sql`).

- [ ] **Step 1: Write the failing unit tests in `tests/test_build_seed.py`**

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
            (img_dir / "u_1700000000_deadbeef.jpg").write_bytes(b"jpg")

            legacy = {"picture_link": "https://drive.google.com/open?id=1abcDEF"}
            self.assertEqual(resolve_photo_filename(legacy, img_dir), "1abcDEF.jpeg")

            backup = {"photo": "static/img/u_1700000000_deadbeef.jpg"}
            self.assertEqual(resolve_photo_filename(backup, img_dir), "u_1700000000_deadbeef.jpg")

            default_backup = {"photo": "static/img/default.jpg"}
            self.assertIsNone(resolve_photo_filename(default_backup, img_dir))

    def test_build_seed_orders_by_timestamp_and_normalizes_email(self):
        with tempfile.TemporaryDirectory() as tmp:
            img_dir = Path(tmp)
            payload = {
                "entries": [
                    {
                        "timestamp": "2020-05-01 12:00:00",
                        "fullname": "Bui Van B",
                        "year": 2015,
                        "picture_link": "",
                        "education": "2015-2019: L'Université d'Angers",
                        "work": "",
                        "email": "  BUI.B@Example.com ",
                    },
                    {
                        "timestamp": "2019-11-14 10:56:01",
                        "fullname": "Nguyen Minh A",
                        "year": 2010,
                        "picture_link": "",
                        "education": "2010-2014: Can Tho",
                        "work": "Dev",
                        "email": "NGUYEN.A@Example.com",
                    },
                ]
            }
            sql = build_seed_sql(payload, img_dir)
            pos_a = sql.find("nguyen.a@example.com")
            pos_b = sql.find("bui.b@example.com")
            self.assertGreater(pos_a, -1)
            self.assertGreater(pos_b, pos_a)
            self.assertIn("L''Université d''Angers", sql)

    def test_build_seed_preserves_id_and_is_deleted_from_admin_backup(self):
        with tempfile.TemporaryDirectory() as tmp:
            img_dir = Path(tmp)
            payload = {
                "exported_at": "2026-11-08",
                "entries": [
                    {
                        "id": 42,
                        "timestamp": "2026-11-08 09:00:00",
                        "fullname": "Tran C",
                        "year": 2018,
                        "education": "2018-2022: Paris",
                        "work": "Engineer",
                        "email": "tran.c@example.com",
                        "photo": "static/img/default.jpg",
                        "is_deleted": 1,
                    }
                ],
            }
            sql = build_seed_sql(payload, img_dir)
            self.assertIn("(`id`, `email`, `fullname`, `graduation_year`, `education`, `work`, `photo_filename`, `submitted_at`, `is_deleted`)", sql)
            self.assertIn("(42, 'tran.c@example.com'", sql)
            self.assertIn(", NULL, '2026-11-08 09:00:00', 1)", sql)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m unittest tests/test_build_seed.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'scripts.build_seed'`

- [ ] **Step 3: Create `sql/schema.sql`, `scripts/build_seed.py`, and update `.gitignore`**

- In `sql/schema.sql`, write the exact `CREATE TABLE IF NOT EXISTS` statements for `alumni_entries`, `email_otps`, and `admin_login_attempts` from Spec §5.2 (`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`).
- In `scripts/build_seed.py`, implement `resolve_photo_filename(entry: dict, img_dir: Path) -> str | None` (extracts Drive ID from `picture_link` and checks `KNOWN_IMG_EXTS = ("jpg", "jpeg", "png", "JPG", "webp")` in `img_dir`, or extracts basename from `entry["photo"]` if not `default.jpg`) and `build_seed_sql(payload: dict, img_dir: Path) -> str` (sorts by `(timestamp, id)` ascending when `id` is absent, normalizes `email = email.strip().lower()`, escapes SQL strings + newlines safely, and outputs `SET NAMES utf8mb4; SET time_zone = '+07:00';` followed by `INSERT INTO alumni_entries ...`).
- In `.gitignore`, append `includes/config.php`, `seed.sql`, and `storage/cache/*.php`.

- [ ] **Step 4: Run unit tests and dry-run `scripts/build_seed.py` against `data/alumni.json`**

Run: `python -m unittest tests/test_build_seed.py -v && python scripts/build_seed.py`
Expected: All 3 unit tests PASS, and `seed.sql` is generated locally with 84 rows (`Wrote 84 entries to seed.sql`).

- [ ] **Step 5: Commit**

```bash
rtk git add sql/schema.sql scripts/build_seed.py tests/test_build_seed.py .gitignore
rtk git commit -m "feat: add MySQL schema and seed.sql generator for alumni data"
```

---

### Task 2: InfinityFree Spike Script, `.htaccess`, Config, DB & Cache Repository (`spike_check.php`, `.htaccess`, `includes/config.example.php`, `includes/db.php`, `includes/repository.php`)

**Files:**
- Create: `.htaccess`
- Create: `storage/cache/.gitkeep`
- Create: `includes/config.example.php`
- Create: `includes/db.php`
- Create: `includes/repository.php`
- Create: `spike_check.php`
- Create: `tests/test_repository_and_htaccess.py`

**Interfaces:**
- Produces:
  - `includes/config.example.php`: Returns associative config array (`db_host`, `db_name`, `db_user`, `db_pass`, `smtp_host`, `smtp_port` = `587`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name`, `admin_email`, `admin_password_hash`, `otp_daily_cap` = `100`, `site_url`).
  - `includes/db.php`:
    - `get_config(): array`
    - `get_pdo(): PDO` (sets `date_default_timezone_set('Asia/Ho_Chi_Minh')`, connects with `charset=utf8mb4`, `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, and runs `SET time_zone = '+07:00'`).
  - `includes/repository.php`:
    - `format_entry_row(array $row): array` (maps DB row to Spec §5.2 JSON contract: `timestamp`, `fullname`, `year` as `int`, `education`, `work`, `email`, `photo` = `'static/img/' . ($row['photo_filename'] ?: 'default.jpg')`).
    - `get_public_entries(?callable $pdoFactory = null, ?string $cacheFile = null): array` (reads `$cacheFile` default `storage/cache/alumni_latest.php` without invoking `$pdoFactory` when valid; otherwise queries `SELECT e.* FROM alumni_entries e JOIN (SELECT MAX(id) AS id FROM alumni_entries WHERE is_deleted = 0 GROUP BY email) m USING (id) ORDER BY e.graduation_year ASC, e.submitted_at DESC, e.id DESC`, writes cache atomically, and returns rows).
    - `rebuild_cache(PDO $pdo, ?string $cacheFile = null): array` (queries active latest rows per email, writes `<?php return ...;` to `$cacheFile . '.tmp.' . uniqid('', true)` and `rename()`s to `$cacheFile`).
    - `get_latest_entry_by_email(PDO $pdo, string $email): ?array` (returns formatted contract array for the `MAX(id)` row where `email = :email AND is_deleted = 0`, or `null`).
    - `export_backup_payload(PDO $pdo): array` (returns `['exported_at' => date('Y-m-d'), 'entries' => [...]]` for all rows in `alumni_entries` ordered by `id ASC`, including `id` and `is_deleted`).
  - `spike_check.php`: Standalone diagnostic script covering Spec §6 (`SELECT VERSION()`, `REMOTE_ADDR`, `ini_get('memory_limit')`, `gd_info()`, and optional `?send_test_mail=1` gated by admin password).

- [ ] **Step 1: Write the failing structural & contract tests in `tests/test_repository_and_htaccess.py`**

```python
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class RepositoryAndHtaccessTests(unittest.TestCase):
    def test_htaccess_enforces_https_blocks_dirs_and_sets_cache_headers(self):
        content = (ROOT / ".htaccess").read_text(encoding="utf-8")
        self.assertIn("RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]", content)
        self.assertIn("^(includes|storage|sql|scripts|tests|docs)/", content)
        self.assertIn("max-age=31536000, immutable", content)
        self.assertIn("AddDefaultCharset UTF-8", content)

    def test_repository_uses_max_id_dedup_and_atomic_rename_without_file_exists_per_row(self):
        repo = (ROOT / "includes" / "repository.php").read_text(encoding="utf-8")
        self.assertIn("SELECT MAX(id) AS id FROM alumni_entries WHERE is_deleted = 0 GROUP BY email", repo)
        self.assertIn("rename(", repo)
        self.assertIn("var_export(", repo)
        self.assertNotIn("file_exists(__DIR__ . '/../static/img/'", repo)

    def test_db_sets_ho_chi_minh_timezone_and_mysql_plus_07(self):
        db_php = (ROOT / "includes" / "db.php").read_text(encoding="utf-8")
        self.assertIn("date_default_timezone_set('Asia/Ho_Chi_Minh')", db_php)
        self.assertIn("SET time_zone = '+07:00'", db_php)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m unittest tests/test_repository_and_htaccess.py -v`
Expected: FAIL with `FileNotFoundError: ... .htaccess`

- [ ] **Step 3: Implement `.htaccess`, `includes/config.example.php`, `includes/db.php`, `includes/repository.php`, `storage/cache/.gitkeep`, and `spike_check.php`**

- In `.htaccess`:
  - `AddDefaultCharset UTF-8` and `Options -Indexes`.
  - `RewriteEngine On`: redirect HTTP to HTTPS (`RewriteCond %{HTTPS} !=on` AND `RewriteCond %{HTTP:X-Forwarded-Proto} !https` $\rightarrow$ `301`), block direct HTTP requests to `^(includes|storage|sql|scripts|tests|docs)/` and `^(\.git|seed\.sql|data/)` with `[F,L]`.
  - Set `Header set Cache-Control "public, max-age=31536000, immutable"` for `\.(jpg|jpeg|png|webp|JPG|css|js)$` and `Header set Cache-Control "no-store"` for `\.php$`.
- In `includes/config.example.php`, define all config keys with clear comments for InfinityFree (`sqlXXX.infinityfree.com`, Gmail App Password on port `587`, `password_hash()` example).
- In `includes/db.php`, implement `get_config()` and lazy singleton `get_pdo()`.
- In `includes/repository.php`, implement `format_entry_row()`, `get_public_entries()`, `rebuild_cache()`, `get_latest_entry_by_email()`, and `export_backup_payload()`. Ensure `get_public_entries()` checks `is_file($cacheFile)` first and returns the `include $cacheFile` array directly if `is_array($cached)` — never calling `get_pdo()` on cache hit.
- In `spike_check.php`, implement the Spec §6 readiness probe (outputs HTML/JSON table of PHP version, `memory_limit`, `gd_info()`, `REMOTE_ADDR`, MySQL `SELECT VERSION(), NOW()`, and optional test email trigger).

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m unittest tests/test_repository_and_htaccess.py -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add .htaccess storage/cache/.gitkeep includes/config.example.php includes/db.php includes/repository.php spike_check.php tests/test_repository_and_htaccess.py
rtk git commit -m "feat: add .htaccess, PDO connection, atomic cache repository, and spike probe"
```

---

### Task 3: Image Processing & Standalone PHPMailer Wrapper (`includes/image.php`, `includes/mailer.php`, `includes/PHPMailer/*`)

**Files:**
- Create: `includes/PHPMailer/Exception.php`
- Create: `includes/PHPMailer/PHPMailer.php`
- Create: `includes/PHPMailer/SMTP.php`
- Create: `includes/mailer.php`
- Create: `includes/image.php`
- Create: `tests/test_image_and_mailer.py`

**Interfaces:**
- Produces:
  - `includes/image.php`:
    - `process_uploaded_photo(array $file, ?string $targetDir = null): string`
      - Validates `$file['error'] === UPLOAD_ERR_OK`, `$file['size'] <= 5 * 1024 * 1024`.
      - Calls `getimagesize($file['tmp_name'])`; accepts only `'image/jpeg'`, `'image/png'`, `'image/webp'`; rejects if `$width <= 0 || $height <= 0 || ($width * $height) > 25000000` before GD decompression.
      - Loads image via `imagecreatefromjpeg` / `imagecreatefrompng` / `imagecreatefromwebp`; applies EXIF orientation for JPEG when `function_exists('exif_read_data')`.
      - Computes target dimensions keeping aspect ratio within `maxWidth = 800`, `maxHeight = 1000` (never upscaling smaller images).
      - Creates destination canvas with `imagecreatetruecolor($dstW, $dstH)`, fills entire background with solid white `imagecolorallocate($dst, 255, 255, 255)` via `imagefilledrectangle()` before `imagecopyresampled()` so transparent PNG/WebP backgrounds become white.
      - Saves JPEG at quality `85` to `$targetDir . '/u_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg'` and returns the filename (`u_<timestamp>_<random8>.jpg`).
      - Throws `InvalidArgumentException` with user-friendly Vietnamese message on validation failure or `RuntimeException` on GD failure.
  - `includes/mailer.php`:
    - `send_mail(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool` (configures `PHPMailer\PHPMailer\PHPMailer` with `isSMTP()`, `Host = $cfg['smtp_host']`, `Port = 587`, `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`, `SMTPAuth = true`, `CharSet = 'UTF-8'`).
    - `send_otp_email(string $toEmail, string $otpCode): bool` (sends bilingual FR/VI verification email with subject `[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn: {$otpCode}`).
    - `send_admin_notification(string $subject, string $htmlBody): bool` (sends notification to `$cfg['admin_email']`, catching and logging any exception via `error_log()` without throwing).

- [ ] **Step 1: Write the failing tests in `tests/test_image_and_mailer.py`**

```python
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class ImageAndMailerTests(unittest.TestCase):
    def test_phpmailer_standalone_files_exist_and_mailer_uses_587_starttls(self):
        for name in ("Exception.php", "PHPMailer.php", "SMTP.php"):
            p = ROOT / "includes" / "PHPMailer" / name
            self.assertTrue(p.is_file(), f"Missing {p}")
            self.assertGreater(p.stat().st_size, 1000)

        mailer = (ROOT / "includes" / "mailer.php").read_text(encoding="utf-8")
        self.assertIn("ENCRYPTION_STARTTLS", mailer)
        self.assertIn("587", mailer)
        self.assertIn("[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn:", mailer)

    def test_image_processor_guards_25mp_fills_white_and_outputs_jpeg_85(self):
        img_php = (ROOT / "includes" / "image.php").read_text(encoding="utf-8")
        self.assertIn("25000000", img_php)
        self.assertIn("imagecolorallocate(", img_php)
        self.assertIn("255, 255, 255", img_php)
        self.assertIn("imagefilledrectangle(", img_php)
        self.assertIn("imagejpeg(", img_php)
        self.assertIn("85", img_php)
        self.assertIn("u_", img_php)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m unittest tests/test_image_and_mailer.py -v`
Expected: FAIL (`Missing .../includes/PHPMailer/Exception.php`)

- [ ] **Step 3: Bundle standalone PHPMailer (`includes/PHPMailer/{Exception,PHPMailer,SMTP}.php`) and implement `includes/mailer.php` and `includes/image.php`**

- Fetch or write the official standalone PHPMailer `src/{Exception,PHPMailer,SMTP}.php` into `includes/PHPMailer/`.
- Implement `includes/mailer.php` with `send_mail()`, `send_otp_email()`, and `send_admin_notification()`.
- Implement `includes/image.php` with `process_uploaded_photo()` enforcing the 5 MB limit, `getimagesize()` MIME whitelist (`image/jpeg`, `image/png`, `image/webp`), `width * height <= 25000000` pre-decompression guard, white background fill (`255, 255, 255`), EXIF rotation if `exif_read_data` exists, aspect-preserving resize to $\le 800 \times 1000$, and JPEG quality 85 output.

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m unittest tests/test_image_and_mailer.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add includes/PHPMailer includes/mailer.php includes/image.php tests/test_image_and_mailer.py
rtk git commit -m "feat: add GD image processor and standalone PHPMailer SMTP wrapper"
```

---

### Task 4: OTP & Profile Submission API Endpoints (`api/request_otp.php`, `api/verify_otp.php`, `api/submit_profile.php`)

**Files:**
- Create: `api/request_otp.php`
- Create: `api/verify_otp.php`
- Create: `api/submit_profile.php`
- Create: `tests/test_api_endpoints.py`

**Interfaces:**
- Consumes:
  - `get_pdo()`, `get_config()` from `includes/db.php`
  - `get_latest_entry_by_email()`, `rebuild_cache()` from `includes/repository.php`
  - `process_uploaded_photo()` from `includes/image.php`
  - `send_otp_email()`, `send_admin_notification()` from `includes/mailer.php`
- Produces:
  - `GET api/request_otp.php`: Starts hardened session (`HttpOnly`, `SameSite=Strict`, `Secure` when HTTPS), generates random arithmetic question (`a + b` or `a - b` with positive result), stores integer answer in `$_SESSION['captcha_answer']`, returns `{"ok": true, "question": "7 + 5 = ?"}`.
  - `POST api/request_otp.php`: Accepts JSON or form body `{email, captcha, website_url}`. Always `unset($_SESSION['captcha_answer'])` immediately upon reading. Rejects non-empty `website_url` honeypot, invalid captcha, or invalid email. Prunes `email_otps` older than 1 day (`DELETE FROM email_otps WHERE created_at < NOW() - INTERVAL 1 DAY`). Enforces 3 rate limits on `email_otps`:
    1. Max 3 OTPs per `email` in last 10 minutes (`created_at > NOW() - INTERVAL 10 MINUTE`).
    2. Max 10 OTPs per `requester_ip` (`$_SERVER['REMOTE_ADDR']`) in last 15 minutes (`created_at > NOW() - INTERVAL 15 MINUTE`).
    3. Max `$cfg['otp_daily_cap']` (default `100`) OTPs across the whole table in last 24 hours (`created_at > NOW() - INTERVAL 1 DAY`).
    Generates `random_int(100000, 999999)`, inserts `password_hash($code, PASSWORD_DEFAULT)` with `expires_at = NOW() + INTERVAL 10 MINUTE`, sends OTP email, and returns `{"ok": true, "message": "..."}` (identical whether email already exists in `alumni_entries` or not).
  - `POST api/verify_otp.php`: Accepts `{email, otp}`. Looks up the latest row in `email_otps` for normalized `email` with `consumed_at IS NULL AND expires_at > NOW()`. If missing or `password_verify()` fails, increments `attempts = attempts + 1` (and sets `consumed_at = NOW()` if `attempts + 1 >= 5`), returning `400`. If valid, sets `consumed_at = NOW()`, calls `session_regenerate_id(true)`, sets `$_SESSION['verified_email'] = $email` and `$_SESSION['verified_until'] = time() + 1800`, queries `get_latest_entry_by_email($pdo, $email)`, and returns `{"ok": true, "verified": true, "existing_profile": $profile}`.
  - `POST api/submit_profile.php`: Accepts `multipart/form-data` (`fullname`, `graduation_year`, `education`, `work`, optional `photo` file). Reads email exclusively from `$_SESSION['verified_email']` (rejects with `401` if missing or `time() > $_SESSION['verified_until']`). Normalizes `fullname` via `mb_convert_case(preg_replace('/\s+/u', ' ', trim($fullname)), MB_CASE_TITLE, 'UTF-8')` (2–120 chars), validates `graduation_year` in `[1990, (int)date('Y') + 3]`, `education` (required, $\le 3000$ chars), `work` (optional, $\le 3000$ chars). Processes uploaded `photo` if provided; otherwise inherits `photo_filename` from current active row for that email. Inserts new row into `alumni_entries`, clears `$_SESSION['verified_email']` and `$_SESSION['verified_until']`, calls `rebuild_cache($pdo)`, sends non-blocking admin notification email, and returns `{"ok": true, "fullname": $fullname, "year": $year}`.

- [ ] **Step 1: Write the failing API contract tests in `tests/test_api_endpoints.py`**

```python
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class ApiEndpointsTests(unittest.TestCase):
    def test_request_otp_enforces_three_rate_limits_and_clears_captcha_session(self):
        code = (ROOT / "api" / "request_otp.php").read_text(encoding="utf-8")
        self.assertIn("unset($_SESSION['captcha_answer'])", code)
        self.assertIn("INTERVAL 10 MINUTE", code)
        self.assertIn("INTERVAL 15 MINUTE", code)
        self.assertIn("INTERVAL 1 DAY", code)
        self.assertIn("REMOTE_ADDR", code)
        self.assertNotIn("HTTP_X_FORWARDED_FOR", code)

    def test_verify_otp_consumes_on_5th_attempt_or_success_and_regenerates_session(self):
        code = (ROOT / "api" / "verify_otp.php").read_text(encoding="utf-8")
        self.assertIn("attempts >= 5", code.replace("attempts + 1 >= 5", "attempts >= 5"))
        self.assertIn("consumed_at = NOW()", code)
        self.assertIn("session_regenerate_id(true)", code)
        self.assertIn("verified_email", code)
        self.assertIn("verified_until", code)
        self.assertIn("existing_profile", code)

    def test_submit_profile_uses_session_email_normalizes_title_and_rebuilds_cache(self):
        code = (ROOT / "api" / "submit_profile.php").read_text(encoding="utf-8")
        self.assertIn("$_SESSION['verified_email']", code)
        self.assertIn("MB_CASE_TITLE", code)
        self.assertIn("rebuild_cache(", code)
        self.assertIn("send_admin_notification(", code)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m unittest tests/test_api_endpoints.py -v`
Expected: FAIL (`FileNotFoundError: ... api/request_otp.php`)

- [ ] **Step 3: Implement `api/request_otp.php`, `api/verify_otp.php`, and `api/submit_profile.php`**

- Write `api/request_otp.php`, `api/verify_otp.php`, and `api/submit_profile.php` adhering strictly to the signatures, rate-limit intervals, session keys, validation bounds, and response shapes above.

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m unittest tests/test_api_endpoints.py -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add api/request_otp.php api/verify_otp.php api/submit_profile.php tests/test_api_endpoints.py
rtk git commit -m "feat: add OTP verification and profile submission API endpoints"
```

---

### Task 5: Hardened Admin Panel (`admin.php`)

**Files:**
- Create: `admin.php`
- Create: `tests/test_admin_panel.py`

**Interfaces:**
- Consumes:
  - `get_pdo()`, `get_config()` from `includes/db.php`
  - `rebuild_cache()`, `export_backup_payload()` from `includes/repository.php`
  - `send_admin_notification()` from `includes/mailer.php`
- Produces:
  - `admin.php`:
    - Session cookie configured with `HttpOnly`, `SameSite=Strict`, `Secure` (when HTTPS).
    - Brute-force protection using `admin_login_attempts` (prunes rows older than 30 days on login):
      - Global lock check: if count of `success = 0` in last 1 hour (`created_at > NOW() - INTERVAL 1 HOUR`) $\ge 20$, reject login for 1 hour.
      - Per-IP lock check: if count of `success = 0` for `$_SERVER['REMOTE_ADDR']` in last 15 minutes (`created_at > NOW() - INTERVAL 15 MINUTE`) $\ge 5$, reject login for 15 minutes.
      - On failed password: inserts `success = 0`, computes new failure counts; if IP failures just reached `5` or global failures just reached `20`, calls `send_admin_notification()` once with IP, failure count, and unlock timestamp; calls `sleep(min(8, 1 << max(0, $ipFailures - 1)))` (`1s, 2s, 4s, 8s`).
      - On valid `password_verify($password, $cfg['admin_password_hash'])`: inserts `success = 1`, calls `session_regenerate_id(true)`, sets `$_SESSION['admin_logged_in'] = true` and `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`, and sends login alert email to `ADMIN_EMAIL` (`IP + timestamp`).
    - Authenticated POST actions (all require `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')`):
      - `action=toggle_delete` (`id`, `is_deleted` $\in \{0, 1\}$): updates `alumni_entries`, calls `rebuild_cache($pdo)`.
      - `action=rebuild_cache`: calls `rebuild_cache($pdo)`.
      - `action=download_backup`: calls `export_backup_payload($pdo)` and streams `Content-Disposition: attachment; filename="alumni-backup-YYYY-MM-DD.json"`.
      - `action=logout`: destroys session.
    - History table showing all rows in `alumni_entries` ordered by `id DESC` with status badges: `"Đang hiển thị"` (matches `MAX(id)` for that email where `is_deleted = 0`), `"Bản cũ"` (`is_deleted = 0` but superseded), or `"Đã ẩn"` (`is_deleted = 1`).

- [ ] **Step 1: Write the failing tests in `tests/test_admin_panel.py`**

```python
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class AdminPanelTests(unittest.TestCase):
    def test_admin_throttling_lockouts_and_single_threshold_alerts(self):
        admin = (ROOT / "admin.php").read_text(encoding="utf-8")
        self.assertIn("admin_login_attempts", admin)
        self.assertIn("INTERVAL 15 MINUTE", admin)
        self.assertIn("INTERVAL 1 HOUR", admin)
        self.assertIn("INTERVAL 30 DAY", admin)
        self.assertIn("sleep(", admin)
        self.assertIn("=== 5", admin)
        self.assertIn("=== 20", admin)
        self.assertIn("password_verify(", admin)
        self.assertIn("hash_equals(", admin)

    def test_admin_actions_cover_toggle_delete_rebuild_cache_and_backup_export(self):
        admin = (ROOT / "admin.php").read_text(encoding="utf-8")
        self.assertIn("toggle_delete", admin)
        self.assertIn("rebuild_cache", admin)
        self.assertIn("download_backup", admin)
        self.assertIn("alumni-backup-", admin)
        self.assertIn("Đang hiển thị", admin)
        self.assertIn("Bản cũ", admin)
        self.assertIn("Đã ẩn", admin)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python -m unittest tests/test_admin_panel.py -v`
Expected: FAIL (`FileNotFoundError: ... admin.php`)

- [ ] **Step 3: Implement `admin.php`**

- Create `admin.php` styled with *L'Édition Classique* tokens (`assets/css/style.css`), implementing the login screen, brute-force throttling/lockout + email alerts, CSRF-protected actions (`toggle_delete`, `rebuild_cache`, `download_backup`, `logout`), and the full entry history table.

- [ ] **Step 4: Run test to verify it passes**

Run: `python -m unittest tests/test_admin_panel.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add admin.php tests/test_admin_panel.py
rtk git commit -m "feat: add hardened admin panel with login throttling, history moderation, and JSON backup"
```

---

### Task 6: Frontend Yearbook Page & 2-Step Contribute Modal (`index.php`, `assets/js/app.js`, `assets/css/style.css`, `README.md`)

**Files:**
- Create: `index.php`
- Remove: `index.html` (replaced by `index.php`)
- Modify: `assets/js/app.js`
- Modify: `assets/css/style.css`
- Modify: `README.md`
- Create: `tests/frontend.test.mjs`

**Interfaces:**
- Consumes:
  - `get_public_entries()` from `includes/repository.php`
  - `GET/POST api/request_otp.php`, `POST api/verify_otp.php`, `POST api/submit_profile.php`
  - `makeAnchor()`, `escapeHtml()`, `formatLinesWithYears()` from `assets/js/text.js`
- Produces:
  - `index.php`:
    - Does NOT call `session_start()`.
    - Loads `$entries = get_public_entries();` and embeds `<script>window.__ALUMNI_DATA__ = <?= json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>`.
    - Cache-busts `assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>`, `assets/js/text.js?v=...`, and `assets/js/app.js?v=...`.
    - Replaces Google Form external links on `#open-contribute-btn` (toolbar CTA) and inside `#help-modal` with buttons that open `#contribute-modal`.
    - Includes `#contribute-backdrop` and `#contribute-modal` markup with Step 1 (Email + Arithmetic Captcha + Honeypot `website_url`), Step 2 (6-digit OTP verification + resend/back button), and Step 3 (Profile form: `fullname`, `graduation_year`, `education`, `work`, `photo` file input with current portrait preview and note *"Giữ nguyên ảnh hiện tại nếu bạn không chọn ảnh mới"*).
  - `assets/js/app.js`:
    - `buildPhoto(photoUrl, alt)`: sets `img.src = photoUrl || DEFAULT_IMG`, toggles `.is-default` when `src === DEFAULT_IMG`, and attaches a single `img.onerror` fallback to `DEFAULT_IMG` (removes `IMG_EXTS` and Google Drive URL probing).
    - `init()`: reads `Array.isArray(window.__ALUMNI_DATA__) ? window.__ALUMNI_DATA__ : []`, applies `dedupByEmail` + `titleCase`, populates year filter, renders index + entries, and scrolls to `window.location.hash` if present.
    - `resizeImageClientSide(file, maxEdge = 1600, quality = 0.9)`: draws image onto `<canvas>` if needed and resolves to a JPEG `Blob`/`File` (falling back to original `file` on error).
    - Contribute modal controller handling Step 1 (`GET`/`POST api/request_otp.php`), Step 2 (`POST api/verify_otp.php` + pre-filling form from `existing_profile`), and Step 3 (`POST api/submit_profile.php` $\rightarrow$ navigates to `?updated=${Date.now()}#${makeAnchor(`${res.year}-${res.fullname}`)}`).

- [ ] **Step 1: Write the failing Node.js tests in `tests/frontend.test.mjs`**

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();

test('index.php embeds window.__ALUMNI_DATA__, versioned assets, and contribute modal without session_start', () => {
  const html = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
  assert.ok(!html.includes('session_start('), 'index.php must not start a session');
  assert.ok(html.includes('window.__ALUMNI_DATA__'), 'index.php must embed window.__ALUMNI_DATA__');
  assert.ok(html.includes('filemtime('), 'index.php must version CSS/JS assets with filemtime');
  assert.ok(html.includes('id="contribute-modal"'), 'index.php must include #contribute-modal');
  assert.ok(!html.includes('forms.gle'), 'index.php must not link to external Google Form');
});

test('app.js uses e.photo directly without multi-extension probing and implements 3-step OTP modal flow', () => {
  const js = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
  assert.ok(!js.includes('googleusercontent.com'), 'app.js must not probe Google Drive thumbnails');
  assert.ok(!js.includes("['jpg', 'jpeg', 'png', 'JPG', 'webp']"), 'app.js must not probe 5 image extensions');
  assert.ok(js.includes('window.__ALUMNI_DATA__'), 'app.js must read window.__ALUMNI_DATA__');
  assert.ok(js.includes('api/request_otp.php'), 'app.js must call api/request_otp.php');
  assert.ok(js.includes('api/verify_otp.php'), 'app.js must call api/verify_otp.php');
  assert.ok(js.includes('api/submit_profile.php'), 'app.js must call api/submit_profile.php');
  assert.ok(js.includes('canvas'), 'app.js must resize image on canvas before upload');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test tests/frontend.test.mjs`
Expected: FAIL (`ENOENT: no such file or directory, open '.../index.php'`)

- [ ] **Step 3: Implement `index.php`, update `assets/js/app.js`, `assets/css/style.css`, and `README.md`, and remove `index.html`**

- Create `index.php` from `index.html`, adding the PHP cache loader at the top, `filemtime()` asset query strings, `window.__ALUMNI_DATA__` inline script, and `#contribute-modal` markup; remove `index.html`.
- Update `assets/js/app.js` to use `window.__ALUMNI_DATA__`, single-path `buildPhoto(e.photo, e.fullname)`, and the 3-step contribute modal controller with `<canvas>` image compression.
- Append modal form styles (`.contribute-modal`, `.step-pane`, `.form-field`, `.otp-input`, `.photo-preview`, `.alert-msg`) to `assets/css/style.css` matching *L'Édition Classique* (`--color-primary: #80182a`, `--font-serif`, `--font-sans`).
- Update `README.md` with InfinityFree deployment, cutover (`scripts/build_seed.py` $\rightarrow$ phpMyAdmin), and backup instructions.

- [ ] **Step 4: Run all automated test suites (`node --test` and `python -m unittest`)**

Run: `node --test tests/frontend.test.mjs && python -m unittest discover -s tests -p "test_*.py" -v`
Expected: All Node and Python test suites PASS with zero errors.

- [ ] **Step 5: Commit**

```bash
rtk git add -A
rtk git commit -m "feat: replace static frontend fetch with inline PHP data and 2-step OTP modal"
```
