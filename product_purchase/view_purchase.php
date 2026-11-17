<?php
// ============================================================
// FILE: product_purchase/view_purchase.php
// ============================================================
require_once '../config/config.php';
require_permission('purchases');
$page_title = 'View Purchase';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('purchases_list.php');

// Fetch main purchase details
$stmt = $conn->prepare("SELECT p.*, v.vendor_name, v.phone, v.address 
    FROM product_purchase p 
    JOIN vendors v ON p.vendor_id = v.id 
    WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) { set_flash('error', 'Purchase not found.'); redirect('purchases_list.php'); }

// Fetch items in this purchase
$itemsStmt = $conn->prepare("SELECT pi.*, pr.product_name, pr.category 
    FROM purchase_items pi 
    JOIN products pr ON pi.product_id = pr.id 
    WHERE pi.purchase_id = ?");
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Purchase Details</h1>
        <p class="text-xs text-slate-400 mt-0.5">Order #<?= e($purchase['purchase_no']) ?></p>
    </div>
    <div class="flex gap-2">
        <a href="edit_purchase.php?id=<?= $id ?>" class="px-4 py-2 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">
            <i class="fas fa-edit mr-1"></i> Edit
        </a>
        <a href="purchases_list.php" class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
    <!-- Vendor Info -->
    <div class="glass-dark rounded-2xl p-5 border border-white/5">
        <h3 class="text-xs font-semibold text-indigo-400 mb-3 uppercase tracking-wider">Vendor Info</h3>
        <div class="text-lg font-bold text-white mb-1"><?= e($purchase['vendor_name']) ?></div>
        <?php if($purchase['phone']): ?><div class="text-sm text-slate-300"><i class="fas fa-phone text-[10px] mr-2 text-slate-500"></i><?= e($purchase['phone']) ?></div><?php endif; ?>
        <?php if($purchase['address']): ?><div class="text-xs text-slate-400 mt-2"><i class="fas fa-map-marker-alt text-[10px] mr-2 text-slate-500"></i><?= e($purchase['address']) ?></div><?php endif; ?>
    </div>

    <!-- Purchase Info -->
    <div class="glass-dark rounded-2xl p-5 border border-white/5">
        <h3 class="text-xs font-semibold text-emerald-400 mb-3 uppercase tracking-wider">Purchase Info</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Date:</span> <span class="text-white"><?= fmt_date($purchase['purchase_date']) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">Total Items:</span> <span class="text-white"><?= count($items) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">Grand Total:</span> <span class="text-emerald-300 font-bold text-lg"><?= money($purchase['grand_total']) ?></span></div>
        </div>
    </div>

    <!-- Payment Info -->
    <div class="glass-dark rounded-2xl p-5 border border-white/5 bg-indigo-500/5">
        <h3 class="text-xs font-semibold text-amber-400 mb-3 uppercase tracking-wider">Payment Details</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Mode:</span> <span class="text-white uppercase text-[10px] bg-white/10 px-2 py-0.5 rounded"><?= e($purchase['payment_mode']) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">Paid Amount:</span> <span class="text-emerald-300 font-bold"><?= money($purchase['paid_amount']) ?></span></div>
            <div class="flex justify-between border-t border-white/10 pt-2 mt-2">
                <span class="text-slate-400">Due Amount:</span> 
                <?php if($purchase['due_amount'] > 0): ?>
                    <span class="text-rose-400 font-bold"><?= money($purchase['due_amount']) ?></span>
                <?php else: ?>
                    <span class="text-emerald-500 text-xs font-semibold"><i class="fas fa-check-circle mr-1"></i> Cleared</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="glass-dark rounded-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-white/10">
        <h3 class="text-sm font-semibold text-white">Products in this Purchase</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">
                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Product Name</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Category</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Qty</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Rate</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr class="border-b border-white/5 hover:bg-white/3 transition">
                        <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                        <td class="px-4 py-3 text-white font-medium"><?= e($item['product_name']) ?></td>
                        <td class="px-4 py-3 text-slate-400 capitalize"><?= e($item['category']) ?></td>
                        <td class="px-4 py-3 text-center text-slate-300"><?= $item['qty'] ?></td>
                        <td class="px-4 py-3 text-right text-slate-300"><?= money($item['rate']) ?></td>
                        <td class="px-4 py-3 text-right text-emerald-300 font-semibold"><?= money($item['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-white/5">
                    <td colspan="5" class="px-4 py-3 text-right text-slate-300 font-semibold">Grand Total:</td>
                    <td class="px-4 py-3 text-right text-emerald-400 font-bold text-sm"><?= money($purchase['grand_total']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>