
<?php
// ============================================================
// FILE: sales/delete_sale.php
// DESCRIPTION: Delete Sale + Restore Stock + Remove Linked Service
// POST Only
// ============================================================

require_once '../config/config.php';
require_permission('sales');

// ------------------------------------------------------------
// 1. POST REQUEST VALIDATION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('sales_list.php');
}

$sale_id = (int)($_POST['id'] ?? 0);

if ($sale_id <= 0) {
    set_flash('error', 'Invalid sale ID.');
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 2. FETCH SALE + LINKED SERVICE STATUS
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT 
        s.*,
        sj.status AS service_status
    FROM sales s
    LEFT JOIN services sj 
        ON s.service_job_id = sj.id
    WHERE s.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $sale_id);
$stmt->execute();

$sale = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 3. CHECK LINKED SERVICE STATUS
// ------------------------------------------------------------

// Service pending nahi hai toh sale delete nahi hogi
if (
    !empty($sale['service_job_id']) &&
    !empty($sale['service_status']) &&
    $sale['service_status'] !== 'pending'
) {
    set_flash(
        'error',
        "Sale ({$sale['sale_no']}) delete nahi ho sakti: linked print service ab '{$sale['service_status']}' hai. Pehle service ka kaam dekh lein."
    );

    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 4. START DATABASE TRANSACTION
// ------------------------------------------------------------

$conn->begin_transaction();

try {

    // --------------------------------------------------------
    // 4.1 RESTORE PRODUCT STOCK
    // --------------------------------------------------------

    // Cancelled sale ka stock already restore ho chuka hota hai
    if ($sale['status'] !== 'cancelled') {

        $itemsRes = $conn->prepare("
            SELECT product_id, qty
            FROM sale_items
            WHERE sale_id = ?
        ");

        $itemsRes->bind_param('i', $sale_id);
        $itemsRes->execute();

        $items = $itemsRes->get_result()->fetch_all(MYSQLI_ASSOC);

        $itemsRes->close();

        $stockStmt = $conn->prepare("
            UPDATE products
            SET stock_qty = stock_qty + ?
            WHERE id = ?
        ");

        foreach ($items as $item) {

            $qty = (int)$item['qty'];
            $product_id = (int)$item['product_id'];

            $stockStmt->bind_param('ii', $qty, $product_id);
            $stockStmt->execute();
        }

        $stockStmt->close();
    }

    // --------------------------------------------------------
    // 4.2 DELETE SALE PAYMENTS
    // --------------------------------------------------------

    $payStmt = $conn->prepare("
        DELETE FROM payments
        WHERE ref_type = 'sale'
        AND ref_id = ?
    ");

    $payStmt->bind_param('i', $sale_id);
    $payStmt->execute();

    $payStmt->close();

    // --------------------------------------------------------
    // 4.3 DELETE LINKED SERVICE + ITS PAYMENTS
    // --------------------------------------------------------

    if (!empty($sale['service_job_id'])) {

        $service_id = (int)$sale['service_job_id'];

        // First unlink service from sale
        $unlink = $conn->prepare("
            UPDATE sales
            SET service_job_id = NULL
            WHERE id = ?
        ");

        $unlink->bind_param('i', $sale_id);
        $unlink->execute();

        $unlink->close();

        // Delete linked service payments
        $svcPay = $conn->prepare("
            DELETE FROM payments
            WHERE ref_type = 'service'
            AND ref_id = ?
        ");

        $svcPay->bind_param('i', $service_id);
        $svcPay->execute();

        $svcPay->close();

        // Delete linked service
        $svcDel = $conn->prepare("
            DELETE FROM services
            WHERE id = ?
        ");

        $svcDel->bind_param('i', $service_id);
        $svcDel->execute();

        $svcDel->close();
    }

    // --------------------------------------------------------
    // 4.4 DELETE SALE ITEMS
    // --------------------------------------------------------

    $itemDel = $conn->prepare("
        DELETE FROM sale_items
        WHERE sale_id = ?
    ");

    $itemDel->bind_param('i', $sale_id);
    $itemDel->execute();

    $itemDel->close();

    // --------------------------------------------------------
    // 4.5 DELETE SALE
    // --------------------------------------------------------

    $saleDel = $conn->prepare("
        DELETE FROM sales
        WHERE id = ?
    ");

    $saleDel->bind_param('i', $sale_id);
    $saleDel->execute();

    $saleDel->close();

    // --------------------------------------------------------
    // 5. COMMIT TRANSACTION
    // --------------------------------------------------------

    $conn->commit();

    set_flash(
        'success',
        "Sale ({$sale['sale_no']}) delete ho gayi. Stock wapas add kar diya gaya."
    );

} catch (Throwable $e) {

    // Any error: rollback all database changes
    $conn->rollback();

    error_log("Sale Delete Error [ID: {$sale_id}]: " . $e->getMessage());

    set_flash(
        'error',
        'Sale delete karte waqt error aa gaya. Please dobara try karein.'
    );
}

// ------------------------------------------------------------
// 6. REDIRECT
// ------------------------------------------------------------

redirect('sales_list.php');
