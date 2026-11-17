
<?php
// ============================================================
// FILE: services/view_service_invoice.php
// DARK SCREEN + LIGHT PROFESSIONAL PRINT INVOICE
// MULTI-ITEM SERVICE INVOICE
// ============================================================

require_once '../config/config.php';
require_permission('services');
$page_title = 'Service Invoice';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('services_list.php');
}

// ------------------------------------------------------------
// FETCH SERVICE + CUSTOMER
// ------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT
        sv.*,
        c.client_name,
        c.phone,
        c.address,
        c.gst_no
    FROM services sv
    LEFT JOIN clients c ON sv.client_id = c.id
    WHERE sv.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$service) {
    set_flash('error', 'Service job not found.');
    redirect('services_list.php');
}

// ------------------------------------------------------------
// INVOICE ONLY AFTER COMPLETION
// ------------------------------------------------------------
if (!in_array($service['status'], ['completed', 'delivered'], true)) {
    set_flash('error', 'Invoice is available only after service completion.');
    redirect('services_list.php');
}

// ------------------------------------------------------------
// FETCH SERVICE ITEMS
// ------------------------------------------------------------
$items = [];

$item_stmt = $conn->prepare("
    SELECT
        item_name,
        qty,
        print_charge_per_pc,
        print_charge_total,
        print_type,
        print_notes
    FROM service_items
    WHERE service_id = ?
    ORDER BY id ASC
");

$item_stmt->bind_param('i', $id);
$item_stmt->execute();

$item_result = $item_stmt->get_result();

while ($row = $item_result->fetch_assoc()) {
    $items[] = $row;
}

$item_stmt->close();

// ------------------------------------------------------------
// LEGACY FALLBACK
// ------------------------------------------------------------
if (empty($items)) {

    $legacy_qty = (int)($service['qty'] ?? 0);
    $legacy_rate = (float)($service['print_charge_per_pc'] ?? 0);

    $items[] = [
        'item_name' => 'Custom Printing Service',
        'qty' => $legacy_qty,
        'print_charge_per_pc' => $legacy_rate,
        'print_charge_total' => $legacy_qty * $legacy_rate,
        'print_type' => $service['print_type'] ?? '',
        'print_notes' => $service['notes'] ?? ''
    ];
}

// ------------------------------------------------------------
// AMOUNT CALCULATIONS
// ------------------------------------------------------------
$items_subtotal = 0;

foreach ($items as $item) {
    $items_subtotal += (float)$item['print_charge_total'];
}

$subtotal = (float)($service['sub_total'] ?? 0);
$gst_percent = (float)($service['gst_percent'] ?? 0);
$gst_amount = (float)($service['gst_amount'] ?? 0);
$grand_total = (float)($service['total_amount'] ?? 0);
$paid_amount = (float)($service['paid_amount'] ?? 0);

// Older records fallback
if ($subtotal <= 0 && $items_subtotal > 0) {
    $subtotal = $items_subtotal;
}

if ($gst_amount <= 0 && $gst_percent > 0 && $subtotal > 0) {
    $gst_amount = ($subtotal * $gst_percent) / 100;
}

if ($grand_total <= 0) {
    $grand_total = $subtotal + $gst_amount;
}

$due_amount = max(0, $grand_total - $paid_amount);

// ------------------------------------------------------------
// DISPLAY HELPERS
// ------------------------------------------------------------
$customer_name = !empty($service['client_name'])
    ? $service['client_name']
    : 'Walk-in Customer';

$service_date = !empty($service['service_date'])
    ? fmt_date($service['service_date'])
    : '-';

$status_label = ucwords(str_replace('_', ' ', $service['status']));

$payment_mode = !empty($service['payment_mode'])
    ? strtoupper(str_replace('_', ' ', $service['payment_mode']))
    : 'N/A';

$payment_status = $due_amount > 0
    ? ($paid_amount > 0 ? 'Partially Paid' : 'Unpaid')
    : 'Fully Paid';

include '../includes/header.php';
?>

<!-- ========================================================
     PAGE HEADER
========================================================= -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-6 no-print">

    <div class="flex items-center gap-3">

        <div class="w-11 h-11 rounded-xl bg-teal-500/10 border border-teal-500/20 flex items-center justify-center">
            <i class="fas fa-file-invoice text-teal-400 text-lg"></i>
        </div>

        <div>
            <h1 class="text-2xl font-bold text-white">Service Invoice</h1>
            <p class="text-xs text-slate-400 mt-1">
                Invoice #<?= e($service['service_no']) ?>
            </p>
        </div>

    </div>

    <div class="flex flex-wrap gap-2">

        <button onclick="window.print()"
            class="px-5 py-2.5 rounded-xl bg-teal-500 hover:bg-teal-600 text-white text-xs font-bold transition shadow-lg shadow-teal-500/20">
            <i class="fas fa-print mr-2"></i>
            Print Invoice
        </button>

        <a href="services_list.php"
            class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition">
            <i class="fas fa-arrow-left mr-2"></i>
            Back
        </a>

    </div>

</div>

<!-- ========================================================
     INVOICE PAPER
========================================================= -->
<div class="invoice-paper">

    <div class="invoice-top-line"></div>

    <div class="invoice-content">

        <!-- HEADER -->
        <div class="invoice-header">

            <div class="brand-section">

                <div class="brand-icon">
                    <i class="fas fa-print"></i>
                </div>

                <div>
                    <h2>PrintERP</h2>
                    <p>Printing & Custom Services</p>
                    <span class="brand-caption">SERVICE MANAGEMENT</span>
                </div>

            </div>

            <div class="invoice-title">

                <span class="invoice-badge">OFFICIAL INVOICE</span>

                <h1>INVOICE</h1>

                <p><?= e($service['service_no']) ?></p>

                <div class="status-pill">
                    <span></span>
                    <?= e($status_label) ?>
                </div>

            </div>

        </div>

        <!-- INVOICE META -->
        <div class="invoice-meta">

            <div class="meta-item">
                <span>INVOICE NUMBER</span>
                <strong><?= e($service['service_no']) ?></strong>
            </div>

            <div class="meta-item">
                <span>INVOICE DATE</span>
                <strong><?= e($service_date) ?></strong>
            </div>

            <div class="meta-item">
                <span>SERVICE TYPE</span>
                <strong><?= e($service['service_type'] ?: 'Printing Service') ?></strong>
            </div>

            <div class="meta-item">
                <span>PAYMENT MODE</span>
                <strong><?= e($payment_mode) ?></strong>
            </div>

        </div>

        <!-- CUSTOMER + SERVICE DETAILS -->
        <div class="details-grid">

            <div class="detail-card">

                <div class="card-heading">
                    <i class="fas fa-user"></i>
                    CUSTOMER DETAILS
                </div>

                <h3><?= e($customer_name) ?></h3>

                <?php if (!empty($service['phone'])): ?>
                    <p>
                        <i class="fas fa-phone"></i>
                        <?= e($service['phone']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($service['address'])): ?>
                    <p>
                        <i class="fas fa-map-marker-alt"></i>
                        <?= nl2br(e($service['address'])) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($service['gst_no'])): ?>
                    <div class="customer-gstin">
                        GSTIN: <?= e($service['gst_no']) ?>
                    </div>
                <?php endif; ?>

            </div>

            <div class="detail-card">

                <div class="card-heading">
                    <i class="fas fa-clipboard-list"></i>
                    SERVICE INFORMATION
                </div>

                <div class="info-row">
                    <span>Job Number</span>
                    <strong><?= e($service['service_no']) ?></strong>
                </div>

                <div class="info-row">
                    <span>Service Type</span>
                    <strong><?= e($service['service_type'] ?: '-') ?></strong>
                </div>

                <div class="info-row">
                    <span>Print Type</span>
                    <strong><?= e($service['print_type'] ?: '-') ?></strong>
                </div>

                <div class="info-row">
                    <span>Job Status</span>
                    <strong class="success-text"><?= e($status_label) ?></strong>
                </div>

            </div>

        </div>

        <!-- SERVICE ITEMS -->
        <div class="items-section">

            <div class="section-heading">

                <div>
                    <h3>Service Details</h3>
                    <p>Printing items and charges breakdown</p>
                </div>

                <span class="items-count">
                    <?= count($items) ?> ITEMS
                </span>

            </div>

            <div class="table-wrapper">

                <table class="invoice-table">

                    <thead>
                        <tr>
                            <th width="45">#</th>
                            <th>Description</th>
                            <th>Print Type</th>
                            <th class="center">Qty</th>
                            <th class="right">Rate</th>
                            <th class="right">Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($items as $index => $item): ?>

                            <tr>

                                <td class="item-number">
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <div class="item-name">
                                        <?= e($item['item_name'] ?: 'Printing Service') ?>
                                    </div>

                                    <?php if (!empty($item['print_notes'])): ?>
                                        <div class="item-note">
                                            <?= e($item['print_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e($item['print_type'] ?: '-') ?>
                                </td>

                                <td class="center qty-value">
                                    <?= (int)$item['qty'] ?>
                                </td>

                                <td class="right nowrap">
                                    <?= money($item['print_charge_per_pc']) ?>
                                </td>

                                <td class="right amount-value nowrap">
                                    <?= money($item['print_charge_total']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- ADDITIONAL NOTES -->
        <?php if (!empty($service['notes'])): ?>

            <div class="notes-card">

                <div class="card-heading">
                    <i class="fas fa-sticky-note"></i>
                    ADDITIONAL NOTES
                </div>

                <p><?= nl2br(e($service['notes'])) ?></p>

            </div>

        <?php endif; ?>

        <!-- PAYMENT + TOTALS -->
        <div class="summary-grid">

            <div class="payment-card">

                <div class="card-heading">
                    <i class="fas fa-wallet"></i>
                    PAYMENT SUMMARY
                </div>

                <div class="payment-received">
                    <span>AMOUNT RECEIVED</span>
                    <h2><?= money($paid_amount) ?></h2>
                </div>

                <div class="payment-divider"></div>

                <div class="payment-info">
                    <span>Payment Status</span>
                    <strong class="<?= $due_amount > 0 ? ($paid_amount > 0 ? 'warning-text' : 'danger-text') : 'success-text' ?>">
                        <?= e($payment_status) ?>
                    </strong>
                </div>

                <div class="payment-info">
                    <span>Payment Mode</span>
                    <strong><?= e($payment_mode) ?></strong>
                </div>

                <div class="payment-info">
                    <span>Balance Outstanding</span>
                    <strong class="<?= $due_amount > 0 ? 'warning-text' : 'success-text' ?>">
                        <?= money($due_amount) ?>
                    </strong>
                </div>

            </div>

            <div class="totals-card">

                <div class="card-heading">
                    <i class="fas fa-calculator"></i>
                    BILL SUMMARY
                </div>

                <div class="total-row">
                    <span>Subtotal</span>
                    <strong><?= money($subtotal) ?></strong>
                </div>

                <div class="total-row">
                    <span>GST (<?= number_format($gst_percent, 2) ?>%)</span>
                    <strong><?= money($gst_amount) ?></strong>
                </div>

                <div class="grand-total-row">
                    <span>GRAND TOTAL</span>
                    <strong><?= money($grand_total) ?></strong>
                </div>

                <div class="total-row paid-row">
                    <span>Amount Paid</span>
                    <strong><?= money($paid_amount) ?></strong>
                </div>

                <div class="balance-row">
                    <span>BALANCE DUE</span>
                    <strong><?= money($due_amount) ?></strong>
                </div>

            </div>

        </div>

        <!-- FOOTER -->
        <div class="invoice-footer">

            <div class="footer-thanks">

                <i class="fas fa-check-circle"></i>

                <div>
                    <h4>Thank You For Your Business!</h4>
                    <p>We appreciate your trust in our printing services.</p>
                </div>

            </div>

            <div class="footer-note">
                This is a computer-generated invoice.
                <br>
                Please retain this invoice for your records.
            </div>

        </div>

    </div>

</div>

<!-- ========================================================
     CSS
========================================================= -->
<style>

/* SCREEN: DARK INVOICE */

.invoice-paper {
    max-width: 900px;
    margin: 0 auto 35px;
    background: #101827;
    color: #e5edf7;
    border: 1px solid #263449;
    border-radius: 17px;
    overflow: hidden;
    box-shadow: 0 25px 65px rgba(0,0,0,.28);
    font-family: Inter, Arial, sans-serif;
}

.invoice-top-line {
    height: 5px;
    background: linear-gradient(90deg, #0d9488, #2563eb, #6366f1);
}

.invoice-content {
    padding: 38px 42px;
}

/* HEADER */

.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 25px;
    padding-bottom: 28px;
    border-bottom: 1px solid #263449;
}

.brand-section {
    display: flex;
    align-items: center;
    gap: 14px;
}

.brand-icon {
    width: 52px;
    height: 52px;
    border-radius: 13px;
    background: #142d3a;
    border: 1px solid #24505b;
    color: #2dd4bf;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
}

.brand-section h2 {
    font-size: 24px;
    font-weight: 850;
    letter-spacing: -.7px;
    color: #f8fafc;
}

.brand-section p {
    font-size: 11px;
    color: #9aabc0;
    margin-top: 3px;
}

.brand-caption {
    display: inline-block;
    font-size: 8px;
    color: #2dd4bf;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-top: 8px;
}

.invoice-title {
    text-align: right;
}

.invoice-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 6px;
    background: #182d45;
    border: 1px solid #294768;
    color: #93c5fd;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.3px;
}

.invoice-title h1 {
    color: #f8fafc;
    font-size: 30px;
    font-weight: 900;
    letter-spacing: 2px;
    margin-top: 9px;
}

.invoice-title > p {
    font-size: 12px;
    color: #a8b8cc;
    margin-top: 2px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 11px;
    padding: 6px 11px;
    border-radius: 20px;
    background: #12332e;
    color: #6ee7b7;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
}

.status-pill span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #34d399;
}

/* META */

.invoice-meta {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    padding: 22px 0;
    border-bottom: 1px solid #263449;
}

.meta-item {
    padding: 12px;
    border-radius: 9px;
    background: #151f30;
    border: 1px solid #243247;
    min-width: 0;
}

.meta-item span {
    display: block;
    font-size: 8px;
    color: #8093aa;
    font-weight: 800;
    letter-spacing: .8px;
    margin-bottom: 8px;
}

.meta-item strong {
    display: block;
    font-size: 10px;
    color: #e7edf5;
    overflow-wrap: anywhere;
}

/* DETAILS */

.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding: 24px 0;
}

