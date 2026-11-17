<?php
// ============================================================
// FILE: product_purchase/delete_purchase.php
// ============================================================
require_once '../config/config.php';
require_permission('purchases');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid purchase ID.'); 
    redirect('purchases_list.php'); 
}

// Fetch items to revert stock
$itemsStmt = $conn->prepare("SELECT product_id, qty FROM purchase_items WHERE purchase_id = ?");
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

if (empty($items)) {
    set_flash('error', 'Purchase not found or has no items.');
    redirect('purchases_list.php');
}

$conn->begin_transaction();

try {
    // 1. Revert Stock (-)
    $stockStmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
    foreach ($items as $item) {
        $stockStmt->bind_param('ii', $item['qty'], $item['product_id']);
        $stockStmt->execute();
    }
    $stockStmt->close();

    // 2. Delete Payments related to this Purchase (Important!)
    // Ye payments table me jayega aur ref_type 'purchase' wale delete karega
    $delPayments = $conn->prepare("DELETE FROM payments WHERE ref_type = 'purchase' AND ref_id = ?");
    $delPayments->bind_param('i', $id);
    $delPayments->execute();
    $delPayments->close();

    // 3. Delete Purchase Items
    $delItems = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
    $delItems->bind_param('i', $id);
    $delItems->execute();
    $delItems->close();

    // 4. Delete Main Purchase Entry
    $delMain = $conn->prepare("DELETE FROM product_purchase WHERE id = ?");
    $delMain->bind_param('i', $id);
    $delMain->execute();
    $delMain->close();

    $conn->commit();
    set_flash('success', 'Purchase deleted, stock reverted, and payments removed.');

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Delete failed: ' . $e->getMessage());
}

redirect('purchases_list.php');
?>