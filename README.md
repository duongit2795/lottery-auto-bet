# Bot Telegram bán dịch vụ SIM nhận OTP (BossOTP)

Bot Telegram bán dịch vụ thuê sim nhận OTP qua API [BossOTP](https://bossotp.net/rent-api), kèm trang quản trị PHP.

## Tính năng

**Bot (người dùng)**
- 📱 Thuê sim: danh sách dịch vụ phân trang, tìm nhanh bằng cách gõ tên (vd: `facebook`), xác nhận trước khi trừ tiền
- 🔑 Tự động gửi OTP về khi có (cron/polling), nút **Kiểm tra OTP** và **Hủy & hoàn tiền**
- ↩️ Tự hoàn tiền khi hết thời gian chờ, BossOTP báo `FAILED`, hoặc lỗi lúc thuê (hết số, quá tải...)
- 📶 Chọn nhà mạng khi thuê (Viettel, Mobifone, Vinaphone, Vietnamobile, Gmobile)
- ♻️ Thuê lại đúng số cũ (`re_number`)
- 📤 **Gửi SMS từ số thuê**: SMS cố định, SMS tùy chọn (khách nhập số nhận + nội dung), hoặc 📞 chuyển tiếp cuộc gọi. Bật theo từng dịch vụ trong trang admin
- 📞 Hiển thị danh sách cuộc gọi đến số thuê (dịch vụ OTP bằng cuộc gọi)
- ✉️ **Gửi tin nhắn** (menu riêng): chọn dịch vụ, nhập 1 hoặc nhiều số nhận (tối đa 50/lần), nhập nội dung, xác nhận. Bot thuê số và gửi SMS qua hàng đợi, rồi gửi báo cáo kết quả. Tin lỗi được thử lại bằng số khác, quá số lần thử thì hoàn tiền hoặc hoàn lượt
- 📦 **Gói tin nhắn**: admin tạo gói (vd 100 tin / 50.000đ / 30 ngày, áp dụng cho mọi dịch vụ hoặc 1 dịch vụ). Khách mua bằng số dư. Khi gửi, lượt gói được dùng trước, hết lượt mới trừ tiền
- Lọc từ cấm trong nội dung, giới hạn độ dài, giới hạn số yêu cầu đồng thời theo quy định của BossOTP
- 💰 Nạp tiền: mã QR VietQR có sẵn số tiền & nội dung, **tự cộng tiền qua SePay/Casso**, hoặc bấm "Tôi đã chuyển khoản" để admin duyệt ngay trong Telegram
- 👤 Tài khoản (số dư, tổng nạp/chi, biến động số dư), 📜 lịch sử đơn, 🏷 bảng giá
- 🎁 Giới thiệu bạn bè: hoa hồng % trên mỗi đơn thành công
- Khuyến mãi % khi nạp, giới hạn số đơn chờ, chống hủy liên tục, chế độ bảo trì

**Lệnh admin trong bot** (với Telegram ID nằm trong cài đặt): `/admin` (thống kê, số dư API), `/cong <id> <tiền>`, `/tru <id> <tiền>`, `/ban <id>`, `/unban <id>`, `/user <id>`, `/thongbao <nội dung>`, `/baotri on|off`

**Trang quản trị `/admin`**
- Tổng quan: doanh thu, lợi nhuận, biểu đồ 14 ngày, dịch vụ bán chạy, số dư tài khoản API
- Đơn thuê: lọc/tìm, check OTP thủ công, hủy/hoàn tiền (kể cả đơn đã thành công khi khách khiếu nại)
- Nạp tiền: duyệt/từ chối (sửa được số tiền thực nhận), nạp thủ công
- Người dùng: tìm kiếm, cộng/trừ tiền, khóa, nhắn tin riêng, xem lịch sử
- Tin nhắn đã gửi: theo dõi hàng đợi, hủy (có hoàn tiền), gửi lại tin lỗi, chạy hàng đợi ngay
- Gói tin nhắn: tạo/sửa gói, tặng gói cho khách, chỉnh lượt và hạn dùng của gói khách đã mua
- Dịch vụ & giá: đồng bộ từ API, tự tính giá bán theo % hoặc số tiền lãi, sửa giá/tên, bật/tắt, sắp xếp
- Gửi thông báo hàng loạt
- Cài đặt bot, ngân hàng, giá, giới thiệu; đặt webhook bằng 1 nút; đổi mật khẩu
- **API nhà cung cấp**: cấu hình endpoint và cách đọc JSON trả về, kèm nút test xem phản hồi thật

## Yêu cầu
PHP ≥ 8.0, có các extension `curl`, `pdo_sqlite` (hoặc `pdo_mysql`), `mbstring`. Webhook cần tên miền có HTTPS.

## Cài đặt
1. Upload toàn bộ mã nguồn lên hosting, đảm bảo thư mục `data/` và thư mục gốc ghi được (để tạo `config.php`).
2. Mở `https://ten-mien/install.php`: chọn SQLite hoặc MySQL, tạo tài khoản admin, nhập bot token và **API key BossOTP**.
3. Vào `/admin` → **Cài đặt**:
   - Điền Telegram ID admin, thông tin ngân hàng (số TK, mã BIN, chủ TK)
   - Bấm **Kiểm tra bot** (tự lấy username, cài menu lệnh) rồi **Đặt webhook**
4. **API nhà cung cấp**: bấm **Test → Số dư** và **Test → Dịch vụ**. Nếu phản hồi gốc khác cấu hình mặc định, sửa endpoint hoặc ánh xạ JSON cho khớp tài liệu BossOTP rồi lưu.
5. **Dịch vụ & giá** → **Đồng bộ từ API**, chỉnh giá và bật dịch vụ muốn bán.
6. Thêm cron chạy mỗi phút để tự lấy OTP và hoàn tiền đơn hết hạn:
   ```
   * * * * * php /duong-dan/cron.php
   ```
   Nếu hosting không có cron, dùng dịch vụ cron ngoài gọi URL `https://ten-mien/cron.php?secret=...` (URL đầy đủ có trong trang Cài đặt).

### Dùng database MySQL ở máy khác (database từ xa)
1. Trên máy chứa database: tạo database (utf8mb4) và user, cấp quyền cho user đó truy cập từ IP của hosting chạy bot, mở cổng 3306 trên tường lửa.
   ```sql
   CREATE DATABASE simbot CHARACTER SET utf8mb4;
   CREATE USER 'simbot'@'IP_HOSTING' IDENTIFIED BY 'mat_khau_manh';
   GRANT ALL ON simbot.* TO 'simbot'@'IP_HOSTING';
   ```
   Với MySQL tự cài, sửa `bind-address = 0.0.0.0` trong `my.cnf` để nhận kết nối từ ngoài.
2. Ở `install.php` chọn **MySQL**: Host là IP hoặc tên miền của máy database, điền port, tên database, user và mật khẩu. Database đám mây (Aiven, TiDB Cloud, PlanetScale, ...) cần tick thêm **Kết nối SSL**.
3. Bảng dữ liệu được tạo tự động. Có thể kiểm tra kết nối trước bằng lệnh `mysql -h HOST -P 3306 -u USER -p` chạy trên hosting.

### Chạy không cần webhook (VPS / máy cá nhân)
```
php polling.php
```
Script tự xóa webhook, nhận tin nhắn và kiểm tra OTP mỗi 10 giây, không cần cron.

### Nạp tiền tự động (SePay / Casso)
- Trong Cài đặt, đặt **Key webhook SePay/Casso** là một chuỗi bí mật.
- SePay: webhook URL `https://ten-mien/payment_webhook.php`, xác thực "API Key" = chuỗi trên.
- Casso: webhook URL như trên, "Secure token" = chuỗi trên.
- Mỗi user có mã nạp riêng (vd: `NAPAB12CD`). Giao dịch có nội dung chứa mã này sẽ được cộng tiền ngay, kèm khuyến mãi nếu có.

## Cấu hình API BossOTP
Cấu hình mặc định đã khớp với [API v4 của BossOTP](https://bossotp.net/rent-api):

| Chức năng | Endpoint |
|---|---|
| Số dư | `GET /api/v4/users/me/balance` |
| Dịch vụ | `GET /api/v4/service-manager/services` |
| Thuê số | `GET /api/v4/rents/create?service_id=…[&network=…&re_number=…&language=vn]` |
| Kiểm tra OTP | `GET /api/v4/rents/check?_id=…` |
| Gửi SMS / chuyển tiếp | `GET /api/v4/rents/send-sms?_id=…[&sms=…&number_receiver=…│&number_forward_call=…]` |

API key được gửi qua tham số `api_token`. BossOTP **không có API hủy số**, nên mặc định khách không tự hủy được. Đơn sẽ được hoàn tiền khi BossOTP trả `FAILED` hoặc khi hết "Thời gian chờ OTP" trong Cài đặt. Mã lỗi (`NO_NUMBER_AVAILABLE`, `TOO_MANY_REQUEST`, ...) được dịch sang thông báo tiếng Việt cho khách. Khi gặp `NOT_ENOUGH_BALANCE`, admin được báo nạp tiền.

Nếu BossOTP thay đổi API, sửa endpoint và ánh xạ trong trang **API nhà cung cấp**. Endpoint dùng cú pháp `METHOD /đường-dẫn?tham_số={biến}`, với các biến `{api_key}`, `{service_id}`, `{order_id}`.
Ánh xạ JSON dùng đường dẫn kiểu `data.phone`. Có thể ghi nhiều lựa chọn, cách nhau bằng `|`, bot lấy giá trị đầu tiên tìm thấy.
Ví dụ, API trả về `{"status":1,"result":{"number":"098..","id":55}}` thì đặt:
- Ánh xạ "Số điện thoại" = `result.number`
- Ánh xạ "ID đơn thuê" = `result.id`

Log mọi request/response tới nhà cung cấp nằm ở `data/logs/provider-YYYY-MM-DD.log`.

## Cấu trúc
```
install.php          Trình cài đặt
webhook.php          Nhận update Telegram
cron.php             Kiểm tra OTP / hoàn tiền đơn hết hạn
polling.php          Chạy bot bằng long-polling (CLI)
payment_webhook.php  Webhook nạp tiền SePay/Casso
src/                 Bot, OtpProvider (client API), OrderService, Wallet, DB, Settings, Telegram
admin/               Trang quản trị
data/                CSDL SQLite + log (đã chặn truy cập web)
```

## Bảo mật
- `config.php` và `data/` không được commit (xem `.gitignore`). `.htaccess` đã chặn truy cập web vào `data/` và `src/`. Với Nginx, chặn tương tự: `location ~ ^/(data|src)/ { deny all; }`.
- API key và bot token chỉ lưu trong CSDL, nhập qua trang quản trị, không ghi trong mã nguồn.
