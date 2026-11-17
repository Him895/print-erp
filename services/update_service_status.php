<?php
// ============================================================
// FILE: services/update_service_status.php
// ============================================================
require_once '../config/config.php';
require_permission('services');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('services_list.php');
}

$service_id = (int)($_POST['service_id'] ?? 0);
$status = $_POST['status'] ?? 'pending';

if ($service_id <= 0 || !in_array($status, ['pending', 'in_progress', 'completed', 'delivered'])) {
    set_flash('error', 'Invalid request.');
    redirect('services_list.php');
}

// Update Status
$stmt = $conn->prepare("UPDATE services SET status = ? WHERE id = ?");
$stmt->bind_param('si', $status, $service_id);

if ($stmt->execute()) {
    set_flash('success', "Job status updated to " . ucfirst(str_replace('_', ' ', $status)) . "!");
} else {
    set_flash('error', 'Failed to update status.');
}
$stmt->close();

// Wapas usi page pe redirect kar
redirect('view_service.php?id=' . $service_id);