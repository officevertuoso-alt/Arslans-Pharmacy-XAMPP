<?php
/**
 * ajax/get_credit_customers.php
 * Returns unique customers with pending dues for POS autocomplete
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$db = db();
try {
    $sql = "
        SELECT 
            cc.customer_phone AS phone,
            MAX(cc.customer_name) AS name,
            COALESCE(SUM(CASE WHEN cc.status='active' 
                THEN (COALESCE(s.total, cc.total_amount) 
                      - COALESCE(s.paid_amount,0) 
                      - COALESCE((SELECT SUM(cp2.amount) FROM credit_payments cp2
                                  WHERE cp2.credit_id=cc.id
                                    AND (cp2.note IS NULL OR (cp2.note NOT LIKE 'Upfront%' AND cp2.note NOT LIKE 'upfront%'))),0))
                ELSE 0 END), 0) AS due_amount
        FROM customer_credits cc
        LEFT JOIN sales s ON s.id = cc.sale_id
        WHERE cc.customer_name IS NOT NULL AND cc.customer_name != ''
          AND cc.customer_name != 'Walk-in Customer'
        GROUP BY cc.customer_phone
        ORDER BY due_amount DESC, name ASC
        LIMIT 300
    ";
    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach($rows as $r){
        if(empty(trim($r['name'] ?? ''))) continue;
        $out[] = [
            'customer_name'  => trim($r['name']),
            'customer_phone' => trim($r['phone'] ?? ''),
            'due_amount'     => (float)($r['due_amount'] ?? 0),
        ];
    }
    echo json_encode($out);
} catch(Exception $e){
    echo json_encode(['error' => $e->getMessage()]);
}