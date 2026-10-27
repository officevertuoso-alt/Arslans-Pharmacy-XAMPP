<?php
/**
 * ajax/get_expiry_data.php
 * Returns expiry summary data for charts and dashboard widgets.
 * Called by dashboard AJAX and expiry_report.php.
 * Returns JSON: { success, data: { expired, expiring_7, expiring_30, expiring_90, good } }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

$db = db();

$data = [
    'expired'     => 0,
    'expiring_7'  => 0,
    'expiring_30' => 0,
    'expiring_90' => 0,
    'good'        => 0,
    'no_expiry'   => 0,
];

try {
    $row = $db->getOne(
        "SELECT
            SUM(CASE
                WHEN expiry_date < CURDATE() AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS expired,

            SUM(CASE
                WHEN is_expired = 0
                 AND expiry_date IS NOT NULL
                 AND expiry_date >= CURDATE()
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS expiring_7,

            SUM(CASE
                WHEN is_expired = 0
                 AND expiry_date IS NOT NULL
                 AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS expiring_30,

            SUM(CASE
                WHEN is_expired = 0
                 AND expiry_date IS NOT NULL
                 AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                 AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS expiring_90,

            SUM(CASE
                WHEN is_expired = 0
                 AND expiry_date IS NOT NULL
                 AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                 AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS good,

            SUM(CASE
                WHEN expiry_date IS NULL AND remaining_quantity > 0
                THEN 1 ELSE 0 END) AS no_expiry

         FROM batches",
        []
    );

    if ($row) {
        $data = [
            'expired'     => (int)$row['expired'],
            'expiring_7'  => (int)$row['expiring_7'],
            'expiring_30' => (int)$row['expiring_30'],
            'expiring_90' => (int)$row['expiring_90'],
            'good'        => (int)$row['good'],
            'no_expiry'   => (int)$row['no_expiry'],
        ];
    }

    // Value totals (for loss estimation)
    $valueRow = $db->getOne(
        "SELECT
            COALESCE(SUM(CASE WHEN expiry_date < CURDATE() AND remaining_quantity > 0
                THEN remaining_quantity * purchase_price ELSE 0 END), 0) AS expired_value,
            COALESCE(SUM(CASE WHEN is_expired = 0
                AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                AND remaining_quantity > 0
                THEN remaining_quantity * purchase_price ELSE 0 END), 0) AS at_risk_value
         FROM batches",
        []
    );

    $data['expired_value']  = $valueRow ? round((float)$valueRow['expired_value'], 2)  : 0;
    $data['at_risk_value']  = $valueRow ? round((float)$valueRow['at_risk_value'], 2)  : 0;

} catch (Exception $e) {
    error_log('get_expiry_data error: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'data' => $data]);
exit;
