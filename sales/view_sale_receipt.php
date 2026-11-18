
<?php
// ============================================================
// FILE: sales/view_sale_receipt.php
// ORIGINAL SALE-TIME ADVANCE + ORIGINAL DUE RECEIPT
// Later Due Payments Will Not Change This Receipt
// ============================================================

require_once '../config/config.php';
require_permission('sales');

$page_title = 'Sale Payment Receipt';

$id      = (int)($_GET['id'] ?? 0);
$sale_id = (int)($_GET['sale_id'] ?? 0);

// ------------------------------------------------------------
// 1. GET SALE ID
// ------------------------------------------------------------

if ($id <= 0 && $sale_id <= 0) {
    set_flash('error', 'Invalid receipt request.');
    redirect('sales_list.php');
}

if ($id > 0) {

    $stmt = $conn->prepare("
        SELECT ref_id
        FROM payments
        WHERE id = ?
          AND ref_type = 'sale'
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $paymentRef = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$paymentRef) {
        set_flash('error', 'Payment receipt not found.');
        redirect('sales_list.php');
    }

    $sale_id = (int)$paymentRef['ref_id'];
}

if ($sale_id <= 0) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 2. FETCH SALE + CUSTOMER DETAILS
// Customer details customers table se fetch hongi.
// Existing sales.client_id FK same rakha hai.
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT
        s.*,
        c.customer_name,
        c.phone,
        c.address,
        c.gst_no
    FROM sales s
    LEFT JOIN customers c ON s.client_id = c.id
    WHERE s.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $sale_id);
$stmt->execute();

$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 3. FETCH ONLY FIRST / ORIGINAL SALE PAYMENT
// Later due payments are intentionally ignored.
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE ref_type = 'sale'
      AND ref_id = ?
    ORDER BY id ASC
    LIMIT 1
");

$stmt->bind_param('i', $sale_id);
$stmt->execute();

$originalPayment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ------------------------------------------------------------
// 4. ORIGINAL RECEIPT DETAILS
// ------------------------------------------------------------

if ($originalPayment) {

    $paid_amt = (float)$originalPayment['amount'];

    $payment_no = $originalPayment['payment_no'];

    $payment_date = $originalPayment['payment_date'];

    $payment_mode = strtoupper(
        $originalPayment['payment_mode'] ?? 'CASH'
    );

} else {

    // Legacy sale: no payment row exists.
    $paid_amt = (float)($sale['paid_amount'] ?? 0);

    $payment_no = 'ADVANCE';

    $payment_date = $sale['sale_date'];

    $payment_mode = strtoupper(
        $sale['payment_mode'] ?? 'CASH'
    );
}

// ------------------------------------------------------------
// 5. FETCH SALE ITEMS
// ------------------------------------------------------------

