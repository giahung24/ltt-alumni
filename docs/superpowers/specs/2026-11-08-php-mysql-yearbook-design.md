# Thiết kế phiên bản PHP/MySQL — Kỷ yếu lớp Pháp (`ltt-alumni.page.gd`)

- **Ngày:** 2026-11-08
- **Branch:** `php-mysql`
- **Môi trường đích:** InfinityFree Free Hosting (`ltt-alumni.page.gd`), PHP 8.x + MySQL/MariaDB

---

## 1. Mục đích & Bối cảnh (Intent & Why)

Hiện tại, trang kỷ yếu cựu học sinh lớp Pháp ngữ THPT Chuyên Lý Tự Trọng Cần Thơ (`main`) là một ứng dụng tĩnh (HTML/CSS/JS) đọc dữ liệu từ `data/alumni.json` và đồng bộ hàng tuần qua GitHub Actions từ Google Form/Sheet + Google Drive.

Cách vận hành cũ gặp 3 hạn chế lớn:
1. **Tên miền GitHub Pages:** Đường dẫn có dạng `acc_name.github.io/ltt-alumni` thay vì tên miền riêng gọn đẹp như `ltt-alumni.page.gd`.
2. **Bảo mật kho mã nguồn và dữ liệu:** Dùng GitHub Pages miễn phí buộc repository phải để **Public**, làm lộ toàn bộ file `data/alumni.json` (chứa email, lịch sử học tập, công việc) trên GitHub.
3. **Độ trễ và phụ thuộc Google Form/Drive:** Cựu học sinh cập nhật xong phải đợi cron chạy mới lên web, và việc tải ảnh trên Google Form yêu cầu đăng nhập tài khoản Google.

Phiên bản **PHP/MySQL trên InfinityFree** giải quyết triệt để cả 3 vấn đề: chạy trên tên miền `ltt-alumni.page.gd`, cho phép giữ GitHub repository ở chế độ **Private**, lưu trữ và xử lý hồ sơ trực tiếp bằng PHP/MySQL, đồng thời cho phép cựu học sinh thêm/cập nhật hồ sơ và ảnh chân dung ngay trên web với xác thực OTP qua email.

---

## 2. Mục tiêu, Phi mục tiêu & Điều cần tránh (Goals, Non-Goals & Anti-Goals)

### Goals (Mục tiêu)
- **Giữ nguyên 100% thiết kế & trải nghiệm tra cứu *L'Édition Classique*:** Bảo toàn toàn bộ giao diện, bảng màu, hệ thống chữ hai giọng (`Source Serif 4` & `Be Vietnam Pro`), thanh lọc theo khóa, tìm kiếm không dấu tức thì, ngăn kéo danh sách (`index-drawer`), nút bật/tắt hiển thị email và hiển thị dòng thời gian (`Formation` / `Profession`).
- **Tự chủ hoàn toàn luồng Thêm / Cập nhật hồ sơ trên web:** Thay thế Google Form bằng hộp thoại (Modal) 2 bước ngay trên trang chính:
  1. Nhập email + giải phép tính ngẫu nhiên chống bot $\rightarrow$ nhận mã OTP 6 chữ số qua email.
  2. Xác thực OTP $\rightarrow$ nếu email đã tồn tại trong kỷ yếu thì **tự động điền sẵn (auto-fill)** thông tin và ảnh hiện tại để người dùng sửa; nếu chưa có thì hiển thị form trống $\rightarrow$ lưu bản ghi mới vào MySQL và hiển thị ngay lập tức.
- **Lưu lịch sử các bản ghi (Append-only history):** Mọi lần gửi mới đều được `INSERT` thành dòng mới trong bảng MySQL (không ghi đè/xóa dòng cũ). Khi hiển thị chỉ lấy bản ghi mới nhất theo từng `email` (dựa trên `submitted_at` và `id` lớn nhất).
- **Xử lý ảnh chân dung trực tiếp bằng PHP:** Khi người dùng tải ảnh mới lên, PHP kiểm tra định dạng thực, tự xoay theo EXIF, thu nhỏ/nén về kích thước chuẩn (chiều rộng tối đa `800px`, JPEG chất lượng 85%, ~40–70 KB) và lưu vào `static/img/`.
- **Tối ưu đặc biệt cho giới hạn của InfinityFree:** Sử dụng cơ chế nhúng dữ liệu kèm **File Cache nội bộ** để các lượt truy cập thông thường không cần mở kết nối MySQL và không tốn thêm HTTP request phụ.
- **Trang quản trị nhẹ (`admin.php`):** Cho phép quản trị viên đăng nhập bằng mật khẩu để xem lịch sử các lần gửi, xóa/ẩn bản ghi rác (tự động khôi phục về bản ghi hợp lệ trước đó của cùng email), và import dữ liệu khởi tạo từ `data/alumni.json`.

