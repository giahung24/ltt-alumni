# Thiết kế phiên bản PHP/MySQL — Kỷ yếu lớp Pháp (`ltt-alumni.page.gd`)

- **Ngày:** 2026-11-08
- **Branch:** `php-mysql`
- **Môi trường đích:** InfinityFree Free Hosting (`ltt-alumni.page.gd`), PHP 8.x + MySQL/MariaDB

---

## 1. Mục đích & Bối cảnh (Intent & Why)

Hiện tại, trang kỷ yếu cựu học sinh lớp Pháp ngữ THPT Chuyên Lý Tự Trọng Cần Thơ (`main`) là một ứng dụng tĩnh (HTML/CSS/JS) đọc dữ liệu từ `data/alumni.json` (84 hồ sơ, 89 file ảnh trong `static/img/`) và đồng bộ định kỳ qua GitHub Actions từ Google Form/Sheet + Google Drive.

Cách vận hành cũ gặp 3 hạn chế lớn:
1. **Tên miền GitHub Pages:** Đường dẫn có dạng `acc_name.github.io/ltt-alumni` thay vì tên miền riêng gọn đẹp như `ltt-alumni.page.gd`.
2. **Lịch sử dữ liệu trên repo public:** GitHub Pages miễn phí buộc repository phải để **Public**, nên toàn bộ lịch sử git của `data/alumni.json` (các phiên bản cũ của từng hồ sơ) ai cũng xem được.
   - *Lưu ý trung thực:* chuyển repo sang Private chỉ che **lịch sử git**. Các trường hiển thị trên trang (họ tên, email, học vấn, công việc) **vẫn công khai** vì chúng được nhúng trong HTML của `index.php` để phục vụ tìm kiếm và nút "Hiện email". Dữ liệu đã public trên GitHub từ trước cũng không thể "thu hồi". Dữ liệu thực sự bí mật chỉ gồm: `config.php` (mật khẩu DB/SMTP/admin), bảng OTP và địa chỉ IP người gửi.
3. **Độ trễ và phụ thuộc Google Form/Drive:** Cựu học sinh cập nhật xong phải đợi cron chạy mới lên web, và việc tải ảnh trên Google Form yêu cầu đăng nhập tài khoản Google.

Phiên bản **PHP/MySQL trên InfinityFree** giải quyết cả 3 vấn đề: chạy trên tên miền `ltt-alumni.page.gd`, cho phép giữ GitHub repository ở chế độ **Private**, lưu trữ và xử lý hồ sơ trực tiếp bằng PHP/MySQL, đồng thời cho phép cựu học sinh thêm/cập nhật hồ sơ và ảnh chân dung ngay trên web với xác thực OTP qua email.

---

## 2. Mục tiêu, Phi mục tiêu & Điều cần tránh (Goals, Non-Goals & Anti-Goals)

### Goals (Mục tiêu)
- **Giữ nguyên 100% thiết kế & trải nghiệm tra cứu *L'Édition Classique*:** Bảo toàn toàn bộ giao diện, bảng màu, hệ thống chữ hai giọng (`Source Serif 4` & `Be Vietnam Pro`), thanh lọc theo khóa, tìm kiếm không dấu tức thì, ngăn kéo danh sách (`index-drawer`), nút bật/tắt hiển thị email và hiển thị dòng thời gian (`Formation` / `Profession`).
- **Tự chủ hoàn toàn luồng Thêm / Cập nhật hồ sơ trên web:** Thay thế Google Form bằng hộp thoại (Modal) 2 bước ngay trên trang chính:
  1. Nhập email + giải phép tính ngẫu nhiên chống bot $\rightarrow$ nhận mã OTP 6 chữ số qua email.
  2. Xác thực OTP $\rightarrow$ nếu email đã tồn tại trong kỷ yếu thì **tự động điền sẵn (auto-fill)** thông tin và ảnh hiện tại để người dùng sửa; nếu chưa có thì hiển thị form trống $\rightarrow$ lưu bản ghi mới vào MySQL và hiển thị ngay lập tức.
