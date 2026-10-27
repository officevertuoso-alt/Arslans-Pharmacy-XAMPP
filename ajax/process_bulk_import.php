<?php
// === FILE: pharmacy_software/ajax/process_bulk_import.php ===
// Bulk Import — creates products exactly like add.php does (uses saveProduct())
ini_set('display_errors', '0');
error_reporting(0);
ob_start();

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/product_functions.php';

header('Content-Type: application/json');
ob_clean();

requirePharmacist();

// CSRF check
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

canDo('products', 'import') OR die(json_encode(['success' => false, 'message' => 'Access Denied']));

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!in_array($action, ['validate', 'import'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

if (empty($_FILES['import_file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file    = $_FILES['import_file'];
$type    = $_POST['import_type'] ?? 'medicine'; // 'medicine' | 'non_medicine'
$maxSize = 5 * 1024 * 1024;
$maxRows = 500;

if (!in_array($type, ['medicine', 'non_medicine'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid import type']);
    exit;
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    $errMsg = $uploadErrors[$file['error']] ?? 'Unknown upload error (code ' . $file['error'] . ')';
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit;
}
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB allowed.']);
    exit;
}

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$tmpPath  = $file['tmp_name'];
$origName = basename($file['name']);

if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
    echo json_encode(['success' => false, 'message' => 'Only .xlsx, .xls, .csv files are allowed.']);
    exit;
}

// ── COLUMN MAPS — must exactly match download_import_template.php ────────────
// Medicine:     product_name(0), brand_name(1), generic_name(2),
//               dosage_form(3),  strength(4),   unit_type(5),
//               units_per_pack(6), barcode(7)
// Non-medicine: product_name(0), brand_name(1), size_value(2),
//               unit_type(3),    units_per_pack(4), barcode(5)
$medicineMap = [
    'product_name'   => 0,
    'brand_name'     => 1,
    'generic_name'   => 2,
    'dosage_form'    => 3,
    'strength'       => 4,
    'unit_type'      => 5,
    'units_per_pack' => 6,
    'barcode'        => 7,
];
$nonMedicineMap = [
    'product_name'   => 0,
    'brand_name'     => 1,
    'size_value'     => 2,
    'unit_type'      => 3,
    'units_per_pack' => 4,
    'barcode'        => 5,
];
$colMap = ($type === 'medicine') ? $medicineMap : $nonMedicineMap;

// Matches add.php $defaultForms list exactly
$validDosageForms = [
    'Tablet','Syrup','Capsule','Injection','Cream','Ointment',
    'Drops','Gel','Inhaler','Suppository','Patch','Powder',
    'Suspension','Solution','Lotion','Granules','Other',
];

// Valid unit types (same as add.php dropdown)
$validUnitTypes = [
    'Strip','Bottle','Box','Tube','Sachet','Vial','Ampoule','Pcs',
    'Bag','Roll',
];

// ── HELPER: detect header/notes/empty rows ─────────────────────────────────
function isMetaRow(array $row): bool {
    $nonEmpty = array_filter($row, fn($v) => trim((string)$v) !== '');
    if (empty($nonEmpty)) return true;
    $first = strtolower(trim((string)($row[0] ?? '')));
    if (str_starts_with($first, '#')) return true;
    if (in_array($first, ['product_name', 'product_name *'], true)) return true;
    // Notes/instruction rows start with REQUIRED or optional
    if (in_array($first, ['required', 'optional'], true)) return true;
    return false;
}

// ── PARSE FILE ──────────────────────────────────────────────────────────────
function parseUploadedFile(string $path, string $ext): array {
    $rows = [];

    if (in_array($ext, ['xlsx', 'xls'])) {
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new RuntimeException('XLSX/XLS support requires PhpSpreadsheet library. Please upload a CSV file instead.');
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $rawRows     = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $dataStartIndex = 0;
        foreach ($rawRows as $i => $row) {
            $first = strtolower(trim((string)($row[0] ?? '')));
            if (in_array($first, ['product_name', 'product_name *'], true)) {
                $dataStartIndex = $i + 1;
                break;
            }
        }
        // Skip notes rows right after header
        while (isset($rawRows[$dataStartIndex]) && isMetaRow($rawRows[$dataStartIndex])) {
            $dataStartIndex++;
        }
        foreach ($rawRows as $i => $row) {
            if ($i < $dataStartIndex) continue;
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
            $rows[] = array_values($row);
        }

    } else {
        // CSV
        $handle = fopen($path, 'r');
        if (!$handle) throw new RuntimeException('Cannot open uploaded file.');

        // Strip UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $headerFound = false;
        $dataStarted = false;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === null) continue;
            $first = strtolower(trim($row[0] ?? ''));

            if (!$headerFound) {
                if (in_array($first, ['product_name', 'product_name *'], true)) {
                    $headerFound = true;
                }
                continue; // skip everything up to and including header row
            }
            if (!$dataStarted) {
                if (isMetaRow($row)) continue; // skip notes row(s)
                $dataStarted = true;
            }
            // Skip blank rows
            if (empty(array_filter($row, fn($v) => trim($v) !== ''))) continue;
            $rows[] = $row;
        }
        fclose($handle);
    }

    return $rows;
}

function getCell(array $row, array $colMap, string $key): string {
    $idx = $colMap[$key] ?? null;
    return ($idx !== null) ? trim((string)($row[$idx] ?? '')) : '';
}

// ── VALIDATE ONE ROW ────────────────────────────────────────────────────────
function validateRow(
    int $rowNum, array $row, array $colMap, string $type,
    array $validDosageForms, array $validUnitTypes, array $seenNames
): array {
    $errors = []; $warnings = []; $data = [];
    $g = fn($k) => getCell($row, $colMap, $k);

    // product_name — required
    $productName = $g('product_name');
    if ($productName === '') {
        $errors[] = 'product_name is required';
    } else {
        $data['product_name'] = $productName;
        if (in_array(strtolower($productName), array_map('strtolower', $seenNames), true)) {
            $errors[] = "Duplicate product_name in file: '$productName'";
        }
    }

    // brand_name — required
    $brandName = $g('brand_name');
    if ($brandName === '') {
        $errors[] = 'brand_name is required';
    } else {
        $data['brand_name'] = $brandName;
    }

    // unit_type — required
    $unitType = $g('unit_type');
    if ($unitType === '') {
        $errors[] = 'unit_type is required (e.g. Strip, Bottle, Box, Pcs)';
    } else {
        if (!in_array($unitType, $validUnitTypes, true)) {
            $warnings[] = "unit_type '$unitType' is not standard but will be accepted.";
        }
        $data['unit_type'] = $unitType;
    }

    // units_per_pack — required, numeric >= 1
    $unitsRaw = $g('units_per_pack');
    if ($unitsRaw === '') {
        $errors[] = 'units_per_pack is required (number, min 1)';
        $data['units_per_pack'] = 1;
    } elseif (!is_numeric($unitsRaw) || (int)$unitsRaw < 1) {
        $errors[] = "units_per_pack must be a number >= 1, got: '$unitsRaw'";
        $data['units_per_pack'] = 1;
    } else {
        $data['units_per_pack'] = (int)$unitsRaw;
    }

    // Medicine-specific fields
    if ($type === 'medicine') {
        $genericName = $g('generic_name');
        if ($genericName === '') {
            $errors[] = 'generic_name is required for medicines';
        } else {
            $data['generic_name'] = $genericName;
        }

        $dosageForm = $g('dosage_form');
        if ($dosageForm === '') {
            $errors[] = 'dosage_form is required (e.g. Tablet, Syrup, Capsule)';
        } else {
            // Case-insensitive match
            $matched = null;
            foreach ($validDosageForms as $vdf) {
                if (strcasecmp($dosageForm, $vdf) === 0) { $matched = $vdf; break; }
            }
            if ($matched) {
                $data['dosage_form'] = $matched;
            } else {
                $warnings[] = "dosage_form '$dosageForm' not in standard list — accepted as-is.";
                $data['dosage_form'] = $dosageForm;
            }
        }
        $data['strength']   = $g('strength') ?: null;
        $data['size_value'] = null;
    } else {
        $data['size_value']   = $g('size_value') ?: null;
        $data['generic_name'] = null;
        $data['dosage_form']  = null;
        $data['strength']     = null;
    }

    $data['barcode']     = $g('barcode') ?: null;
    $data['category_id'] = ($type === 'medicine') ? 1 : 2;
    $data['image_path']  = null; // bulk import has no image

    // DB duplicate check
    if (!empty($productName)) {
        $existing = getOne("SELECT id FROM products WHERE product_name = :name", [':name' => $productName]);
        if ($existing) {
            $warnings[] = "Product '$productName' already exists in database — will be skipped on import.";
            $data['_skip'] = true;
        }
    }

    return [
        'row_num'  => $rowNum,
        'data'     => $data,
        'status'   => empty($errors) ? (empty($warnings) ? 'valid' : 'warning') : 'error',
        'errors'   => $errors,
        'warnings' => $warnings,
    ];
}

// ── ACTION: VALIDATE ────────────────────────────────────────────────────────
if ($action === 'validate') {
    try {
        $rawRows = parseUploadedFile($tmpPath, $ext);
        if (count($rawRows) === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'No data rows found. Make sure your file has products filled in below the header and notes rows.',
            ]);
            exit;
        }
        if (count($rawRows) > $maxRows) {
            echo json_encode(['success' => false, 'message' => "Too many rows. Max $maxRows allowed, found " . count($rawRows) . "."]);
            exit;
        }

        $results = []; $seenNames = [];
        $totalErrors = 0; $totalWarnings = 0; $totalValid = 0;

        foreach ($rawRows as $i => $row) {
            $rowNum = $i + 3; // header=row1, notes=row2, data starts row3
            $result = validateRow($rowNum, $row, $colMap, $type, $validDosageForms, $validUnitTypes, $seenNames);
            if ($result['status'] === 'error')   $totalErrors++;
            if ($result['status'] === 'warning') $totalWarnings++;
            if ($result['status'] === 'valid')   $totalValid++;
            if (!empty($result['data']['product_name'])) $seenNames[] = $result['data']['product_name'];
            $results[] = $result;
        }

        echo json_encode([
            'success'    => true,
            'total'      => count($results),
            'valid'      => $totalValid,
            'warnings'   => $totalWarnings,
            'errors'     => $totalErrors,
            'can_import' => $totalErrors === 0,
            'rows'       => $results,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Parse error: ' . $e->getMessage()]);
    }
    exit;
}

// ── ACTION: IMPORT ──────────────────────────────────────────────────────────
if ($action === 'import') {
    try {
        $rawRows = parseUploadedFile($tmpPath, $ext);
        if (count($rawRows) === 0) {
            echo json_encode(['success' => false, 'message' => 'No data rows found.']);
            exit;
        }
        if (count($rawRows) > $maxRows) {
            echo json_encode(['success' => false, 'message' => "Too many rows. Max $maxRows."]);
            exit;
        }

        // Full re-validate before touching DB
        $seenNames = []; $validated = [];
        foreach ($rawRows as $i => $row) {
            $rowNum = $i + 3;
            $result = validateRow($rowNum, $row, $colMap, $type, $validDosageForms, $validUnitTypes, $seenNames);
            if ($result['status'] === 'error') {
                echo json_encode([
                    'success' => false,
                    'message' => "Validation error at row {$rowNum}: " . implode('; ', $result['errors']),
                ]);
                exit;
            }
            if (!empty($result['data']['product_name'])) $seenNames[] = $result['data']['product_name'];
            $validated[] = $result;
        }

        $importedCount = 0; $productsCreated = 0; $rowResults = [];
        $userId = $_SESSION['user_id'] ?? 0;

        foreach ($validated as $item) {
            $d = $item['data'];

            // Skip DB duplicates
            if (!empty($d['_skip'])) {
                $rowResults[] = [
                    'row_num'      => $item['row_num'],
                    'product_name' => $d['product_name'],
                    'status'       => 'skipped',
                    'message'      => 'Product already exists — skipped.',
                    'warnings'     => $item['warnings'],
                ];
                continue;
            }

            // Build data array EXACTLY like add.php does before calling saveProduct()
            $productData = [
                'category_id'    => $d['category_id'],
                'product_name'   => $d['product_name'],
                'brand_name'     => $d['brand_name'],
                'unit_type'      => $d['unit_type'],
                'units_per_pack' => $d['units_per_pack'],
                'barcode'        => $d['barcode'],       // null if empty (same as add.php)
                'image_path'     => null,                // no image in bulk import
            ];
            if ($d['category_id'] === 1) {
                // Medicine — same keys as add.php
                $productData['generic_name'] = $d['generic_name'] ?? '';
                $productData['dosage_form']  = $d['dosage_form']  ?? '';
                $productData['strength']     = $d['strength'];     // null if empty
            } else {
                // Non-medicine
                $productData['size_value'] = $d['size_value'];     // null if empty
            }

            // Use saveProduct() — the same function add.php uses
            $productId = saveProduct($productData);

            if ($productId && $productId > 0) {
                $importedCount++;
                $productsCreated++;
                $rowResults[] = [
                    'row_num'      => $item['row_num'],
                    'product_name' => $d['product_name'],
                    'status'       => 'success',
                    'message'      => 'Product created successfully.',
                    'warnings'     => $item['warnings'],
                ];
                if (function_exists('logActivity')) {
                    logActivity('product_added', "Bulk import: {$d['product_name']} (ID: $productId)");
                }
            } else {
                $rowResults[] = [
                    'row_num'      => $item['row_num'],
                    'product_name' => $d['product_name'],
                    'status'       => 'failed',
                    'message'      => 'Database error — product not saved.',
                    'warnings'     => $item['warnings'],
                ];
            }
        }

        // Log import (non-fatal if table missing)
        try {
            $db = db();
            $db->query("INSERT INTO bulk_import_logs
                (import_type, filename, total_rows, success_rows, failed_rows, errors_json, imported_by, created_at)
                VALUES (:itype, :fname, :total, :success, :failed, :errors_json, :uid, :now)");
            $failedCount = count(array_filter($rowResults, fn($r) => $r['status'] === 'failed'));
            $db->bind(':itype',       $type);
            $db->bind(':fname',       $origName);
            $db->bind(':total',       count($validated));
            $db->bind(':success',     $importedCount);
            $db->bind(':failed',      $failedCount);
            $db->bind(':errors_json', json_encode([]));
            $db->bind(':uid',         $userId);
            $db->bind(':now',         date('Y-m-d H:i:s'));
            $db->execute();
        } catch (\Throwable $logErr) {
            // Non-fatal — import still succeeded
        }

        $failedCount = count(array_filter($rowResults, fn($r) => $r['status'] === 'failed'));
        echo json_encode([
            'success'          => true,
            'total'            => count($validated),
            'imported'         => $importedCount,
            'products_created' => $productsCreated,
            'batches_added'    => 0,
            'warnings'         => count(array_filter($rowResults, fn($r) => !empty($r['warnings']))),
            'failed'           => $failedCount,
            'rows'             => $rowResults,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Import error: ' . $e->getMessage()]);
    }
    exit;
}