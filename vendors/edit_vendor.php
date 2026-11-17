<?php
// ============================================================
// FILE: vendors/edit_vendor.php
// ============================================================
require_once '../config/config.php';
require_permission('vendors');
$page_title = 'Edit Vendor';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('vendors_list.php');

$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ? AND is_active = 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$vendor) { set_flash('error', 'Vendor not found.'); redirect('vendors_list.php'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['vendor_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $error = 'Vendor name required.';
    } else {
        $stmt = $conn->prepare("UPDATE vendors SET vendor_name=?, phone=?, email=?, address=? WHERE id=?");
        $stmt->bind_param('ssssi', $name, $phone, $email, $address, $id);
        if ($stmt->execute()) {
            set_flash('success', 'Vendor updated successfully!');
            redirect('vendors_list.php');
        } else {
            $error = 'Update failed: ' . $conn->error;
        }
        $stmt->close();
    }
    $vendor = array_merge($vendor, $_POST);
}

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Edit Vendor</h1>
        <p class="text-xs text-slate-400 mt-0.5">ID #<?= $id ?></p>
    </div>
    <a href="vendors_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-2xl">
    <div class="space-y-4">
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Vendor Name *</label>
            <input type="text" name="vendor_name" required value="<?= e($vendor['vendor_name']) ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Phone</label>
                <input type="text" name="phone" value="<?= e($vendor['phone'] ?? '') ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Email</label>
                <input type="email" name="email" value="<?= e($vendor['email'] ?? '') ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
            </div>
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">Address</label>
            <textarea name="address" rows="3" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50 resize-none"><?= e($vendor['address'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-2 pt-4 border-t border-white/5">
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">
                <i class="fas fa-save mr-1"></i> Update Vendor
            </button>
            <a href="vendors_list.php" class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition">Cancel</a>
        </div>
    </div>
</form>

<?php include '../includes/footer.php'; ?>