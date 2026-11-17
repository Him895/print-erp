<?php
// ============================================================
// FILE: services/delete_service.php
// ============================================================
require_once '../config/config.php';
require_permission('services');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid service ID.'); 
    redirect('services_list.php'); 
}

// Check if this service is linked to a sale
$chk = $conn->prepare("SELECT sale_id FROM services WHERE id = ?");
$chk->bind_param('i', $id);
$chk->execute();
$service = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$service) {
    set_flash('error', 'Service not found.');
    redirect('services_list.php');
}

if ($service['sale_id'] !== NULL) {
    set_flash('error', 'This service is linked to a Sale and cannot be deleted directly. Please delete the Sale first.');
    redirect('services_list.php');
}

$conn->begin_transaction();

try {
    // 1. Delete Payments
    $delPay = $conn->prepare("DELETE FROM payments WHERE ref_type = 'service' AND ref_id = ?");
    $delPay->bind_param('i', $id);
    $delPay->execute();
    $delPay->close();

    // 2. Delete Service
    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    set_flash('success', 'Service job and linked payments deleted successfully.');

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Delete failed: ' . $e->getMessage());
}

redirect('services_list.php');
?>