<?php
/**
 * Cấu hình WebUpdater — chép file này thành config.php rồi sửa các dòng có dấu ✏️.
 */
return [
    // ✏️ Mật khẩu vào trang cập nhật. Khuyên dùng password_hash (an toàn hơn):
    //    tạo bằng lệnh: php -r "echo password_hash('mat-khau-cua-ban', PASSWORD_DEFAULT);"
    //    hoặc mở trang cập nhật lần đầu, trang sẽ gợi ý mã hash cho bạn.
    'password_hash' => '',
    'password' => '',          // hoặc ghi mật khẩu thường ở đây (kém an toàn hơn)

    // Thư mục gốc của dự án (mặc định: thư mục cha của module). Thường không cần sửa.
    'root' => dirname(__DIR__),

    // ✏️ Lấy bản mới từ GitHub
    'github' => [
        'repo' => 'ten-tai-khoan/ten-repo',   // ví dụ: duongit2795/lottery-auto-bet
        'branch' => 'main',
        'token' => '',                        // chỉ cần khi repo Private (quyền Contents: Read-only)
    ],

    // ✏️ Đường dẫn KHÔNG BAO GIỜ bị ghi đè khi cập nhật (tính từ thư mục gốc dự án).
    //    Thêm file cấu hình, thư mục upload, dữ liệu... của dự án vào đây.
    'keep' => ['config.php', '.env', 'data', 'uploads', '.git'],

    // ✏️ File bắt buộc phải có trong gói cập nhật — để chắc chắn bạn không upload nhầm zip.
    'required' => ['index.php'],

    // Mở thử trang này sau khi cập nhật; lỗi 500 → tự khôi phục bản cũ. Để trống = bỏ qua.
    'health_url' => '',        // ví dụ: 'https://ten-mien.com/'

    'max_backups' => 5,        // số bản sao lưu giữ lại
    'lint' => true,            // kiểm tra lỗi cú pháp PHP trước khi cập nhật (nếu hosting cho phép)

    // Việc cần làm sau mỗi lần cập nhật thành công (vd: chạy migrate database). Để null nếu không cần.
    'after_update' => null,
    // ví dụ: 'after_update' => function () { require dirname(__DIR__) . '/migrate.php'; },
];
