
<?php
// ============================================================
// FILE: reports/sales_report.php
// SALES + DIRECT SERVICE REPORT
// Date Filters + Pagination + Full-Range Summary + CSV Export
// ============================================================

require_once '../config/config.php';
require_permission('reports.sales');
$page_title = 'Sales & Service Report';

// ------------------------------------------------------------
// 1. DATE FILTERS
// ------------------------------------------------------------

$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$from_date = $_GET['from'] ?? $default_from;
$to_date   = $_GET['to'] ?? $default_to;

$from_date = is_scalar($from_date) ? trim((string)$from_date) : $default_from;
$to_date   = is_scalar($to_date) ? trim((string)$to_date) : $default_to;

$report_error = '';

function isValidReportDate($date)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTime::getLastErrors();

    return $dateObject instanceof DateTime
        && $dateObject->format('Y-m-d') === $date
        && (
            $dateErrors === false ||
            (
                $dateErrors['warning_count'] === 0 &&
                $dateErrors['error_count'] === 0
            )
        );
}

if (!isValidReportDate($from_date) || !isValidReportDate($to_date)) {
    $report_error = 'Please select valid dates.';
} elseif ($from_date > $to_date) {
    $report_error = 'From Date cannot be greater than To Date.';
}

// ------------------------------------------------------------
// 2. PAGINATION SETTINGS
// ------------------------------------------------------------

$per_page_options = [10, 25, 50, 100];

$per_page = filter_var(
    $_GET['per_page'] ?? 25,
    FILTER_VALIDATE_INT
);

if (!in_array($per_page, $per_page_options, true)) {
    $per_page = 25;
}

$current_page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

if (!$current_page || $current_page < 1) {
    $current_page = 1;
}

$total_records = 0;
$total_pages = 1;
$offset = 0;

$transactions = [];

$totalRevenue = 0;
$totalCost = 0;
$totalProfit = 0;
$totalPaid = 0;
$totalDue = 0;

// ------------------------------------------------------------
// 3. COMBINED SALES + SERVICE QUERY
// ------------------------------------------------------------

