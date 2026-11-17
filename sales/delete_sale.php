<?php
// ============================================================
// FILE: sales/delete_sale.php
// Sale delete (POST only): stock wapas, payments, linked service sab saaf
// ============================================================
require_once '../config/config.php';
require_permission('sales');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('sales_list.php');
}

$sale_id = (int)($_POST['id'] ?? 0);
if ($sale_id <= 0) {
    set_flash('error', 'Invalid sale ID.');
    redirect('sales_list.php');
}

// ============ FETCH SALE (+ linked service status) ============
$stmt = $conn->prepare("SELECT s.*, sj.status AS service_status
    FROM sales s
    LEFT JOIN services sj ON s.service_job_id = sj.id
    WHERE s.id = ?");
$stmt->bind_param('i', $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// Service agar shuru/complete ho chuki hai toh sale delete nahi karenge
if (!empty($sale['service_job_id']) && !empty($sale['service_status']) && $sale['service_status'] !== 'pending') {
    set_flash('error', "Sale ({$sale['sale_no']}) delete nahi ho sakti: linked print service ab '{$sale['service_status']}' hai. Pehle service ka kaam dekh lein.");
    redirect('sales_list.php');
}

$conn->begin_transaction();

try {
    // 1. Stock wapas (cancelled sale ka stock pehle hi wapas hua hoga, isliye skip)
    if ($sale['status'] !== 'cancelled') {
        $itemsRes = $conn->prepare("SELECT product_id, qty FROM sale_items WHERE sale_id = ?");
        $itemsRes->bind_param('i', $sale_id);
        $itemsRes->execute();
        $items = $itemsRes->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsRes->close();

        $stockStmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
        foreach ($items as $it) {
            $stockStmt->bind_param('ii', $it['qty'], $it['product_id']);
            $stockStmt->execute();
        }
        $stockStmt->close();
    }

    // 2. Sale ke payments delete
    $payStmt = $conn->prepare("DELETE FROM payments WHERE ref_type = 'sale' AND ref_id = ?");
    $payStmt->bind_param('i', $sale_id);
    $payStmt->execute();
    $payStmt->close();

    // 3. Linked service job (auto-generated) + uske payments delete
    if (!empty($sale['service_job_id'])) {
        $svc_id = (int)$sale['service_job_id'];

        $unlink = $conn->prepare("UPDATE sales SET service_job_id = NULL WHERE id = ?");
        $unlink->bind_param('i', $sale_id);
        $unlink->execute();
        $unlink->close();

        $svcPay = $conn->prepare("DELETE FROM payments WHERE ref_type = 'service' AND ref_id = ?");
        $svcPay->bind_param('i', $svc_id);
        $svcPay->execute();
        $svcPay->close();

        $svcDel = $conn->prepare("DELETE FROM services WHERE id = ?");
        $svcDel->bind_param('i', $svc_id);
        $svcDel->execute();
        $svcDel->close();
    }

    // 4. Sale items + sale delete
    $itemDel = $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?");
    $itemDel->bind_param('i', $sale_id);
    $itemDel->execute();
    $itemDel->close();

    $saleDel = $conn->prepare("DELETE FROM sales WHERE id = ?");
    $saleDel->bind_param('i', $sale_id);
    $saleDel->execute();
    $saleDel->close();

    $conn->commit();
    set_flash('success', "Sale ({$sale['sale_no']}) delete ho gayi. Stock wapas add kar diya gaya.");

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Delete me error: ' . $e->getMessage());
}

redirect('sales_list.php');