- **Lưu lịch sử các bản ghi (Append-only history):** Mọi lần gửi mới đều được `INSERT` thành dòng mới (không ghi đè/xóa dòng cũ). Khi hiển thị chỉ lấy bản ghi mới nhất theo từng `email` (`MAX(id)`, xem §5.2).
- **Xử lý ảnh chân dung:** Trình duyệt thu nhỏ ảnh trước khi tải lên; PHP kiểm tra định dạng thực, chuẩn hóa về JPEG (chiều rộng tối đa `800px`, chất lượng 85%, ~40–70 KB) và lưu vào `static/img/`.
- **Tối ưu đặc biệt cho giới hạn của InfinityFree:** Nhúng dữ liệu vào `index.php` kèm **file cache nội bộ** để các lượt truy cập thông thường không mở kết nối MySQL, không mở session và không tốn thêm HTTP request phụ.
- **Trang quản trị nhẹ (`admin.php`):** Quản trị viên đăng nhập bằng mật khẩu (có giới hạn số lần sai, làm chậm và gửi mail cảnh báo tới email admin) để xem lịch sử các lần gửi, ẩn/khôi phục bản ghi rác (tự động hiển thị lại bản hợp lệ trước đó của cùng email), làm mới cache và **tải bản sao lưu**.
- **Thông báo cho admin:** Mỗi lần có hồ sơ được thêm/cập nhật, hệ thống gửi mail báo tới `ADMIN_EMAIL` để kiểm duyệt kịp thời.
- **Không mất dữ liệu:** Có quy trình chuyển đổi (cutover) rõ ràng và cơ chế sao lưu định kỳ (§5.7, §5.8), vì sau khi chuyển, dữ liệu mới chỉ còn nằm trên InfinityFree.

### Non-Goals (Phi mục tiêu)
- Không xây dựng hệ thống tài khoản có mật khẩu, trang cá nhân riêng hay mạng xã hội (bình luận, nhắn tin).
- Không sử dụng framework nặng (Laravel, Symfony, React/Vue) hay công cụ build phức tạp; triển khai bằng cách upload trực tiếp qua FTP lên `htdocs/` của InfinityFree.
- Không tiếp tục đồng bộ với Google Sheet hay Google Drive sau khi đã chuyển dữ liệu sang MySQL.
- **Không có hàng chờ duyệt** cho hồ sơ mới ở phiên bản đầu: hồ sơ hiển thị ngay, admin kiểm duyệt sau qua mail thông báo + nút ẩn. Chỉ thêm hàng chờ duyệt nếu thực tế xuất hiện spam.
- Không sao lưu tự động bằng GitHub Actions/cron bên ngoài: cơ chế chống bot của InfinityFree chặn mọi client không chạy JavaScript (xem §3).

### Anti-Goals (Điều tuyệt đối tránh)
- **Không để lộ bí mật:** `includes/config.php`, bảng `email_otps`, cột `submitter_ip` và bảng `admin_login_attempts` không bao giờ được trả ra trình duyệt.
- **Không đặt file dữ liệu thô trên server:** `data/alumni.json` **không** được upload lên InfinityFree (dữ liệu khởi tạo đi qua `seed.sql` + phpMyAdmin, xem §5.7).
- **Không mở kết nối MySQL hay session PHP trên mỗi lượt tải trang** nếu cache còn hiệu lực: tránh lỗi `max_user_connections` khi nhiều người cùng mở link kỷ yếu.
- **Không cho phép thay đổi thông tin hiển thị của một email nếu chưa xác thực OTP của chính email đó.**
- **Không truyền OTP/mật khẩu admin qua HTTP thường:** toàn site bắt buộc HTTPS.

---

## 3. Ràng buộc kỹ thuật của InfinityFree (Constraints)

Từ thông số gói **InfinityFree** (`Plan: infinityfree`) và forum hỗ trợ chính thức:
- **Disk Quota:** 5 GB (dư sức cho hàng ngàn hồ sơ và ảnh đã nén ~50 KB/ảnh).
- **Inodes:** Giới hạn `80.000` files/folders $\rightarrow$ không dùng cây `vendor/` của Composer; chỉ thêm **PHPMailer bản rời (3 file)**.
- **Daily Hits:** Giới hạn `50.000 hits/ngày` (mỗi file HTML, CSS, JS, ảnh được trình duyệt tải tính là 1 hit; lỗi 404 cũng tính):
  - Một lượt xem cuộn hết trang ≈ 4 file (HTML + CSS + 2 JS) + 84 ảnh ≈ **88 hits** $\rightarrow$ quota đủ cho khoảng **570 lượt xem đầy đủ/ngày**. Một bài chia sẻ trong nhóm Facebook lớn có thể chạm trần; ảnh chiếm phần lớn số hit.
  - Biện pháp: nhúng dữ liệu vào `index.php` (không gọi API JSON); giữ `loading="lazy"` cho ảnh; header `Cache-Control` dài hạn trong `.htaccess` cho `static/img/` (tên file ảnh là duy nhất nên an toàn) và `assets/` (bắt buộc kèm số phiên bản trong URL, xem §5.6) để khách quay lại không tốn thêm hit.
  - Server trả sẵn đường dẫn ảnh chính xác (`photo`) để trình duyệt không phải thử lần lượt các đuôi `.jpg`, `.jpeg`, `.png`, `.JPG`, `.webp` gây 404.
