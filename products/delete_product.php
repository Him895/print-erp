<?php
// ============================================================
// FILE: products/delete_product.php
// Soft delete (is_active = 0)
// ============================================================
require_once '../config/config.php';
require_permission('products');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid product ID.'); 
    redirect('products_list.php'); 
}

// Check if product is used in Sales
$saleChk = $conn->prepare("SELECT COUNT(*) AS c FROM sale_items WHERE product_id = ?");
$saleChk->bind_param('i', $id);
$saleChk->execute();
$saleCnt = $saleChk->get_result()->fetch_assoc()['c'];
$saleChk->close();

// Check if product is used in Purchase
$purChk = $conn->prepare("SELECT COUNT(*) AS c FROM purchase_items WHERE product_id = ?");
$purChk->bind_param('i', $id);
$purChk->execute();
$purCnt = $purChk->get_result()->fetch_assoc()['c'];
$purChk->close();

if ($saleCnt > 0 || $purCnt > 0) {
    // Product used hai, isliye soft delete karenge (is_active = 0)
    $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        set_flash('success', 'Product archived (used in sales/purchases so cannot be hard deleted).');
    } else {
        set_flash('error', 'Product not found or already deleted.');
    }
    $stmt->close();
} else {
    // Product use nahi hua, direct soft delete
    $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        set_flash('success', 'Product deleted successfully.');
    } else {
        set_flash('error', 'Product not found or already deleted.');
    }
    $stmt->close();
}

redirect('products_list.php');
?>