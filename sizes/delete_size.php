<?php
require_once '../config/config.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('sizes_list.php');

$chk = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE size_id = ? AND is_active = 1");
$chk->bind_param('i', $id); $chk->execute();
$cnt = $chk->get_result()->fetch_assoc()['c']; $chk->close();

if ($cnt > 0) { set_flash('error', "This size used by $cnt products."); redirect('sizes_list.php'); }

$stmt = $conn->prepare("UPDATE sizes SET is_active = 0 WHERE id = ?");
$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) set_flash('success', 'Size deleted.');
else set_flash('error', 'Not found.');
$stmt->close();
redirect('sizes_list.php');
?>