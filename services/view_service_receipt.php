
<?php
// ============================================================
// FILE: services/view_service_receipt.php
// Customer-Owned Print Service Payment Receipt
// URL: view_service_receipt.php?id=<payment_id>&service_id=<service_id>
// ============================================================

require_once '../config/config.php';
require_permission('services');
$page_title = 'Service Payment Receipt';

$id = (int)($_GET['id'] ?? 0);                 // Payment ID
$service_id = (int)($_GET['service_id'] ?? 0); // Service ID

if ($id <= 0 && $service_id <= 0) {
    set_flash('error', 'Invalid receipt request.');
    redirect('services_list.php');
}

// ============================================================
// FETCH PAYMENT
// ============================================================

$payment = null;

if ($id > 0) {

    $stmt = $conn->prepare("
        SELECT *
        FROM payments
        WHERE id = ?
          AND ref_type = 'service'
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $payment = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($payment) {

        // If both IDs are supplied, they must belong together.
        if ($service_id > 0 && $service_id !== (int)$payment['ref_id']) {
            set_flash('error', 'Payment does not belong to this service.');
            redirect('services_list.php');
        }

        $service_id = (int)$payment['ref_id'];
    }
}

if ($service_id <= 0) {
    set_flash('error', 'Service not found.');
    redirect('services_list.php');
}

// ============================================================
// FETCH SERVICE + CUSTOMER
// ============================================================

$stmt = $conn->prepare("
    SELECT
        s.*,
        c.client_name,
        c.phone,
        c.address,
        c.gst_no
    FROM services s
    LEFT JOIN clients c ON s.client_id = c.id
    WHERE s.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $service_id);
$stmt->execute();

$service = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$service) {
    set_flash('error', 'Service not found.');
    redirect('services_list.php');
}

// ============================================================
// FETCH CUSTOMER ITEMS
// ============================================================

