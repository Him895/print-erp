<?php
// ============================================================
// FILE: vendors/vendors_list.php
// ============================================================
require_once '../config/config.php';
require_permission('vendors');
$page_title = 'Vendors';

// Fetch all vendors
$vendors = $conn->query("SELECT * FROM vendors WHERE is_active = 1 ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$totalVendors = count($vendors);

include '../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Vendors</h1>
        <p class="text-xs text-slate-400 mt-0.5">Manage your suppliers and vendors</p>
    </div>
    <a href="add_vendor.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">
        <i class="fas fa-plus-circle"></i> Add Vendor
    </a>
</div>

<!-- Summary Card -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Vendors</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-indigo-300 to-indigo-500 bg-clip-text text-transparent mt-1"><?= $totalVendors ?></div>
    </div>
</div>

<!-- Table -->
<div class="glass-dark rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">
                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Vendor Name</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Contact</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Address</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($vendors)): ?>
                    <?php foreach ($vendors as $i => $v): ?>
                        <tr class="border-b border-white/5 hover:bg-white/3 transition">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-9 h-9 rounded-lg flex items-center justify-center text-[13px] bg-indigo-500/15 text-indigo-300">
                                        <i class="fas fa-truck"></i>
                                    </span>
                                    <span class="text-white font-medium"><?= e($v['vendor_name']) ?></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                <?php if($v['phone']): ?><div><i class="fas fa-phone text-[10px] mr-1 text-slate-500"></i><?= e($v['phone']) ?></div><?php endif; ?>
                                <?php if($v['email']): ?><div><i class="fas fa-envelope text-[10px] mr-1 text-slate-500"></i><?= e($v['email']) ?></div><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-400 max-w-[200px] truncate" title="<?= e($v['address']) ?>"><?= e($v['address'] ?: '-') ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="edit_vendor.php?id=<?= $v['id'] ?>" class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 flex items-center justify-center hover:bg-indigo-500/30 transition" title="Edit">
                                        <i class="fas fa-edit text-[10px]"></i>
                                    </a>
                                    <a href="delete_vendor.php?id=<?= $v['id'] ?>" onclick="return confirm('Delete this vendor?')" class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 flex items-center justify-center hover:bg-rose-500/30 transition" title="Delete">
                                        <i class="fas fa-trash text-[10px]"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="text-center py-12 text-slate-500">
                                <i class="fas fa-truck text-3xl block mb-2 opacity-30"></i>
                                <div class="text-sm">No vendors yet</div>
                                <a href="add_vendor.php" class="inline-block mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline">Add your first vendor →</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>