### Non-Goals (Phi mục tiêu)
- Không xây dựng hệ thống tài khoản có mật khẩu, trang cá nhân riêng hay mạng xã hội (bình luận, nhắn tin).
- Không sử dụng framework nặng (Laravel, Symfony, React/Vue) hay công cụ build phức tạp; triển khai bằng cách upload trực tiếp qua FTP lên `htdocs/` của InfinityFree.
- Không tiếp tục đồng bộ hai chiều với Google Sheet hay Google Drive sau khi đã chuyển dữ liệu sang MySQL.

### Anti-Goals (Điều tuyệt đối tránh)
- **Không để lộ file cấu hình, dữ liệu thô hoặc cache ra URL công khai:** Các file `config.php`, file cache nội bộ hoặc bản sao `alumni.json` không được phép truy cập trực tiếp từ trình duyệt.
- **Không mở kết nối MySQL trên mỗi lượt tải trang nếu cache còn hiệu lực:** Tránh lỗi `max_user_connections` trên gói miễn phí của InfinityFree khi nhiều người cùng mở link kỷ yếu.
- **Không cho phép ghi đè thông tin của email đã có nếu chưa xác thực OTP của chính email đó.**

---

## 3. Ràng buộc kỹ thuật của InfinityFree (Constraints)

Từ thông số gói **InfinityFree** (`Plan: infinityfree`):
- **Disk Quota:** 5 GB (dư sức cho hàng ngàn hồ sơ và ảnh đã nén ~50 KB/ảnh).
- **Inodes:** Giới hạn `80.000` files/folders $\rightarrow$ không dùng thư viện `vendor/` có hàng chục ngàn file nhỏ; viết PHP thuần gọn nhẹ, chỉ dùng đúng class SMTP đơn lẻ.
- **Daily Hits:** Giới hạn `50.000 hits/ngày` (mỗi file HTML, CSS, JS, ảnh được trình duyệt tải tính là 1 hit) $\rightarrow$ cần:
  - Nhúng thẳng dữ liệu danh bạ vào `index.php` (tiết kiệm 1 hit gọi API JSON mỗi lần mở trang).
  - Bật `loading="lazy"` cho ảnh chân dung và cấu hình header `Cache-Control` dài hạn trong `.htaccess` cho `static/img/`, `assets/css/`, `assets/js/` để khách quay lại không tốn thêm hits.
  - Chỉ lưu **1 định dạng duy nhất (`.jpg`)** cho ảnh mới hoặc trả sẵn đường dẫn ảnh chính xác từ server để trình duyệt không phải thử lần lượt 5 đuôi `.jpg`, `.jpeg`, `.png`, `.JPG`, `.webp` gây lỗi 404 (mỗi lỗi 404 cũng bị tính là 1 hit!).
- **MySQL:** Chỉ cho phép kết nối từ nội bộ script PHP trên host (`sqlXXX.infinityfree.com`), không cho kết nối từ máy ngoài.
- **Hàm `mail()` bị khóa:** InfinityFree vô hiệu hóa `mail()` mặc định của PHP $\rightarrow$ bắt buộc gửi email OTP qua giao thức **SMTP có xác thực (TLS/SSL cổng 587 hoặc 465)** sử dụng Gmail App Password (hoặc SMTP tương đương).
- **Giới hạn kích thước file:** Tối đa `10 MB`/file trên host $\rightarrow$ giới hạn file ảnh tải lên ở mức `5 MB` ở cả client và server.

---

## 4. Các phương án kiến trúc đã cân nhắc (Approaches)

### Phương án 1 — Render kết hợp (Inline Data + Cache nội bộ) & Lọc tức thì ở Client *(Đã chọn)*
- `index.php` đọc danh sách hồ sơ mới nhất từ file cache nội bộ `storage/cache/alumni_latest.json` (nếu chưa có cache mới truy vấn MySQL rồi ghi ra cache) và nhúng trực tiếp vào `window.__ALUMNI_DATA__`.
- Toàn bộ thao tác lọc theo khóa, tìm kiếm không dấu, mở danh sách nhanh và bật/tắt email diễn ra tức thì trên trình duyệt bằng JS mà không tốn thêm kết nối MySQL hay HTTP hits.
- Khi có hồ sơ mới gửi qua API hoặc Admin xóa/sửa bản ghi, PHP tự động xóa/cập nhật lại file cache.