if ($report_error === '') {

    /*
     * IMPORTANT:
     *
     * SALES:
     * Revenue = Grand Total - GST
     * Cost = Product Purchase Cost
     *
     * SERVICES:
     * Revenue = Sub Total (GST excluded)
     * Cost = 0 because service material/labour cost
     * is not currently tracked in the database.
     *
     * Paid and Due remain the actual transaction amounts.
     */

    $base_sql = "
        SELECT
            s.id,
            s.sale_no AS ref_no,
            s.sale_date AS report_date,

            (
                COALESCE(s.grand_total, 0) -
                COALESCE(s.gst_amount, 0)
            ) AS revenue,

            COALESCE(s.paid_amount, 0) AS paid_amount,
            COALESCE(s.due_amount, 0) AS due_amount,

            COALESCE(s.payment_mode, '') AS payment_mode,

            COALESCE(c.customer_name, 'Walk-in') AS customer_name,

            'Sale' AS transaction_type,

            (
                SELECT COALESCE(
                    SUM(si.qty * p.purchase_price),
                    0
                )
                FROM sale_items si
                INNER JOIN products p
                    ON p.id = si.product_id
                WHERE si.sale_id = s.id
            ) AS cost

        FROM sales s

        LEFT JOIN customers c
            ON c.id = s.client_id

        WHERE
            s.sale_date >= ?
            AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
            AND s.status != 'cancelled'

        UNION ALL

        SELECT
            sv.id,
            sv.service_no AS ref_no,
            sv.service_date AS report_date,

            COALESCE(
                sv.sub_total,
                sv.total_amount - COALESCE(sv.gst_amount, 0)
            ) AS revenue,

            COALESCE(sv.paid_amount, 0) AS paid_amount,
            COALESCE(sv.due_amount, 0) AS due_amount,

            COALESCE(sv.payment_mode, '') AS payment_mode,

            COALESCE(c.customer_name, 'Walk-in') AS customer_name,

            'Service' AS transaction_type,

            0 AS cost

        FROM services sv

        LEFT JOIN customers c
            ON c.id = sv.client_id

        WHERE
            sv.service_date >= ?
            AND sv.service_date < DATE_ADD(?, INTERVAL 1 DAY)
            AND sv.sale_id IS NULL
            AND sv.status != 'cancelled'
    ";

    // --------------------------------------------------------
    // 4. TOTAL RECORD COUNT
    // --------------------------------------------------------

    $count_sql = "
        SELECT COUNT(*) AS total_records
        FROM (
            $base_sql
        ) AS report_data
    ";

    $count_stmt = $conn->prepare($count_sql);

    if (!$count_stmt) {
        $report_error = 'Unable to prepare report count query.';
        error_log('Sales Report Count Prepare Error: ' . $conn->error);
    } else {

        $count_stmt->bind_param(
            'ssss',
            $from_date,
            $to_date,
            $from_date,
            $to_date
        );

        if (!$count_stmt->execute()) {
            $report_error = 'Unable to calculate report records.';
            error_log('Sales Report Count Execute Error: ' . $count_stmt->error);
        } else {

            $count_result = $count_stmt->get_result()->fetch_assoc();
            $total_records = (int)($count_result['total_records'] ?? 0);
        }

        $count_stmt->close();
    }

    // --------------------------------------------------------
    // 5. FULL DATE RANGE SUMMARY
    // --------------------------------------------------------

    if ($report_error === '') {

        $summary_sql = "
            SELECT
                COALESCE(SUM(report_data.revenue), 0) AS total_revenue,
                COALESCE(SUM(report_data.cost), 0) AS total_cost,
                COALESCE(SUM(report_data.paid_amount), 0) AS total_paid,
                COALESCE(SUM(report_data.due_amount), 0) AS total_due

            FROM (
                $base_sql
            ) AS report_data
        ";

        $summary_stmt = $conn->prepare($summary_sql);

        if (!$summary_stmt) {
            $report_error = 'Unable to prepare report summary.';
            error_log('Sales Report Summary Prepare Error: ' . $conn->error);
        } else {

            $summary_stmt->bind_param(
                'ssss',
                $from_date,
                $to_date,
                $from_date,
                $to_date
            );

            if (!$summary_stmt->execute()) {
                $report_error = 'Unable to calculate report summary.';
                error_log('Sales Report Summary Execute Error: ' . $summary_stmt->error);
            } else {

                $summary = $summary_stmt->get_result()->fetch_assoc();

                $totalRevenue = (float)($summary['total_revenue'] ?? 0);
                $totalCost    = (float)($summary['total_cost'] ?? 0);
                $totalPaid    = (float)($summary['total_paid'] ?? 0);
                $totalDue     = (float)($summary['total_due'] ?? 0);

                $totalProfit = $totalRevenue - $totalCost;
            }

            $summary_stmt->close();
        }
    }

    // --------------------------------------------------------
    // 6. PAGINATION CALCULATION
    // --------------------------------------------------------

    if ($report_error === '') {

        $total_pages = max(
            1,
            (int)ceil($total_records / $per_page)
        );

        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }

        $offset = ($current_page - 1) * $per_page;

        // ----------------------------------------------------
        // 7. FETCH CURRENT PAGE RECORDS
        // ----------------------------------------------------

        $data_sql = "
            SELECT *
            FROM (
                $base_sql
            ) AS report_data

            ORDER BY
                report_date DESC,
                id DESC,
                transaction_type ASC

            LIMIT ? OFFSET ?
        ";

        $data_stmt = $conn->prepare($data_sql);

        if (!$data_stmt) {
            $report_error = 'Unable to prepare report data query.';
            error_log('Sales Report Data Prepare Error: ' . $conn->error);
        } else {

            $data_stmt->bind_param(
                'ssssii',
                $from_date,
                $to_date,
                $from_date,
                $to_date,
                $per_page,
                $offset
            );

            if (!$data_stmt->execute()) {
                $report_error = 'Unable to fetch report data.';
                error_log('Sales Report Data Execute Error: ' . $data_stmt->error);
            } else {

                $transactions = $data_stmt
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC);
            }

            $data_stmt->close();
        }
    }
}

