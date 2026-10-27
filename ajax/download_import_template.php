<?php
/**
 * ajax/download_import_template.php
 * Downloads a clean sample CSV/XLSX template for bulk product import.
 * Columns match add.php exactly — nothing extra, nothing missing.
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/security.php';
// NOTE: No CSRF check here — this is a safe, read-only GET download.
// Auth (requireLogin) + permission (canDo) + role (requirePharmacist) are sufficient.
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'import')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
requirePharmacist();

$type   = $_GET['type']   ?? 'medicine';      // 'medicine' | 'non_medicine'
$format = $_GET['format'] ?? 'csv';           // 'csv' | 'xlsx'

if (!in_array($type,   ['medicine','non_medicine'], true)) $type   = 'medicine';
if (!in_array($format, ['csv','xlsx'],              true)) $format = 'csv';

// ── Column definitions ────────────────────────────────────────────────────
// Exactly what add.php requires. * = required.
if ($type === 'medicine') {
    $headers = [
        'product_name',    // * required
        'brand_name',      // * required
        'generic_name',    // * required (medicine only)
        'dosage_form',     // * required (medicine only) — e.g. Tablet, Syrup, Capsule
        'strength',        //   optional — e.g. 500mg, 125ml/5ml
        'unit_type',       // * required — Strip / Bottle / Box / Tube / Sachet / Vial / Ampoule / Pcs
        'units_per_pack',  // * required — numeric, min 1
        'barcode',         //   optional
    ];
    $samples = [
        [
            'Panadol',
            'GSK',
            'Paracetamol',
            'Tablet',
            '500mg',
            'Strip',
            '10',
            '',
        ],
        [
            'Augmentin',
            'GSK',
            'Amoxicillin/Clavulanate',
            'Tablet',
            '625mg',
            'Strip',
            '7',
            '',
        ],
        [
            'Brufen Syrup',
            'Abbott',
            'Ibuprofen',
            'Syrup',
            '100mg/5ml',
            'Bottle',
            '1',
            '',
        ],
    ];
    $filename = 'medicine_import_template';
} else {
    $headers = [
        'product_name',    // * required
        'brand_name',      // * required
        'size_value',      //   optional — e.g. Small, 250ml, 1CC
        'unit_type',       // * required — Pcs / Box / Bottle / Tube / Sachet / Bag / Roll
        'units_per_pack',  // * required — numeric, min 1
        'barcode',         //   optional
    ];
    $samples = [
        [
            'Surgical Gloves',
            'Cardinal Health',
            'Small',
            'Box',
            '100',
            '',
        ],
        [
            'Thermometer Digital',
            'Certiza',
            '',
            'Pcs',
            '1',
            '',
        ],
        [
            'Hand Sanitizer',
            'Dettol',
            '500ml',
            'Bottle',
            '1',
            '',
        ],
    ];
    $filename = 'non_medicine_import_template';
}

// ── Notes row (instructions) ──────────────────────────────────────────────
// Shown as a commented/italics row so users know which fields are required
if ($type === 'medicine') {
    $notesRow = [
        'REQUIRED',
        'REQUIRED',
        'REQUIRED',
        'REQUIRED — Tablet/Syrup/Capsule/Injection/Cream/Ointment/Drops/Gel/Inhaler/Powder/etc',
        'optional — e.g. 500mg or 125ml/5ml',
        'REQUIRED — Strip/Bottle/Box/Tube/Sachet/Vial/Ampoule/Pcs',
        'REQUIRED — number e.g. 10',
        'optional — scan or leave blank',
    ];
} else {
    $notesRow = [
        'REQUIRED',
        'REQUIRED',
        'optional — e.g. Small, 250ml, 1CC',
        'REQUIRED — Pcs/Box/Bottle/Tube/Sachet/Bag/Roll',
        'REQUIRED — number e.g. 1',
        'optional — scan or leave blank',
    ];
}

// ── Output ────────────────────────────────────────────────────────────────
// If xlsx requested but ZipArchive not available, fall back to CSV
if ($format === 'xlsx') {
    if (class_exists('ZipArchive')) {
        outputXlsx($headers, $notesRow, $samples, $filename);
    } else {
        outputCsv($headers, $notesRow, $samples, $filename); // fallback: CSV opens fine in Excel
    }
} else {
    outputCsv($headers, $notesRow, $samples, $filename);
}

// ── CSV output ────────────────────────────────────────────────────────────
function outputCsv(array $headers, array $notesRow, array $samples, string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, $headers);
    fputcsv($out, $notesRow);   // row 2 = instructions
    foreach ($samples as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── XLSX output (pure PHP, no library needed) ─────────────────────────────
function outputXlsx(array $headers, array $notesRow, array $samples, string $filename): void {
    // Build a minimal XLSX manually using ZIP + XML
    // Sheet data
    $allRows = array_merge([$headers, $notesRow], $samples);

    $sharedStrings = [];
    $ssIndex = [];

    // Collect all unique strings
    foreach ($allRows as $row) {
        foreach ($row as $cell) {
            $val = (string)$cell;
            if (!isset($ssIndex[$val])) {
                $ssIndex[$val] = count($sharedStrings);
                $sharedStrings[] = $val;
            }
        }
    }

    // sheet1.xml
    $sheetXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet">';
    $sheetXml .= '<sheetData>';

    $colLetters = ['A','B','C','D','E','F','G','H','I','J'];
    foreach ($allRows as $ri => $row) {
        $rowNum = $ri + 1;
        $sheetXml .= '<row r="'.$rowNum.'">';
        foreach ($row as $ci => $cell) {
            $col = $colLetters[$ci] ?? chr(65 + $ci);
            $ref = $col . $rowNum;
            $si  = $ssIndex[(string)$cell];
            $sheetXml .= '<c r="'.$ref.'" t="s"><v>'.$si.'</v></c>';
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    // sharedStrings.xml
    $ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
    foreach ($sharedStrings as $s) {
        $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1, 'UTF-8').'</t></si>';
    }
    $ssXml .= '</sst>';

    // workbook.xml
    $wbXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $wbXml .= '<sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets>';
    $wbXml .= '</workbook>';

    // [Content_Types].xml
    $ctXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ctXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $ctXml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $ctXml .= '<Default Extension="xml"  ContentType="application/xml"/>';
    $ctXml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $ctXml .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $ctXml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $ctXml .= '</Types>';

    // _rels/.rels
    $relsXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $relsXml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $relsXml .= '</Relationships>';

    // xl/_rels/workbook.xml.rels
    $wbRelsXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbRelsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $wbRelsXml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
    $wbRelsXml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $wbRelsXml .= '</Relationships>';

    // Build ZIP in memory
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',              $ctXml);
    $zip->addFromString('_rels/.rels',                      $relsXml);
    $zip->addFromString('xl/workbook.xml',                  $wbXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels',       $wbRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml',         $sheetXml);
    $zip->addFromString('xl/sharedStrings.xml',             $ssXml);
    $zip->close();

    $xlsxContent = file_get_contents($tmp);
    @unlink($tmp);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . strlen($xlsxContent));
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xlsxContent;
    exit;
}