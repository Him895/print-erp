<?php
require_once '../config/config.php';
require_login();
$page_title = 'Edit Size';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('sizes_list.php');

$stmt = $conn->prepare("SELECT * FROM sizes WHERE id = ?");
$stmt->bind_param('i', $id); $stmt->execute();
$s = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$s) { set_flash('error', 'Size not found.'); redirect('sizes_list.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['size_name'] ?? '');
    
    if ($name === '') {
        $error = 'Name required.';
    } else {
        // sort_order hata diya update query se
        $stmt = $conn->prepare("UPDATE sizes SET size_name=? WHERE id=?");
        $stmt->bind_param('si', $name, $id);
        if ($stmt->execute()) { 
            set_flash('success', 'Size updated!'); 
            redirect('sizes_list.php'); 
        } else {
            $error = 'Update failed.';
        }
        $stmt->close();
    }
    $s = array_merge($s, $_POST);
}
include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div><h1 class="text-2xl font-bold text-white">Edit Size</h1></div>
    <a href="sizes_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition"><i class="fas fa-arrow-left mr-1"></i> Back</a>
</div>

<?php if ($error): ?><div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300"><i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?></div><?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-md space-y-4">
    <div>
        <label class="text-xs text-slate-400 mb-1 block">Size Name *</label>
        <input type="text" name="size_name" required value="<?= e($s['size_name']) ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
    </div>
    
    <div class="flex items-center gap-2 pt-4 border-t border-white/5">
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold"><i class="fas fa-save mr-1"></i> Update</button>
        <a href="sizes_list.php" class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm">Cancel</a>
    </div>
</form>

<?php include '../includes/footer.php'; ?>