
<?php
// ============================================================
// FILE: payments/view_payment_receipt.php
// COMMON PAYMENT RECEIPT
// Supports: SALE / DIRECT SERVICE / PURCHASE
// URL: view_payment_receipt.php?id=PAYMENT_ID
// ============================================================

require_once '../config/config.php';
require_permission('payments');
$page_title = 'Payment Receipt';

$payment_id = (int)($_GET['id'] ?? 0);

if ($payment_id <= 0) {
    redirect('payments_list.php');
}

// ============================================================
// FETCH PAYMENT
// ============================================================

$stmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $payment_id);
$stmt->execute();

$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    include '../includes/header.php';
    ?>
    <div class="glass-dark rounded-2xl p-8 text-center">
        <i class="fas fa-exclamation-circle text-rose-400 text-4xl mb-3"></i>
        <h2 class="text-xl font-bold text-white">Payment Not Found</h2>
        <p class="text-sm text-slate-400 mt-2">The requested payment record does not exist.</p>
        <a href="payments_list.php"
           class="inline-block mt-5 px-4 py-2 rounded-lg bg-indigo-500 text-white text-sm">
            Back to Payments
        </a>
    </div>
    <?php
    include '../includes/footer.php';
    exit;
}

// ============================================================
// IDENTIFY PAYMENT TYPE
// ============================================================

$ref_type = strtolower($payment['ref_type'] ?? '');
$ref_id = (int)($payment['ref_id'] ?? 0);

$source = [];
$party_name = '';
$party_phone = '';
$party_address = '';
$party_gst = '';

$invoice_no = '';
$invoice_label = '';

if ($ref_id <= 0) {
    die('Invalid payment reference.');
}

// ============================================================
// FETCH SALE DETAILS
// ============================================================

if ($ref_type === 'sale') {

    $stmt = $conn->prepare("
        SELECT s.*, c.customer_name, c.phone, c.address, c.gst_no
        FROM sales s
        LEFT JOIN customers c ON s.client_id = c.id
        WHERE s.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $ref_id);
    $stmt->execute();

    $source = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();

    if (!$source) {
        die('Sale invoice not found.');
    }

    $invoice_no = $source['sale_no'] ?? ('SALE-' . $ref_id);
    $invoice_label = 'Sale Invoice';

    $party_name = $source['customer_name'] ?? 'Walk-in Customer';
    $party_phone = $source['phone'] ?? '';
    $party_address = $source['address'] ?? '';
    $party_gst = $source['gst_no'] ?? '';

}

// ============================================================
// FETCH DIRECT SERVICE DETAILS
// ============================================================

elseif ($ref_type === 'service') {

    $stmt = $conn->prepare("
        SELECT sv.*, c.customer_name, c.phone, c.address, c.gst_no
        FROM services sv
        LEFT JOIN customers c ON sv.client_id = c.id
        WHERE sv.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $ref_id);
    $stmt->execute();

    $source = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();

    if (!$source) {
        die('Service record not found.');
    }

    $invoice_no = $source['service_no'] ?? ('SERVICE-' . $ref_id);
    $invoice_label = 'Service Invoice';

    $party_name = $source['customer_name'] ?? 'Walk-in Customer';
    $party_phone = $source['phone'] ?? '';
    $party_address = $source['address'] ?? '';
    $party_gst = $source['gst_no'] ?? '';

}

// ============================================================
// FETCH PURCHASE DETAILS
// ============================================================

elseif ($ref_type === 'purchase') {

    $stmt = $conn->prepare("
        SELECT p.*, v.vendor_name, v.phone, v.address, v.gst_no
        FROM product_purchase p
        LEFT JOIN vendors v ON p.vendor_id = v.id
        WHERE p.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $ref_id);
    $stmt->execute();

    $source = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();

    if (!$source) {
        die('Purchase invoice not found.');
    }

    $invoice_no = $source['purchase_no'] ?? ('PURCHASE-' . $ref_id);
    $invoice_label = 'Purchase Invoice';

    $party_name = $source['vendor_name'] ?? 'Vendor';
    $party_phone = $source['phone'] ?? '';
    $party_address = $source['address'] ?? '';
    $party_gst = $source['gst_no'] ?? '';

} else {

    die('Unsupported payment reference type.');
}

// ============================================================
// CALCULATE DISPLAY VALUES
// ============================================================

$payment_amount = (float)($payment['amount'] ?? 0);

