
<?php
// ============================================================
// FILE: customers/delete_customer.php
// DESCRIPTION: Soft Delete Customer
// ============================================================

require_once '../config/config.php';

require_permission('customers');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    set_flash('error', 'Invalid customer ID.');
    redirect('customers_list.php');
}

// Soft delete
$stmt = $conn->prepare("
    UPDATE customers
    SET is_active = 0
    WHERE id = ?
");

$stmt->bind_param('i', $id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    set_flash('success', 'Customer deleted successfully.');
} else {
    set_flash('error', 'Customer not found or already deleted.');
}

$stmt->close();

redirect('customers_list.php');
?>
