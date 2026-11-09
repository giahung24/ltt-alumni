<?php
// Copy to includes/config.php and fill in. NEVER commit config.php (gitignored).
return [
    // InfinityFree panel > MySQL Databases (host looks like sqlXXX.infinityfree.com)
    'db_host' => 'sqlXXX.infinityfree.com',
    'db_name' => 'if0_00000000_yearbook',
    'db_user' => 'if0_00000000',
    'db_pass' => 'CHANGE_ME',

    // Gmail SMTP. smtp_pass = Gmail App Password (Google Account > Security > 2-Step Verification > App passwords)
    'smtp_host'      => 'smtp.gmail.com',
    'smtp_port'      => 587,
    'smtp_user'      => 'you@gmail.com',
    'smtp_pass'      => 'xxxx xxxx xxxx xxxx',
    'smtp_from'      => 'you@gmail.com',
    'smtp_from_name' => 'Kỷ yếu lớp Pháp LTT',

    // Admin login. Generate the hash with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_email'         => 'admin@example.com',
    'admin_password_hash' => '$2y$10$REPLACE_WITH_REAL_HASH',

    'otp_daily_cap' => 100,                          // max OTP emails per day (Gmail limit safety)
    'site_url'      => 'https://12p-ltt.page.gd',
];