.detail-card,
.payment-card,
.totals-card {
    background: #151f30;
    border: 1px solid #26374c;
    border-radius: 12px;
    padding: 19px;
}

.card-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 9px;
    font-weight: 850;
    color: #8fa4bb;
    letter-spacing: 1px;
    margin-bottom: 16px;
}

.card-heading i {
    color: #2dd4bf;
    font-size: 12px;
}

.detail-card h3 {
    font-size: 16px;
    font-weight: 800;
    color: #f1f5f9;
    margin-bottom: 10px;
}

.detail-card > p {
    display: flex;
    gap: 9px;
    align-items: flex-start;
    font-size: 10px;
    line-height: 1.7;
    color: #9aabc0;
    margin-top: 5px;
}

.detail-card > p i {
    color: #647f9d;
    margin-top: 2px;
    min-width: 12px;
}

.customer-gstin {
    color: #cbd5e1;
    font-size: 9px;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #2a394d;
    font-weight: 700;
}

.info-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 7px 0;
    border-bottom: 1px solid #263449;
    font-size: 10px;
}

.info-row:last-child {
    border-bottom: 0;
}

.info-row span {
    color: #8fa4bb;
}

.info-row strong {
    color: #e2e8f0;
    text-align: right;
}

.success-text {
    color: #34d399 !important;
}