// ------------------------------------------------------------
// 8. PAGINATION URL HELPER
// ------------------------------------------------------------

function reportPageUrl($page, $from, $to, $perPage)
{
    return '?' . http_build_query([
        'from' => $from,
        'to' => $to,
        'per_page' => $perPage,
        'page' => $page
    ]);
}

include '../includes/header.php';
?>

<style>
    .report-card {
        background: rgba(255,255,255,.035);
        border: 1px solid rgba(255,255,255,.08);
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
        font-size: 11px;
        border: 1px solid rgba(255,255,255,.10);
        background: rgba(255,255,255,.04);
        color: #cbd5e1;
        transition: .2s;
    }

    .pagination-link:hover {
        background: rgba(99,102,241,.18);
        border-color: rgba(99,102,241,.4);
        color: white;
    }

    .pagination-link.active {
        background: #6366f1;
        border-color: #6366f1;
        color: white;
        font-weight: 700;
    }

    .pagination-link.disabled {
        opacity: .35;
        pointer-events: none;
    }

    @media print {

        @page {
            size: landscape;
            margin: 12mm;
        }

        body * {
            visibility: hidden !important;
        }

        .print-area,
        .print-area * {
            visibility: visible !important;
        }

        .print-area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            background: white !important;
            color: black !important;
            padding: 0 !important;
        }

        .print-area .text-white,
        .print-area .text-slate-200,
        .print-area .text-slate-300,
        .print-area .text-slate-400,
        .print-area .text-slate-500 {
            color: #222 !important;
        }

        .print-area .text-emerald-300,
        .print-area .text-emerald-400 {
            color: #047857 !important;
        }

        .print-area .text-indigo-300,
        .print-area .text-indigo-400 {
            color: #4338ca !important;
        }

        .print-area .text-amber-300,
        .print-area .text-amber-400 {
            color: #b45309 !important;
        }

        .print-area .text-rose-300,
        .print-area .text-rose-400 {
            color: #be123c !important;
        }

        .print-area .bg-white\/5,
        .print-area .bg-white\/10 {
            background: #f3f4f6 !important;
        }

        .print-area .glass-dark,
        .print-area .report-card {
            background: white !important;
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }

        .print-area table {
            border-collapse: collapse !important;
        }

        .print-area th,
        .print-area td {
            color: #222 !important;
            border-bottom: 1px solid #ddd !important;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Sales & Service Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Sales, direct print services, revenue, cost and payment analysis
        </p>
    </div>

    <div class="flex flex-wrap gap-2">

        <button
            type="button"
            onclick="window.print()"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">

            <i class="fas fa-print mr-1"></i>
            Print / PDF
        </button>

        <button
            type="button"
            onclick="exportCSV()"
            class="px-4 py-2 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-semibold hover:bg-emerald-500/30 transition">

            <i class="fas fa-file-csv mr-1"></i>
            Export Current Page
        </button>

    </div>

</div>

<!-- =========================================================
     ERROR MESSAGE
========================================================= -->

<?php if ($report_error !== ''): ?>

    <div class="mb-5 rounded-xl p-4 bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">

        <i class="fas fa-exclamation-circle mr-2"></i>

        <?= e($report_error) ?>

    </div>

<?php endif; ?>

<!-- =========================================================
     DATE FILTERS
========================================================= -->

<form
    method="GET"
    class="glass-dark rounded-2xl p-4 mb-5 flex flex-wrap gap-3 items-end no-print">

    <div>

        <label class="text-[10px] text-slate-400 block mb-1">
            From Date
        </label>

        <input
            type="date"
            name="from"
            value="<?= e($from_date) ?>"
            required
            class="bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">

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
            class="bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">

    </div>

    <div>

        <label class="text-[10px] text-slate-400 block mb-1">
            Records Per Page
        </label>

        <select
            name="per_page"
            class="bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">

            <?php foreach ($per_page_options as $option): ?>

                <option
                    value="<?= $option ?>"
                    class="bg-slate-800"
                    <?= $per_page === $option ? 'selected' : '' ?>>

                    <?= $option ?> Records

                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <button
        type="submit"
        class="px-4 py-2 rounded-lg bg-indigo-500 text-white text-xs font-semibold hover:bg-indigo-600 transition">

        <i class="fas fa-filter mr-1"></i>
        Apply Filter

    </button>

    <a
        href="sales_report.php"
        class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition">

        <i class="fas fa-undo mr-1"></i>
        Reset

    </a>

</form>

<?php if ($report_error === ''): ?>

<!-- =========================================================
     PRINTABLE AREA
========================================================= -->

<div class="print-area">

    <!-- PRINT HEADER -->

    <div class="hidden print:block mb-6 text-center">

        <h2 class="text-2xl font-bold">
            PrintERP
        </h2>

        <p class="text-sm mt-1">
            Sales & Service Report
        </p>

        <p class="text-xs mt-1">
            Period: <?= fmt_date($from_date) ?> to <?= fmt_date($to_date) ?>
        </p>

        <hr class="my-3">

    </div>

    <!-- =====================================================
         SUMMARY CARDS
    ====================================================== -->

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">

        <!-- REVENUE -->

        <div class="report-card rounded-xl p-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Revenue Excl. GST
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-2">
                <?= money($totalRevenue) ?>
            </div>

        </div>

        <!-- COST -->

        <div class="report-card rounded-xl p-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Tracked Cost
            </div>

            <div class="text-xl font-bold text-rose-300 mt-2">
                <?= money($totalCost) ?>
            </div>

        </div>

        <!-- PROFIT -->

        <div class="report-card rounded-xl p-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Estimated Profit
            </div>

            <div class="text-xl font-bold text-indigo-300 mt-2">
                <?= money($totalProfit) ?>
            </div>

        </div>

        <!-- PAID -->

        <div class="report-card rounded-xl p-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Received
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-2">
                <?= money($totalPaid) ?>
            </div>

        </div>

        <!-- DUE -->

        <div class="report-card rounded-xl p-4">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Outstanding
            </div>

            <div class="text-xl font-bold text-amber-300 mt-2">
                <?= money($totalDue) ?>
            </div>

        </div>

    </div>

    <!-- REPORT INFORMATION -->

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">

        <div class="text-xs text-slate-400">

            Report Period:

            <span class="text-slate-200 font-medium">
                <?= fmt_date($from_date) ?>
                -
                <?= fmt_date($to_date) ?>
            </span>

        </div>

        <div class="text-xs text-slate-400">

            Total Transactions:

            <span class="text-white font-semibold">
                <?= number_format($total_records) ?>
            </span>

        </div>

    </div>

    <!-- =====================================================
         REPORT TABLE
    ====================================================== -->

    <div class="glass-dark rounded-2xl overflow-hidden">

        <div class="overflow-x-auto">

            <table
                class="report-table w-full text-xs"
                id="reportTable">

                <thead>

                    <tr class="text-left border-b border-white/10 bg-white/5">

                        <th class="px-4 py-3 text-slate-400 font-medium">
                            Date
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium">
                            Reference No.
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium">
                            Type
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium">
                            Customer
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium">
                            Payment Mode
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right">
                            Revenue
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right">
                            Cost
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right">
                            Profit
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right">
                            Paid
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right">
                            Due
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($transactions)): ?>

                        <?php foreach ($transactions as $t): ?>

                            <?php
                                $revenue = (float)$t['revenue'];
                                $cost = (float)$t['cost'];
                                $profit = $revenue - $cost;

                                $paid = (float)$t['paid_amount'];
                                $due = (float)$t['due_amount'];

                                $is_sale = $t['transaction_type'] === 'Sale';
                            ?>

                            <tr class="border-b border-white/5 hover:bg-white/[0.025]">

                                <!-- DATE -->

                                <td
                                    class="px-4 py-3 text-slate-300"
                                    data-export="<?= e($t['report_date']) ?>">

                                    <?= fmt_date($t['report_date']) ?>

                                </td>

                                <!-- REFERENCE -->

                                <td
                                    class="px-4 py-3 text-indigo-300 font-semibold"
                                    data-export="<?= e($t['ref_no']) ?>">

                                    <?= e($t['ref_no']) ?>

                                </td>

                                <!-- TYPE -->

                                <td
                                    class="px-4 py-3"
                                    data-export="<?= e($t['transaction_type']) ?>">

                                    <?php if ($is_sale): ?>

                                        <span class="text-indigo-300">
                                            <i class="fas fa-shopping-bag mr-1"></i>
                                            Sale
                                        </span>

                                    <?php else: ?>

                                        <span class="text-amber-400">
                                            <i class="fas fa-print mr-1"></i>
                                            Service
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- CUSTOMER -->

                                <td
                                    class="px-4 py-3 text-white"
                                    data-export="<?= e($t['customer_name']) ?>">

                                    <?= e($t['customer_name']) ?>

                                </td>

                                <!-- PAYMENT MODE -->

                                <td
                                    class="px-4 py-3 text-slate-400 uppercase text-[10px]"
                                    data-export="<?= e($t['payment_mode']) ?>">

                                    <?= $t['payment_mode'] !== ''
                                        ? e($t['payment_mode'])
                                        : '—'
                                    ?>

                                </td>

                                <!-- REVENUE -->

                                <td
                                    class="px-4 py-3 text-right text-white font-medium"
                                    data-export="<?= number_format($revenue, 2, '.', '') ?>">

                                    <?= money($revenue) ?>

                                </td>

                                <!-- COST -->

                                <td
                                    class="px-4 py-3 text-right text-slate-400"
                                    data-export="<?= number_format($cost, 2, '.', '') ?>">

                                    <?= money($cost) ?>

                                </td>

                                <!-- PROFIT -->

                                <td
                                    class="px-4 py-3 text-right font-semibold <?= $profit >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>"
                                    data-export="<?= number_format($profit, 2, '.', '') ?>">

                                    <?= money($profit) ?>

                                </td>

                                <!-- PAID -->

                                <td
                                    class="px-4 py-3 text-right text-emerald-300"
                                    data-export="<?= number_format($paid, 2, '.', '') ?>">

                                    <?= money($paid) ?>

                                </td>

                                <!-- DUE -->

                                <td
                                    class="px-4 py-3 text-right <?= $due > 0 ? 'text-rose-400' : 'text-slate-500' ?>"
                                    data-export="<?= number_format($due, 2, '.', '') ?>">

                                    <?= money($due) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="10"
                                class="px-4 py-10 text-center text-slate-500">

                                <i class="fas fa-folder-open text-2xl mb-2 block"></i>

                                No transactions found for the selected date range.

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- =========================================================
     PAGINATION