$itemStmt = $conn->prepare("
    SELECT
        si.*,
        p.product_name
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");

$itemStmt->bind_param('i', $sale_id);
$itemStmt->execute();

$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$itemStmt->close();

// ------------------------------------------------------------
// 6. CALCULATE ORIGINAL SALE AMOUNTS
// Original Due = Grand Total - Original Advance
// This calculation does not use later payment records.
// ------------------------------------------------------------

$grandTotal = (float)($sale['grand_total'] ?? 0);

$originalDue = max(0, $grandTotal - $paid_amt);

include '../includes/header.php';
?>

<!-- PAGE HEADER -->
<div class="flex items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white">
            Sale Payment Receipt
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Original Receipt #<?= e($payment_no) ?>
        </p>
    </div>

    <div class="flex flex-wrap gap-2">

        <button onclick="window.print()"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold">

            <i class="fas fa-print mr-1"></i>
            Print Receipt

        </button>

        <a href="view_sale_invoice.php?id=<?= $sale_id ?>"
            class="px-4 py-2 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-semibold">

            <i class="fas fa-file-invoice mr-1"></i>
            View Invoice

        </a>

        <a href="sales_list.php"
            class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs">

            <i class="fas fa-arrow-left mr-1"></i>
            Back

        </a>

    </div>
</div>

<!-- RECEIPT -->
<div class="print-area max-w-3xl mx-auto">

    <div class="glass-dark rounded-2xl p-6">

        <!-- COMPANY HEADER -->
        <div class="flex justify-between items-start border-b border-white/10 pb-4 mb-4">

            <div>

                <h2 class="text-xl font-bold text-white">
                    <?= e(SITE_NAME) ?>
                </h2>

                <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">

                    T-Shirt / Shirt / Bottle Printing Services

                    <br>

                    <?= defined('SITE_ADDRESS') ? e(SITE_ADDRESS) : '' ?>

                    <?php if (defined('SITE_PHONE')): ?>
                        <br>
                        Ph: <?= e(SITE_PHONE) ?>
                    <?php endif; ?>

                </p>

            </div>

            <div class="text-right">

                <div class="text-sm font-bold text-emerald-300 tracking-wide">
                    FIRST PAYMENT RECEIPT
                </div>

                <div class="text-xs text-slate-400 mt-1">
                    Receipt: <?= e($payment_no) ?>
                </div>

                <div class="text-xs text-slate-400">
                    Date: <?= e(fmt_date($payment_date)) ?>
                </div>

            </div>

        </div>

        <!-- CUSTOMER DETAILS -->
        <div class="mb-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mb-1">
                Received From
            </div>

            <div class="text-white font-semibold text-base">
                <?= e($sale['customer_name'] ?? 'Walk-in Customer') ?>
            </div>

            <?php if (!empty($sale['phone'])): ?>
                <div class="text-xs text-slate-400">
                    <i class="fas fa-phone mr-1"></i>
                    <?= e($sale['phone']) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($sale['address'])): ?>
                <div class="text-xs text-slate-400 mt-1">
                    <?= nl2br(e($sale['address'])) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($sale['gst_no'])): ?>
                <div class="text-xs text-slate-400 mt-1">
                    GSTIN: <?= e($sale['gst_no']) ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- SALE DETAILS -->
        <div class="bg-white/5 rounded-lg p-3 mb-4 border border-white/10">

            <div class="flex justify-between text-xs mb-2">

                <span class="text-slate-400">
                    Sale / Invoice No.
                </span>

                <span class="text-indigo-300 font-semibold">
                    <?= e($sale['sale_no']) ?>
                </span>

            </div>

            <div class="flex justify-between text-xs mb-2">

                <span class="text-slate-400">
                    Sale Date
                </span>

                <span class="text-white">
                    <?= e(fmt_date($sale['sale_date'])) ?>
                </span>

            </div>

            <div class="flex justify-between text-xs">

                <span class="text-slate-400">
                    Payment Mode
                </span>

                <span class="text-white font-semibold">
                    <?= e($payment_mode) ?>
                </span>

            </div>

        </div>

        <!-- SALE ITEMS -->
        <?php if (!empty($items)): ?>

            <div class="mb-4">

                <div class="text-[10px] text-slate-400 font-semibold mb-2 uppercase tracking-wider">
                    Sale Items
                </div>

                <div class="overflow-x-auto">

                    <table class="w-full text-[11px]">

                        <thead>
                            <tr class="text-slate-500 border-b border-white/10">

                                <th class="text-left py-2">
                                    Item
                                </th>

                                <th class="text-center py-2">
                                    Qty
                                </th>

                                <th class="text-right py-2">
                                    Rate
                                </th>

                                <th class="text-right py-2">
                                    Amount
                                </th>

                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($items as $it): ?>

                                <tr class="border-b border-white/5">

                                    <td class="py-2 text-slate-200">
                                        <?= e($it['product_name'] ?? 'Product') ?>
                                    </td>

                                    <td class="py-2 text-center text-slate-300">
                                        <?= (int)$it['qty'] ?>
                                    </td>

                                    <td class="py-2 text-right text-slate-300">
                                        <?= money($it['rate']) ?>
                                    </td>

                                    <td class="py-2 text-right text-white font-medium">
                                        <?= money($it['amount']) ?>
                                    </td>

                                </tr>

                                <?php if ((float)$it['print_charge_total'] > 0): ?>

                                    <tr class="border-b border-white/5">

                                        <td colspan="3" class="py-2 pl-3 text-amber-300">

                                            Print Charges

                                            <?php if (!empty($it['print_notes'])): ?>
                                                <div class="text-slate-500 whitespace-pre-line">
                                                    <?= e($it['print_notes']) ?>
                                                </div>
                                            <?php endif; ?>

                                        </td>

                                        <td class="py-2 text-right text-amber-300">
                                            <?= money($it['print_charge_total']) ?>
                                        </td>

                                    </tr>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        <?php endif; ?>

        <!-- ORIGINAL ADVANCE AMOUNT -->
        <div class="bg-gradient-to-r from-emerald-500/15 to-teal-500/10 rounded-xl p-5 border border-emerald-500/20 mb-4 text-center">

            <div class="text-[10px] uppercase tracking-wider text-emerald-400 font-semibold mb-1">
                Original Advance Received
            </div>

            <div class="text-3xl font-bold text-emerald-300">
                <?= money($paid_amt) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-2">
                Payment Date: <?= e(fmt_date($payment_date)) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Payment Mode: <?= e($payment_mode) ?>
            </div>

        </div>

        <!-- SALE TOTAL SUMMARY -->
        <div class="border-t border-white/10 pt-3 space-y-2">

            <div class="flex justify-between text-xs text-slate-400">

                <span>Product Total</span>

                <span class="text-white">
                    <?= money($sale['product_total']) ?>
                </span>

            </div>

            <?php if ((float)$sale['print_total'] > 0): ?>

                <div class="flex justify-between text-xs text-slate-400">

                    <span>Print Charges</span>

                    <span class="text-amber-300">
                        <?= money($sale['print_total']) ?>
                    </span>

                </div>

            <?php endif; ?>

            <?php if ((float)$sale['gst_amount'] > 0): ?>

                <div class="flex justify-between text-xs text-slate-400">

                    <span>
                        GST (<?= (float)$sale['gst_percent'] ?>%)
                    </span>

                    <span class="text-indigo-300">
                        <?= money($sale['gst_amount']) ?>
                    </span>

                </div>

            <?php endif; ?>

            <!-- GRAND TOTAL -->
            <div class="flex justify-between text-sm font-bold border-t border-white/10 pt-2">

                <span class="text-slate-200">
                    Grand Total
                </span>

                <span class="text-white">
                    <?= money($grandTotal) ?>
                </span>

            </div>

            <!-- ORIGINAL DUE AT SALE TIME -->
            <div class="flex justify-between text-sm font-bold border-t border-white/10 pt-2">

                <span class="text-slate-200">
                    Original Due Amount
                </span>

                <span class="<?= $originalDue > 0 ? 'text-rose-400' : 'text-emerald-400' ?>">

                    <?= $originalDue > 0
                        ? money($originalDue)
                        : 'NIL - FULLY PAID' ?>

                </span>

            </div>

        </div>

        <!-- FOOTER -->
        <div class="mt-6 pt-3 border-t border-white/10 text-center text-[10px] text-slate-500">

            Thank you! This is a computer generated receipt.

            <br>

            <?= e(SITE_NAME) ?> · <?= e(SITE_VERSION) ?>

        </div>

    </div>

