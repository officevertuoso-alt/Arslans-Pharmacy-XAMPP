<?php
/**
 * ajax/backup_database.php - Backup Engine
 */

// ISS-025 FIX: Removed manual error_reporting — config.php controls this globally
// Keep resource limits for long-running backup operations
ini_set('memory_limit', '512M');
set_time_limit(300);

ob_start();

// Required files
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

ob_clean();
header('Content-Type: application/json');

function sendJson($success, $message, $extra = array()) {
    $out = array('success' => $success, 'message' => $message);
    foreach ($extra as $k => $v) {
        $out[$k] = $v;
    }
    echo json_encode($out);
    exit;
}

// Session check
if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    sendJson(false, 'Session expired. Please login again.');
}

if (!isAdmin()) {
    sendJson(false, 'Admin access required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'POST request required.');
}

$csrf = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
if (!verifyCSRFToken($csrf)) {
    sendJson(false, 'Invalid security token.');
}

$action = isset($_POST['action']) ? trim($_POST['action']) : 'create';

if ($action === 'create') {
    handleCreateBackup();
} elseif ($action === 'delete') {
    handleDeleteBackup();
} elseif ($action === 'save_auto') {
    handleSaveAutoSettings();
} else {
    sendJson(false, 'Unknown action.');
}

function handleCreateBackup() {
    $backupType = isset($_POST['backup_type']) ? trim($_POST['backup_type']) : 'full';
    $allowed = array('full', 'data', 'selective', 'config', 'pre_reset', 'pre_restore');
    
    if (!in_array($backupType, $allowed)) {
        sendJson(false, 'Invalid backup type.');
    }
    
    // Create backup directory
    $backupDir = dirname(__DIR__) . '/uploads/backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    if (!is_writable($backupDir)) {
        sendJson(false, 'Backup directory is not writable.');
    }
    
    // Get company name
    $company = 'Pharmacy';
    try {
        $db = db();
        $stmt = $db->query("SELECT setting_value FROM company_settings WHERE setting_key='company_name' LIMIT 1");
        if ($stmt) {
            $val = $stmt->fetchColumn();
            if ($val) $company = $val;
        }
    } catch (Exception $e) {
        // Use default
    }
    
    // Get all tables
    try {
        $db = db();
        $stmt = $db->query("SHOW TABLES");
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        sendJson(false, 'Could not fetch tables: ' . $e->getMessage());
    }
    
    if (empty($allTables)) {
        sendJson(false, 'No tables found in database.');
    }
    
    $tablesToDump = $allTables;
    $includeSchema = true;
    $compress = true;
    
    if ($backupType === 'data') {
        $includeSchema = false;
    } elseif ($backupType === 'config') {
        $tablesToDump = array('company_settings', 'users', 'user_permissions', 'permission_presets');
        $compress = false;
    } elseif ($backupType === 'selective') {
        $selected = isset($_POST['tables']) ? $_POST['tables'] : array();
        if (empty($selected)) {
            sendJson(false, 'No tables selected.');
        }
        $tablesToDump = array_intersect($selected, $allTables);
        if (empty($tablesToDump)) {
            sendJson(false, 'Selected tables not found.');
        }
    }
    
    // Generate filename
    $timestamp = date('Y-m-d_H-i-s');
    $ext = $compress ? 'sql.gz' : 'sql';
    $filename = "pharmacy_backup_{$backupType}_{$timestamp}.{$ext}";
    $filepath = $backupDir . $filename;
    
    // Create backup
    try {
        $result = createSqlBackup($tablesToDump, $includeSchema, $filepath, $compress);
        if (!$result) {
            sendJson(false, 'Failed to create backup file.');
        }
        
        $size = filesize($filepath);
        $sizeHuman = formatFileSize($size);
        
        sendJson(true, 'Backup created successfully.', array(
            'filename' => $filename,
            'size' => $sizeHuman,
            'size_bytes' => $size,
            'tables_count' => count($tablesToDump),
            'created_at' => date('d-M-Y H:i:s')
        ));
        
    } catch (Exception $e) {
        sendJson(false, 'Backup failed: ' . $e->getMessage());
    }
}

function createSqlBackup($tables, $includeSchema, $outputFile, $compress = true) {
    try {
        $db = db();
        $tempFile = dirname($outputFile) . '/temp_' . uniqid() . '.sql';
        $fh = fopen($tempFile, 'w');
        
        if (!$fh) {
            return false;
        }
        
        // Write header
        fwrite($fh, "-- Pharmacy Management System Backup\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
        
        foreach ($tables as $table) {
            fwrite($fh, "-- Table: {$table}\n");
            
            if ($includeSchema) {
                $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                if ($create) {
                    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fh, $create[1] . ";\n\n");
                }
            }
            
            // Get data
            $stmt = $db->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';
                
                foreach ($rows as $row) {
                    $values = array();
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($val)) {
                            $values[] = $val;
                        } else {
                            $values[] = $db->quote((string)$val);
                        }
                    }
                    fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $values) . ");\n");
                }
                fwrite($fh, "\n");
            }
        }
        
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
        
        // Compress or move
        if ($compress && function_exists('gzopen')) {
            $gz = gzopen($outputFile, 'wb6');
            $in = fopen($tempFile, 'rb');
            while (!feof($in)) {
                $buffer = fread($in, 65536);
                if ($buffer !== false) {
                    gzwrite($gz, $buffer);
                }
            }
            fclose($in);
            gzclose($gz);
            unlink($tempFile);
        } else {
            rename($tempFile, $outputFile);
        }
        
        return file_exists($outputFile) && filesize($outputFile) > 0;
        
    } catch (Exception $e) {
        error_log("Backup creation error: " . $e->getMessage());
        return false;
    }
}

function handleDeleteBackup() {
    if (!canDo('backup', 'delete')) {
        sendJson(false, 'No permission to delete backups.');
    }
    
    $filename = isset($_POST['filename']) ? basename(trim($_POST['filename'])) : '';
    if (empty($filename)) {
        sendJson(false, 'No filename provided.');
    }
    
    $backupDir = dirname(__DIR__) . '/uploads/backups/';
    $filepath = $backupDir . $filename;
    
    if (!file_exists($filepath)) {
        sendJson(false, 'File not found.');
    }
    
    if (unlink($filepath)) {
        $metaFile = $filepath . '.meta.json';
        if (file_exists($metaFile)) {
            unlink($metaFile);
        }
        sendJson(true, 'Backup deleted.');
    } else {
        sendJson(false, 'Cannot delete file.');
    }
}

function handleSaveAutoSettings() {
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] == '1' ? '1' : '0';
    $keep = isset($_POST['keep']) ? (int)$_POST['keep'] : 10;
    $keep = in_array($keep, array(10, 20, 30, 50)) ? $keep : 10;
    
    try {
        $db = db();
        $db->exec("INSERT INTO company_settings (setting_key, setting_value) 
                   VALUES ('auto_backup_enabled', '$enabled')
                   ON DUPLICATE KEY UPDATE setting_value = '$enabled'");
        $db->exec("INSERT INTO company_settings (setting_key, setting_value) 
                   VALUES ('auto_backup_keep', '$keep')
                   ON DUPLICATE KEY UPDATE setting_value = '$keep'");
        
        sendJson(true, 'Auto-backup settings saved.');
    } catch (Exception $e) {
        sendJson(false, 'Save failed: ' . $e->getMessage());
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>