========================================================= -->

<div class="mt-5 flex flex-col sm:flex-row items-center justify-between gap-3 no-print">

    <div class="text-xs text-slate-400">

        <?php if ($total_records > 0): ?>

            Showing

            <span class="text-white font-semibold">
                <?= number_format($offset + 1) ?>
            </span>

            to

            <span class="text-white font-semibold">
                <?= number_format(min($offset + $per_page, $total_records)) ?>
            </span>

            of

            <span class="text-white font-semibold">
                <?= number_format($total_records) ?>
            </span>

            transactions

        <?php else: ?>

            No records to display.

        <?php endif; ?>

    </div>

    <?php if ($total_pages > 1): ?>

        <div class="flex flex-wrap items-center justify-center gap-1">

            <!-- FIRST -->

            <a
                href="<?= e(reportPageUrl(1, $from_date, $to_date, $per_page)) ?>"
                class="pagination-link <?= $current_page <= 1 ? 'disabled' : '' ?>"
                title="First Page">

                <i class="fas fa-angle-double-left"></i>

            </a>

            <!-- PREVIOUS -->

            <a
                href="<?= e(reportPageUrl(max(1, $current_page - 1), $from_date, $to_date, $per_page)) ?>"
                class="pagination-link <?= $current_page <= 1 ? 'disabled' : '' ?>">

                <i class="fas fa-angle-left"></i>

            </a>

            <!-- PAGE NUMBERS -->

            <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
            ?>

            <?php if ($start_page > 1): ?>

                <a
                    href="<?= e(reportPageUrl(1, $from_date, $to_date, $per_page)) ?>"
                    class="pagination-link">

                    1

                </a>

                <?php if ($start_page > 2): ?>

                    <span class="text-slate-500 px-1">
                        ...
                    </span>

                <?php endif; ?>

            <?php endif; ?>

            <?php for ($p = $start_page; $p <= $end_page; $p++): ?>

                <a
                    href="<?= e(reportPageUrl($p, $from_date, $to_date, $per_page)) ?>"
                    class="pagination-link <?= $current_page === $p ? 'active' : '' ?>">

                    <?= $p ?>

                </a>

            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>

                <?php if ($end_page < $total_pages - 1): ?>

                    <span class="text-slate-500 px-1">
                        ...
                    </span>

                <?php endif; ?>

                <a
                    href="<?= e(reportPageUrl($total_pages, $from_date, $to_date, $per_page)) ?>"
                    class="pagination-link">

                    <?= $total_pages ?>

                </a>

            <?php endif; ?>

            <!-- NEXT -->

            <a
                href="<?= e(reportPageUrl(min($total_pages, $current_page + 1), $from_date, $to_date, $per_page)) ?>"
                class="pagination-link <?= $current_page >= $total_pages ? 'disabled' : '' ?>">

                <i class="fas fa-angle-right"></i>

            </a>

            <!-- LAST -->

            <a
                href="<?= e(reportPageUrl($total_pages, $from_date, $to_date, $per_page)) ?>"
                class="pagination-link <?= $current_page >= $total_pages ? 'disabled' : '' ?>"
                title="Last Page">

                <i class="fas fa-angle-double-right"></i>

            </a>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>

<!-- =========================================================
     CSV EXPORT
========================================================= -->

<script>
function exportCSV() {

    const table = document.getElementById('reportTable');

    if (!table) {
        alert('No report data available to export.');
        return;
    }

    const rows = table.querySelectorAll('tr');
    const csvRows = [];

    rows.forEach(row => {

        const cells = row.querySelectorAll('th, td');

        if (!cells.length) {
            return;
        }

        const rowData = [];

        cells.forEach(cell => {

            let value = cell.dataset.export;

            if (value === undefined) {
                value = cell.innerText.trim();
            }

            // Escape double quotes for valid CSV format.
            value = String(value).replace(/"/g, '""');

            rowData.push('"' + value + '"');

        });

        csvRows.push(rowData.join(','));

    });

    const csvContent = '\uFEFF' + csvRows.join('\r\n');

    const blob = new Blob(
        [csvContent],
        { type: 'text/csv;charset=utf-8;' }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');

    link.href = url;

    link.download =
        'sales_service_report_<?= e($from_date) ?>_to_<?= e($to_date) ?>_page_<?= (int)$current_page ?>.csv';

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
