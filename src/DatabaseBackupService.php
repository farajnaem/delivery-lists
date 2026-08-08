<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DatabaseBackupService
{
    private static function backupDir(): string
    {
        $dir = dirname(__DIR__) . '/database/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function isSqlite(): bool
    {
        return config('db_driver') === 'sqlite';
    }

    /** @return list<array{filename:string,size:int,created_at:string,driver:string}> */
    public static function list(): array
    {
        $dir = self::backupDir();
        $files = glob($dir . '/*') ?: [];
        $items = [];
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if ($name === '.gitkeep') {
                continue;
            }
            $items[] = [
                'filename' => $name,
                'size' => filesize($path) ?: 0,
                'created_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
                'driver' => str_ends_with($name, '.sql') ? 'mysql' : 'sqlite',
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $items;
    }

    public static function create(): array
    {
        $ts = date('Y-m-d_His');
        if (self::isSqlite()) {
            $src = config('db_path');
            if (!is_file($src)) {
                throw new \RuntimeException('ملف قاعدة البيانات غير موجود.');
            }
            $filename = "backup_{$ts}.sqlite";
            $dest = self::backupDir() . '/' . $filename;
            if (!copy($src, $dest)) {
                throw new \RuntimeException('فشل إنشاء النسخة الاحتياطية.');
            }
            return ['filename' => $filename, 'size' => filesize($dest) ?: 0];
        }

        $filename = "backup_{$ts}.sql";
        $dest = self::backupDir() . '/' . $filename;
        self::exportMysqlToFile($dest);
        return ['filename' => $filename, 'size' => filesize($dest) ?: 0];
    }

    public static function restore(string $filename): void
    {
        $filename = basename($filename);
        $path = self::backupDir() . '/' . $filename;
        if (!is_file($path)) {
            throw new \RuntimeException('النسخة الاحتياطية غير موجودة.');
        }

        if (self::isSqlite()) {
            if (!str_ends_with($filename, '.sqlite')) {
                throw new \RuntimeException('هذه النسخة لقاعدة MySQL — النظام يستخدم SQLite محلياً.');
            }
            $dest = config('db_path');
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // نسخة أمان قبل الاستعادة
            if (is_file($dest)) {
                $safety = self::backupDir() . '/pre_restore_' . date('Y-m-d_His') . '.sqlite';
                copy($dest, $safety);
            }
            if (!copy($path, $dest)) {
                throw new \RuntimeException('فشل استعادة النسخة الاحتياطية.');
            }
            return;
        }

        if (!str_ends_with($filename, '.sql')) {
            throw new \RuntimeException('هذه النسخة لـ SQLite — النظام يستخدم MySQL.');
        }
        self::importMysqlFromFile($path);
    }

    public static function delete(string $filename): void
    {
        $path = self::backupDir() . '/' . basename($filename);
        if (!is_file($path)) {
            throw new \RuntimeException('النسخة غير موجودة.');
        }
        if (!unlink($path)) {
            throw new \RuntimeException('فشل حذف النسخة.');
        }
    }

    /**
     * إنشاء نسخة احتياطية جديدة بمحتوى نسخة موجودة (تكرار الملف).
     *
     * @return array{filename:string,size:int,source:string}
     */
    public static function duplicate(string $filename): array
    {
        $filename = basename($filename);
        $src = self::backupDir() . '/' . $filename;
        if (!is_file($src)) {
            throw new \RuntimeException('النسخة المصدر غير موجودة.');
        }

        $ext = str_ends_with(strtolower($filename), '.sql') ? 'sql' : 'sqlite';
        if (self::isSqlite() && $ext !== 'sqlite') {
            throw new \RuntimeException('لا يمكن نسخ ملف MySQL (.sql) بينما النظام يستخدم SQLite.');
        }
        if (!self::isSqlite() && $ext !== 'sql') {
            throw new \RuntimeException('لا يمكن نسخ ملف SQLite بينما النظام يستخدم MySQL.');
        }

        $ts = date('Y-m-d_His');
        $destName = "backup_from_{$ts}.{$ext}";
        $dest = self::backupDir() . '/' . $destName;
        if (!copy($src, $dest)) {
            throw new \RuntimeException('فشل إنشاء نسخة من الملف الموجود.');
        }

        return [
            'filename' => $destName,
            'size' => filesize($dest) ?: 0,
            'source' => $filename,
        ];
    }

    /**
     * حفظ ملف نسخة موجودة (مرفوع) ضمن مجلد النسخ الاحتياطية.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file من $_FILES
     * @return array{filename:string,size:int}
     */
    public static function importUploaded(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('فشل رفع الملف — تأكد من اختيار ملف صالح.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $original = basename((string) ($file['name'] ?? ''));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('ملف الرفع غير صالح.');
        }

        $lower = strtolower($original);
        $ext = str_ends_with($lower, '.sql') ? 'sql' : (str_ends_with($lower, '.sqlite') ? 'sqlite' : '');
        if ($ext === '') {
            throw new \RuntimeException('الصيغة المدعومة: .sqlite أو .sql فقط.');
        }
        if (self::isSqlite() && $ext !== 'sqlite') {
            throw new \RuntimeException('النظام يستخدم SQLite — ارفع ملف .sqlite.');
        }
        if (!self::isSqlite() && $ext !== 'sql') {
            throw new \RuntimeException('النظام يستخدم MySQL — ارفع ملف .sql.');
        }

        $ts = date('Y-m-d_His');
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'import';
        $destName = "backup_import_{$safeBase}_{$ts}.{$ext}";
        $dest = self::backupDir() . '/' . $destName;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new \RuntimeException('تعذّر حفظ الملف المرفوع.');
        }

        return ['filename' => $destName, 'size' => filesize($dest) ?: 0];
    }

    private static function exportMysqlToFile(string $dest): void
    {
        $pdo = Database::getConnection();
        $tables = ['users', 'campaigns', 'beneficiaries', 'delivery_events', 'delivery_batches', 'sms_outbox'];
        $sql = "-- كشوفات التسليم — نسخة احتياطية MySQL\n";
        $sql .= '-- ' . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
            if (!$create) {
                continue;
            }
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= ($create['Create Table'] ?? '') . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $colList = implode('`, `', $cols);
            foreach ($rows as $row) {
                $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
                $sql .= "INSERT INTO `{$table}` (`{$colList}`) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        if (file_put_contents($dest, $sql) === false) {
            throw new \RuntimeException('فشل كتابة ملف النسخة الاحتياطية.');
        }
    }

    private static function importMysqlFromFile(string $path): void
    {
        $pdo = Database::getConnection();
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('ملف النسخة الاحتياطية فارغ.');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (\Throwable) {
                // تجاهل أوامر غير قابلة للتنفيذ
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