$invoice_total = (float)(
    $source['total_amount']
    ?? $source['grand_total']
    ?? $source['net_amount']
    ?? (
        (float)($source['sub_total'] ?? 0)
        + (float)($source['gst_amount'] ?? 0)
    )
);

$current_paid = (float)($source['paid_amount'] ?? 0);
$current_due = (float)($source['due_amount'] ?? 0);

// Fallback if invoice total is not available.
if ($invoice_total <= 0) {
    $invoice_total = $current_paid + $current_due;
}

$payment_mode = ucfirst(strtolower($payment['payment_mode'] ?? 'cash'));
$payment_status = ucfirst(strtolower($payment['status'] ?? 'confirmed'));

$payment_date = !empty($payment['payment_date'])
    ? fmt_date($payment['payment_date'])
    : '-';

$created_date = !empty($payment['created_at'])
    ? fmt_date($payment['created_at'])
    : '-';

$is_purchase = ($ref_type === 'purchase');

$receipt_title = $is_purchase
    ? 'PAYMENT VOUCHER'
    : 'PAYMENT RECEIPT';

$receipt_subtitle = $is_purchase
    ? 'Vendor Payment Confirmation'
    : 'Customer Payment Confirmation';

include '../includes/header.php';
?>

<!-- ============================================================
     ACTION BUTTONS
============================================================ -->

<div class="flex items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white">
            <?= e($receipt_title) ?>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
            <?= e($receipt_subtitle) ?>
        </p>
    </div>

    <div class="flex items-center gap-2">

        <a href="payments_list.php"
           class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>

        <button onclick="window.print()"
                class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-white text-xs font-bold transition">
            <i class="fas fa-print mr-1"></i> Print Receipt
        </button>

    </div>
</div>

<!-- ============================================================
     RECEIPT
============================================================ -->