### Phương án 2 — Client Fetch API trực tiếp từ MySQL mỗi lượt tải
- Giữ nguyên `fetch('api/alumni.php')` và truy vấn MySQL ở mọi lượt mở trang.
- *Loại bỏ vì:* Tốn gấp đôi số request PHP mỗi lần mở trang và dễ chạm giới hạn kết nối MySQL đồng thời của InfinityFree.

### Phương án 3 — Server-Side Rendering (SSR) thuần bằng PHP mỗi lần lọc/tìm kiếm
- *Loại bỏ vì:* Mỗi lần đổi khóa hoặc tìm kiếm phải tải lại trang, làm tăng vọt Daily Hits và mất trải nghiệm lọc tức thì.

---

## 5. Thiết kế chi tiết (Detailed Design)

### 5.1. Cấu trúc thư mục dự kiến

```text
/ (upload lên thư mục htdocs/ của InfinityFree)
├── .htaccess                  # Bảo vệ thư mục nhạy cảm, cấu hình cache tĩnh, UTF-8
├── index.php                  # Trang chính (giao diện kỷ yếu + modal cập nhật hồ sơ + hướng dẫn)
├── admin.php                  # Trang quản trị nhẹ (xem lịch sử, xóa bản ghi rác, import JSON)
├── api/
│   ├── request_otp.php        # POST: Kiểm tra phép tính + rate limit, tạo & gửi OTP qua SMTP
│   ├── verify_otp.php         # POST: Xác thực OTP, cấp token phiên sửa & trả dữ liệu cũ (nếu có)
│   └── submit_profile.php     # POST (multipart): Kiểm tra token, xử lý ảnh, INSERT MySQL & làm mới cache
├── includes/
│   ├── config.example.php     # Mẫu cấu hình (DB, SMTP, Admin Password)
│   ├── config.php             # Cấu hình thật trên server (nằm trong .gitignore, KHÔNG commit lên git)
│   ├── db.php                 # Kết nối PDO MySQL chuẩn UTF8MB4
│   ├── repository.php         # Truy vấn dữ liệu, khử trùng lặp theo email, đọc/ghi cache nội bộ
│   ├── image.php              # Kiểm tra MIME, xoay EXIF, resize/nén JPEG bằng PHP GD
│   └── mailer.php             # Gửi email OTP qua SMTP (TLS/SSL socket thuần PHP không cần Composer)
├── storage/                   # Chặn truy cập HTTP trực tiếp bằng .htaccess
│   └── cache/
│       └── alumni_latest.json # Bản cache danh sách hiển thị
├── assets/
│   ├── css/
│   │   └── style.css          # Giữ nguyên style L'Édition Classique + bổ sung CSS cho Modal Form
│   └── js/
│       ├── text.js            # Giữ nguyên xử lý chuỗi, anchor và timeline
│       └── app.js             # Đọc window.__ALUMNI_DATA__, giữ nguyên bộ lọc + thêm xử lý Modal OTP
├── static/
│   └── img/                   # Chứa default.jpg, ~75 ảnh hiện có và các ảnh upload mới
└── data/
    └── alumni.json            # Giữ trong repo private để khởi tạo/import ban đầu (chặn HTTP trong .htaccess)
```

### 5.2. Thiết kế Cơ sở dữ liệu (MySQL Schema)

Sử dụng bộ mã `utf8mb4_unicode_ci` để hỗ trợ đầy đủ tiếng Việt và tiếng Pháp:

```sql
CREATE TABLE IF NOT EXISTS alumni_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    fullname VARCHAR(191) NOT NULL,
    graduation_year SMALLINT UNSIGNED NOT NULL,
    education TEXT NOT NULL,
    work TEXT NULL,
    photo_filename VARCHAR(255) NULL,     -- Tên file thực tế trong static/img/ (vd: "1w7dAK...jpg" hoặc "u_a8f9...jpg")
    picture_link VARCHAR(500) NULL,       -- Giữ lại link Drive cũ từ dữ liệu lịch sử (nếu có) để tham khảo
    facebook VARCHAR(500) NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitter_ip VARCHAR(45) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_email_latest (email, is_deleted, submitted_at, id),
    INDEX idx_year (graduation_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    verify_token VARCHAR(64) NULL,        -- Sinh ra sau khi verify OTP thành công để dùng cho bước submit
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requester_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_email_active (email, expires_at),
    INDEX idx_ip_rate (requester_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Quy tắc lấy danh sách hiển thị (Deduplication theo Email):**
- Chỉ xét các bản ghi có `is_deleted = 0`.
- Với mỗi `LOWER(TRIM(email))`, chọn duy nhất 1 bản ghi có `(submitted_at DESC, id DESC)` lớn nhất.
- Server kiểm tra sẵn file ảnh tương ứng trong `static/img/` và trả về trường `photo` là đường dẫn tương đối chính xác (ví dụ `static/img/1w7dAKcobZ2s6yQnjMAcQejhK2nDb0bj6.jpg` hoặc `static/img/default.jpg`). Nhờ vậy trình duyệt tải đúng 1 file ảnh duy nhất, **loại bỏ hoàn toàn các request 404 thử đuôi ảnh** trên bản tĩnh cũ.

### 5.3. Luồng Thêm / Cập nhật hồ sơ (2 bước có OTP & Phép tính ngẫu nhiên)

Khi người dùng bấm nút **"Thêm / cập nhật hồ sơ"** trên thanh công cụ (hoặc trong hộp thoại Hướng dẫn), hộp thoại `#contribute-modal` mở ra:

#### Bước 1: Xác minh Email & Chống Bot (`api/request_otp.php`)
- Khi render `index.php` (hoặc khi mở modal), PHP khởi tạo trong `$_SESSION` một phép tính số học đơn giản ngẫu nhiên (ví dụ: `7 + 5 = ?` hoặc `14 - 6 = ?`) kèm trường ẩn honeypot (`website_url`).
- Người dùng nhập:
  - `email` (bắt buộc, đúng định dạng email).
  - Đáp án phép tính chống bot.
- Server kiểm tra:
  - Honeypot phải trống; đáp án phép tính phải khớp với `$_SESSION` (sau khi kiểm tra lập tức đổi phép tính mới để chống replay).
  - **Rate limit:** Tối đa 3 yêu cầu OTP / 10 phút cho cùng 1 email, và tối đa 10 yêu cầu / 15 phút cho cùng 1 địa chỉ IP.
- Nếu hợp lệ:
  - Tạo mã OTP 6 chữ số ngẫu nhiên (`random_int(100000, 999999)`), lưu bản băm (`password_hash`) vào bảng `email_otps` với thời hạn `10 phút`.
  - Gửi email tiêu đề `[Kỷ yếu lớp Pháp LTT] Mã xác thực của bạn: XXXXXX` qua SMTP.

#### Bước 2: Nhập OTP & Tự động điền thông tin cũ (`api/verify_otp.php`)
- Người dùng nhập mã OTP 6 chữ số.
- Server kiểm tra:
  - Nếu sai quá 5 lần cho cùng mã OTP $\rightarrow$ hủy mã đó, yêu cầu lấy mã mới.
  - Nếu đúng và chưa hết hạn $\rightarrow$ sinh `verify_token` ngẫu nhiên (32 bytes hex, có hiệu lực 30 phút), lưu vào dòng `email_otps` và lưu vào `$_SESSION`.
  - Truy vấn bảng `alumni_entries` xem `email` này đã có bản ghi hợp lệ (`is_deleted = 0`) mới nhất nào chưa.
  - Trả về JSON: `{ verified: true, verify_token: "...", existing_profile: { fullname, year, education, work, photo_url } | null }`.
- Giao diện chuyển sang màn hình nhập thông tin:
  - Nếu `existing_profile` khác `null`, tự động điền sẵn **Họ và tên**, **Năm tốt nghiệp (Khóa)**, **Quá trình học tập (Formation)**, **Công việc (Profession)** và hiển thị ảnh chân dung hiện tại (kèm ghi chú: *"Giữ nguyên ảnh hiện tại nếu bạn không chọn ảnh mới"*).
  - Nếu là hồ sơ mới, các trường để trống.

