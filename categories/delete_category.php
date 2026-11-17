<?php
// ============================================================
// FILE: categories/delete_category.php
// ============================================================
require_once '../config/config.php';
require_permission('categories');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { 
    set_flash('error', 'Invalid category ID.'); 
    redirect('categories_list.php'); 
}

// Check if products use it
$chk = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE category_id = ? AND is_active = 1");
$chk->bind_param('i', $id);
$chk->execute();
$cnt = $chk->get_result()->fetch_assoc()['c'];
$chk->close();

if ($cnt > 0) {
    set_flash('error', "This category has $cnt products. Delete products first.");
    redirect('categories_list.php');
}

// Delete category
$stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
$stmt->bind_param('i', $id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    set_flash('success', 'Category deleted permanently!');
} else {
    set_flash('error', 'Category not found or already deleted.');
}
$stmt->close();

redirect('categories_list.php');
?>