<div class="payment-receipt glass-dark rounded-2xl overflow-hidden max-w-4xl mx-auto">

    <div class="receipt-top-line"></div>

    <div class="p-6 md:p-8 receipt-content">

        <!-- HEADER -->

        <div class="flex flex-col sm:flex-row justify-between gap-5 pb-6 border-b border-white/10">

            <div>
                <div class="flex items-center gap-3 mb-3">

                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xl shadow-lg">
                        <i class="fas fa-receipt"></i>
                    </div>

                    <div>
                        <h2 class="text-xl font-black text-white">
                            PAYMENT
                        </h2>
                        <p class="text-[10px] uppercase tracking-[2px] text-slate-400">
                            Transaction Record
                        </p>
                    </div>

                </div>

                <p class="text-xs text-slate-400">
                    <?= e($receipt_subtitle) ?>
                </p>
            </div>

            <div class="sm:text-right">

                <div class="inline-flex px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider
                    <?= $is_purchase
                        ? 'bg-rose-500/15 text-rose-300 border border-rose-500/20'
                        : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/20' ?>">

                    <?= $is_purchase ? 'Money Paid' : 'Money Received' ?>

                </div>

                <h3 class="text-2xl font-black text-white mt-3">
                    <?= e($payment['payment_no'] ?? '-') ?>
                </h3>

                <p class="text-[10px] text-slate-500 mt-1">
                    Payment ID: #<?= (int)$payment_id ?>
                </p>

            </div>

        </div>

        <!-- PARTY AND PAYMENT DETAILS -->

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6">

            <div class="receipt-card rounded-xl p-4 border border-white/10 bg-white/[0.03]">

                <p class="text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-3">
                    <?= $is_purchase ? 'Vendor Details' : 'Customer Details' ?>
                </p>

                <h3 class="text-base font-bold text-white">
                    <?= e($party_name) ?>
                </h3>

                <?php if ($party_phone): ?>
                    <p class="text-xs text-slate-400 mt-2">
                        <i class="fas fa-phone mr-2"></i>
                        <?= e($party_phone) ?>
                    </p>
                <?php endif; ?>

                <?php if ($party_address): ?>
                    <p class="text-xs text-slate-400 mt-2">
                        <i class="fas fa-map-marker-alt mr-2"></i>
                        <?= nl2br(e($party_address)) ?>
                    </p>
                <?php endif; ?>

                <?php if ($party_gst): ?>
                    <p class="text-xs text-slate-400 mt-2">
                        GSTIN: <?= e($party_gst) ?>
                    </p>
                <?php endif; ?>

            </div>

            <div class="receipt-card rounded-xl p-4 border border-white/10 bg-white/[0.03]">

                <p class="text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-3">
                    Payment Information
                </p>

                <div class="flex justify-between gap-3 mb-2">
                    <span class="text-xs text-slate-400">Payment Date</span>
                    <strong class="text-xs text-white">
                        <?= e($payment_date) ?>
                    </strong>
                </div>

                <div class="flex justify-between gap-3 mb-2">
                    <span class="text-xs text-slate-400">Payment Mode</span>
                    <strong class="text-xs text-white">
                        <?= e($payment_mode) ?>
                    </strong>
                </div>

                <div class="flex justify-between gap-3 mb-2">
                    <span class="text-xs text-slate-400">Status</span>
                    <strong class="text-xs text-emerald-400">
                        <?= e($payment_status) ?>
                    </strong>
                </div>

                <div class="flex justify-between gap-3">
                    <span class="text-xs text-slate-400">Reference</span>
                    <strong class="text-xs text-white">
                        <?= e($invoice_no) ?>
                    </strong>
                </div>

            </div>

        </div>

        <!-- PAYMENT SUMMARY -->

        <div class="mt-6">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-bold text-white">
                    <i class="fas fa-file-invoice-dollar text-indigo-400 mr-2"></i>
                    Transaction Summary
                </h3>

                <span class="text-[10px] text-slate-500">
                    <?= e($invoice_label) ?>
                </span>

            </div>

            <div class="overflow-x-auto rounded-xl border border-white/10">

                <table class="w-full text-sm">

                    <thead>
                        <tr class="bg-white/5 text-slate-400 text-[10px] uppercase tracking-wider">
                            <th class="text-left px-4 py-3">Description</th>
                            <th class="text-right px-4 py-3">Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <tr class="border-t border-white/10">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-white">
                                    <?= e($receipt_title) ?>
                                </p>
                                <p class="text-[10px] text-slate-500 mt-1">
                                    Invoice: <?= e($invoice_no) ?>
                                </p>
                            </td>

                            <td class="px-4 py-4 text-right font-bold text-white">
                                <?= money($payment_amount) ?>
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- AMOUNT HIGHLIGHT -->

        <div class="mt-5 rounded-xl p-5 border border-emerald-500/20 bg-emerald-500/10">

            <p class="text-[10px] uppercase tracking-[2px] text-emerald-300 font-bold">
                <?= $is_purchase ? 'Amount Paid to Vendor' : 'Amount Received from Customer' ?>
            </p>

            <div class="flex items-center justify-between gap-3 mt-2">

                <h2 class="text-3xl md:text-4xl font-black text-white">
                    <?= money($payment_amount) ?>
                </h2>

                <div class="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-xl">
                    <i class="fas <?= $is_purchase ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                </div>

            </div>

            <p class="text-[10px] text-emerald-200/70 mt-2">
                Payment successfully recorded in the system.
            </p>

        </div>

        <!-- CURRENT INVOICE BALANCE -->

        <div class="mt-6">

            <h3 class="text-sm font-bold text-white mb-3">
                Invoice Payment Summary
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                <div class="receipt-card rounded-xl p-4 border border-white/10 bg-white/[0.03]">
                    <p class="text-[10px] text-slate-500 uppercase font-bold">
                        Invoice Total
                    </p>
                    <p class="text-lg font-bold text-white mt-2">
                        <?= money($invoice_total) ?>
                    </p>
                </div>

                <div class="receipt-card rounded-xl p-4 border border-white/10 bg-white/[0.03]">
                    <p class="text-[10px] text-slate-500 uppercase font-bold">
                        Total Paid
                    </p>
                    <p class="text-lg font-bold text-emerald-400 mt-2">
                        <?= money($current_paid) ?>
                    </p>
                </div>

                <div class="receipt-card rounded-xl p-4 border border-white/10 bg-white/[0.03]">
                    <p class="text-[10px] text-slate-500 uppercase font-bold">
                        Remaining Due
                    </p>
                    <p class="text-lg font-bold <?= $current_due > 0 ? 'text-amber-400' : 'text-emerald-400' ?> mt-2">
                        <?= money($current_due) ?>
                    </p>
                </div>

            </div>

            <p class="text-[10px] text-slate-500 mt-2">
                Balance figures reflect the invoice's current recorded payment status.
            </p>

        </div>

        <!-- NOTES -->

        <?php if (!empty($payment['notes'])): ?>

            <div class="mt-5 rounded-xl p-4 border border-white/10 bg-white/[0.03]">
                <p class="text-[10px] uppercase tracking-wider text-slate-500 font-bold mb-2">
                    Payment Notes
                </p>
                <p class="text-xs text-slate-300">
                    <?= nl2br(e($payment['notes'])) ?>
                </p>
            </div>

        <?php endif; ?>

        <!-- FOOTER -->

        <div class="mt-8 pt-5 border-t border-white/10 text-center">

            <div class="flex items-center justify-center gap-2 text-emerald-400 mb-2">
                <i class="fas fa-check-circle"></i>
                <span class="text-xs font-bold uppercase tracking-wider">
                    Payment Recorded Successfully
                </span>
            </div>

            <p class="text-[10px] text-slate-500">
                This is a computer-generated payment receipt.
            </p>

            <p class="text-[10px] text-slate-500 mt-1">
                Payment No: <?= e($payment['payment_no'] ?? '-') ?>
                | Generated: <?= e($created_date) ?>
            </p>

        </div>

    </div>