.warning-text {
    color: #fbbf24 !important;
}

.danger-text {
    color: #f87171 !important;
}

/* ITEMS */

.items-section {
    padding: 22px 0;
}

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.section-heading h3 {
    font-size: 15px;
    font-weight: 800;
    color: #f1f5f9;
}

.section-heading p {
    color: #8193aa;
    font-size: 10px;
    margin-top: 4px;
}

.items-count {
    padding: 6px 10px;
    border-radius: 6px;
    background: #172d3c;
    color: #5eead4;
    font-size: 9px;
    font-weight: 800;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #28384c;
    border-radius: 10px;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
}

.invoice-table thead {
    background: #1a2a3d;
}

.invoice-table th {
    padding: 14px 11px;
    color: #a9bbcf;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .7px;
    text-align: left;
    white-space: nowrap;
}

.invoice-table td {
    padding: 15px 11px;
    border-top: 1px solid #253449;
    color: #aab9ca;
    vertical-align: top;
}

.invoice-table tbody tr:nth-child(even) {
    background: #131e2e;
}

.invoice-table tbody tr:nth-child(odd) {
    background: #101a29;
}

.item-number {
    color: #71849b !important;
}

.item-name {
    color: #f1f5f9;
    font-weight: 750;
}

.item-note {
    font-size: 9px;
    color: #8497ad;
    line-height: 1.5;
    margin-top: 5px;
    white-space: pre-wrap;
}