- **Hệ thống chống bot của trình duyệt (`aes.js` + cookie `__test` + chuyển hướng `?i=1`):** Mọi request phải đến từ trình duyệt chạy được JavaScript và nhận cookie. Hệ quả:
  - `fetch()` cùng origin từ trang đã tải: hoạt động bình thường.
  - GitHub Actions, `curl`, dịch vụ giám sát uptime: **bị chặn** $\rightarrow$ không có sao lưu tự động từ bên ngoài.
  - Bot tạo bản xem trước link của Facebook/Zalo có thể bị chặn $\rightarrow$ cần kiểm chứng sớm (§6).
- **MySQL:** Chỉ cho phép kết nối từ script PHP trên host (`sqlXXX.infinityfree.com`), không kết nối được từ máy ngoài; quản lý qua phpMyAdmin trong control panel.
- **Gửi mail:** `mail()` bị khóa $\rightarrow$ gửi qua **SMTP có xác thực, chỉ dùng cổng `587` + `STARTTLS`** (cổng 25 bị cấm; cổng 465 có thể bị chặn theo forum InfinityFree). Dùng Gmail App Password (yêu cầu bật xác minh 2 bước); Gmail giới hạn khoảng 500 người nhận/ngày.
- **HTTPS:** InfinityFree cấp SSL miễn phí nhưng phải **cài thủ công trong control panel**; sau đó ép chuyển hướng HTTPS bằng `.htaccess`.
- **Giới hạn kích thước file:** Tối đa `10 MB`/file trên host $\rightarrow$ giới hạn ảnh tải lên ở mức `5 MB` ở cả client và server.
- **Bộ nhớ PHP (`memory_limit`) có hạn:** giải nén một ảnh 25 megapixel cần khoảng 100 MB RAM $\rightarrow$ phải kiểm tra kích thước pixel trước khi giải nén (§5.3).
- **Múi giờ:** múi giờ mặc định của server MySQL/PHP không phải giờ Việt Nam; dữ liệu cũ (`timestamp` từ Google Sheet) theo giờ Việt Nam (GMT+7).

---

## 4. Các phương án kiến trúc đã cân nhắc (Approaches)

### Phương án 1 — Render kết hợp (Inline Data + Cache nội bộ) & Lọc tức thì ở Client *(Đã chọn)*
- `index.php` đọc danh sách hồ sơ mới nhất từ file cache nội bộ `storage/cache/alumni_latest.php` (nếu chưa có cache mới truy vấn MySQL rồi ghi ra cache) và nhúng trực tiếp vào `window.__ALUMNI_DATA__`.
- Toàn bộ thao tác lọc theo khóa, tìm kiếm không dấu, mở danh sách nhanh và bật/tắt email diễn ra tức thì trên trình duyệt bằng JS mà không tốn thêm kết nối MySQL hay HTTP hit.
- Khi có hồ sơ mới gửi qua API hoặc admin ẩn/khôi phục bản ghi, PHP tạo lại file cache.

### Phương án 2 — Client Fetch API trực tiếp từ MySQL mỗi lượt tải
- *Loại bỏ vì:* Tốn gấp đôi số request PHP mỗi lần mở trang và dễ chạm giới hạn kết nối MySQL đồng thời của InfinityFree.

### Phương án 3 — Server-Side Rendering (SSR) thuần bằng PHP mỗi lần lọc/tìm kiếm
- *Loại bỏ vì:* Mỗi lần đổi khóa hoặc tìm kiếm phải tải lại trang, làm tăng vọt Daily Hits và mất trải nghiệm lọc tức thì.

### Phương án 4 — File JSONL ghi nối tiếp thay cho MySQL
- Lưu mỗi lần gửi thành một dòng trong `storage/entries.jsonl` (ghi có khóa `flock`); không cần MySQL, không cần lớp cache, sao lưu chỉ là tải một file.
- *Không chọn vì:* đã chốt hướng PHP/MySQL (branch `php-mysql`); MySQL giúp việc truy vấn lịch sử, ẩn/khôi phục và rate limit gọn hơn, có sẵn phpMyAdmin để sửa tay. Giữ làm phương án dự phòng nếu MySQL của InfinityFree tỏ ra không ổn định.

---

## 5. Thiết kế chi tiết (Detailed Design)

### 5.1. Cấu trúc thư mục