</div>

<!-- PRINT STYLING -->
<style>
@media print {

    @page {
        size: A4;
        margin: 10mm;
    }

    html,
    body {
        background: #fff !important;
        color: #111 !important;
    }

    body * {
        visibility: hidden;
    }

    .print-area,
    .print-area * {
        visibility: visible;
    }

    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        max-width: 100%;
        color: #111 !important;
    }

    .print-area .glass-dark {
        background: #fff !important;
        border: 1px solid #ddd !important;
        box-shadow: none !important;
        padding: 18px !important;
    }

    .print-area .text-white,
    .print-area .text-slate-200 {
        color: #111 !important;
    }

    .print-area .text-slate-300,
    .print-area .text-slate-400,
    .print-area .text-slate-500 {
        color: #555 !important;
    }

    .print-area .text-emerald-300,
    .print-area .text-emerald-400 {
        color: #047857 !important;
    }

    .print-area .text-indigo-300 {
        color: #4338ca !important;
    }

    .print-area .text-amber-300 {
        color: #b45309 !important;
    }

    .print-area .text-rose-400 {
        color: #b91c1c !important;
    }

    .print-area .bg-white\/5 {
        background: #f8fafc !important;
    }

    .print-area .bg-gradient-to-r {
        background: #ecfdf5 !important;
    }

    .print-area .border-white\/10,
    .print-area .border-white\/5 {
        border-color: #ddd !important;
    }

    .no-print {
        display: none !important;
    }

    .print-area table {
        width: 100%;
        border-collapse: collapse;
    }

    .print-area tr {
        break-inside: avoid;
    }
}
</style>

<?php include '../includes/footer.php'; ?>
