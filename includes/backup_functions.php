<?php
/**
 * includes/backup_functions.php
 *
 * ISS-029 FIX: Shared backup helper functions.
 * Previously createBackup() was only accessible by including ajax/backup_database.php
 * directly from restore_database.php, creating a circular include with ob_start() side effects.
 * This file is the single authoritative source for the createBackup() wrapper.
 *
 * Include this file wherever createBackup() is needed instead of including backup_database.php.
 */

if (!function_exists('createBackup')) {
    /**
     * Create a database backup file.
     *
     * @param string $type     Backup type: 'full' | 'pre_restore' | 'pre_reset'
     * @param string $label    Optional label appended to the filename
     * @return array           ['success'=>bool, 'filename'=>string, 'message'=>string]
     */
    function createBackup(string $type = 'full', string $label = ''): array
    {
        $backupDir = dirname(__DIR__) . '/uploads/backups/';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $label    = $label ?: $type;
        $filename = 'pharmacy_backup_' . $label . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
        $filepath = $backupDir . $filename;

        try {
            $pdo = db()->getConnection();

            // Fetch all user tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            $gz = gzopen($filepath, 'wb9');
            if (!$gz) {
                return ['success' => false, 'filename' => '', 'message' => 'Could not open backup file for writing.'];
            }

            gzwrite($gz, "-- PharmaCare Pro Backup\n-- Type: {$type}\n-- Created: " . date('Y-m-d H:i:s') . "\n\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                // Schema
                $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $create[1] . ";\n\n");

                // Data in chunks of 500 rows
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $offset = 0;
                while ($offset < $count) {
                    $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}")->fetchAll(PDO::FETCH_NUM);
                    if (empty($rows)) break;
                    $cols = $pdo->query("SELECT * FROM `{$table}` LIMIT 0")->getColumnMeta(0) ? array_map(
                        fn($i) => $pdo->query("SELECT * FROM `{$table}` LIMIT 0")->getColumnMeta($i)['name'],
                        range(0, $pdo->query("SELECT * FROM `{$table}` LIMIT 0")->columnCount() - 1)
                    ) : [];
                    $colList = implode('`, `', $cols);
                    foreach ($rows as $row) {
                        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                        gzwrite($gz, "INSERT INTO `{$table}` (`{$colList}`) VALUES (" . implode(', ', $vals) . ");\n");
                    }
                    $offset += 500;
                }
                gzwrite($gz, "\n");
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);

            return ['success' => true, 'filename' => $filename, 'message' => 'Backup created successfully.'];

        } catch (Throwable $e) {
            return ['success' => false, 'filename' => '', 'message' => $e->getMessage()];
        }
    }
}
