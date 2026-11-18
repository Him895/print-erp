
<?php
// ============================================================
// FILE: sales/view_sale_invoice.php
// Sale Final Invoice (Product + Print + GST)
// DRAFT watermark jab service pending ho
// ============================================================

require_once '../config/config.php';
require_permission('sales');

$page_title = 'Sale Invoice';

// Amount ko words me (Indian system: Thousand, Lakh, Crore).
if (!function_exists('amount_in_words')) {
    function amount_in_words($amount) {
        $num = (int)round((float)$amount);
        if ($num <= 0) return 'Zero';

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
                 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $two = function ($n) use ($ones, $tens) {
            return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        };

        $three = function ($n) use ($ones, $two) {
            $s = '';
            if ($n >= 100) {
                $s = $ones[intdiv($n, 100)] . ' Hundred';
                $n %= 100;
                if ($n) $s .= ' ';
            }
            return $s . ($n ? $two($n) : '');
        };

        $parts = [];

        $crore = intdiv($num, 10000000);
        $num %= 10000000;

        $lakh = intdiv($num, 100000);
        $num %= 100000;

        $thou = intdiv($num, 1000);
        $num %= 1000;

        if ($crore) $parts[] = $three($crore) . ' Crore';
        if ($lakh)  $parts[] = $two($lakh) . ' Lakh';
        if ($thou)  $parts[] = $two($thou) . ' Thousand';
        if ($num)   $parts[] = $three($num);

        return implode(' ', $parts);
    }
}

$sale_id = (int)($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    set_flash('error', 'Invalid invoice ID.');
    redirect('sales_list.php');
}

// ============ FETCH SALE ============
// Customer details customers table se fetch hongi.
// Existing sales.client_id FK same rakha hai.