$itemStmt = $conn->prepare("
    SELECT *
    FROM service_items
    WHERE service_id = ?
    ORDER BY id ASC
");

$itemStmt->bind_param('i', $service_id);
$itemStmt->execute();

$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$itemStmt->close();

// ============================================================
// FETCH PAYMENT HISTORY
// ============================================================

$paymentStmt = $conn->prepare("
    SELECT *
    FROM payments
    WHERE ref_type = 'service'
      AND ref_id = ?
    ORDER BY payment_date ASC, id ASC
");

$paymentStmt->bind_param('i', $service_id);
$paymentStmt->execute();

$payments = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$paymentStmt->close();

// ============================================================
// PAYMENT DETAILS
// ============================================================

$paid_amt = $payment
    ? (float)$payment['amount']
    : (float)($service['paid_amount'] ?? 0);

$payment_no = $payment
    ? $payment['payment_no']
    : 'SERVICE';

$payment_date = $payment
    ? $payment['payment_date']
    : $service['service_date'];

$payment_mode = strtoupper(
    $payment
        ? ($payment['payment_mode'] ?? 'CASH')
        : ($service['payment_mode'] ?? 'CASH')
);

// ============================================================
// PAID TILL THIS RECEIPT
// ============================================================

if ($payment) {

    $ptStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid
        FROM payments
        WHERE ref_type = 'service'
          AND ref_id = ?
          AND id <= ?
    ");

    $ptStmt->bind_param('ii', $service_id, $id);
    $ptStmt->execute();

    $paid_till = (float)$ptStmt->get_result()->fetch_assoc()['total_paid'];

    $ptStmt->close();

} else {

    $paid_till = (float)($service['paid_amount'] ?? 0);

}

// ============================================================
// SERVICE TOTALS
// ============================================================

$subtotal = (float)($service['sub_total'] ?? 0);
$gst_percent = (float)($service['gst_percent'] ?? 0);
$gst_amount = (float)($service['gst_amount'] ?? 0);
$grand_total = (float)($service['total_amount'] ?? 0);

$current_paid = (float)($service['paid_amount'] ?? 0);
$current_due = (float)($service['due_amount'] ?? 0);

// Historical balance after this payment
$balance_after = max(0, $grand_total - $paid_till);

$is_final = ($current_due <= 0);

// Fallback subtotal
if ($subtotal <= 0 && $grand_total > 0) {
    $subtotal = $grand_total - $gst_amount;
}

// ============================================================
// SERVICE STATUS
// ============================================================

$status = strtolower($service['status'] ?? 'pending');

$status_labels = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'completed'   => 'Completed',
    'delivered'   => 'Delivered',
    'cancelled'   => 'Cancelled'
];

$status_label = $status_labels[$status] ?? ucfirst(str_replace('_', ' ', $status));

$status_colors = [
    'pending'     => 'text-amber-300 bg-amber-500/10 border-amber-500/20',
    'in_progress' => 'text-indigo-300 bg-indigo-500/10 border-indigo-500/20',
    'completed'   => 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
    'delivered'   => 'text-teal-300 bg-teal-500/10 border-teal-500/20',
    'cancelled'   => 'text-rose-300 bg-rose-500/10 border-rose-500/20'
];

$status_class = $status_colors[$status] ?? 'text-slate-300 bg-white/5 border-white/10';

// ============================================================
// OTHER DETAILS
// ============================================================

$service_no = $service['service_no'] ?? ('SRV-' . $service_id);

$service_type = ucwords(
    str_replace('_', ' ', $service['service_type'] ?? 'Print Service')
);

$service_date = !empty($service['service_date'])
    ? fmt_date($service['service_date'])
    : '-';

$completed_date = !empty($service['completed_date'])
    ? fmt_date($service['completed_date'])
    : '-';

$total_items = count($items);

$total_qty = 0;

foreach ($items as $item) {
    $total_qty += (int)($item['qty'] ?? 0);
}

include '../includes/header.php';
?>

<!-- ============================================================
     PAGE HEADER + ACTIONS
============================================================ -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 no-print">

    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                <i class="fas fa-receipt text-emerald-400"></i>
            </span>

            <h1 class="text-2xl font-bold text-white">
                Service Payment Receipt
            </h1>
        </div>

        <p class="text-xs text-slate-400 ml-11">
            Receipt #<?= e($payment_no) ?>
            <span class="mx-1">·</span>
            Service <?= e($service_no) ?>
        </p>
    </div>

    <div class="flex flex-wrap gap-2">

        <button
            onclick="window.print()"
            class="px-4 py-2.5 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition"
        >
            <i class="fas fa-print mr-1"></i>
            Print Receipt
        </button>

        <a
            href="view_service_receipt.php?service_id=<?= $service_id ?>"
            class="px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
        >
            <i class="fas fa-file-invoice mr-1"></i>
            Full Service Receipt
        </a>

        <?php if (!$is_final): ?>

            <a
                href="../payments/add_payment.php?type=receivable&service_id=<?= $service_id ?>"
                class="px-4 py-2.5 rounded-lg bg-amber-500/20 border border-amber-500/30 text-amber-300 text-xs font-semibold hover:bg-amber-500/30 transition"
            >
                <i class="fas fa-money-bill-wave mr-1"></i>
                Receive Due
            </a>

        <?php endif; ?>

        <a
            href="services_list.php"
            class="px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
        >
            <i class="fas fa-arrow-left mr-1"></i>
            Back
        </a>

    </div>

</div>

<!-- ============================================================
     RECEIPT
============================================================ -->

<div class="glass-dark rounded-2xl p-5 sm:p-7 max-w-3xl mx-auto print-area relative overflow-hidden">

    <!-- Decorative background -->
    <div class="absolute -top-24 -right-20 w-64 h-64 bg-emerald-500/5 rounded-full blur-3xl pointer-events-none"></div>

    <!-- RECEIPT HEADER -->

    <div class="relative flex justify-between items-start gap-4 border-b border-white/10 pb-5 mb-5">

        <div>

            <div class="flex items-center gap-2 mb-2">

                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/10">
                    <i class="fas fa-print text-white text-lg"></i>
                </div>

                <div>
                    <h2 class="text-xl font-extrabold text-white tracking-tight">
                        <?= e(SITE_NAME) ?>
                    </h2>

                    <p class="text-[9px] text-emerald-300 uppercase tracking-widest font-semibold">
                        Printing & Customization Services
                    </p>
                </div>

            </div>

            <div class="text-[10px] text-slate-400 leading-relaxed">

                <?= defined('SITE_ADDRESS') ? e(SITE_ADDRESS) : '' ?>

                <?php if (defined('SITE_PHONE')): ?>
                    <br>
                    <i class="fas fa-phone text-[9px] mr-1"></i>
                    <?= e(SITE_PHONE) ?>
                <?php endif; ?>

            </div>

        </div>

        <div class="text-right shrink-0">

            <div class="inline-block px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-[10px] font-bold tracking-widest mb-2">
                PAYMENT RECEIPT
            </div>

            <div class="text-xs text-slate-300 font-semibold">
                <?= e($payment_no) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                <?= fmt_date($payment_date) ?>
            </div>

            <div class="mt-2">
                <span class="inline-block px-2.5 py-1 rounded-full border text-[9px] font-bold <?= $status_class ?>">
                    <?= e($status_label) ?>
                </span>
            </div>

        </div>

    </div>

    <!-- CUSTOMER DETAILS -->

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">

        <div class="rounded-xl bg-white/[0.035] border border-white/10 p-4">

            <div class="text-[9px] uppercase tracking-widest text-slate-500 font-bold mb-2">
                <i class="fas fa-user mr-1"></i>
                Received From
            </div>

            <div class="text-white font-bold text-base">
                <?= e($service['client_name'] ?? 'Walk-in Customer') ?>
            </div>

            <?php if (!empty($service['phone'])): ?>

                <div class="text-xs text-slate-400 mt-2">
                    <i class="fas fa-phone text-[9px] mr-1"></i>
                    <?= e($service['phone']) ?>
                </div>

            <?php endif; ?>

            <?php if (!empty($service['address'])): ?>

                <div class="text-xs text-slate-400 mt-1 leading-relaxed">
                    <i class="fas fa-map-marker-alt text-[9px] mr-1"></i>
                    <?= nl2br(e($service['address'])) ?>
                </div>

            <?php endif; ?>

            <?php if (!empty($service['gst_no'])): ?>

                <div class="text-[10px] text-slate-500 mt-2">
                    GSTIN: <?= e($service['gst_no']) ?>
                </div>

            <?php endif; ?>

        </div>

        <div class="rounded-xl bg-white/[0.035] border border-white/10 p-4">

            <div class="text-[9px] uppercase tracking-widest text-slate-500 font-bold mb-2">
                <i class="fas fa-clipboard-list mr-1"></i>
                Service Information
            </div>

            <div class="flex justify-between gap-2 text-xs mb-2">
                <span class="text-slate-500">Service No.</span>
                <span class="text-indigo-300 font-semibold">
                    <?= e($service_no) ?>
                </span>
            </div>

            <div class="flex justify-between gap-2 text-xs mb-2">
                <span class="text-slate-500">Service Type</span>
                <span class="text-slate-200 font-medium text-right">
                    <?= e($service_type) ?>
                </span>
            </div>

            <div class="flex justify-between gap-2 text-xs mb-2">
                <span class="text-slate-500">Service Date</span>
                <span class="text-slate-200">
                    <?= e($service_date) ?>
                </span>
            </div>

            <div class="flex justify-between gap-2 text-xs mb-2">
                <span class="text-slate-500">Payment Mode</span>
                <span class="text-white font-semibold">
                    <?= e($payment_mode) ?>
                </span>
            </div>

            <div class="flex justify-between gap-2 text-xs">
                <span class="text-slate-500">Completed Date</span>
                <span class="text-slate-200">
                    <?= e($completed_date) ?>
                </span>
            </div>

        </div>

    </div>

    <!-- CUSTOMER OWNED ITEMS -->

    <div class="mb-5">

        <div class="flex items-center justify-between gap-3 mb-3">

            <h3 class="text-xs uppercase tracking-widest text-slate-300 font-bold">
                <i class="fas fa-tshirt text-emerald-400 mr-1"></i>
                Customer Items & Print Details
            </h3>

            <span class="text-[10px] text-slate-500">
                <?= $total_items ?> Items · <?= $total_qty ?> Qty
            </span>

        </div>

        <?php if (!empty($items)): ?>

            <div class="rounded-xl border border-white/10 overflow-hidden">

                <table class="w-full text-xs">

                    <thead>
                        <tr class="bg-white/[0.045] text-slate-500 text-[9px] uppercase tracking-wider">
                            <th class="text-left font-semibold py-3 px-3">Item</th>
                            <th class="text-center font-semibold py-3 px-2 w-12">Qty</th>
                            <th class="text-left font-semibold py-3 px-2">Print Details</th>
                            <th class="text-right font-semibold py-3 px-3">Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($items as $index => $it): ?>

                            <tr class="border-t border-white/[0.06]">

                                <td class="py-3 px-3 align-top">

                                    <div class="text-slate-200 font-semibold">
                                        <?= e($it['item_name'] ?? 'Customer Item') ?>
                                    </div>

                                    <?php if (!empty($it['print_notes'])): ?>

                                        <div class="text-[9px] text-slate-500 mt-1 leading-relaxed whitespace-pre-line">
                                            <?= e($it['print_notes']) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td class="py-3 px-2 text-center text-slate-300 align-top">
                                    <?= (int)($it['qty'] ?? 0) ?>
                                </td>

                                <td class="py-3 px-2 align-top">

                                    <div class="text-slate-300 whitespace-pre-line">
                                        <?= e($it['print_type'] ?? '-') ?>
                                    </div>

                                    <div class="text-[9px] text-slate-500 mt-1">
                                        Per Pc: <?= money($it['print_charge_per_pc'] ?? 0) ?>
                                    </div>

                                </td>

                                <td class="py-3 px-3 text-right text-white font-semibold align-top whitespace-nowrap">
                                    <?= money($it['print_charge_total'] ?? 0) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-slate-500">
                No individual customer items found.
            </div>

        <?php endif; ?>

    </div>

    <!-- AMOUNT RECEIVED -->

    <div class="relative rounded-2xl bg-gradient-to-r from-emerald-500/15 via-teal-500/10 to-emerald-500/5 border border-emerald-500/20 p-5 mb-5 text-center overflow-hidden">

        <div class="absolute inset-0 bg-gradient-to-r from-emerald-500/[0.03] to-transparent pointer-events-none"></div>

        <div class="relative">

            <div class="text-[9px] uppercase tracking-[0.2em] text-emerald-400 font-bold mb-2">
                Amount Received
            </div>

            <div class="text-3xl sm:text-4xl font-extrabold text-emerald-300 tracking-tight">
                <?= money($paid_amt) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-2">
                <?= e($payment_no) ?>
                <span class="mx-1">·</span>
                <?= fmt_date($payment_date) ?>
            </div>

        </div>

    </div>

    <!-- BILL SUMMARY -->

    <div class="border border-white/10 rounded-xl p-4 mb-5">

        <div class="text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-3">
            <i class="fas fa-calculator text-emerald-400 mr-1"></i>
            Service Payment Summary
        </div>

        <div class="space-y-2">

            <div class="flex justify-between text-xs text-slate-400">
                <span>Print Service Subtotal</span>
                <span class="text-slate-200 font-medium">
                    <?= money($subtotal) ?>
                </span>
            </div>

            <?php if ($gst_amount > 0): ?>

                <div class="flex justify-between text-xs text-slate-400">
                    <span>GST (<?= (float)$gst_percent ?>%)</span>
                    <span class="text-indigo-300 font-medium">
                        <?= money($gst_amount) ?>
                    </span>
                </div>

            <?php endif; ?>

            <div class="flex justify-between items-center text-sm font-bold border-t border-white/10 pt-3 mt-2">
                <span class="text-slate-200">
                    Service Grand Total
                </span>

                <span class="text-white text-base">
                    <?= money($grand_total) ?>
                </span>
            </div>

            <div class="flex justify-between text-xs text-slate-400 pt-1">
                <span>Amount Received (This Receipt)</span>
                <span class="text-emerald-300 font-semibold">
                    <?= money($paid_amt) ?>
                </span>
            </div>

            <div class="flex justify-between text-xs text-slate-400">
                <span>Total Paid (Till This Receipt)</span>
                <span class="text-emerald-300">
                    <?= money($paid_till) ?>
                </span>
            </div>

            <div class="flex justify-between items-center text-sm font-bold border-t border-white/10 pt-3 mt-2">

                <span class="text-slate-200">
                    Balance Due
                </span>

                <span class="<?= $balance_after > 0 ? 'text-rose-400' : 'text-emerald-400' ?>">
                    <?= $balance_after > 0 ? money($balance_after) : 'NIL (Fully Paid)' ?>
                </span>

            </div>

        </div>

    </div>

    <!-- SELECTED PAYMENT INFORMATION -->

    <?php if ($payment): ?>

        <div class="rounded-xl border border-emerald-500/15 bg-emerald-500/[0.04] p-4 mb-5">

            <div class="text-[10px] uppercase tracking-widest text-emerald-300 font-bold mb-3">
                <i class="fas fa-check-circle mr-1"></i>
                Transaction Details
            </div>

            <div class="grid grid-cols-2 gap-3">

                <div>
                    <div class="text-[9px] text-slate-500 mb-1">Payment Number</div>
                    <div class="text-xs text-slate-200 font-semibold">
                        <?= e($payment['payment_no']) ?>
                    </div>
                </div>

                <div>
                    <div class="text-[9px] text-slate-500 mb-1">Payment Status</div>
                    <div class="text-xs text-emerald-300 font-semibold">
                        <?= e(ucfirst($payment['status'] ?? 'confirmed')) ?>
                    </div>
                </div>

                <div>
                    <div class="text-[9px] text-slate-500 mb-1">Payment Mode</div>
                    <div class="text-xs text-slate-200">
                        <?= e(strtoupper($payment['payment_mode'] ?? 'CASH')) ?>
                    </div>
                </div>

                <div>
                    <div class="text-[9px] text-slate-500 mb-1">Payment Date</div>
                    <div class="text-xs text-slate-200">
                        <?= fmt_date($payment['payment_date']) ?>
                    </div>
                </div>

            </div>

            <?php if (!empty($payment['notes'])): ?>

                <div class="mt-3 pt-3 border-t border-white/10">
                    <div class="text-[9px] text-slate-500 mb-1">Payment Notes</div>
                    <div class="text-xs text-slate-300 whitespace-pre-line">
                        <?= e($payment['notes']) ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <!-- PAYMENT HISTORY -->

    <?php if (!empty($payments)): ?>

        <div class="mb-5">

            <div class="text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-3">
                <i class="fas fa-history text-indigo-400 mr-1"></i>
                Payment History
            </div>

            <div class="rounded-xl border border-white/10 overflow-hidden">

                <table class="w-full text-[10px]">

                    <thead>
                        <tr class="bg-white/[0.045] text-slate-500 uppercase">
                            <th class="text-left py-2.5 px-3 font-semibold">Payment No.</th>
                            <th class="text-left py-2.5 px-2 font-semibold">Date</th>
                            <th class="text-left py-2.5 px-2 font-semibold">Mode</th>
                            <th class="text-right py-2.5 px-3 font-semibold">Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($payments as $p): ?>

                            <tr class="border-t border-white/[0.06]">

                                <td class="py-2.5 px-3 text-slate-200 font-medium">
                                    <?= e($p['payment_no']) ?>

                                    <?php if ($payment && (int)$p['id'] === (int)$payment['id']): ?>
                                        <span class="text-emerald-400 ml-1">Current</span>
                                    <?php endif; ?>

                                </td>

                                <td class="py-2.5 px-2 text-slate-400">
                                    <?= fmt_date($p['payment_date']) ?>
                                </td>

                                <td class="py-2.5 px-2 text-slate-400 uppercase">
                                    <?= e($p['payment_mode'] ?? 'cash') ?>
                                </td>

                                <td class="py-2.5 px-3 text-right text-emerald-300 font-semibold">
                                    <?= money($p['amount']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

    <!-- SERVICE NOTES -->

    <?php if (!empty($service['notes'])): ?>

        <div class="rounded-xl bg-white/[0.035] border border-white/10 p-4 mb-5">

            <div class="text-[10px] uppercase tracking-widest text-slate-400 font-bold mb-2">
                <i class="fas fa-sticky-note text-amber-400 mr-1"></i>
                Service Notes
            </div>

            <div class="text-xs text-slate-300 leading-relaxed whitespace-pre-line">
                <?= e($service['notes']) ?>
            </div>

        </div>

    <?php endif; ?>

    <!-- FOOTER -->

    <div class="border-t border-white/10 pt-4 mt-5 text-center">

        <div class="text-sm font-bold text-emerald-300 mb-1">
            Thank You For Your Business!
        </div>

        <div class="text-[10px] text-slate-500 leading-relaxed">
            This is a computer generated payment receipt.
            <br>
            Please keep this receipt for your records.
        </div>

        <div class="text-[9px] text-slate-600 mt-3">
            <?= e(SITE_NAME) ?>
            <?php if (defined('SITE_VERSION')): ?>
                · <?= e(SITE_VERSION) ?>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- ============================================================
     PRINT STYLES
============================================================ -->

<style>

    @media print {

        body * {
            visibility: hidden;
        }

        .print-area,
        .print-area * {
            visibility: visible;
        }

        .print-area {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 20px !important;
            background: #ffffff !important;
            color: #111827 !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            overflow: visible !important;
        }

        .no-print {
            display: none !important;
        }

        .print-area .text-white,
        .print-area .text-slate-200,
        .print-area .text-slate-300 {
            color: #111827 !important;
        }

        .print-area .text-slate-400,
        .print-area .text-slate-500,
        .print-area .text-slate-600 {
            color: #555555 !important;
        }

        .print-area .text-emerald-300,
        .print-area .text-emerald-400 {
            color: #047857 !important;
        }

        .print-area .text-indigo-300 {
            color: #4338ca !important;
        }

        .print-area .text-rose-400 {
            color: #b91c1c !important;
        }

        .print-area .text-amber-300 {
            color: #b45309 !important;
        }

        .print-area .bg-white\/\[0\.035\],
        .print-area .bg-white\/5,
        .print-area .bg-white\/\[0\.045\] {
            background: #f8fafc !important;
        }

        .print-area .bg-emerald-500\/\[0\.04\],
        .print-area .bg-emerald-500\/15,
        .print-area .bg-gradient-to-r {
            background: #ecfdf5 !important;
        }

        .print-area .border-white\/10,
        .print-area .border-white\/\[0\.06\] {
            border-color: #e5e7eb !important;
        }

        .print-area .border-emerald-500\/20,
        .print-area .border-emerald-500\/15 {
            border-color: #a7f3d0 !important;
        }

        .print-area .shadow-lg {
            box-shadow: none !important;
        }

        .print-area .absolute {
            display: none !important;
        }

        .print-area table {
            page-break-inside: auto;
        }

        .print-area tr {
            page-break-inside: avoid;
        }

        .print-area .rounded-xl,
        .print-area .rounded-2xl {
            border-radius: 8px !important;
        }

        @page {
            size: A4;
            margin: 10mm;
        }

    }

</style>

<?php include '../includes/footer.php'; ?>
