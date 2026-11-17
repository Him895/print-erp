
<?php
// ============================================================
// FILE: reports/purchase_report.php
// PURCHASE REPORT
// Vendor-wise Purchase Analysis + Date Filter + Pagination
// ============================================================

require_once '../config/config.php';
require_permission('reports.purchase');
$page_title = 'Purchase Report';

// ------------------------------------------------------------
// DATE FILTER
// ------------------------------------------------------------

$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$from_date = (isset($_GET['from']) && is_string($_GET['from']))
    ? trim($_GET['from'])
    : $default_from;

$to_date = (isset($_GET['to']) && is_string($_GET['to']))
    ? trim($_GET['to'])
    : $default_to;

$date_error = '';

$validateDate = function ($date) {
    $d = DateTime::createFromFormat('!Y-m-d', $date);

    return $d && $d->format('Y-m-d') === $date;
};

if (!$validateDate($from_date) || !$validateDate($to_date)) {
    $date_error = 'Please select valid dates.';
    $from_date = $default_from;
    $to_date   = $default_to;
}

if ($from_date > $to_date) {
    $date_error = 'From date cannot be greater than To date.';
    $from_date = $default_from;
    $to_date   = $default_to;
}

// ------------------------------------------------------------
// PAGINATION
// ------------------------------------------------------------

$per_page = 25;

$current_page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$total_records = 0;
$total_pages   = 1;
$offset        = 0;

$purchases = [];

$totalAmount = 0;
$totalPaid   = 0;
$totalDue    = 0;

// ------------------------------------------------------------
// FETCH REPORT DATA
// ------------------------------------------------------------

if ($date_error === '') {

    // --------------------------------------------------------
    // SUMMARY: ALL PURCHASES IN SELECTED DATE RANGE
    // --------------------------------------------------------

    $summary_sql = "
        SELECT
            COUNT(*) AS total_records,
            COALESCE(SUM(p.grand_total), 0) AS total_amount,
            COALESCE(SUM(p.paid_amount), 0) AS total_paid,
            COALESCE(SUM(p.due_amount), 0) AS total_due
        FROM product_purchase p
        INNER JOIN vendors v ON p.vendor_id = v.id
        WHERE p.purchase_date >= ?
          AND p.purchase_date < DATE_ADD(?, INTERVAL 1 DAY)
    ";

    $summary_stmt = $conn->prepare($summary_sql);

    if ($summary_stmt) {

        $summary_stmt->bind_param('ss', $from_date, $to_date);

        if ($summary_stmt->execute()) {

            $summary = $summary_stmt->get_result()->fetch_assoc();

            if ($summary) {
                $total_records = (int) $summary['total_records'];
                $totalAmount   = (float) $summary['total_amount'];
                $totalPaid     = (float) $summary['total_paid'];
                $totalDue      = (float) $summary['total_due'];
            }
        }

        $summary_stmt->close();

    } else {
        $date_error = 'Unable to prepare purchase summary.';
    }

    // --------------------------------------------------------
    // CALCULATE TOTAL PAGES
    // --------------------------------------------------------

    $total_pages = max(1, (int) ceil($total_records / $per_page));

    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }

    $offset = ($current_page - 1) * $per_page;

    // --------------------------------------------------------
    // FETCH CURRENT PAGE PURCHASES
    // --------------------------------------------------------

    if ($date_error === '' && $total_records > 0) {

        $sql = "
            SELECT
                p.*,
                v.vendor_name
            FROM product_purchase p
            INNER JOIN vendors v ON p.vendor_id = v.id
            WHERE p.purchase_date >= ?
              AND p.purchase_date < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY p.purchase_date DESC, p.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $stmt->bind_param(
                'ssii',
                $from_date,
                $to_date,
                $per_page,
                $offset
            );

            if ($stmt->execute()) {
                $purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } else {
                $date_error = 'Unable to fetch purchase records.';
            }

            $stmt->close();

        } else {
            $date_error = 'Unable to prepare purchase report.';
        }
    }
}

