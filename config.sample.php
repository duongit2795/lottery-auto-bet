<?php
// Copy file này thành config.php (hoặc chạy install.php để tạo tự động).
return [
    // 'sqlite' (mặc định, không cần cài gì) hoặc 'mysql'
    'db_driver' => 'sqlite',
    'sqlite_path' => __DIR__ . '/data/bot.sqlite',

    'mysql_host' => '127.0.0.1',
    'mysql_port' => 3306,
    'mysql_db'   => 'simbot',
    'mysql_user' => 'root',
    'mysql_pass' => '',

    // Chuỗi bí mật dùng để xác thực webhook Telegram & cron qua URL
    'secret' => 'change-me-to-a-random-string',
];
