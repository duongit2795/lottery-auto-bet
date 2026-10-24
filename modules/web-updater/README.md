# WebUpdater — Cập nhật website PHP bằng 1 nút bấm

Module nhỏ, chép vào bất kỳ dự án PHP nào. Dùng để cập nhật mã nguồn ngay trên trình duyệt (kể cả điện thoại), không cần vào aaPanel / FTP / SSH.

- ☁️ **Cập nhật từ GitHub**: tải bản mới nhất của 1 nhánh và cập nhật.
- 📦 **Cập nhật bằng file zip**: upload file zip mã nguồn.
- 🗂 **Tự sao lưu** trước mỗi lần cập nhật (giữ 5 bản). Khôi phục bằng 1 nút.
- 🛡 **An toàn**:
  - Kiểm tra đúng mã nguồn và lỗi cú pháp trước khi chép.
  - Không ghi đè cấu hình và dữ liệu.
  - Web lỗi sau cập nhật thì tự quay về bản cũ.
- 🔐 Trang có mật khẩu riêng, chống dò mật khẩu, có CSRF.

Không cần framework, không cần database. Yêu cầu: **PHP ≥ 8.0**, extension **zip** và **curl**.

---

## 1. Cài vào dự án (3 bước)

**Bước 1 — Chép thư mục** `web-updater` vào thư mục gốc dự án và đổi tên thành `updater`:

```
du-an-cua-ban/
├── index.php
├── config.php          ← cấu hình của dự án (sẽ được giữ nguyên)
├── ...
└── updater/            ← module này
    ├── index.php       ← trang cập nhật
    ├── Updater.php     ← phần xử lý
    ├── config.sample.php
    └── .htaccess
```

**Bước 2 — Tạo cấu hình:** chép `updater/config.sample.php` thành `updater/config.php` rồi sửa các dòng có dấu ✏️:

```php
'password' => 'mat-khau-manh-cua-ban',
'github' => ['repo' => 'ten-tai-khoan/ten-repo', 'branch' => 'main', 'token' => ''],
'keep' => ['config.php', '.env', 'data', 'uploads', '.git'],
'required' => ['index.php'],
'health_url' => 'https://ten-mien.com/',
```

**Bước 3 — Mở** `https://ten-mien.com/updater/`, đăng nhập rồi bấm **Cập nhật từ GitHub**.

> aaPanel cần cấp quyền ghi cho thư mục web: **Files → chọn thư mục → Permission → owner `www`, tick áp dụng cho thư mục con**. Chưa có extension zip thì vào **App Store → PHP → Settings → Install extensions → zip**.

---

## 2. Giải thích cấu hình

| Khóa | Ý nghĩa | Ví dụ |
|---|---|---|
| `password_hash` / `password` | Mật khẩu vào trang. Ưu tiên `password_hash` vì an toàn hơn | `php -r "echo password_hash('abc', PASSWORD_DEFAULT);"` |
| `root` | Thư mục gốc dự án. Mặc định là thư mục cha của `updater/` | `dirname(__DIR__)` |
| `github.repo` | Repo GitHub chứa mã nguồn | `duongit2795/lottery-auto-bet` |
| `github.branch` | Nhánh lấy bản mới | `main` |
| `github.token` | Chỉ cần khi repo **Private**. Tạo ở GitHub → Settings → Developer settings → Fine-grained tokens, quyền **Contents: Read-only** | `github_pat_...` |
| `keep` | File/thư mục **không bao giờ bị ghi đè** | `['config.php', 'data', 'uploads']` |
| `required` | File **bắt buộc có** trong gói, để không upload nhầm zip | `['index.php', 'src/App.php']` |
| `health_url` | Trang mở thử sau khi cập nhật. Lỗi 500 thì tự khôi phục. Để trống là bỏ qua | `https://ten-mien.com/` |
| `max_backups` | Số bản sao lưu giữ lại | `5` |
| `lint` | Kiểm tra lỗi cú pháp PHP trước khi cập nhật (nếu hosting cho chạy `exec`) | `true` |
| `after_update` | Hàm chạy sau khi cập nhật thành công, ví dụ migrate database | `function () { require dirname(__DIR__).'/migrate.php'; }` |

`updater/config.php` và `updater/data/` luôn được giữ nguyên tự động.

---

## 3. Cách hoạt động

```
Bấm "Cập nhật"
   │
   ├─ 1. Tải zip từ GitHub (hoặc nhận file upload)
   ├─ 2. Giải nén vào updater/data/tmp-xxxx
   ├─ 3. Có đủ file trong `required`? ── không ──► Hủy, không đổi gì
   ├─ 4. Lỗi cú pháp PHP? ──────────────── có ───► Hủy, không đổi gì
   ├─ 5. Sao lưu code hiện tại → updater/data/backups/backup-YYYYMMDD-HHMMSS.zip
   ├─ 6. Chép đè file mới (bỏ qua `keep`, chỉ ghi file có thay đổi)
   ├─ 7. Mở thử `health_url` ──────── lỗi 500 ──► Tự khôi phục bản sao lưu
   └─ 8. Chạy `after_update`, ghi phiên bản + lịch sử
```

Lưu ý: file **bị xóa** ở bản mới vẫn còn trên server. Updater chỉ thêm và sửa file, không xóa, để an toàn.

---

## 4. Dùng trong code (không qua giao diện)

```php
require __DIR__ . '/updater/Updater.php';
$u = new WebUpdater(require __DIR__ . '/updater/config.php');

$u->latest();                    // ['sha','short','message','date'] của commit mới nhất
$u->updateFromGithub();          // ['ok' => true/false, 'message' => '...']
$u->applyZip('/tmp/ban-moi.zip', 'Bản 1.2');
$u->backup();                    // tạo bản sao lưu, trả về đường dẫn file
$u->backups();                   // danh sách bản sao lưu
$u->restore('backup-20261024-101706.zip');
$u->state();                     // phiên bản đang chạy
$u->history();                   // lịch sử cập nhật
```

Muốn gắn vào trang admin sẵn có của dự án: bỏ qua `index.php` của module, tự làm nút bấm, rồi gọi các hàm trên. Phần đăng nhập dùng của dự án.

---

## 5. Bảo mật

- Đặt **mật khẩu mạnh**. Ai vào được trang này là thay được toàn bộ mã nguồn.
- `.htaccess` đã chặn truy cập trực tiếp `config.php`, `Updater.php` và thư mục `data/` (Apache).
- Dùng **Nginx** thì thêm vào cấu hình site:
  ```nginx
  location ~ ^/updater/(data/|config\.php|config\.sample\.php|Updater\.php|README\.md) { deny all; }
  ```
- File zip có đường dẫn kiểu `../` sẽ bị từ chối.

---

## 6. Lỗi thường gặp

| Thông báo | Cách xử lý |
|---|---|
| Hosting chưa bật extension "zip" | aaPanel → App Store → PHP → Settings → Install extensions → **zip** |
| Thư mục ... không ghi được | Cấp quyền ghi cho user chạy PHP (aaPanel: owner `www`) |
| Không đọc được GitHub: Not Found | Sai `repo`/`branch`, hoặc repo Private mà chưa có `token` |
| File zip không đúng mã nguồn dự án | Zip thiếu các file trong `required`. Kiểm tra lại file hoặc cấu hình |
| Upload lỗi / file quá lớn | Tăng `upload_max_filesize` và `post_max_size` trong cấu hình PHP |
| Web lỗi → đã tự khôi phục | Bản mới có lỗi khi chạy. Sửa code rồi cập nhật lại |
