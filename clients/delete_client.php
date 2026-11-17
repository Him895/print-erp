<?php
// ============================================================
// FILE: clients/delete_client.php
// ============================================================
require_once '../config/config.php';
require_permission('clients');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid client ID.'); 
    redirect('clients_list.php'); 
}

// Soft delete
$stmt = $conn->prepare("UPDATE clients SET is_active = 0 WHERE id = ?");
$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    set_flash('success', 'Client deleted successfully.');
} else {
    set_flash('error', 'Client not found or already deleted.');
}
$stmt->close();

redirect('clients_list.php');
?>