$stmt = $conn->prepare("
    SELECT
        s.*,
        c.customer_name,
        c.phone,
        c.email,
        c.address,
        c.gst_no,
        sj.id AS svc_id,
        sj.service_no,
        sj.status AS service_status
    FROM sales s
    LEFT JOIN customers c ON s.client_id = c.id
    LEFT JOIN services sj ON s.service_job_id = sj.id
    WHERE s.id = ?
");

$stmt->bind_param('i', $sale_id);
$stmt->execute();

$sale = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// ============ FETCH SALE ITEMS ============

$itemStmt = $conn->prepare("
    SELECT si.*, p.product_name
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id
");

$itemStmt->bind_param('i', $sale_id);
$itemStmt->execute();

$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$itemStmt->close();

// Product ki extra details (code/size/color)
$prodMap = [];

$pids = array_values(
    array_unique(
        array_map('intval', array_column($items, 'product_id'))
    )
);

if (!empty($pids)) {
    $in = implode(',', $pids);

    $pr = $conn->query("
        SELECT *
        FROM products
        WHERE id IN ($in)
    ");

    if ($pr) {
        foreach ($pr->fetch_all(MYSQLI_ASSOC) as $row) {
            $prodMap[(int)$row['id']] = $row;
        }
    }
}

// ============ FETCH PAYMENTS (Sale ke against) ============

$payStmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE ref_type = 'sale' AND ref_id = ?
    ORDER BY payment_date, id
");

$payStmt->bind_param('i', $sale_id);
$payStmt->execute();

$payments = $payStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$payStmt->close();

// ============ STATUS LOGIC ============

$is_draft = false;

if (!empty($sale['service_job_id']) && $sale['service_status'] === 'pending') {
    $is_draft = true;
}

if ($sale['due_amount'] <= 0) {
    $status_label = 'PAID';
    $status_color = 'emerald';
} elseif ($sale['paid_amount'] > 0) {
    $status_label = 'PARTIAL';
    $status_color = 'amber';
} else {
    $status_label = 'PENDING';
    $status_color = 'rose';
}

if ($is_draft) {
    $status_label = 'DRAFT';
    $status_color = 'slate';
}

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5 no-print">
    <div>
        <h1 class="text-2xl font-bold text-white">Tax Invoice</h1>
        <p class="text-xs text-slate-400 mt-0.5"><?= e($sale['sale_no']) ?></p>
    </div>

    <div class="flex flex-wrap gap-2">
        <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">
            <i class="fas fa-print mr-1"></i> Print Invoice
        </button>

        <?php if ($sale['due_amount'] > 0): ?>
            <a href="../payments/add_payment.php?type=receivable&sale_id=<?= $sale_id ?>" class="px-4 py-2 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-semibold hover:bg-emerald-500/30 transition">
                <i class="fas fa-money-bill mr-1"></i> Receive Due Payment
            </a>
        <?php endif; ?>

        <a href="sales_list.php" class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>
</div>

<!-- ================= PRINTABLE INVOICE ================= -->

<div class="glass-dark rounded-2xl p-6 max-w-4xl mx-auto print-area relative overflow-hidden">

    <!-- DRAFT Watermark -->
    <?php if ($is_draft): ?>
        <div class="draft-watermark">DRAFT</div>
    <?php endif; ?>

    <!-- ===== HEADER ===== -->

    <div class="flex justify-between items-start border-b border-white/10 pb-5 mb-5">
        <div>
            <h2 class="text-2xl font-bold text-white mb-1"><?= SITE_NAME ?></h2>

            <p class="text-[11px] text-slate-400 leading-relaxed">
                T-Shirt / Shirt / Bottle Printing Services<br>
                <?= defined('SITE_ADDRESS') ? e(SITE_ADDRESS) : '' ?>

                <?php if (defined('SITE_PHONE')): ?>
                    <br>Ph: <?= e(SITE_PHONE) ?>
                <?php endif; ?>

                <?php if (defined('SITE_GST')): ?>
                    <br>GSTIN: <?= e(SITE_GST) ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="text-right">
            <div class="text-lg font-bold <?= $status_color === 'emerald' ? 'text-emerald-300' : ($status_color === 'amber' ? 'text-amber-300' : ($status_color === 'rose' ? 'text-rose-300' : 'text-slate-300')) ?> mb-1 tracking-wide">
                TAX INVOICE
            </div>

            <div class="text-xs text-slate-400">
                No: <span class="text-white font-semibold"><?= e($sale['sale_no']) ?></span>
            </div>

            <div class="text-xs text-slate-400">
                Date: <?= fmt_date($sale['sale_date']) ?>
            </div>

            <div class="mt-2 inline-block px-3 py-1 rounded-md text-[10px] font-bold tracking-wider
                <?= $status_color === 'emerald' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : '' ?>
                <?= $status_color === 'amber' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : '' ?>
                <?= $status_color === 'rose' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : '' ?>
                <?= $status_color === 'slate' ? 'bg-slate-500/20 text-slate-300 border border-slate-500/30' : '' ?>">
                <?= $status_label ?>
            </div>
        </div>
    </div>

    <!-- ===== BILL TO ===== -->

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
        <div>
            <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-1">
                Bill To:
            </div>

            <div class="text-white font-semibold text-base">
                <?= e($sale['customer_name'] ?? 'Walk-in Customer') ?>
            </div>

            <?php if ($sale['phone']): ?>
                <div class="text-xs text-slate-400">
                    <i class="fas fa-phone text-[9px] mr-1"></i><?= e($sale['phone']) ?>
                </div>
            <?php endif; ?>

            <?php if ($sale['email']): ?>
                <div class="text-xs text-slate-400">
                    <i class="fas fa-envelope text-[9px] mr-1"></i><?= e($sale['email']) ?>
                </div>
            <?php endif; ?>

            <?php if ($sale['address']): ?>
                <div class="text-xs text-slate-400 mt-1">
                    <?= nl2br(e($sale['address'])) ?>
                </div>
            <?php endif; ?>

            <?php if ($sale['gst_no']): ?>
                <div class="text-xs text-indigo-300 mt-1">
                    GSTIN: <?= e($sale['gst_no']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="md:text-right">
            <?php if ($sale['service_no']): ?>
                <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-1">
                    Linked Service Job:
                </div>

                <div class="text-indigo-300 text-sm font-medium">
                    <?= e($sale['service_no']) ?>
                </div>

                <div class="text-[10px] text-slate-400 mt-1">
                    Status:
                    <span class="<?= $sale['service_status'] === 'completed' ? 'text-emerald-300' : 'text-amber-300' ?>">
                        <?= ucfirst(e($sale['service_status'])) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== ITEMS TABLE ===== -->

    <div class="overflow-x-auto mb-5">
        <table class="w-full text-xs">
            <thead>
                <tr class="border-y border-white/15 bg-white/5">
                    <th class="text-left py-2 px-2 text-slate-300 font-semibold w-8">#</th>
                    <th class="text-left py-2 px-2 text-slate-300 font-semibold">Item Description</th>
                    <th class="text-center py-2 px-2 text-slate-300 font-semibold w-12">Qty</th>
                    <th class="text-right py-2 px-2 text-slate-300 font-semibold w-20">Rate</th>
                    <th class="text-right py-2 px-2 text-slate-300 font-semibold w-24">Amount</th>
                </tr>
            </thead>

            <tbody>
                <?php $sl = 1; foreach ($items as $it): ?>
                    <tr class="border-b border-white/5">
                        <td class="py-2 px-2 text-slate-400"><?= $sl++ ?></td>

                        <td class="py-2 px-2">
                            <div class="text-white font-medium">
                                <?= e($it['product_name'] ?? 'Product') ?>
                            </div>

                            <?php
                                $pd = $prodMap[(int)$it['product_id']] ?? [];
                                $pcode = $pd['product_code'] ?? ($pd['sku'] ?? ($pd['code'] ?? ''));
                                $psize = $pd['size'] ?? '';
                                $pcolor = $pd['color'] ?? '';
                            ?>

                            <?php if ($pcode || $psize || $pcolor): ?>
                                <div class="text-[10px] text-slate-500">
                                    <?= $pcode ? 'Code: ' . e($pcode) : '' ?>
                                    <?= $psize ? ' · Size: ' . e($psize) : '' ?>
                                    <?= $pcolor ? ' · Color: ' . e($pcolor) : '' ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($it['print_charge_total'] > 0): ?>
                                <div class="text-[10px] text-amber-300 mt-0.5">
                                    <i class="fas fa-print text-[8px] mr-1"></i>Print:
                                    <?= e($it['print_type']) ?> — <?= money($it['print_charge_total']) ?>

                                    <?php if ($it['print_notes']): ?>
                                        <span class="block whitespace-pre-line text-slate-500 italic">
                                            <?= e($it['print_notes']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="py-2 px-2 text-center text-white">
                            <?= (int)$it['qty'] ?>
                        </td>

                        <td class="py-2 px-2 text-right text-slate-200">
                            <?= money($it['rate']) ?>
                        </td>

                        <td class="py-2 px-2 text-right text-white font-medium">
                            <?= money($it['amount']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ===== TOTALS + PAYMENT SUMMARY ===== -->

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <!-- Payment History -->

        <div>
            <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-2">
                Payment History:
            </div>

            <?php if (empty($payments)): ?>
                <div class="text-xs text-slate-500 italic bg-white/5 rounded-lg p-3 border border-white/10">
                    Koi payment nahi mila.
                    <?= $is_draft ? 'Service complete hone ke baad invoice final hoga.' : '' ?>
                </div>
            <?php else: ?>

                <div class="bg-white/5 rounded-lg border border-white/10 overflow-hidden">
                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="bg-white/5 border-b border-white/10">
                                <th class="text-left py-1.5 px-2 text-slate-400 font-medium">Date</th>
                                <th class="text-left py-1.5 px-2 text-slate-400 font-medium">Mode</th>
                                <th class="text-right py-1.5 px-2 text-slate-400 font-medium">Amount</th>
                                <th class="text-center py-1.5 px-2 text-slate-400 font-medium no-print w-10">Slip</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr class="border-b border-white/5 last:border-0">
                                    <td class="py-1.5 px-2 text-slate-300">
                                        <?= fmt_date($p['payment_date']) ?>
                                    </td>

                                    <td class="py-1.5 px-2 text-slate-400 uppercase text-[10px]">
                                        <?= e($p['payment_mode']) ?>
                                    </td>

                                    <td class="py-1.5 px-2 text-right text-emerald-300 font-medium">
                                        <?= money($p['amount']) ?>
                                    </td>

                                    <td class="py-1.5 px-2 text-center no-print">
                                        <a href="view_sale_receipt.php?id=<?= (int)$p['id'] ?>&sale_id=<?= $sale_id ?>" class="text-indigo-300 hover:text-indigo-200" title="Receipt <?= e($p['payment_no']) ?>">
                                            <i class="fas fa-receipt text-[10px]"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>

        <!-- Totals -->

        <div class="bg-white/5 rounded-lg p-4 border border-white/10">
            <div class="flex justify-between text-xs text-slate-400 mb-1.5">
                <span>Product Total:</span>
                <span class="text-white"><?= money($sale['product_total']) ?></span>
            </div>

            <div class="flex justify-between text-xs text-slate-400 mb-1.5">
                <span>Print Charges:</span>
                <span class="text-amber-300"><?= money($sale['print_total']) ?></span>
            </div>

            <div class="flex justify-between text-xs text-slate-400 mb-1.5 border-t border-white/10 pt-1.5">
                <span>Sub Total:</span>
                <span class="text-white"><?= money($sale['sub_total']) ?></span>
            </div>

            <div class="flex justify-between text-xs text-slate-400 mb-2">
                <span>GST (<?= (float)$sale['gst_percent'] ?>%):</span>
                <span class="text-indigo-300"><?= money($sale['gst_amount']) ?></span>
            </div>

            <div class="flex justify-between text-sm font-bold border-t border-white/15 pt-2 mb-2">
                <span class="text-white">Grand Total:</span>
                <span class="text-emerald-300"><?= money($sale['grand_total']) ?></span>
            </div>

            <div class="flex justify-between text-xs text-slate-400 mb-1">
                <span>Paid Amount:</span>
                <span class="text-emerald-300 font-medium"><?= money($sale['paid_amount']) ?></span>
            </div>

            <div class="flex justify-between text-sm font-bold border-t border-white/10 pt-1.5">
                <span class="text-slate-200">Balance Due:</span>
                <span class="<?= $sale['due_amount'] > 0 ? 'text-rose-400' : 'text-emerald-400' ?>">
                    <?= money($sale['due_amount']) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ===== AMOUNT IN WORDS ===== -->

    <div class="mt-4 p-2 bg-indigo-500/10 border border-indigo-500/20 rounded-lg">
        <span class="text-[10px] uppercase tracking-wider text-indigo-400 font-semibold">
            Amount in Words:
        </span>

        <span class="text-xs text-white font-medium">
            <?= amount_in_words($sale['grand_total']) ?> Rupees Only
        </span>
    </div>

    <!-- ===== DRAFT NOTICE ===== -->

    <?php if ($is_draft): ?>
        <div class="mt-4 p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg text-xs text-amber-300">
            <i class="fas fa-exclamation-triangle mr-1"></i>

            <strong>DRAFT INVOICE:</strong>
            Print service abhi complete nahi hui hai.
            Service complete hone ke baad ye invoice final hoga.
            Amount due sirf tab payable hai jab service complete ho jaye.
        </div>
    <?php endif; ?>

    <!-- ===== FOOTER ===== -->

    <div class="mt-6 pt-4 border-t border-white/10 flex justify-between items-end">
        <div class="text-[10px] text-slate-500">
            Thank you for your business!<br>
            This is a computer generated invoice. <?= SITE_NAME ?> · <?= SITE_VERSION ?>
        </div>

        <div class="text-right">
            <div class="h-10 border-b border-white/20 w-32 mb-1"></div>
            <div class="text-[10px] text-slate-400">Authorized Signatory</div>
        </div>
    </div>

</div>

<style>
/* DRAFT watermark */
.draft-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 120px;
    font-weight: 900;
    color: rgba(239, 68, 68, 0.08);
    letter-spacing: 10px;
    pointer-events: none;
    user-select: none;
    z-index: 0;
    white-space: nowrap;
}

.print-area > *:not(.draft-watermark) {
    position: relative;
    z-index: 1;
}

@media print {
    body * {
        visibility: hidden;
    }

    .print-area, .print-area * {
        visibility: visible;
    }

    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        color: black;
        padding: 20px;
        border: none !important;
    }

    .no-print {
        display: none !important;
    }

    .print-area .text-white {
        color: #000 !important;
    }

    .print-area .text-slate-400,
    .print-area .text-slate-300,
    .print-area .text-slate-500 {
        color: #555 !important;
    }

    .print-area .text-emerald-300 {
        color: #047857 !important;
    }

    .print-area .text-amber-300 {
        color: #b45309 !important;
    }

    .print-area .text-indigo-300,
    .print-area .text-indigo-400 {
        color: #4338ca !important;
    }

    .print-area .text-rose-300,
    .print-area .text-rose-400 {
        color: #b91c1c !important;
    }

    .print-area .bg-white\/5 {
        background: #f9fafb !important;
    }

    .print-area .bg-indigo-500\/10 {
        background: #eef2ff !important;
    }

    .print-area .bg-amber-500\/10 {
        background: #fffbeb !important;
    }

    .print-area .bg-emerald-500\/20 {
        background: #d1fae5 !important;
    }

    .print-area .bg-amber-500\/20 {
        background: #fef3c7 !important;
    }

    .print-area .bg-rose-500\/20 {
        background: #fee2e2 !important;
    }

    .print-area .bg-slate-500\/20 {
        background: #e5e7eb !important;
    }

    .print-area .border-white\/10,
    .print-area .border-white\/15,
    .print-area .border-white\/5 {
        border-color: #e5e7eb !important;
    }

    .print-area .glass-dark {
        background: white !important;
        border: none !important;
        padding: 0 !important;
    }

    .print-area .draft-watermark {
        color: rgba(239, 68, 68, 0.15) !important;
    }

    @page {
        margin: 10mm;
        size: A4;
    }
}
</style>

<?php include '../includes/footer.php'; ?>
