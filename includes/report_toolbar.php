<?php
/**
 * includes/report_toolbar.php
 * Phase 22 – Standardized Report Toolbar
 * Provides: PDF Export | Excel Export | Print
 *
 * Usage:
 *   $toolbar = [
 *     'report_type'    => 'sales',        // matches export_excel.php & export_pdf.php switch
 *     'excel_params'   => $_GET,          // current filter params to pass through
 *     'pdf_params'     => $_GET,
 *     'print_target'   => '#reportTable', // optional – defaults to window.print()
 *     'show_pdf'       => true,
 *     'show_excel'     => true,
 *     'show_print'     => true,
 *   ];
 *   include dirname(__DIR__).'/includes/report_toolbar.php';
 */
if (!isset($toolbar)) $toolbar = [];
$_rt_type   = $toolbar['report_type'] ?? 'sales';
$_rt_excel  = $toolbar['show_excel']  ?? true;
$_rt_pdf    = $toolbar['show_pdf']    ?? true;
$_rt_print  = $toolbar['show_print']  ?? true;

// Build query strings from current GET params
$_rt_params = $toolbar['excel_params'] ?? $_GET;
unset($_rt_params['ajax']);                          // strip internal ajax flag
$_rt_params['report'] = $_rt_type;
$_rt_excel_qs = http_build_query($_rt_params);

$_rt_pdf_params = $toolbar['pdf_params'] ?? $_rt_params;
$_rt_pdf_params['report'] = $_rt_type;
$_rt_pdf_qs = http_build_query($_rt_pdf_params);
?>
<div class="report-toolbar d-flex flex-wrap gap-2 align-items-center">
    <?php if ($_rt_pdf): ?>
    <a href="../reports/export_pdf.php?<?= htmlspecialchars($_rt_pdf_qs) ?>"
       target="_blank"
       class="btn btn-sm btn-danger report-toolbar-btn"
       title="Export PDF">
        <i class="fas fa-file-pdf me-1"></i>PDF
    </a>
    <?php endif; ?>

    <?php if ($_rt_excel): ?>
    <a href="../reports/export_excel.php?<?= htmlspecialchars($_rt_excel_qs) ?>"
       class="btn btn-sm btn-success report-toolbar-btn"
       title="Export Excel">
        <i class="fas fa-file-excel me-1"></i>Excel
    </a>
    <?php endif; ?>

    <?php if ($_rt_print): ?>
    <button type="button"
            class="btn btn-sm btn-secondary report-toolbar-btn"
            onclick="window.print()"
            title="Print">
        <i class="fas fa-print me-1"></i>Print
    </button>
    <?php endif; ?>
</div>

<style>
.report-toolbar { margin-left: auto; }
.report-toolbar-btn {
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.3px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.report-toolbar-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
@media print {
    .report-toolbar { display: none !important; }
}
</style>