// ------------------------------------------------------------
// PAGINATION URL
// ------------------------------------------------------------

$buildPageUrl = function ($page) use ($from_date, $to_date) {
    return '?' . http_build_query([
        'from' => $from_date,
        'to'   => $to_date,
        'page' => $page
    ]);
};

// ------------------------------------------------------------
// DISPLAY RANGE
// ------------------------------------------------------------

$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to   = min($offset + $per_page, $total_records);

// ------------------------------------------------------------
// HEADER
// ------------------------------------------------------------

include '../includes/header.php';
?>

<style>
    .report-card {
        background: rgba(255,255,255,0.035);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 14px;
    }

    .report-table th {
        white-space: nowrap;
    }

    .report-table td {
        white-space: nowrap;
    }

    .pagination-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid rgba(255,255,255,0.10);
        color: #cbd5e1;
        background: rgba(255,255,255,0.04);
        transition: 0.2s;
    }

    .pagination-link:hover {
        background: rgba(99,102,241,0.18);
        border-color: rgba(99,102,241,0.35);
        color: #a5b4fc;
    }

    .pagination-link.active {
        background: #6366f1;
        border-color: #6366f1;
        color: white;
    }

    .pagination-link.disabled {
        opacity: 0.35;
        pointer-events: none;
    }

    @media print {

        @page {
            size: landscape;
            margin: 12mm;
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
            background: white !important;
            color: black !important;
            padding: 10px !important;
        }

        .print-area .text-white {
            color: black !important;
        }

        .print-area .text-slate-400 {
            color: #555 !important;
        }

        .print-area .text-slate-300 {
            color: #333 !important;
        }

        .print-area .text-indigo-300 {
            color: #4338ca !important;
        }

        .print-area .text-emerald-300 {
            color: #047857 !important;
        }

        .print-area .text-rose-300 {
            color: #b91c1c !important;
        }

        .print-area .bg-white\/5 {
            background: #f3f4f6 !important;
        }

        .print-area .border-white\/10,
        .print-area .border-white\/5 {
            border-color: #ddd !important;
        }

        .print-area .glass-dark,
        .report-card {
            background: white !important;
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }

        .print-area table {
            width: 100%;
            border-collapse: collapse;
        }

        .print-area th,
        .print-area td {
            color: black !important;
            border-bottom: 1px solid #ddd !important;
            padding: 8px !important;
        }

        .print-area thead {
            background: #f3f4f6 !important;
        }

        .print-area .print-heading {
            display: block !important;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<!-- ========================================================
     PAGE HEADING
========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white">
            Purchase Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Vendor-wise purchase analysis and payment summary
        </p>
    </div>

    <div class="flex gap-2">

        <button
            type="button"
            onclick="window.print()"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition"
        >
            <i class="fas fa-print mr-1"></i>
            Print
        </button>

        <button
            type="button"
            onclick="exportCSV()"
            class="px-4 py-2 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-semibold hover:bg-emerald-500/30 transition"
        >
            <i class="fas fa-file-excel mr-1"></i>
            Export Current Page
        </button>

    </div>

</div>

<!-- ========================================================
     DATE FILTER
========================================================= -->

<form
    method="GET"
    class="glass-dark rounded-2xl p-4 mb-5 flex flex-wrap items-end gap-3 no-print"
>

    <div>
        <label class="text-[10px] text-slate-400 block mb-1">
            From Date
        </label>

        <input
            type="date"
            name="from"
            value="<?= e($from_date) ?>"
            required
            class="bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200"
        >
    </div>

    <div>
        <label class="text-[10px] text-slate-400 block mb-1">
            To Date
        </label>

        <input
            type="date"
            name="to"
            value="<?= e($to_date) ?>"
            required
            class="bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200"
        >
    </div>

    <button
        type="submit"
        class="px-5 py-2 rounded-lg bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-semibold transition"
    >
        <i class="fas fa-filter mr-1"></i>
        Apply Filter
    </button>

    <a
        href="purchase_report.php"
        class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
    >
        <i class="fas fa-rotate-left mr-1"></i>
        Reset
    </a>

</form>

<?php if ($date_error !== ''): ?>

    <div class="mb-5 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= e($date_error) ?>
    </div>

<?php endif; ?>

<!-- ========================================================
     PRINT AREA
========================================================= -->

<div class="print-area">

    <!-- PRINT HEADER -->

    <div class="hidden print-heading mb-6 text-center">

        <h2 class="text-2xl font-bold text-white">
            PrintERP
        </h2>

        <p class="text-sm text-slate-400 mt-1">
            Purchase Report
        </p>

        <p class="text-xs text-slate-400 mt-1">
            <?= e(fmt_date($from_date)) ?>
            to
            <?= e(fmt_date($to_date)) ?>
        </p>

        <hr class="my-3">

    </div>

    <!-- ====================================================
         SUMMARY CARDS
    ===================================================== -->

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">

        <!-- TOTAL PURCHASE -->

        <div class="report-card rounded-xl p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Total Purchase
                </div>

                <i class="fas fa-cart-shopping text-indigo-300"></i>

            </div>

            <div class="text-xl font-bold text-white mt-2">
                <?= money($totalAmount) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                <?= number_format($total_records) ?> purchase records
            </div>

        </div>

        <!-- TOTAL PAID -->

        <div class="report-card rounded-xl p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Total Paid
                </div>

                <i class="fas fa-circle-check text-emerald-300"></i>

            </div>

            <div class="text-xl font-bold text-emerald-300 mt-2">
                <?= money($totalPaid) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Payments recorded
            </div>

        </div>

        <!-- TOTAL DUE -->

        <div class="report-card rounded-xl p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Total Due
                </div>

                <i class="fas fa-clock text-rose-300"></i>

            </div>

            <div class="text-xl font-bold text-rose-300 mt-2">
                <?= money($totalDue) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Outstanding vendor balance
            </div>

        </div>

    </div>

    <!-- ====================================================
         PURCHASE TABLE
    ===================================================== -->

    <div class="glass-dark rounded-2xl overflow-hidden">

        <div class="p-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-2">

            <div>
                <h3 class="text-sm font-bold text-white">
                    Purchase Transactions
                </h3>

                <p class="text-[10px] text-slate-400 mt-1">
                    <?= e(fmt_date($from_date)) ?>
                    -
                    <?= e(fmt_date($to_date)) ?>
                </p>
            </div>

            <div class="text-xs text-slate-400">
                Total Records:
                <span class="text-white font-semibold">
                    <?= number_format($total_records) ?>
                </span>
            </div>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-xs report-table" id="reportTable">

                <thead>

                    <tr class="text-left border-b border-white/10 bg-white/5">

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            #
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Date
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Purchase No
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Vendor
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Total
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Paid
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Due
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($purchases)): ?>

                        <?php foreach ($purchases as $index => $p): ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-slate-400">
                                    <?= $offset + $index + 1 ?>
                                </td>

                                <td class="px-4 py-3 text-slate-300">
                                    <?= e(fmt_date($p['purchase_date'])) ?>
                                </td>

                                <td class="px-4 py-3 text-indigo-300 font-semibold">
                                    <?= e($p['purchase_no']) ?>
                                </td>

                                <td class="px-4 py-3 text-white font-medium">
                                    <?= e($p['vendor_name']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-white font-semibold">
                                    <?= money($p['grand_total']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300">
                                    <?= money($p['paid_amount']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-rose-300">
                                    <?= money($p['due_amount']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">

                                <div class="flex flex-col items-center">

                                    <div class="w-12 h-12 rounded-full bg-white/5 flex items-center justify-center mb-3">
                                        <i class="fas fa-box-open text-xl text-slate-500"></i>
                                    </div>

                                    <div class="text-sm font-semibold text-slate-300">
                                        No Purchase Records Found
                                    </div>

                                    <p class="text-xs text-slate-500 mt-1">
                                        No purchases available for the selected date range.
                                    </p>

                                </div>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- =================================================
             PAGINATION
        ================================================== -->

        <?php if ($total_records > 0): ?>

            <div class="p-4 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 no-print">

                <div class="text-xs text-slate-400">

                    Showing
                    <span class="text-white font-semibold">
                        <?= number_format($showing_from) ?>
                    </span>

                    to

                    <span class="text-white font-semibold">
                        <?= number_format($showing_to) ?>
                    </span>

                    of

                    <span class="text-white font-semibold">
                        <?= number_format($total_records) ?>
                    </span>

                    purchases

                </div>

                <div class="flex items-center gap-1 flex-wrap justify-center">

                    <!-- PREVIOUS -->

                    <a
                        href="<?= e($buildPageUrl(max(1, $current_page - 1))) ?>"
                        class="pagination-link <?= $current_page <= 1 ? 'disabled' : '' ?>"
                        aria-label="Previous page"
                    >
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <!-- PAGE NUMBERS -->

                    <?php

                    $start_page = max(1, $current_page - 2);
                    $end_page   = min($total_pages, $current_page + 2);

                    if ($start_page > 1):
                    ?>

                        <a href="<?= e($buildPageUrl(1)) ?>" class="pagination-link">
                            1
                        </a>

                        <?php if ($start_page > 2): ?>
                            <span class="text-slate-500 px-1">...</span>
                        <?php endif; ?>

                    <?php endif; ?>

                    <?php for ($page = $start_page; $page <= $end_page; $page++): ?>

                        <a
                            href="<?= e($buildPageUrl($page)) ?>"
                            class="pagination-link <?= $page === $current_page ? 'active' : '' ?>"
                        >
                            <?= $page ?>
                        </a>

                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>

                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="text-slate-500 px-1">...</span>
                        <?php endif; ?>

                        <a
                            href="<?= e($buildPageUrl($total_pages)) ?>"
                            class="pagination-link"
                        >
                            <?= $total_pages ?>
                        </a>

                    <?php endif; ?>

                    <!-- NEXT -->

                    <a
                        href="<?= e($buildPageUrl(min($total_pages, $current_page + 1))) ?>"
                        class="pagination-link <?= $current_page >= $total_pages ? 'disabled' : '' ?>"
                        aria-label="Next page"
                    >
                        <i class="fas fa-chevron-right"></i>
                    </a>

                </div>

            </div>

        <?php endif; ?>

    </div>

    <!-- PRINT FOOTER -->

    <div class="hidden print:block mt-5 text-xs text-slate-400 text-right">
        Generated on <?= date('d-m-Y h:i A') ?>
    </div>

</div>

<!-- ========================================================
     CSV EXPORT - CURRENT PAGE
========================================================= -->

<script>
function exportCSV() {

    const table = document.getElementById('reportTable');

    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tr');
    const csv = [];

    rows.forEach(row => {

        const cells = row.querySelectorAll('th, td');

        // Skip empty-state row
        if (cells.length !== 7) {
            return;
        }

        const rowData = [];

        cells.forEach((cell, index) => {

            // Skip serial number column
            if (index === 0) {
                return;
            }

            let value = cell.innerText.trim();

            // Remove currency formatting for numeric columns
            if (index >= 4) {
                value = value.replace(/[₹,]/g, '').trim();
            }

            // Escape double quotes for CSV
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No purchase records available to export.');
        return;
    }

    const csvContent = '\uFEFF' + csv.join('\r\n');

    const blob = new Blob(
        [csvContent],
        { type: 'text/csv;charset=utf-8;' }
    );

    const url = URL.createObjectURL(blob);

    const a = document.createElement('a');

    a.href = url;
    a.download = 'purchase_report_<?= date('Ymd_His') ?>.csv';

    document.body.appendChild(a);
    a.click();

    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