.qty-value {
    color: #e2e8f0 !important;
    font-weight: 750;
}

.amount-value {
    color: #5eead4 !important;
    font-weight: 800;
}

.center {
    text-align: center !important;
}

.right {
    text-align: right !important;
}

.nowrap {
    white-space: nowrap;
}

/* NOTES */

.notes-card {
    padding: 16px;
    border-radius: 10px;
    background: #172538;
    border: 1px solid #2a3e55;
    margin: 8px 0 22px;
}

.notes-card p {
    color: #c0ccda;
    font-size: 10px;
    line-height: 1.7;
    white-space: pre-wrap;
}

/* PAYMENT AND TOTALS */

.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 17px;
    padding-top: 22px;
    border-top: 1px solid #28384c;
}

.payment-card,
.totals-card {
    padding: 20px;
}

.payment-received {
    margin-top: 20px;
}

.payment-received span {
    font-size: 8px;
    color: #8fa4bb;
    letter-spacing: 1px;
    font-weight: 800;
}

.payment-received h2 {
    font-size: 27px;
    color: #34d399;
    font-weight: 850;
    margin-top: 8px;
}

.payment-divider {
    height: 1px;
    background: #2b3b50;
    margin: 19px 0 10px;
}

.payment-info {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 0;
    font-size: 10px;
}

