<?php
/**
 * inventory_functions.php – Centralised stock management
 * Handles stock adjustments, product stock refresh, and locking.
 */

if (!function_exists('lockBatchForUpdate')) {
    function lockBatchForUpdate(int $batchId): array {
        $pdo = _getPDO();
        $stmt = $pdo->prepare("SELECT * FROM batches WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new Exception("Batch $batchId not found");
        return $batch;
    }
}

if (!function_exists('adjustBatchStock')) {
    function adjustBatchStock(int $batchId, int $deltaPieces, string $movementType, 
                               int $referenceId, string $referenceType, string $note = ''): bool {
        $pdo = _getPDO();
        $pdo->beginTransaction();
        try {
            $batch = lockBatchForUpdate($batchId);
            $newQty = max(0, (int)$batch['remaining_quantity'] + $deltaPieces);
            $pdo->prepare("UPDATE batches SET remaining_quantity = :new WHERE id = :id")
                ->execute([':new' => $newQty, ':id' => $batchId]);
            
            // Log movement
            _pdoRun(
                "INSERT INTO stock_movements 
                 (batch_id, product_id, movement_type, quantity, reference_id, reference_type,
                  previous_quantity, new_quantity, notes)
                 VALUES (:bid, :pid, :type, :qty, :refid, :reftype, :prev, :new, :note)",
                [
                    ':bid'    => $batchId,
                    ':pid'    => $batch['product_id'],
                    ':type'   => $movementType,
                    ':qty'    => abs($deltaPieces),
                    ':refid'  => $referenceId,
                    ':reftype'=> $referenceType,
                    ':prev'   => $batch['remaining_quantity'],
                    ':new'    => $newQty,
                    ':note'   => $note,
                ]
            );
            $pdo->commit();
            updateProductStockAndPrice($batch['product_id']);
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("adjustBatchStock: " . $e->getMessage());
            return false;
        }
    }
}

// Product stock refresh – ensure it updates products.stock_quantity correctly
if (!function_exists('updateProductStockAndPrice')) {
    function updateProductStockAndPrice(int $productId): void {
        _pdoRun(
            "UPDATE products p
             SET p.stock_quantity = COALESCE((
                 SELECT SUM(b.remaining_quantity)
                 FROM batches b
                 WHERE b.product_id = p.id
                   AND b.is_expired = 0
                   AND b.remaining_quantity > 0
             ), 0)
             WHERE p.id = :pid",
            [':pid' => $productId]
        );
    }
}
?>