```text
Repo (private)
├── scripts/
│   └── build_seed.py          # Chạy LOCAL: data/alumni.json + static/img/ → seed.sql (không upload)
└── data/alumni.json           # Chỉ nằm trong repo, KHÔNG upload lên server

Server — thư mục htdocs/ của InfinityFree
├── .htaccess                  # Ép HTTPS, chặn includes/ & storage/, cache tĩnh, UTF-8
├── index.php                  # Trang chính (giao diện kỷ yếu + modal cập nhật hồ sơ + hướng dẫn)
├── admin.php                  # Trang quản trị nhẹ (đăng nhập có throttle, lịch sử, ẩn/khôi phục, cache, sao lưu)
├── api/
│   ├── request_otp.php        # GET: trả phép tính mới | POST: kiểm tra phép tính + rate limit, gửi OTP
│   ├── verify_otp.php         # POST: xác thực OTP, đánh dấu email đã xác minh trong session, trả hồ sơ cũ
│   └── submit_profile.php     # POST (multipart): kiểm tra session, xử lý ảnh, INSERT, làm mới cache, báo admin
├── includes/
│   ├── config.example.php     # Mẫu cấu hình (DB, SMTP, ADMIN_PASSWORD_HASH, ADMIN_EMAIL, giới hạn)
│   ├── config.php             # Cấu hình thật (trong .gitignore, KHÔNG commit)
│   ├── db.php                 # Kết nối PDO utf8mb4 + SET time_zone = '+07:00'
│   ├── repository.php         # Truy vấn hồ sơ mới nhất, đọc/ghi cache, xuất bản sao lưu
│   ├── image.php              # Kiểm tra ảnh, chống ảnh quá lớn, chuẩn hóa JPEG bằng GD
│   ├── mailer.php             # Hàm send_mail() bọc PHPMailer (587 STARTTLS)
│   └── PHPMailer/             # PHPMailer.php, SMTP.php, Exception.php (bản rời, không Composer)
├── storage/                   # Chặn truy cập HTTP bằng .htaccess
│   └── cache/
│       └── alumni_latest.php  # Cache dạng `<?php return [...];` — dù lộ cũng chỉ chạy ra trang trắng
├── assets/
│   ├── css/style.css          # Giữ nguyên L'Édition Classique + CSS cho modal
│   └── js/
│       ├── text.js            # Giữ nguyên xử lý chuỗi, anchor và timeline
│       └── app.js             # Đọc window.__ALUMNI_DATA__, giữ nguyên bộ lọc + thêm modal OTP
└── static/
    └── img/                   # default.jpg, 89 ảnh hiện có và các ảnh upload mới
```

### 5.2. Cơ sở dữ liệu & hợp đồng dữ liệu

Sử dụng bộ mã `utf8mb4_unicode_ci` (so sánh chuỗi **không phân biệt hoa thường**) để hỗ trợ đầy đủ tiếng Việt và tiếng Pháp. Mỗi kết nối PDO chạy `SET time_zone = '+07:00'`; PHP đặt `date_default_timezone_set('Asia/Ho_Chi_Minh')`. Mọi mốc thời gian (hết hạn OTP, cửa sổ rate limit) được tính bằng SQL (`NOW() + INTERVAL 10 MINUTE`), không trộn giờ PHP với giờ MySQL.

