# Kỷ yếu lớp Pháp ngữ (bản PHP/MySQL)

Kỷ yếu cựu học sinh lớp Pháp ngữ, THPT Chuyên Lý Tự Trọng, Cần Thơ. Trang chạy
tại `https://12p-ltt.page.gd` (InfinityFree): xem hồ sơ theo khóa, tìm kiếm không
dấu, và **tự thêm / cập nhật hồ sơ** qua email + mã OTP (thay cho Google Form).

- `index.php` đọc danh sách hồ sơ mới nhất từ cache `storage/cache/alumni_latest.php`
  (nếu chưa có thì truy vấn MySQL rồi ghi cache) và nhúng vào `window.__ALUMNI_DATA__`:
  lượt xem bình thường không mở MySQL, không session, không request JSON thêm.
- Gửi hồ sơ: `api/request_otp.php` (phép tính + gửi OTP) → `api/verify_otp.php` →
  `api/submit_profile.php` (xử lý ảnh bằng GD, lưu MySQL, làm mới cache, báo admin).
- `admin.php`: đăng nhập có giới hạn số lần, ẩn/khôi phục bản ghi, làm mới cache, tải sao lưu JSON.
- Thiết kế: [DESIGN.md](DESIGN.md), [PRODUCT.md](PRODUCT.md); kế hoạch và spec trong `docs/superpowers/`.

## Triển khai (chỉ upload những mục sau vào `htdocs/`)

```text
.htaccess
index.php
admin.php
api/
includes/          (kèm config.php thật; có PHPMailer/)
storage/cache/     (thư mục rỗng, phải ghi được)
assets/
static/img/
```

**Không bao giờ upload:** `data/`, `seed.sql`, `sql/`, `scripts/`, `tests/`, `docs/`, `.github/`.
`scripts/spike_check.php` chỉ dùng để kiểm tra host một lần: **tuyệt đối không để nó lại trên server**
(xóa ngay sau khi chạy xong).

## Cấu hình

1. Sao chép `includes/config.example.php` thành `includes/config.php` và điền: MySQL
   (`db_*`), SMTP Gmail (`smtp_*`, dùng App Password, cổng 587 STARTTLS), `admin_email`,
   `admin_password_hash`, `otp_daily_cap`, `site_url`.
2. Tạo mã băm mật khẩu admin: `php -r "echo password_hash('mat-khau', PASSWORD_DEFAULT);"`.
3. `includes/config.php`, `seed.sql`, `storage/cache/*.php` đã nằm trong `.gitignore`: không commit.

## Cơ sở dữ liệu & dữ liệu ban đầu

1. phpMyAdmin → chạy `sql/schema.sql`.
2. Tạo seed từ dữ liệu cũ: `python scripts/build_seed.py` (ra `seed.sql`), rồi chạy file này trong phpMyAdmin.
3. Upload ảnh trong `static/img/` (gồm `default.jpg`), vào `admin.php` bấm "Làm mới cache".

## Sao lưu & khôi phục

- Sao lưu: `admin.php` → tải file JSON, và sao chép thư mục `static/img/` qua FTP.
- Khôi phục: `python scripts/build_seed.py <backup.json> restore.sql`, chạy `restore.sql` trong
  phpMyAdmin trên bảng `alumni_entries` **rỗng** (giữ nguyên `id` và `is_deleted`), rồi làm mới cache.

## Chạy thử ở máy local

Cần PHP 8.x có `gd`, `pdo_sqlite`, `mbstring`, `exif`. Không cần MySQL:

```bash
python scripts/build_seed.py
php scripts/dev_cache.php          # dựng storage/cache/alumni_latest.php từ seed.sql (SQLite in-memory)
php -S 127.0.0.1:8000
```

Mở `http://127.0.0.1:8000/`. Xem, lọc, tìm kiếm hoạt động đầy đủ; các bước gửi OTP/hồ sơ cần MySQL
và SMTP thật nên chỉ kiểm tra được trên host. Kiểm tra logic: `php tests/check_repository.php`,
`php tests/check_image.php`, `python -m unittest tests.test_build_seed`.

## Thư viện

PHPMailer **v7.2.0**, bản rời (không Composer) trong `includes/PHPMailer/`
(`PHPMailer.php`, `SMTP.php`, `Exception.php`, `LICENSE`).

## Góp ý

Mọi chỉnh sửa, góp ý vui lòng [gửi email](mailto:giahung24@gmail.com).
