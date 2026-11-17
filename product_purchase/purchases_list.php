<?php
// ============================================================
// FILE: product_purchase/purchases_list.php
// ============================================================
require_once '../config/config.php';
require_permission('purchases');
$page_title = 'Purchases';

// Fetch all purchases with vendor and item count
$sql = "SELECT p.*, v.vendor_name, 
        (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) as item_count
        FROM product_purchase p
        JOIN vendors v ON p.vendor_id = v.id
        ORDER BY p.created_at DESC";
$purchases = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Summary
$totalPurchases = count($purchases);
$totalAmount = 0;
$totalDue = 0;
foreach ($purchases as $p) {
    $totalAmount += $p['grand_total'];
    $totalDue += $p['due_amount'];
}

include '../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Purchases</h1>
        <p class="text-xs text-slate-400 mt-0.5">Vendors se kharida hua stock</p>
    </div>
    <a href="add_purchase.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">
        <i class="fas fa-plus-circle"></i> Add Purchase
    </a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Orders</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-indigo-300 to-indigo-500 bg-clip-text text-transparent mt-1"><?= $totalPurchases ?></div>
    </div>
    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Amount</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-emerald-300 to-emerald-500 bg-clip-text text-transparent mt-1"><?= money($totalAmount) ?></div>
    </div>
    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Due (Payable)</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-rose-300 to-rose-500 bg-clip-text text-transparent mt-1"><?= money($totalDue) ?></div>
    </div>
</div>

<div class="glass-dark rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">
                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Purchase No</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Vendor</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Items</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Grand Total</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Due</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Date</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($purchases)): ?>
                    <?php foreach ($purchases as $i => $p): ?>
                        <tr class="border-b border-white/5 hover:bg-white/3 transition">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 text-indigo-300 font-medium"><?= e($p['purchase_no']) ?></td>
                            <td class="px-4 py-3 text-white"><?= e($p['vendor_name']) ?></td>
                            <td class="px-4 py-3 text-center text-slate-300">
                                <span class="px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-300"><?= $p['item_count'] ?></span>
                            </td>
                            <td class="px-4 py-3 text-right text-white font-medium"><?= money($p['grand_total']) ?></td>
                            <td class="px-4 py-3 text-right text-emerald-300"><?= money($p['paid_amount']) ?></td>
                            <td class="px-4 py-3 text-right">
                                <?php if($p['due_amount'] > 0): ?>
                                    <span class="text-rose-400 font-bold"><?= money($p['due_amount']) ?></span>
                                <?php else: ?>
                                    <span class="text-emerald-500 text-[10px]"><i class="fas fa-check-circle"></i> Clear</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-400"><?= fmt_date($p['purchase_date']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <!-- View Button -->
                                    <a href="view_purchase.php?id=<?= $p['id'] ?>" class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 inline-flex items-center justify-center hover:bg-indigo-500/30 transition" title="View">
                                        <i class="fas fa-eye text-[10px]"></i>
                                    </a>
                                    
                                    <!-- Edit Button -->
                                    <a href="edit_purchase.php?id=<?= $p['id'] ?>" class="w-7 h-7 rounded-lg bg-amber-500/15 text-amber-300 inline-flex items-center justify-center hover:bg-amber-500/30 transition" title="Edit">
                                        <i class="fas fa-edit text-[10px]"></i>
                                    </a>
                                    
                                    <!-- Delete Button -->
                                    <a href="delete_purchase.php?id=<?= $p['id'] ?>" onclick="return confirm('Delete this purchase? Stock will be reverted.')" class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 inline-flex items-center justify-center hover:bg-rose-500/30 transition" title="Delete">
                                        <i class="fas fa-trash text-[10px]"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="text-center py-12 text-slate-500">
                                <i class="fas fa-shopping-cart text-3xl block mb-2 opacity-30"></i>
                                <div class="text-sm">No purchases yet</div>
                                <a href="add_purchase.php" class="inline-block mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline">Add your first purchase →</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>