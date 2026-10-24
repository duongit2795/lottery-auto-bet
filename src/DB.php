<?php
declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function init(array $config): void
    {
        self::$driver = $config['db_driver'] ?? 'sqlite';
        if (self::$driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['mysql_host'],
                (int)$config['mysql_port'],
                $config['mysql_db']
            );
            $opts = [PDO::ATTR_TIMEOUT => 10];
            if (!empty($config['mysql_ssl'])) {
                // Database đám mây (Aiven, TiDB, PlanetScale, ...) thường bắt buộc SSL
                $ca = self::caFile((string)($config['mysql_ssl_ca'] ?? ''));
                if ($ca !== null) {
                    $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                }
                $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $ca !== null;
            }
            self::$pdo = new PDO($dsn, $config['mysql_user'], $config['mysql_pass'], $opts);
            try {
                self::$pdo->exec("SET time_zone = '+07:00'");
            } catch (PDOException $e) {
                // một số dịch vụ không cho đổi time_zone; giờ được tính bằng PHP nên không ảnh hưởng
            }
        } else {
            $path = $config['sqlite_path'] ?? APP_ROOT . '/data/bot.sqlite';
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            self::$pdo = new PDO('sqlite:' . $path);
            self::$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        }
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** Đường dẫn được phép đọc theo open_basedir của hosting (aaPanel bật mặc định). */
    private static function allowedPath(string $path): bool
    {
        $base = (string)ini_get('open_basedir');
        if ($base === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $base) as $dir) {
            if ($dir !== '' && str_starts_with($path, rtrim($dir, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * File CA để xác thực SSL của MySQL. Thứ tự: đường dẫn trong config → data/cacert.pem →
     * CA hệ thống (nếu open_basedir cho phép) → tự tải bộ CA của curl.se về data/cacert.pem.
     */
    private static function caFile(string $configured): ?string
    {
        $local = APP_ROOT . '/data/cacert.pem';
        foreach ([$configured, $local, '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $path) {
            if ($path !== '' && self::allowedPath($path) && @is_readable($path)) {
                return $path;
            }
        }
        foreach (['https://curl.se/ca/cacert.pem', 'https://raw.githubusercontent.com/bagder/ca-bundle/master/ca-bundle.crt'] as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10]);
            $pem = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')) {
                if (!is_dir(dirname($local))) {
                    @mkdir(dirname($local), 0775, true);
                }
                return @file_put_contents($local, $pem) !== false ? $local : null;
            }
        }
        return null;
    }

    public static function pdo(): PDO
    {
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        return self::query("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $params))->rowCount();
    }

    public static function transaction(callable $fn)
    {
        self::$pdo->beginTransaction();
        try {
            $r = $fn();
            self::$pdo->commit();
            return $r;
        } catch (Throwable $e) {
            self::$pdo->rollBack();
            throw $e;
        }
    }

    /** Tạo bảng. Dùng cú pháp chung cho SQLite & MySQL. */
    public static function migrate(): void
    {
        $mysql = self::$driver === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $text = $mysql ? 'MEDIUMTEXT' : 'TEXT';

        $tables = [
            "CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(100) PRIMARY KEY,
                v $text
            )$tail",
            "CREATE TABLE IF NOT EXISTS admins (
                id $pk,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS users (
                id $pk,
                tg_id BIGINT NOT NULL UNIQUE,
                username VARCHAR(64),
                first_name VARCHAR(128),
                balance DECIMAL(15,0) NOT NULL DEFAULT 0,
                total_deposit DECIMAL(15,0) NOT NULL DEFAULT 0,
                total_spent DECIMAL(15,0) NOT NULL DEFAULT 0,
                ref_by BIGINT,
                ref_earned DECIMAL(15,0) NOT NULL DEFAULT 0,
                deposit_code VARCHAR(20) UNIQUE,
                is_banned TINYINT NOT NULL DEFAULT 0,
                state VARCHAR(64),
                draft $text,
                lang VARCHAR(5) DEFAULT 'vi',
                created_at DATETIME,
                last_active DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS services (
                id $pk,
                provider_id VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                cost DECIMAL(15,0) NOT NULL DEFAULT 0,
                price DECIMAL(15,0) NOT NULL DEFAULT 0,
                custom_price TINYINT NOT NULL DEFAULT 0,
                is_active TINYINT NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                sms_mode VARCHAR(10) NOT NULL DEFAULT 'none',
                sms_manual TINYINT NOT NULL DEFAULT 0,
                sup_custom_sms TINYINT NOT NULL DEFAULT 0,
                sup_default_sms TINYINT NOT NULL DEFAULT 0,
                sup_forward_call TINYINT NOT NULL DEFAULT 0,
                sup_call TINYINT NOT NULL DEFAULT 0,
                sup_call_from_number TINYINT NOT NULL DEFAULT 0,
                api_sms_mode VARCHAR(10),
                api_raw $text,
                updated_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS orders (
                id $pk,
                user_id INT NOT NULL,
                service_id INT NOT NULL,
                service_name VARCHAR(191),
                provider_order_id VARCHAR(128),
                phone VARCHAR(32),
                otp VARCHAR(64),
                sms $text,
                price DECIMAL(15,0) NOT NULL DEFAULT 0,
                cost DECIMAL(15,0) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                kind VARCHAR(10) NOT NULL DEFAULT 'otp',
                user_package_id INT,
                sms_closed TINYINT NOT NULL DEFAULT 0,
                sms_lock INT NOT NULL DEFAULT 0,
                network VARCHAR(20),
                sms_sent TINYINT NOT NULL DEFAULT 0,
                sms_info VARCHAR(255),
                call_logs $text,
                tg_message_id BIGINT,
                expires_at DATETIME,
                created_at DATETIME,
                updated_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS deposits (
                id $pk,
                user_id INT NOT NULL,
                amount DECIMAL(15,0) NOT NULL,
                method VARCHAR(32) NOT NULL DEFAULT 'bank',
                ref VARCHAR(128) UNIQUE,
                note VARCHAR(255),
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME,
                updated_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS transactions (
                id $pk,
                user_id INT NOT NULL,
                amount DECIMAL(15,0) NOT NULL,
                balance_after DECIMAL(15,0) NOT NULL,
                type VARCHAR(20) NOT NULL,
                note VARCHAR(255),
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS sms_packages (
                id $pk,
                name VARCHAR(191) NOT NULL,
                credits INT NOT NULL DEFAULT 0,
                price DECIMAL(15,0) NOT NULL DEFAULT 0,
                days_valid INT NOT NULL DEFAULT 30,
                service_id INT,
                description VARCHAR(255),
                is_active TINYINT NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS user_packages (
                id $pk,
                user_id INT NOT NULL,
                package_id INT,
                name VARCHAR(191),
                service_id INT,
                credits_total INT NOT NULL DEFAULT 0,
                credits_left INT NOT NULL DEFAULT 0,
                price DECIMAL(15,0) NOT NULL DEFAULT 0,
                expires_at DATETIME,
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS sms_jobs (
                id $pk,
                batch VARCHAR(32) NOT NULL,
                user_id INT NOT NULL,
                service_id INT NOT NULL,
                receiver VARCHAR(32),
                content $text,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                price DECIMAL(15,0) NOT NULL DEFAULT 0,
                user_package_id INT,
                order_id INT,
                attempts INT NOT NULL DEFAULT 0,
                error VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS bank_tx (
                ref VARCHAR(128) PRIMARY KEY,
                amount DECIMAL(15,0) NOT NULL DEFAULT 0,
                content VARCHAR(255),
                tx_time VARCHAR(40),
                status VARCHAR(20) NOT NULL,
                user_id INT,
                deposit_id INT,
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS sms_sends (
                id $pk,
                order_id INT NOT NULL,
                user_id INT NOT NULL,
                kind VARCHAR(10) NOT NULL,
                receiver VARCHAR(32),
                content $text,
                ok TINYINT NOT NULL DEFAULT 0,
                error VARCHAR(255),
                created_at DATETIME
            )$tail",
            "CREATE TABLE IF NOT EXISTS broadcasts (
                id $pk,
                message $text,
                sent INT NOT NULL DEFAULT 0,
                failed INT NOT NULL DEFAULT 0,
                created_at DATETIME
            )$tail",
        ];
        foreach ($tables as $sql) {
            self::$pdo->exec($sql);
        }
        $idx = [
            'CREATE INDEX idx_orders_status ON orders(status)',
            'CREATE INDEX idx_orders_user ON orders(user_id)',
            'CREATE INDEX idx_tx_user ON transactions(user_id)',
            'CREATE INDEX idx_dep_user ON deposits(user_id)',
            'CREATE INDEX idx_jobs_status ON sms_jobs(status)',
            'CREATE INDEX idx_jobs_batch ON sms_jobs(batch)',
            'CREATE INDEX idx_upk_user ON user_packages(user_id)',
            'CREATE INDEX idx_sends_order ON sms_sends(order_id)',
        ];
        // Nâng cấp CSDL cũ: thêm cột mới nếu chưa có
        $columns = [
            "ALTER TABLE services ADD COLUMN sms_mode VARCHAR(10) NOT NULL DEFAULT 'none'",
            'ALTER TABLE orders ADD COLUMN network VARCHAR(20)',
            'ALTER TABLE orders ADD COLUMN sms_sent TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN sms_info VARCHAR(255)',
            "ALTER TABLE orders ADD COLUMN call_logs $text",
            "ALTER TABLE orders ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'otp'",
            'ALTER TABLE orders ADD COLUMN user_package_id INT',
            'ALTER TABLE orders ADD COLUMN sms_closed TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN sms_lock INT NOT NULL DEFAULT 0',
            "ALTER TABLE users ADD COLUMN draft $text",
            'ALTER TABLE services ADD COLUMN sms_manual TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE services ADD COLUMN api_sms_mode VARCHAR(10)',
            "ALTER TABLE services ADD COLUMN api_raw $text",
            'ALTER TABLE services ADD COLUMN sup_custom_sms TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE services ADD COLUMN sup_default_sms TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE services ADD COLUMN sup_forward_call TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE services ADD COLUMN sup_call TINYINT NOT NULL DEFAULT 0',
            'ALTER TABLE services ADD COLUMN sup_call_from_number TINYINT NOT NULL DEFAULT 0',
        ];
        foreach (array_merge($columns, $idx) as $sql) {
            try {
                self::$pdo->exec($sql);
            } catch (PDOException $e) {
                // index đã tồn tại
            }
        }
    }
}
