
<?php
// ============================================================
// FILE: sales/sales_list.php
// RECEIPT + INVOICE BUTTON LOGIC
// ============================================================
require_once '../config/config.php';
require_permission('sales');
$page_title = 'Sales';

// Fetch sales, linked service status and latest confirmed payment ID
$sql = "SELECT 
            s.*,
            c.client_name,
            sj.status AS service_status,
            (
                SELECT MAX(p.id)
                FROM payments p
                WHERE p.ref_type = 'sale'
                  AND p.ref_id = s.id
                  AND p.status = 'confirmed'
            ) AS last_payment_id
        FROM sales s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN services sj ON s.service_job_id = sj.id
        ORDER BY s.created_at DESC";

$result = $conn->query($sql);

if (!$result) {
    die('Sales query failed: ' . e($conn->error));
}

$sales = $result->fetch_all(MYSQLI_ASSOC);

// Summary
$totalSales = count($sales);
$totalRevenue = 0;
$totalDue = 0;

foreach ($sales as $s) {
    if ($s['status'] !== 'cancelled') {
        $totalRevenue += (float)$s['grand_total'];
        $totalDue += (float)$s['due_amount'];
    }
}

include '../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Sales</h1>
        <p class="text-xs text-slate-400 mt-0.5">Customer orders and invoices</p>
    </div>

    <a href="add_sale.php"
       class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">
        <i class="fas fa-plus-circle"></i> New Sale
    </a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Sales</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-indigo-300 to-indigo-500 bg-clip-text text-transparent mt-1">
            <?= $totalSales ?>
        </div>
    </div>

    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Revenue</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-emerald-300 to-emerald-500 bg-clip-text text-transparent mt-1">
            <?= money($totalRevenue) ?>
        </div>
    </div>

    <div class="glass-dark rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Due (Udhar)</div>
        <div class="text-2xl font-bold bg-gradient-to-r from-rose-300 to-rose-500 bg-clip-text text-transparent mt-1">
            <?= money($totalDue) ?>
        </div>
    </div>
</div>

<div class="glass-dark rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">
                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Invoice No</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Client</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Grand Total</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-right">Due</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Status</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Date</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($sales)): ?>

                    <?php foreach ($sales as $i => $s):

                        $statusClass = 'bg-amber-500/15 text-amber-300';

                        if ($s['status'] === 'completed') {
                            $statusClass = 'bg-emerald-500/15 text-emerald-300';
                        } elseif ($s['status'] === 'cancelled') {
                            $statusClass = 'bg-rose-500/15 text-rose-300';
                        }

                        $saleId = (int)$s['id'];
                        $lastPay = (int)($s['last_payment_id'] ?? 0);

                        // Print service completion check
                        $hasService = !empty($s['service_job_id']);
                        $serviceCompleted = (
                            $hasService &&
                            strtolower((string)$s['service_status']) === 'completed'
                        );

                        // Invoice is available only after linked service completion.
                        // Sales without a linked service can view invoice normally.
                        $canViewInvoice = !$hasService || $serviceCompleted;

                    ?>

                    <tr class="border-b border-white/5 hover:bg-white/3 transition">

                        <td class="px-4 py-3 text-slate-500">
                            <?= $i + 1 ?>
                        </td>

                        <td class="px-4 py-3 text-indigo-300 font-medium">
                            <?= e($s['sale_no']) ?>
                        </td>

                        <td class="px-4 py-3 text-white">
                            <?= e($s['client_name'] ?? 'Walk-in') ?>
                        </td>

                        <td class="px-4 py-3 text-right text-white font-medium">
                            <?= money($s['grand_total']) ?>
                        </td>

                        <td class="px-4 py-3 text-right text-emerald-300">
                            <?= money($s['paid_amount']) ?>
                        </td>

                        <td class="px-4 py-3 text-right text-rose-300 font-semibold">
                            <?= money($s['due_amount']) ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full <?= $statusClass ?>">
                                <?= ucfirst($s['status']) ?>
                            </span>
                        </td>

                        <td class="px-4 py-3 text-slate-400">
                            <?= fmt_date($s['sale_date']) ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1.5">

                                <?php if ($lastPay > 0): ?>
                                    <!-- Payment Receipt: available even after service completion -->
                                    <a href="view_sale_receipt.php?id=<?= $lastPay ?>&sale_id=<?= $saleId ?>"
                                       class="w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-300 inline-flex items-center justify-center hover:bg-emerald-500/30 transition"
                                       title="View Payment Receipt / Payment History">
                                        <i class="fas fa-receipt text-[10px]"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($canViewInvoice): ?>
                                    <!-- Final Sales Invoice -->
                                    <a href="view_sale_invoice.php?id=<?= $saleId ?>"
                                       class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 inline-flex items-center justify-center hover:bg-indigo-500/30 transition"
                                       title="View Sales Invoice">
                                        <i class="fas fa-file-invoice text-[10px]"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ((float)$s['due_amount'] > 0 && $s['status'] !== 'cancelled'): ?>
                                    <a href="../payments/add_payment.php?type=receivable&sale_id=<?= $saleId ?>"
                                       class="w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-300 inline-flex items-center justify-center hover:bg-emerald-500/30 transition"
                                       title="Receive Due Payment">
                                        <i class="fas fa-rupee-sign text-[10px]"></i>
                                    </a>
                                <?php endif; ?>

                                <a href="edit_sale.php?id=<?= $saleId ?>"
                                   class="w-7 h-7 rounded-lg bg-amber-500/15 text-amber-300 inline-flex items-center justify-center hover:bg-amber-500/30 transition"
                                   title="Edit">
                                    <i class="fas fa-edit text-[10px]"></i>
                                </a>

                                <form method="POST"
                                      action="delete_sale.php"
                                      class="inline-flex m-0"
                                      onsubmit="return confirm('Sale <?= e($s['sale_no']) ?> delete karni hai?\n\n• Stock wapas add ho jayega\n• Is sale ke payments aur linked print service bhi delete honge\n\nYe wapas nahi aayega!');">

                                    <input type="hidden" name="id" value="<?= $saleId ?>">

                                    <button type="submit"
                                            class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 inline-flex items-center justify-center hover:bg-rose-500/30 transition"
                                            title="Delete">
                                        <i class="fas fa-trash text-[10px]"></i>
                                    </button>
                                </form>

                            </div>
                        </td>
                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9">
                            <div class="text-center py-12 text-slate-500">
                                <i class="fas fa-file-invoice-dollar text-3xl block mb-2 opacity-30"></i>
                                <div class="text-sm">No sales yet</div>
                                <a href="add_sale.php"
                                   class="inline-block mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline">
                                    Create your first sale →
                                </a>
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
