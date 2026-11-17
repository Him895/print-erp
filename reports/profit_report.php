
<?php
// ============================================================
// FILE: reports/profit_report.php
// PROFIT & LOSS REPORT
// Sales + Direct Services + Cost of Goods Sold
// GST Excluded Revenue + Monthly Breakdown
// ============================================================

require_once '../config/config.php';
require_permission('reports.profit');
$page_title = 'Profit & Loss Report';

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
// REPORT VARIABLES
// ------------------------------------------------------------

$salesRevenue   = 0;
$serviceRevenue = 0;
$totalRevenue   = 0;
$totalCost      = 0;
$netProfit      = 0;
$profitMargin   = 0;

$totalSales     = 0;
$totalServices  = 0;

$monthly = [];

// ------------------------------------------------------------
// FETCH SUMMARY
// ------------------------------------------------------------

if ($date_error === '') {

    // --------------------------------------------------------
    // 1. SALES REVENUE + COST OF GOODS SOLD
    //
    // Revenue excludes GST.
    // Cost = sold quantity * product purchase price.
    // Cancelled sales are excluded.
    // --------------------------------------------------------

    $salesSql = "
        SELECT
            COUNT(*) AS total_sales,

            COALESCE(
                SUM(
                    COALESCE(s.grand_total, 0)
                    - COALESCE(s.gst_amount, 0)
                ),
                0
            ) AS sales_revenue,

            COALESCE(
                SUM(
                    (
                        SELECT COALESCE(
                            SUM(
                                COALESCE(si.qty, 0)
                                * COALESCE(p.purchase_price, 0)
                            ),
                            0
                        )
                        FROM sale_items si
                        INNER JOIN products p
                            ON p.id = si.product_id
                        WHERE si.sale_id = s.id
                    )
                ),
                0
            ) AS sales_cost

        FROM sales s

        WHERE s.sale_date >= ?
          AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
          AND s.status <> 'cancelled'
    ";

    $salesStmt = $conn->prepare($salesSql);

    if ($salesStmt) {

        $salesStmt->bind_param('ss', $from_date, $to_date);

        if ($salesStmt->execute()) {

            $salesData = $salesStmt->get_result()->fetch_assoc();

            if ($salesData) {
                $totalSales   = (int) $salesData['total_sales'];
                $salesRevenue = (float) $salesData['sales_revenue'];
                $totalCost    = (float) $salesData['sales_cost'];
            }

        } else {
            $date_error = 'Unable to calculate sales report.';
        }

        $salesStmt->close();

    } else {
        $date_error = 'Unable to prepare sales report.';
    }

    // --------------------------------------------------------
    // 2. DIRECT SERVICE REVENUE
    //
    // Only direct services are included.
    // Sale-linked services are excluded to avoid double counting.
    // Service revenue excludes GST.
    // --------------------------------------------------------

    if ($date_error === '') {

        $serviceSql = "
            SELECT
                COUNT(*) AS total_services,

                COALESCE(
                    SUM(
                        COALESCE(
                            sv.sub_total,
                            COALESCE(sv.total_amount, 0)
                            - COALESCE(sv.gst_amount, 0)
                        )
                    ),
                    0
                ) AS service_revenue

            FROM services sv

            WHERE sv.service_date >= ?
              AND sv.service_date < DATE_ADD(?, INTERVAL 1 DAY)
              AND sv.sale_id IS NULL
        ";

        $serviceStmt = $conn->prepare($serviceSql);

        if ($serviceStmt) {

            $serviceStmt->bind_param('ss', $from_date, $to_date);

            if ($serviceStmt->execute()) {

                $serviceData = $serviceStmt->get_result()->fetch_assoc();

                if ($serviceData) {
                    $totalServices  = (int) $serviceData['total_services'];
                    $serviceRevenue = (float) $serviceData['service_revenue'];
                }

            } else {
                $date_error = 'Unable to calculate service revenue.';
            }

            $serviceStmt->close();

        } else {
            $date_error = 'Unable to prepare service report.';
        }
    }

    // --------------------------------------------------------
    // 3. FINAL PROFIT CALCULATION
    // --------------------------------------------------------

    $totalRevenue = $salesRevenue + $serviceRevenue;

    $netProfit = $totalRevenue - $totalCost;

    $profitMargin = $totalRevenue > 0
        ? ($netProfit / $totalRevenue) * 100
        : 0;

    // --------------------------------------------------------
    // 4. MONTHLY BREAKDOWN
    //
    // Sales + Direct Services + Sold Product Cost
    // --------------------------------------------------------

    if ($date_error === '') {

        $monthlySql = "

            SELECT
                report_data.month_key,

                DATE_FORMAT(
                    STR_TO_DATE(report_data.month_key, '%Y-%m'),
                    '%b %Y'
                ) AS month,

                SUM(report_data.sales_revenue) AS sales_revenue,

                SUM(report_data.service_revenue) AS service_revenue,

                SUM(report_data.sales_cost) AS sales_cost

            FROM (

                -- SALES DATA

                SELECT

                    DATE_FORMAT(s.sale_date, '%Y-%m') AS month_key,

                    (
                        COALESCE(s.grand_total, 0)
                        - COALESCE(s.gst_amount, 0)
                    ) AS sales_revenue,

                    0 AS service_revenue,

                    (
                        SELECT COALESCE(
                            SUM(
                                COALESCE(si.qty, 0)
                                * COALESCE(p.purchase_price, 0)
                            ),
                            0
                        )

                        FROM sale_items si

                        INNER JOIN products p
                            ON p.id = si.product_id

                        WHERE si.sale_id = s.id

                    ) AS sales_cost

                FROM sales s

                WHERE s.sale_date >= ?
                  AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
                  AND s.status <> 'cancelled'


                UNION ALL


                -- DIRECT SERVICE DATA

                SELECT

                    DATE_FORMAT(sv.service_date, '%Y-%m') AS month_key,

                    0 AS sales_revenue,

                    COALESCE(
                        sv.sub_total,
                        COALESCE(sv.total_amount, 0)
                        - COALESCE(sv.gst_amount, 0)
                    ) AS service_revenue,

                    0 AS sales_cost

                FROM services sv

                WHERE sv.service_date >= ?
                  AND sv.service_date < DATE_ADD(?, INTERVAL 1 DAY)
                  AND sv.sale_id IS NULL

            ) AS report_data

            GROUP BY report_data.month_key

            ORDER BY report_data.month_key ASC
        ";

        $mStmt = $conn->prepare($monthlySql);

        if ($mStmt) {

            $mStmt->bind_param(
                'ssss',
                $from_date,
                $to_date,
                $from_date,
                $to_date
            );

            if ($mStmt->execute()) {

                $monthly = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            } else {
                $date_error = 'Unable to fetch monthly breakdown.';
            }

            $mStmt->close();

        } else {
            $date_error = 'Unable to prepare monthly breakdown.';
        }
    }
}

