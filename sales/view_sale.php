
<?php
// ============================================================
// FILE: sales/view_sale.php
// ============================================================
require_once '../config/config.php';
require_permission('sales');
$page_title = 'View Sale';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('sales_list.php');

// Fetch main sale
$stmt = $conn->prepare("SELECT s.*, c.customer_name, c.phone, c.address, c.gst_no 
    FROM sales s 
    LEFT JOIN customers c ON s.client_id = c.id 
    WHERE s.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) { set_flash('error', 'Sale not found.'); redirect('sales_list.php'); }

// Fetch items
$itemsStmt = $conn->prepare("SELECT si.*, p.product_name, p.category 
    FROM sale_items si 
    JOIN products p ON si.product_id = p.id 
    WHERE si.sale_id = ?");
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Invoice Details</h1>
        <p class="text-xs text-slate-400 mt-0.5">#<?= e($sale['sale_no']) ?></p>
    </div>
    <div class="flex gap-2">
        <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition">
            <i class="fas fa-print mr-1"></i> Print Bill
        </button>
        <a href="sales_list.php" class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>
</div>

<div class="glass-dark rounded-2xl p-6 max-w-3xl mx-auto print-area">
    
    <!-- Bill Header -->
    <div class="flex justify-between items-start border-b border-white/10 pb-6 mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white mb-1">PrintERP</h2>
            <p class="text-xs text-slate-400">T-Shirt / Shirt / Bottle Printing</p>
        </div>
        <div class="text-right">
            <div class="text-lg font-bold text-indigo-300 mb-1">INVOICE</div>
            <div class="text-xs text-slate-400">No: <?= e($sale['sale_no']) ?></div>
            <div class="text-xs text-slate-400">Date: <?= fmt_date($sale['sale_date']) ?></div>
            <div class="text-xs text-slate-400 mt-1 uppercase">Status: <?= $sale['status'] ?></div>
        </div>
    </div>

    <!-- Customer Info -->
    <div class="mb-6 flex justify-between">
        <div>
            <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-1">Billed To:</div>
            <?php if($sale['client_id'] > 0): ?>
                <div class="text-white font-semibold"><?= e($sale['customer_name'] ?? 'Walk-in Customer') ?></div>
                <?php if($sale['phone']): ?><div class="text-xs text-slate-400"><i class="fas fa-phone text-[10px] mr-1"></i><?= e($sale['phone']) ?></div><?php endif; ?>
                <?php if($sale['gst_no']): ?><div class="text-xs text-slate-400">GST: <?= e($sale['gst_no']) ?></div><?php endif; ?>
            <?php else: ?>
                <div class="text-white font-semibold">Walk-in Customer</div>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-1">Payment Mode:</div>
            <div class="text-white font-semibold uppercase"><?= e($sale['payment_mode']) ?></div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="w-full text-xs mb-6">
        <thead>
            <tr class="text-left border-b border-white/10 bg-white/5">
                <th class="px-3 py-2 text-slate-400 font-medium">Product</th>
                <th class="px-3 py-2 text-slate-400 font-medium text-center">Qty</th>
                <th class="px-3 py-2 text-slate-400 font-medium text-right">Rate</th>
                <th class="px-3 py-2 text-slate-400 font-medium text-right">Amount</th>
                <th class="px-3 py-2 text-slate-400 font-medium text-right">Print</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr class="border-b border-white/5">
                    <td class="px-3 py-3">
                        <div class="text-white font-medium"><?= e($item['product_name']) ?></div>
                        <?php if($item['print_charge_total'] > 0): ?>
                            <div class="text-[10px] text-amber-300/80 mt-0.5">
                                <i class="fas fa-print"></i> <?= e($item['print_type']) ?> 
                                <?php if($item['print_notes']): ?> - <?= e($item['print_notes']) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-center text-slate-300"><?= $item['qty'] ?></td>
                    <td class="px-3 py-3 text-right text-slate-300"><?= money($item['rate']) ?></td>
                    <td class="px-3 py-3 text-right text-slate-300"><?= money($item['amount']) ?></td>
                    <td class="px-3 py-3 text-right text-amber-300"><?= money($item['print_charge_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals & Payment -->
    <div class="flex flex-col md:flex-row justify-end gap-6 border-t border-white/10 pt-4">
        
        <!-- Payment Info -->
        <div class="w-full md:w-1/2 space-y-1 text-xs">
            <?php if($sale['paid_amount'] > 0): ?>
                <div class="flex justify-between text-slate-400"><span>Paid Amount (<?= strtoupper($sale['payment_mode']) ?>):</span> <span class="text-emerald-400 font-bold"><?= money($sale['paid_amount']) ?></span></div>
            <?php endif; ?>
            <?php if($sale['due_amount'] > 0): ?>
                <div class="flex justify-between text-slate-400"><span>Due Amount (Udhar):</span> <span class="text-rose-400 font-bold"><?= money($sale['due_amount']) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Bill Totals -->
        <div class="w-full md:w-64 space-y-2">
            <div class="flex justify-between text-xs text-slate-400"><span>Product Total:</span> <span class="text-white"><?= money($sale['product_total']) ?></span></div>
            <div class="flex justify-between text-xs text-slate-400"><span>Print Total:</span> <span class="text-amber-300"><?= money($sale['print_total']) ?></span></div>
            <div class="flex justify-between text-xs text-slate-400 border-t border-white/5 pt-2"><span>Sub Total:</span> <span class="text-white"><?= money($sale['sub_total']) ?></span></div>
            
            <?php if($sale['gst_amount'] > 0): ?>
            <div class="flex justify-between text-xs text-slate-400"><span>GST (<?= $sale['gst_percent'] ?>%):</span> <span class="text-indigo-300"><?= money($sale['gst_amount']) ?></span></div>
            <?php endif; ?>
            
            <div class="flex justify-between border-t border-white/10 pt-2 text-sm font-bold text-slate-300"><span>Grand Total:</span> <span class="text-emerald-400 text-lg"><?= money($sale['grand_total']) ?></span></div>
        </div>
    </div>

    <div class="mt-8 pt-4 border-t border-white/10 text-center text-[10px] text-slate-500">
        Thank you for your business! 
    </div>

</div>

<style>
    @media print {
        body * { visibility: hidden; }
        .print-area, .print-area * { visibility: visible; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; background: white; color: black; }
        .print-area .text-white, .print-area .text-slate-400, .print-area .text-indigo-300, .print-area .text-amber-300, .print-area .text-emerald-400, .print-area .text-rose-400 { color: black !important; }
        .print-area .bg-white\/5 { background: #f3f4f6 !important; }
        .print-area .border-white\/10 { border-color: #e5e7eb !important; }
    }
</style>

<?php include '../includes/footer.php'; ?>