#### Bước 3: Lưu hồ sơ & Xử lý ảnh (`api/submit_profile.php`)
- Nhận dữ liệu qua `multipart/form-data`:
  - Kiểm tra `verify_token` khớp với `email` và chưa được sử dụng (`used_at IS NULL`).
  - Ràng buộc dữ liệu:
    - `fullname`: bắt buộc, 2–120 ký tự, chuẩn hóa khoảng trắng và viết hoa chữ cái đầu mỗi từ (`mb_convert_case(..., MB_CASE_TITLE, "UTF-8")`).
    - `graduation_year`: số nguyên từ `1990` đến `(năm hiện tại + 3)`.
    - `education`: bắt buộc, tối đa 3.000 ký tự.
    - `work`: tùy chọn, tối đa 3.000 ký tự.
    - `photo` (file upload tùy chọn):
      - Nếu có tải file mới lên: kiểm tra dung lượng $\le 5\text{ MB}$, kiểm tra nội dung thực bằng `getimagesize()` / `finfo_file()` chỉ chấp nhận `image/jpeg`, `image/png`, `image/webp`.
      - Dùng thư viện PHP `GD` xoay ảnh đúng chiều theo EXIF (`exif_read_data` nếu có), thu nhỏ theo tỷ lệ nếu chiều rộng $> 800\text{px}$ (hoặc chiều cao $> 1000\text{px}$), xuất ra file JPEG chuẩn (chất lượng 85) với tên file duy nhất `u_<timestamp>_<random8>.jpg` trong `static/img/`. (Không xóa file ảnh cũ để bảo toàn lịch sử).
      - Nếu không tải ảnh mới lên và đã có hồ sơ cũ: kế thừa `photo_filename` (và `picture_link`) của bản ghi mới nhất trước đó.
  - Thực hiện `INSERT` dòng mới vào `alumni_entries`, đánh dấu `used_at = NOW()` cho dòng OTP, gọi hàm tạo lại cache `storage/cache/alumni_latest.json`, và trả về danh sách hồ sơ mới nhất để frontend cập nhật ngay lên màn hình và cuộn tới đúng thẻ của người dùng.

### 5.4. Trang Quản trị nhẹ (`admin.php`)

- Đăng nhập bằng mật khẩu quản trị cấu hình trong `includes/config.php` (`ADMIN_PASSWORD_HASH`), sử dụng session PHP + CSRF token.
- Các chức năng chính:
  1. **Danh sách lịch sử bản ghi:** Hiển thị toàn bộ các dòng trong `alumni_entries` theo thứ tự thời gian mới nhất (kèm nhãn *"Đang hiển thị"* cho bản ghi mới nhất của mỗi email, hoặc *"Bản cũ"*, hoặc *"Đã ẩn"*).
  2. **Ẩn / Khôi phục bản ghi (`is_deleted`):** Cho phép bấm 1 nút để ẩn bản ghi bị lỗi/spam (hoặc khôi phục lại). Ngay sau khi bấm, hệ thống tự động tạo lại file cache — nếu bản mới nhất bị ẩn, bản hợp lệ ngay trước đó của email đó sẽ tự động được hiển thị lại trên trang chủ.
  3. **Công cụ Khởi tạo / Import từ `data/alumni.json`:** Nút bấm cho phép đọc file `data/alumni.json` sẵn có, ánh xạ từng mục sang bảng `alumni_entries` (tự động dò file ảnh tương ứng trong `static/img/<picture_id>.<ext>` để điền vào cột `photo_filename`), giúp chuyển toàn bộ ~75 hồ sơ hiện tại vào MySQL chỉ bằng 1 click sau khi đưa code lên InfinityFree (tránh trùng lặp nếu bấm nhiều lần).
  4. **Nút làm mới Cache thủ công:** Xóa và tạo lại `storage/cache/alumni_latest.json`.

---

## 6. Những phần để người lập trình chủ động quyết định (Left to the Builder)

- Chi tiết giao diện CSS của hộp thoại `#contribute-modal` và trang `admin.php`, miễn sao tuân thủ đúng bảng màu và font chữ trong `DESIGN.md` (`--color-primary: #80182a`, `--font-serif`, `--font-sans`).
- Cấu trúc chi tiết của class gửi mail SMTP thuần PHP trong `includes/mailer.php` (hỗ trợ `STARTTLS` cổng 587 và `SMTPS` cổng 465 cho Gmail SMTP).
- Nội dung câu chữ chi tiết trong email OTP (song ngữ Pháp – Việt ngắn gọn, trang trọng).
- Các thông báo lỗi chi tiết trên form (bằng tiếng Việt rõ ràng, thân thiện).