</div>

<!-- ============================================================
     PRINT STYLES - WHITE RECEIPT
============================================================ -->

<style>

.payment-receipt {
    color: #e2e8f0;
}

.receipt-top-line {
    height: 5px;
    background: linear-gradient(90deg, #6366f1, #10b981, #8b5cf6);
}

@media print {

    @page {
        size: A4;
        margin: 12mm;
    }

    html,
    body {
        background: #ffffff !important;
        color: #111827 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    body * {
        visibility: hidden !important;
    }

    .payment-receipt,
    .payment-receipt * {
        visibility: visible !important;
    }

    .no-print {
        display: none !important;
    }

    .payment-receipt {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        overflow: visible !important;
        background: #ffffff !important;
        color: #111827 !important;
    }

    .receipt-content {
        padding: 0 !important;
    }

    .receipt-top-line {
        background: #0f766e !important;
        height: 4px !important;
    }

    .payment-receipt * {
        box-shadow: none !important;
        text-shadow: none !important;
    }

    .payment-receipt h2,
    .payment-receipt h3,
    .payment-receipt strong,
    .payment-receipt td,
    .payment-receipt th,
    .payment-receipt p,
    .payment-receipt span {
        color: #111827 !important;
    }

    .payment-receipt .text-white {
        color: #111827 !important;
    }

    .payment-receipt .text-slate-400,
    .payment-receipt .text-slate-500 {
        color: #64748b !important;
    }

    .payment-receipt .text-emerald-400,
    .payment-receipt .text-emerald-300 {
        color: #047857 !important;
    }

    .payment-receipt .text-amber-400 {
        color: #b45309 !important;
    }

    .payment-receipt .text-rose-300 {
        color: #be123c !important;
    }

    .payment-receipt .text-indigo-400 {
        color: #4338ca !important;
    }

    .payment-receipt .bg-white\/5,
    .payment-receipt .bg-white\/\[0\.03\],
    .payment-receipt .bg-emerald-500\/10,
    .payment-receipt .bg-emerald-500\/20,
    .payment-receipt .bg-emerald-500\/15,
    .payment-receipt .bg-rose-500\/15 {
        background: #ffffff !important;
        background-color: #ffffff !important;
    }

    .payment-receipt .border-white\/10,
    .payment-receipt .border-emerald-500\/20,
    .payment-receipt .border-rose-500\/20 {
        border-color: #e2e8f0 !important;
    }

    .payment-receipt .receipt-card {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px !important;
        break-inside: avoid;
    }

    .payment-receipt table {
        width: 100% !important;
        border-collapse: collapse !important;
    }

    .payment-receipt thead tr {
        background: #f1f5f9 !important;
    }

    .payment-receipt th,
    .payment-receipt td {
        border-color: #e2e8f0 !important;
        color: #111827 !important;
    }

    .payment-receipt .rounded-xl,
    .payment-receipt .rounded-2xl {
        border-radius: 8px !important;
    }

    .payment-receipt .bg-gradient-to-br {
        background: #f1f5f9 !important;
    }

    .payment-receipt .text-emerald-200\/70 {
        color: #64748b !important;
    }

    .payment-receipt .receipt-card,
    .payment-receipt .receipt-content {
        break-inside: avoid;
    }

    .payment-receipt {
        -webkit-print-color-adjust: economy;
        print-color-adjust: economy;
    }
}

</style>

<?php include '../includes/footer.php'; ?>
