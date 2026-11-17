<?php
// ============================================================
// FILE: vendors/delete_vendor.php
// ============================================================
require_once '../config/config.php';
require_permission('vendors');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid vendor ID.'); 
    redirect('vendors_list.php'); 
}

// Soft delete
$stmt = $conn->prepare("UPDATE vendors SET is_active = 0 WHERE id = ?");
$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    set_flash('success', 'Vendor deleted successfully.');
} else {
    set_flash('error', 'Vendor not found or already deleted.');
}
$stmt->close();

redirect('vendors_list.php');
?>