.payment-info span {
    color: #8fa4bb;
}

.payment-info strong {
    text-align: right;
}

.total-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    font-size: 10px;
}

.total-row span {
    color: #9aabc0;
}

.total-row strong {
    color: #e2e8f0;
}

.grand-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 7px 0;
    padding: 15px 0;
    border-top: 1px solid #34475f;
    border-bottom: 1px solid #34475f;
}

.grand-total-row span {
    color: #f1f5f9;
    font-size: 10px;
    font-weight: 850;
}

.grand-total-row strong {
    color: #60a5fa;
    font-size: 21px;
    font-weight: 900;
}

.paid-row {
    padding-top: 12px;
}

.balance-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 9px;
    padding: 13px;
    border: 1px solid #4a3d25;
    background: #292518;
    border-radius: 8px;
}

.balance-row span {
    font-size: 9px;
    font-weight: 850;
    color: #fcd34d;
    letter-spacing: .5px;
}

.balance-row strong {
    color: #fbbf24;
    font-size: 15px;
    font-weight: 850;
}

/* FOOTER */

.invoice-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 15px;
    padding-top: 23px;
    margin-top: 24px;
    border-top: 1px solid #28384c;
}

.footer-thanks {
    display: flex;
    align-items: flex-start;
    gap: 11px;
}

.footer-thanks > i {
    color: #2dd4bf;
    font-size: 20px;
}

.footer-thanks h4 {
    color: #f1f5f9;
    font-size: 12px;
    font-weight: 800;
}

.footer-thanks p,
.footer-note {
    color: #8497ad;
    font-size: 9px;
    line-height: 1.7;
    margin-top: 5px;
}

.footer-note {
    text-align: right;
}

/* RESPONSIVE */

@media (max-width: 650px) {

    .invoice-content {
        padding: 22px 15px;
    }

    .invoice-header {
        flex-direction: column;
    }

    .invoice-title {
        text-align: left;
    }

    .invoice-meta {
        grid-template-columns: repeat(2, 1fr);
    }

    .details-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .invoice-table {
        min-width: 620px;
    }

    .invoice-footer {
        flex-direction: column;
        align-items: flex-start;
    }

    .footer-note {
        text-align: left;
    }
}