```sql
CREATE TABLE IF NOT EXISTS alumni_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,          -- Đã chuẩn hóa lúc INSERT: strtolower(trim())
    fullname VARCHAR(191) NOT NULL,
    graduation_year SMALLINT UNSIGNED NOT NULL,
    education TEXT NOT NULL,
    work TEXT NULL,
    photo_filename VARCHAR(255) NULL,     -- Tên file trong static/img/ (vd "1w7dAK...jpg", "u_1762600000_a8f9c2d1.jpg"); NULL → default.jpg
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Giờ Việt Nam
    submitter_ip VARCHAR(45) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_email (email, is_deleted, id),
    INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,       -- password_hash() của mã 6 số
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requester_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,         -- NOW() + INTERVAL 10 MINUTE
    consumed_at DATETIME NULL,            -- Đặt ngay khi xác thực đúng: mỗi OTP chỉ dùng được 1 lần
    INDEX idx_email_active (email, expires_at),
    INDEX idx_ip_rate (requester_ip, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, created_at),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Đã bỏ các cột `picture_link` (chỉ cần lúc tạo seed), `facebook` (84/84 hồ sơ cũ để trống, form mới không có trường này) và `verify_token` (thay bằng session, xem §5.3).

**Quy tắc lấy danh sách hiển thị (dedup theo email):**
- Bảng chỉ được thêm dòng (append-only) và dữ liệu seed được chèn theo thứ tự `timestamp` tăng dần $\rightarrow$ `id` lớn hơn luôn là bản mới hơn.
- Bản hiển thị của mỗi email = dòng có `MAX(id)` trong các dòng `is_deleted = 0`:
  ```sql
  SELECT e.* FROM alumni_entries e
  JOIN (SELECT MAX(id) AS id FROM alumni_entries WHERE is_deleted = 0 GROUP BY email) m USING (id);
  ```
- Email đã được chuẩn hóa khi INSERT, collation không phân biệt hoa thường $\rightarrow$ không cần `LOWER(TRIM())` khi truy vấn.
- Không gọi `file_exists` cho từng ảnh khi build cache: tin vào `photo_filename` (được xác định lúc seed/upload); `NULL` $\rightarrow$ `static/img/default.jpg`.

**Hợp đồng dữ liệu JSON duy nhất** — dùng chung cho cache, `window.__ALUMNI_DATA__`, `existing_profile` (§5.3) và bản sao lưu (§5.8). Giữ đúng tên trường của `alumni.json` cũ để `app.js` gần như không đổi:

```json
{
  "timestamp": "2019-11-14 10:56:01",
  "fullname": "Nguyen, Minh Tu",
  "year": 2010,
  "education": "…",
  "work": "…",
  "email": "helene_ckga@hotmail.com",
  "photo": "static/img/1w7dAKcobZ2s6yQnjMAcQejhK2nDb0bj6.jpg"
}
```

Ánh xạ: `timestamp` ← `submitted_at` (`Y-m-d H:i:s`, giờ Việt Nam), `year` ← `graduation_year`, `photo` ← `static/img/` + (`photo_filename` ?? `default.jpg`).

**Cache:** `repository.php` ghi `storage/cache/alumni_latest.php` dưới dạng `<?php return <var_export($rows)>;` theo kiểu atomic (ghi ra file tạm rồi `rename`) để không có request nào đọc phải file ghi dở. Cache được tạo lại khi: có hồ sơ được gửi, admin ẩn/khôi phục, admin bấm "Làm mới cache", hoặc file cache không tồn tại. Sau khi sửa tay qua phpMyAdmin, admin phải bấm "Làm mới cache".

### 5.3. Luồng Thêm / Cập nhật hồ sơ (OTP & phép tính chống bot)

Session PHP **chỉ được mở trong `api/*.php` và `admin.php`**, không bao giờ trong `index.php`. Khi người dùng bấm **"Thêm / cập nhật hồ sơ"** trên thanh công cụ (hoặc trong hộp thoại Hướng dẫn), hộp thoại `#contribute-modal` mở ra.

#### Bước 1: Xác minh email & chống bot (`api/request_otp.php`)
- Khi modal mở, frontend gọi `GET api/request_otp.php`: server tạo phép tính ngẫu nhiên (vd `7 + 5 = ?`, `14 - 6 = ?`), lưu đáp án vào `$_SESSION` và trả câu hỏi. Form có thêm trường ẩn honeypot `website_url`.
- Người dùng gửi `POST`: `email` (bắt buộc, `filter_var(..., FILTER_VALIDATE_EMAIL)`) và đáp án phép tính.
- Server kiểm tra:
  - Honeypot phải trống; đáp án phải khớp `$_SESSION`. Sau mỗi lần kiểm tra (đúng hay sai) đáp án cũ bị xóa; frontend tự gọi `GET` lấy phép tính mới khi cần.
  - **Rate limit** (đếm trên `email_otps`): tối đa 3 OTP / 10 phút cho cùng email; tối đa 10 OTP / 15 phút cho cùng IP; **tối đa `OTP_DAILY_CAP` (mặc định 100) OTP / ngày cho toàn hệ thống** để bảo vệ quota và uy tín tài khoản Gmail.
  - IP lấy từ `$_SERVER['REMOTE_ADDR']`, **không tin `X-Forwarded-For`** (cần kiểm chứng `REMOTE_ADDR` là IP thật, §6).
- Nếu hợp lệ:
  - Tạo mã `random_int(100000, 999999)`, lưu `password_hash()` vào `email_otps` với `expires_at = NOW() + INTERVAL 10 MINUTE`.
  - Gửi email tiêu đề `[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn: XXXXXX` qua `send_mail()` (PHPMailer, 587 STARTTLS; PHPMailer tự mã hóa tiêu đề UTF-8).
  - Phản hồi **giống hệt nhau** dù email đã có trong kỷ yếu hay chưa.
- Dọn rác: mỗi lần `POST` xóa các dòng `email_otps` cũ hơn 1 ngày.

#### Bước 2: Nhập OTP & tự động điền thông tin cũ (`api/verify_otp.php`)
- Người dùng nhập mã OTP 6 chữ số; server lấy dòng OTP mới nhất của email có `consumed_at IS NULL AND expires_at > NOW()`.
  - Mỗi lần sai: `attempts + 1`. Đến lần sai thứ 5 $\rightarrow$ hủy mã (đặt `consumed_at`), yêu cầu lấy mã mới.
  - Nếu đúng: đặt `consumed_at = NOW()` (OTP không dùng lại được), gọi `session_regenerate_id(true)`, lưu `$_SESSION['verified_email']` và `$_SESSION['verified_until'] = time() + 1800` (30 phút để điền form).
- Truy vấn bản hiển thị hiện tại của email (quy tắc §5.2) và trả về JSON: `{ "verified": true, "existing_profile": <hợp đồng dữ liệu §5.2> | null }`.
- Giao diện chuyển sang màn hình nhập thông tin:
  - Nếu `existing_profile` khác `null`: điền sẵn **Họ và tên**, **Năm tốt nghiệp (Khóa)**, **Quá trình học tập (Formation)**, **Công việc (Profession)** và hiển thị ảnh hiện tại kèm ghi chú *"Giữ nguyên ảnh hiện tại nếu bạn không chọn ảnh mới"*.
  - Nếu là hồ sơ mới: các trường để trống.

#### Bước 3: Lưu hồ sơ & xử lý ảnh (`api/submit_profile.php`)
- **Phía trình duyệt, trước khi gửi:** nếu có chọn ảnh, vẽ ảnh lên `<canvas>` (trình duyệt tự xoay đúng chiều theo EXIF), thu nhỏ để cạnh dài ≤ 1600px, xuất JPEG chất lượng 0,9 rồi mới upload. Nếu canvas lỗi thì gửi file gốc (server vẫn xử lý được).
- Nhận dữ liệu qua `multipart/form-data`:
  - Email lấy **từ `$_SESSION['verified_email']`**, không lấy từ form; từ chối nếu không có hoặc `verified_until` đã quá hạn.
  - Ràng buộc dữ liệu:
    - `fullname`: bắt buộc, 2–120 ký tự, gộp khoảng trắng, viết hoa chữ cái đầu mỗi từ (`mb_convert_case(..., MB_CASE_TITLE, "UTF-8")`).
    - `graduation_year`: số nguyên từ `1990` đến `(năm hiện tại + 3)`.
    - `education`: bắt buộc, tối đa 3.000 ký tự.
    - `work`: tùy chọn, tối đa 3.000 ký tự.
    - `photo` (tùy chọn):
      - Dung lượng ≤ 5 MB; `getimagesize()` phải trả về `image/jpeg`, `image/png` hoặc `image/webp`.
      - **Chặn ảnh quá lớn trước khi giải nén:** từ chối nếu rộng × cao > 25 megapixel.
      - Giải nén bằng GD; tô **nền trắng** trước khi chép ảnh có kênh trong suốt (PNG/WebP), để tránh nền đen khi chuyển sang JPEG; xoay theo EXIF chỉ khi extension `exif` có sẵn (thường không cần vì trình duyệt đã xoay).
      - Thu nhỏ giữ tỷ lệ nếu rộng > 800px hoặc cao > 1000px; xuất JPEG chất lượng 85 với tên `u_<timestamp>_<random8>.jpg` trong `static/img/`. Không xóa ảnh cũ (giữ lịch sử).
      - Nếu không gửi ảnh mới và đã có hồ sơ cũ: kế thừa `photo_filename` của bản hiển thị hiện tại.
  - `INSERT` dòng mới vào `alumni_entries` (email đã chuẩn hóa, `submitter_ip`), xóa `verified_email`/`verified_until` khỏi session, tạo lại cache.
  - Gửi mail thông báo tới `ADMIN_EMAIL`: *hồ sơ mới* hoặc *cập nhật*, kèm họ tên, khóa, email, link tới thẻ hồ sơ và link `admin.php`. Nếu gửi mail lỗi, chỉ ghi log, không làm hỏng việc lưu hồ sơ.
  - Trả về `{ "ok": true, "fullname": "…", "year": 2010 }`. Frontend tính anchor bằng `makeAnchor(`${year}-${fullname}`)` và **tải lại trang** tới `?updated=<time>#<anchor>` (ảnh đã nằm trong cache trình duyệt, chỉ tốn thêm 1 hit), thay vì vá DOM tại chỗ.

**Trường hợp mất quyền truy cập email cũ:** người dùng dùng email mới sẽ tạo thành một thẻ mới; admin nhận mail thông báo và ẩn thẻ cũ trong `admin.php`.

### 5.4. Trang quản trị nhẹ (`admin.php`)

**Đăng nhập & chống dò mật khẩu:**
- Mật khẩu so khớp `ADMIN_PASSWORD_HASH` trong `includes/config.php` bằng `password_verify()`; khi đăng nhập thành công gọi `session_regenerate_id(true)`; mọi thao tác ghi dùng `POST` + CSRF token; cookie session đặt `Secure`, `HttpOnly`, `SameSite=Strict`.
- Mỗi lần thử đăng nhập được ghi vào `admin_login_attempts` (`ip`, `success`).
- **Làm chậm:** mỗi lần nhập sai, server `sleep()` tăng dần theo số lần sai của IP đó trong 15 phút gần nhất (1s, 2s, 4s…, tối đa 8s) rồi mới trả lời.
- **Giới hạn theo IP:** từ 5 lần sai trong 15 phút $\rightarrow$ IP đó bị khóa đăng nhập 15 phút (kể cả nhập đúng cũng bị từ chối trong thời gian khóa).
- **Giới hạn toàn cục** (chống đổi IP): từ 20 lần sai trong 1 giờ trên toàn hệ thống $\rightarrow$ khóa form đăng nhập 1 giờ.
- **Gửi mail cảnh báo tới `ADMIN_EMAIL`** mỗi khi một khóa (theo IP hoặc toàn cục) được kích hoạt — đúng 1 mail cho mỗi lần khóa, kèm IP, số lần sai và thời điểm mở khóa. Gửi thêm 1 mail báo **mỗi lần đăng nhập thành công** (IP + thời gian) để admin phát hiện truy cập lạ.
- Dọn rác: xóa các dòng `admin_login_attempts` cũ hơn 30 ngày khi có lượt đăng nhập.

**Chức năng:**
1. **Lịch sử bản ghi:** toàn bộ dòng `alumni_entries` theo thứ tự mới nhất, gắn nhãn *"Đang hiển thị"* (bản `MAX(id)` còn hiệu lực của email), *"Bản cũ"* hoặc *"Đã ẩn"*.
2. **Ẩn / Khôi phục (`is_deleted`):** 1 nút cho mỗi dòng; tạo lại cache ngay sau đó. Nếu bản mới nhất bị ẩn, bản hợp lệ ngay trước đó của email tự động được hiển thị lại.
3. **Làm mới cache thủ công:** dùng sau khi sửa tay trong phpMyAdmin.
4. **Tải bản sao lưu:** xuất toàn bộ dòng `alumni_entries` (kể cả bản cũ và bản đã ẩn) thành file JSON `alumni-backup-YYYY-MM-DD.json` có dạng `{ "exported_at": "…", "entries": [ <hợp đồng dữ liệu §5.2> + "id", "is_deleted" ] }` — cùng cấu trúc với `alumni.json`, nên `build_seed.py` tạo lại được `seed.sql` từ file này nếu cần khôi phục.

Không có nút import: dữ liệu khởi tạo đi qua `seed.sql` (§5.7).

### 5.5. Thay đổi phía frontend

- `index.php` = `index.html` hiện tại + `<script>window.__ALUMNI_DATA__ = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>` + markup `#contribute-modal`.
- `app.js` chỉ sửa đúng các chỗ:
  - `init()`: đọc `window.__ALUMNI_DATA__` thay vì `fetch('data/alumni.json')`; giữ `dedupByEmail` và `titleCase`.
  - `buildPhoto()`: dùng thẳng `e.photo`, chỉ giữ một fallback về `default.jpg` khi `onerror`; bỏ việc dò đuôi file và link Google Drive.
  - Thêm logic modal (3 bước, thu nhỏ ảnh bằng canvas, tải lại trang tới anchor).
- Nút **"Thêm / cập nhật hồ sơ"** và đoạn văn trong hộp thoại Hướng dẫn đang trỏ tới Google Form $\rightarrow$ đổi thành mở `#contribute-modal`.

### 5.6. `.htaccess`

- Ép HTTPS: chuyển hướng 301 mọi request `http://` sang `https://`.
- Chặn truy cập HTTP vào `includes/` và `storage/` (`Require all denied`). Đây là lớp phòng thủ thứ hai: `config.php` và cache `.php` khi bị gọi trực tiếp cũng chỉ chạy ra trang trắng.
- `AddDefaultCharset UTF-8`.
- Cache:
  - `static/img/`: `Cache-Control: public, max-age=31536000, immutable` (tên ảnh mới luôn là duy nhất).
  - `assets/css/`, `assets/js/`: cache 1 năm, **bắt buộc** gắn phiên bản trong URL: `style.css?v=<?= filemtime(...) ?>`, tương tự cho `text.js`, `app.js`.
  - `index.php`, `api/`, `admin.php`: `Cache-Control: no-store`.

### 5.7. Chuyển đổi dữ liệu & thời điểm chuyển (cutover)

1. **Kiểm chứng sớm** các rủi ro ở §6 bằng file test nhỏ trên InfinityFree, trước khi viết phần còn lại.
2. Cài SSL cho `ltt-alumni.page.gd` trong control panel InfinityFree; tạo database MySQL; upload code và tạo `includes/config.php`.
3. Tạo bảng (schema §5.2) qua phpMyAdmin.
4. **Đóng Google Form** (hoặc đổi lời nhắn sau khi gửi thành link tới site mới), rồi chạy `scripts/export_sheet.py` lần cuối để `data/alumni.json` chứa mọi bài gửi tới thời điểm đóng.
5. Chạy local `python scripts/build_seed.py` $\rightarrow$ `seed.sql`:
   - Sắp xếp các mục theo `timestamp` tăng dần (để `id` tăng theo thời gian).
   - `submitted_at` ← `timestamp` (giữ nguyên giờ Việt Nam); `email` ← `strtolower(trim())`; `year` → `graduation_year`.
   - `photo_filename` ← tìm `static/img/<picture_id>.<ext>` với các đuôi `jpg`, `jpeg`, `png`, `JPG`, `webp`; không thấy thì `NULL`.
   - Chạy **một lần** trong phpMyAdmin (bảng rỗng), không upload `data/alumni.json` lên server.
6. Upload `static/img/` qua FTP; mở `admin.php` bấm "Làm mới cache"; đối chiếu số hồ sơ hiển thị = 84.
7. Trên repo: tắt workflow `refresh-data.yml` (xóa hoặc bỏ lịch `schedule`), chuyển repo sang Private (GitHub Pages tự ngừng), cập nhật `README.md` trỏ tới `https://ltt-alumni.page.gd`.

### 5.8. Sao lưu

- **Định kỳ (gợi ý mỗi tháng, và trước mỗi lần deploy):** admin bấm "Tải bản sao lưu" trong `admin.php` và tải thư mục `static/img/` qua FTP; lưu cả hai ngoài InfinityFree (vd commit vào repo private hoặc Google Drive cá nhân).
- **Khôi phục:** `build_seed.py` nhận file backup JSON $\rightarrow$ `seed.sql` (giữ `id`, `is_deleted`, `submitted_at`) $\rightarrow$ import vào phpMyAdmin, upload lại `static/img/`.
- Không tự động hóa từ bên ngoài được do hệ thống chống bot của InfinityFree (§3).

---

## 6. Rủi ro cần kiểm chứng sớm (Spike trên InfinityFree trước khi xây dựng)

| Rủi ro | Cách kiểm chứng | Nếu thất bại |
|---|---|---|
| Gửi mail SMTP 587 STARTTLS qua Gmail | File PHP test dùng PHPMailer gửi 1 mail | Đổi nhà cung cấp SMTP (Brevo, Zoho…) cũng qua cổng 587 |
| `REMOTE_ADDR` là IP thật của khách | File PHP in `REMOTE_ADDR`, mở từ 2 mạng khác nhau | Chỉ giữ rate limit theo email + giới hạn toàn cục |
| `memory_limit`, GD hỗ trợ WebP/PNG | In `ini_get('memory_limit')`, `gd_info()` | Hạ ngưỡng megapixel; bỏ WebP khỏi danh sách chấp nhận |
| Phiên bản MySQL/MariaDB | `SELECT VERSION()` | Câu truy vấn §5.2 chạy được trên mọi bản ≥ 5.7 |
| Bản xem trước khi chia sẻ link Facebook/Zalo | Dán link vào Facebook Sharing Debugger | Chấp nhận không có bản xem trước (giới hạn của gói miễn phí) |

---

## 7. Những phần để người lập trình chủ động quyết định (Left to the Builder)

- Chi tiết giao diện CSS của hộp thoại `#contribute-modal` và trang `admin.php`, miễn sao tuân thủ đúng bảng màu và font chữ trong `DESIGN.md` (`--color-primary: #80182a`, `--font-serif`, `--font-sans`).
- Nội dung câu chữ chi tiết trong email OTP và email thông báo admin (song ngữ Pháp – Việt ngắn gọn, trang trọng).
- Các thông báo lỗi chi tiết trên form (bằng tiếng Việt rõ ràng, thân thiện).
- Giá trị cụ thể của các giới hạn (`OTP_DAILY_CAP`, ngưỡng khóa đăng nhập) đặt trong `config.php`, mặc định như spec.