// ------------------------------------------------------------
// MONTHLY CALCULATIONS FOR DISPLAY
// ------------------------------------------------------------

$monthlyTotalRevenue = 0;
$monthlyTotalCost    = 0;
$monthlyTotalProfit  = 0;

foreach ($monthly as &$m) {

    $m['sales_revenue']   = (float) $m['sales_revenue'];
    $m['service_revenue'] = (float) $m['service_revenue'];
    $m['sales_cost']      = (float) $m['sales_cost'];

    $m['total_revenue'] =
        $m['sales_revenue'] + $m['service_revenue'];

    $m['profit'] =
        $m['total_revenue'] - $m['sales_cost'];

    $monthlyTotalRevenue += $m['total_revenue'];
    $monthlyTotalCost    += $m['sales_cost'];
    $monthlyTotalProfit  += $m['profit'];
}

unset($m);

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

        .print-area .text-slate-400,
        .print-area .text-slate-500 {
            color: #555 !important;
        }

        .print-area .text-slate-300 {
            color: #333 !important;
        }

        .print-area .text-emerald-300 {
            color: #047857 !important;
        }

        .print-area .text-rose-300,
        .print-area .text-rose-400 {
            color: #b91c1c !important;
        }

        .print-area .text-indigo-300 {
            color: #4338ca !important;
        }

        .print-area .text-amber-300 {
            color: #b45309 !important;
        }

        .print-area .bg-white\/5 {
            background: #f3f4f6 !important;
        }

        .print-area .border-white\/10,
        .print-area .border-white\/5 {
            border-color: #ddd !important;
        }

        .print-area .glass-dark,
        .print-area .report-card {
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
            padding: 9px !important;
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
            Profit & Loss Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Sales, direct service revenue and product cost analysis
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
            Export CSV
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
        href="profit_report.php"
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
            Profit & Loss Report
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

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-5">

        <!-- SALES REVENUE -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Sales Revenue
                </div>

                <i class="fas fa-receipt text-indigo-300"></i>

            </div>

            <div class="text-lg font-bold text-white mt-2">
                <?= money($salesRevenue) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                <?= number_format($totalSales) ?> sales
            </div>

        </div>

        <!-- SERVICE REVENUE -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Service Revenue
                </div>

                <i class="fas fa-print text-emerald-300"></i>

            </div>

            <div class="text-lg font-bold text-emerald-300 mt-2">
                <?= money($serviceRevenue) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                <?= number_format($totalServices) ?> direct services
            </div>

        </div>

        <!-- TOTAL COST -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Product Cost
                </div>

                <i class="fas fa-boxes-stacked text-rose-300"></i>

            </div>

            <div class="text-lg font-bold text-rose-300 mt-2">
                <?= money($totalCost) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Cost of sold products
            </div>

        </div>

        <!-- ESTIMATED PROFIT -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Estimated Profit
                </div>

                <i class="fas fa-chart-line text-indigo-300"></i>

            </div>

            <div class="text-lg font-bold <?= $netProfit >= 0 ? 'text-indigo-300' : 'text-rose-400' ?> mt-2">
                <?= money($netProfit) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Revenue - tracked cost
            </div>

        </div>

        <!-- PROFIT MARGIN -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] text-slate-400 uppercase font-semibold tracking-wider">
                    Profit Margin
                </div>

                <i class="fas fa-percent text-amber-300"></i>

            </div>

            <div class="text-lg font-bold text-amber-300 mt-2">
                <?= number_format($profitMargin, 1) ?>%
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Estimated margin
            </div>

        </div>

    </div>

    <!-- ====================================================
         REVENUE SUMMARY
    ===================================================== -->

    <div class="glass-dark rounded-2xl p-4 mb-5 border border-white/10">

        <h3 class="text-sm font-bold text-white mb-4">
            Financial Summary
        </h3>

        <div class="space-y-3">

            <div class="flex items-center justify-between text-xs">

                <span class="text-slate-400">
                    Sales Revenue (Excl. GST)
                </span>

                <span class="text-white font-semibold">
                    <?= money($salesRevenue) ?>
                </span>

            </div>

            <div class="flex items-center justify-between text-xs">

                <span class="text-slate-400">
                    Direct Service Revenue (Excl. GST)
                </span>

                <span class="text-emerald-300 font-semibold">
                    <?= money($serviceRevenue) ?>
                </span>

            </div>

            <div class="border-t border-white/10 pt-3 flex items-center justify-between text-xs">

                <span class="text-white font-bold">
                    Total Revenue
                </span>

                <span class="text-emerald-300 font-bold">
                    <?= money($totalRevenue) ?>
                </span>

            </div>

            <div class="flex items-center justify-between text-xs">

                <span class="text-slate-400">
                    Cost of Goods Sold
                </span>

                <span class="text-rose-300 font-semibold">
                    - <?= money($totalCost) ?>
                </span>

            </div>

            <div class="border-t border-white/10 pt-3 flex items-center justify-between">

                <span class="text-sm text-white font-bold">
                    Estimated Gross Profit
                </span>

                <span class="text-lg font-bold <?= $netProfit >= 0 ? 'text-indigo-300' : 'text-rose-400' ?>">
                    <?= money($netProfit) ?>
                </span>

            </div>

        </div>

    </div>

    <!-- ====================================================
         MONTHLY BREAKDOWN
    ===================================================== -->

    <div class="glass-dark rounded-2xl overflow-hidden">

        <div class="p-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-2">

            <div>

                <h3 class="text-sm font-bold text-white">
                    Monthly Profit & Loss Breakdown
                </h3>

                <p class="text-[10px] text-slate-400 mt-1">
                    Sales and direct services with product cost
                </p>

            </div>

            <div class="text-xs text-slate-400">
                <?= count($monthly) ?> months
            </div>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-xs report-table" id="reportTable">

                <thead>

                    <tr class="text-left border-b border-white/10 bg-white/5">

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Month
                        </th>

                        <th class="px-4 py-3 text-right text-slate-400 font-semibold">
                            Sales Revenue
                        </th>

                        <th class="px-4 py-3 text-right text-slate-400 font-semibold">
                            Service Revenue
                        </th>

                        <th class="px-4 py-3 text-right text-slate-400 font-semibold">
                            Total Revenue
                        </th>

                        <th class="px-4 py-3 text-right text-slate-400 font-semibold">
                            Product Cost
                        </th>

                        <th class="px-4 py-3 text-right text-slate-400 font-semibold">
                            Est. Profit
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($monthly)): ?>

                        <?php foreach ($monthly as $m): ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-white font-semibold">
                                    <?= e($m['month']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-slate-300">
                                    <?= money($m['sales_revenue']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300">
                                    <?= money($m['service_revenue']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-white font-semibold">
                                    <?= money($m['total_revenue']) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-rose-300">
                                    <?= money($m['sales_cost']) ?>
                                </td>

                                <td class="px-4 py-3 text-right font-bold <?= $m['profit'] >= 0 ? 'text-indigo-300' : 'text-rose-400' ?>">
                                    <?= money($m['profit']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <!-- TOTAL ROW -->

                        <tr class="bg-white/5 border-t border-white/10">

                            <td class="px-4 py-3 text-white font-bold">
                                TOTAL
                            </td>

                            <td class="px-4 py-3 text-right text-slate-300 font-bold">
                                <?= money($salesRevenue) ?>
                            </td>

                            <td class="px-4 py-3 text-right text-emerald-300 font-bold">
                                <?= money($serviceRevenue) ?>
                            </td>

                            <td class="px-4 py-3 text-right text-white font-bold">
                                <?= money($monthlyTotalRevenue) ?>
                            </td>

                            <td class="px-4 py-3 text-right text-rose-300 font-bold">
                                <?= money($monthlyTotalCost) ?>
                            </td>

                            <td class="px-4 py-3 text-right font-bold <?= $monthlyTotalProfit >= 0 ? 'text-indigo-300' : 'text-rose-400' ?>">
                                <?= money($monthlyTotalProfit) ?>
                            </td>

                        </tr>

                    <?php else: ?>

                        <tr>

                            <td colspan="6" class="px-4 py-12 text-center">

                                <div class="flex flex-col items-center">

                                    <div class="w-12 h-12 rounded-full bg-white/5 flex items-center justify-center mb-3">
                                        <i class="fas fa-chart-column text-xl text-slate-500"></i>
                                    </div>

                                    <div class="text-sm font-semibold text-slate-300">
                                        No Financial Data Found
                                    </div>

                                    <p class="text-xs text-slate-500 mt-1">
                                        No sales or direct services found for this date range.
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- ====================================================
         ACCOUNTING NOTE
    ===================================================== -->

    <div class="mt-4 p-3 rounded-xl bg-amber-500/5 border border-amber-500/15">

        <p class="text-[10px] text-amber-300 font-semibold mb-1">
            <i class="fas fa-circle-info mr-1"></i>
            Accounting Note
        </p>

        <p class="text-[10px] text-slate-400 leading-relaxed">
            This report calculates estimated gross profit using sales revenue excluding GST
            and the purchase price of sold products. Direct service revenue is included,
            but service material costs, labour costs, rent, salaries and other operating
            expenses are not included because they are not tracked in this calculation.
            This report should not be treated as final accounting net profit.
        </p>

    </div>

    <!-- PRINT FOOTER -->

    <div class="hidden print:block mt-5 text-xs text-slate-400 text-right">
        Generated on <?= date('d-m-Y h:i A') ?>
    </div>

</div>

<!-- ========================================================
     CSV EXPORT
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
        if (cells.length !== 6) {
            return;
        }

        const rowData = [];

        cells.forEach((cell, index) => {

            let value = cell.innerText.trim();

            // Remove currency symbol and thousands separators
            // from numeric columns.
            if (index > 0) {
                value = value.replace(/[₹,]/g, '').trim();
            }

            // CSV escaping
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No monthly data available to export.');
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
    a.download = 'profit_loss_report_<?= date('Ymd_His') ?>.csv';

    document.body.appendChild(a);
    a.click();

    document.body.removeChild(a);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