/* ========================================================
   PRINT: WHITE PROFESSIONAL INVOICE
========================================================= */

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
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body * {
        visibility: hidden !important;
    }

    .invoice-paper,
    .invoice-paper * {
        visibility: visible !important;
    }

    .invoice-paper {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        color: #111827 !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        overflow: visible !important;
    }

    .invoice-content {
        padding: 22px !important;
        background: #ffffff !important;
    }

    .invoice-top-line {
        background: #0f766e !important;
    }

    .no-print,
    button,
    a {
        display: none !important;
    }

    /* Header */
    .brand-icon {
        background: #ecfdf5 !important;
        border-color: #a7f3d0 !important;
        color: #047857 !important;
    }

    .brand-section h2,
    .invoice-title h1,
    .detail-card h3,
    .section-heading h3,
    .footer-thanks h4 {
        color: #111827 !important;
    }

    .brand-section p,
    .invoice-title > p,
    .brand-caption,
    .meta-item span,
    .section-heading p,
    .footer-note,
    .footer-thanks p {
        color: #4b5563 !important;
    }

    .invoice-badge {
        background: #eff6ff !important;
        border-color: #bfdbfe !important;
        color: #1d4ed8 !important;
    }

    .status-pill {
        background: #ecfdf5 !important;
        color: #047857 !important;
    }

    /* Meta and detail cards */
    .meta-item,
    .detail-card,
    .payment-card,
    .totals-card,
    .notes-card {
        background: #ffffff !important;
        border: 1px solid #d1d5db !important;
    }

    .meta-item strong,
    .info-row strong,
    .payment-info strong,
    .total-row strong,
    .notes-card p {
        color: #111827 !important;
    }

    .card-heading,
    .info-row span,
    .payment-info span,
    .total-row span {
        color: #4b5563 !important;
    }

    .info-row,
    .payment-divider,
    .customer-gstin {
        border-color: #e5e7eb !important;
    }

    .detail-card > p {
        color: #374151 !important;
    }

    /* Items table */
    .table-wrapper {
        overflow: visible !important;
        border-color: #d1d5db !important;
    }

    .invoice-table {
        min-width: 0 !important;
        width: 100% !important;
        background: #ffffff !important;
    }

    .invoice-table thead,
    .invoice-table th {
        background: #e5e7eb !important;
        color: #111827 !important;
    }

    .invoice-table td {
        color: #374151 !important;
        border-color: #e5e7eb !important;
    }

    .invoice-table tbody tr:nth-child(even),
    .invoice-table tbody tr:nth-child(odd) {
        background: #ffffff !important;
    }

    .item-name,
    .qty-value {
        color: #111827 !important;
    }

    .item-note {
        color: #4b5563 !important;
    }

    .amount-value,
    .success-text {
        color: #047857 !important;
    }

    .warning-text {
        color: #b45309 !important;
    }

    .danger-text {
        color: #b91c1c !important;
    }

    /* Payment and totals */
    .payment-received span {
        color: #4b5563 !important;
    }

    .payment-received h2 {
        color: #047857 !important;
    }

    .grand-total-row {
        border-color: #d1d5db !important;
    }

    .grand-total-row span {
        color: #111827 !important;
    }

    .grand-total-row strong {
        color: #1d4ed8 !important;
    }

    .balance-row {
        background: #fffbeb !important;
        border-color: #fcd34d !important;
    }

    .balance-row span,
    .balance-row strong {
        color: #92400e !important;
    }

    /* Footer */
    .invoice-footer {
        border-color: #d1d5db !important;
    }

    .footer-thanks > i {
        color: #047857 !important;
    }

    /* Print table sizing */
    .invoice-table th,
    .invoice-table td {
        padding: 10px 7px !important;
        font-size: 8px !important;
    }

    /* Pagination */
    .invoice-table tr,
    .details-grid,
    .summary-grid,
    .invoice-footer {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

}

</style>

<?php include '../includes/footer.php'; ?>
