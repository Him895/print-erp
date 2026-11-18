
<?php
// ============================================================
// FILE: reports/client_report.php
// CUSTOMER REPORT
// Customer Search + Business + Outstanding + Pagination
// Sales + Direct Services (No Double Counting)
// Full Filtered Summary + CSV Export + Print/PDF
// ============================================================

require_once '../config/config.php';
require_permission('reports.client');
$page_title = 'Customer Report';

// ------------------------------------------------------------
// 1. FILTERS
// ------------------------------------------------------------

$search = trim($_GET['search'] ?? '');

$perPage = 25;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

// ------------------------------------------------------------
// 2. COMMON CUSTOMER FILTER
// ------------------------------------------------------------

$where = "
    WHERE c.is_active = 1
    AND (
        ? = ''
        OR c.customer_name LIKE ?
        OR c.phone LIKE ?
    )
";

$searchPattern = '%' . $search . '%';

$params = [
    $search,
    $searchPattern,
    $searchPattern
];

$types = 'sss';

// ------------------------------------------------------------
// 3. CUSTOMER BUSINESS AGGREGATES
//
// Sales:
// - Exclude cancelled sales
//
// Services:
// - Include only direct services (sale_id IS NULL)
// - Linked services are not counted again
// ------------------------------------------------------------

$aggregateJoins = "

    LEFT JOIN (
        SELECT
            client_id,
            COUNT(*) AS sales_count,
            COALESCE(SUM(grand_total), 0) AS sales_business,
            COALESCE(SUM(paid_amount), 0) AS sales_paid,
            COALESCE(SUM(due_amount), 0) AS sales_due

        FROM sales

        WHERE LOWER(COALESCE(status, '')) <> 'cancelled'

        GROUP BY client_id

    ) s ON s.client_id = c.id

    LEFT JOIN (
        SELECT
            client_id,
            COUNT(*) AS service_count,
            COALESCE(SUM(total_amount), 0) AS service_business,
            COALESCE(SUM(paid_amount), 0) AS service_paid,
            COALESCE(SUM(due_amount), 0) AS service_due

        FROM services

        WHERE sale_id IS NULL

        GROUP BY client_id

    ) sv ON sv.client_id = c.id

";

// ------------------------------------------------------------
// 4. SUMMARY - ALL FILTERED CUSTOMERS
// ------------------------------------------------------------

$summarySql = "
    SELECT
        COUNT(*) AS total_clients,

        COALESCE(SUM(
            COALESCE(s.sales_business, 0)
            + COALESCE(sv.service_business, 0)
        ), 0) AS total_business,

        COALESCE(SUM(
            COALESCE(s.sales_paid, 0)
            + COALESCE(sv.service_paid, 0)
        ), 0) AS total_paid,

        COALESCE(SUM(
            COALESCE(s.sales_due, 0)
            + COALESCE(sv.service_due, 0)
        ), 0) AS total_due,

        SUM(
            CASE
                WHEN (
                    COALESCE(s.sales_due, 0)
                    + COALESCE(sv.service_due, 0)
                ) > 0
                THEN 1 ELSE 0
            END
        ) AS due_clients,

        SUM(
            CASE
                WHEN (
                    COALESCE(s.sales_business, 0)
                    + COALESCE(sv.service_business, 0)
                ) > 0
                THEN 1 ELSE 0
            END
        ) AS business_clients,

        COALESCE(SUM(COALESCE(s.sales_count, 0)), 0) AS total_sales,

        COALESCE(SUM(COALESCE(sv.service_count, 0)), 0) AS total_services

    FROM customers c

    $aggregateJoins

    $where
";

$summaryStmt = $conn->prepare($summarySql);

if (!$summaryStmt) {
    die('Unable to prepare customer summary query.');
}

$summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();

$summary = $summaryStmt->get_result()->fetch_assoc();

$summaryStmt->close();

// Summary values
$totalClients = (int)($summary['total_clients'] ?? 0);

$totalBusiness = (float)($summary['total_business'] ?? 0);
$totalPaid = (float)($summary['total_paid'] ?? 0);
$totalDue = (float)($summary['total_due'] ?? 0);

$dueClients = (int)($summary['due_clients'] ?? 0);
$businessClients = (int)($summary['business_clients'] ?? 0);

$totalSales = (int)($summary['total_sales'] ?? 0);
$totalServices = (int)($summary['total_services'] ?? 0);

// ------------------------------------------------------------
// 5. PAGINATION
// ------------------------------------------------------------

$totalPages = max(1, (int)ceil($totalClients / $perPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

// ------------------------------------------------------------
// 6. FETCH CUSTOMERS - CURRENT PAGE
// ------------------------------------------------------------

$sql = "
    SELECT
        c.id,
        c.customer_name,
        c.phone,

        COALESCE(s.sales_count, 0) AS sales_count,
        COALESCE(s.sales_business, 0) AS sales_business,
        COALESCE(s.sales_paid, 0) AS sales_paid,
        COALESCE(s.sales_due, 0) AS sales_due,

        COALESCE(sv.service_count, 0) AS service_count,
        COALESCE(sv.service_business, 0) AS service_business,
        COALESCE(sv.service_paid, 0) AS service_paid,
        COALESCE(sv.service_due, 0) AS service_due,

        (
            COALESCE(s.sales_business, 0)
            + COALESCE(sv.service_business, 0)
        ) AS total_business,

        (
            COALESCE(s.sales_paid, 0)
            + COALESCE(sv.service_paid, 0)
        ) AS total_paid,

        (
            COALESCE(s.sales_due, 0)
            + COALESCE(sv.service_due, 0)
        ) AS total_due,

        (
            COALESCE(s.sales_count, 0)
            + COALESCE(sv.service_count, 0)
        ) AS total_jobs

    FROM customers c

    $aggregateJoins

    $where

    ORDER BY
        total_due DESC,
        total_business DESC,
        c.customer_name ASC

    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to prepare customer report query.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// ------------------------------------------------------------
// 7. PAGINATION URL
// ------------------------------------------------------------

$queryParams = [
    'search' => $search
];

$buildPageUrl = function ($page) use ($queryParams) {
    $query = $queryParams;
    $query['page'] = $page;

    return '?' . http_build_query($query);
};

// ------------------------------------------------------------
// 8. DISPLAY HELPERS
// ------------------------------------------------------------

function getClientReportBusiness($client)
{
    return (float)($client['total_business'] ?? 0);
}

function getClientReportDue($client)
{
    return (float)($client['total_due'] ?? 0);
}

include '../includes/header.php';
?>

<style>
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
            color: #111827 !important;
            padding: 10px;
        }

        .print-area .text-white {
            color: #111827 !important;
        }

        .print-area .text-slate-300,
        .print-area .text-slate-400,
        .print-area .text-slate-500 {
            color: #374151 !important;
        }

        .print-area .text-emerald-300,
        .print-area .text-emerald-400 {
            color: #047857 !important;
        }

        .print-area .text-indigo-300 {
            color: #4338ca !important;
        }

        .print-area .text-rose-300,
        .print-area .text-rose-400 {
            color: #b91c1c !important;
        }

        .print-area .text-amber-300 {
            color: #92400e !important;
        }

        .print-area .bg-white\/5 {
            background: #f3f4f6 !important;
        }

        .print-area .border-white\/10,
        .print-area .border-white\/5 {
            border-color: #d1d5db !important;
        }

        .print-area .glass-dark {
            background: white !important;
            border: 1px solid #d1d5db !important;
            box-shadow: none !important;
        }

        .print-area table {
            width: 100%;
            border-collapse: collapse;
        }

        .print-area th,
        .print-area td {
            border-bottom: 1px solid #d1d5db !important;
            color: #111827 !important;
        }

        .no-print {
            display: none !important;
        }

        .print-area .print-heading {
            display: block !important;
        }
    }
</style>

<!-- ========================================================
     PAGE HEADER
========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Customer Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Customer business, payments and outstanding analysis
        </p>
    </div>

    <div class="flex gap-2">

        <button
            type="button"
            onclick="window.print()"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition"
        >
            <i class="fas fa-print mr-1"></i>
            Print / PDF
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
     SEARCH FILTER
========================================================= -->

<form method="GET" class="glass-dark rounded-2xl p-4 mb-5 no-print">

    <div class="flex flex-col sm:flex-row gap-3 items-end">

        <div class="flex-1 w-full">

            <label class="text-[10px] text-slate-400 block mb-1">
                Search Customer
            </label>

            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Enter customer name or phone number"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <div class="flex gap-2 w-full sm:w-auto">

            <button
                type="submit"
                class="flex-1 sm:flex-none px-5 py-2 rounded-lg bg-indigo-500 text-white text-xs font-semibold hover:bg-indigo-600 transition"
            >
                <i class="fas fa-search mr-1"></i>
                Search
            </button>

            <a
                href="client_report.php"
                class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
                title="Reset Search"
            >
                <i class="fas fa-rotate-left"></i>
            </a>

        </div>

    </div>

</form>

<!-- ========================================================
     PRINTABLE AREA
========================================================= -->

<div class="print-area">

    <!-- PRINT HEADER -->

    <div class="hidden print-heading mb-6 text-center">

        <h2 class="text-2xl font-bold">
            PrintERP
        </h2>

        <p class="text-sm mt-1">
            Customer Business & Outstanding Report
        </p>

        <p class="text-xs mt-1">
            As on <?= date('d M Y, h:i A') ?>
        </p>

        <?php if ($search !== ''): ?>

            <p class="text-xs mt-1">
                Search: <?= e($search) ?>
            </p>

        <?php endif; ?>

        <hr class="my-3">

    </div>

    <!-- ====================================================
         SUMMARY CARDS
    ===================================================== -->

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Active Customers
            </div>

            <div class="text-2xl font-bold text-white mt-1">
                <?= number_format($totalClients) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Matching customers
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Business
            </div>

            <div class="text-2xl font-bold text-emerald-300 mt-1">
                <?= money($totalBusiness) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Sales + Direct Services
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Received
            </div>

            <div class="text-2xl font-bold text-indigo-300 mt-1">
                <?= money($totalPaid) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Recorded payments
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Outstanding
            </div>

            <div class="text-2xl font-bold text-rose-300 mt-1">
                <?= money($totalDue) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Remaining customer dues
            </div>

        </div>

    </div>

    <!-- ====================================================
         SECONDARY SUMMARY
    ===================================================== -->

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Customers With Dues
            </div>

            <div class="text-xl font-bold text-rose-300 mt-1">
                <?= number_format($dueClients) ?>
            </div>

        </div>

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Business Customers
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-1">
                <?= number_format($businessClients) ?>
            </div>

        </div>

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Total Sales
            </div>

            <div class="text-xl font-bold text-white mt-1">
                <?= number_format($totalSales) ?>
            </div>

        </div>

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Direct Services
            </div>

            <div class="text-xl font-bold text-white mt-1">
                <?= number_format($totalServices) ?>
            </div>

        </div>

    </div>

    <!-- ====================================================
         TABLE HEADING
    ===================================================== -->

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">

        <div>

            <h2 class="text-sm font-bold text-white">
                Customer Business Details
            </h2>

            <p class="text-[10px] text-slate-400 mt-1">
                Showing
                <?= $totalClients > 0 ? number_format($offset + 1) : 0 ?>
                -
                <?= number_format(min($offset + $perPage, $totalClients)) ?>
                of <?= number_format($totalClients) ?> customers
            </p>

        </div>

        <div class="text-[10px] text-slate-400">
            Highest outstanding first
        </div>

    </div>

    <!-- ====================================================
         CUSTOMER TABLE
    ===================================================== -->

    <div class="glass-dark rounded-2xl overflow-hidden border border-white/10">

        <div class="overflow-x-auto">

            <table class="w-full text-xs" id="reportTable">

                <thead>

                    <tr class="text-left border-b border-white/10 bg-white/5">

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            #
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Customer Name
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Phone
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-center whitespace-nowrap">
                            Sales
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-center whitespace-nowrap">
                            Services
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total Business
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total Paid
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total Due
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($clients)): ?>

                        <?php foreach ($clients as $index => $c): ?>

                            <?php
                                $clientBusiness = getClientReportBusiness($c);
                                $clientDue = getClientReportDue($c);

                                $clientPaid = (float)($c['total_paid'] ?? 0);

                                $salesCount = (int)($c['sales_count'] ?? 0);
                                $serviceCount = (int)($c['service_count'] ?? 0);

                                $phone = !empty($c['phone'])
                                    ? $c['phone']
                                    : '-';
                            ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-slate-500">
                                    <?= $offset + $index + 1 ?>
                                </td>

                                <td class="px-4 py-3 text-white font-semibold whitespace-nowrap">
                                    <?= e($c['customer_name']) ?>
                                </td>

                                <td class="px-4 py-3 text-slate-300 whitespace-nowrap">
                                    <?= e($phone) ?>
                                </td>

                                <td class="px-4 py-3 text-center text-slate-300">
                                    <?= number_format($salesCount) ?>
                                </td>

                                <td class="px-4 py-3 text-center text-slate-300">
                                    <?= number_format($serviceCount) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300 font-semibold whitespace-nowrap">
                                    <?= money($clientBusiness) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-indigo-300 whitespace-nowrap">
                                    <?= money($clientPaid) ?>
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap <?= $clientDue > 0
                                    ? 'text-rose-400 font-bold'
                                    : 'text-slate-500' ?>">
                                    <?= money($clientDue) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="8" class="px-4 py-10 text-center">

                                <div class="text-slate-500">

                                    <i class="fas fa-users text-3xl mb-3"></i>

                                    <p class="text-sm font-semibold">
                                        No Customers Found
                                    </p>

                                    <p class="text-xs mt-1">
                                        Try another customer name or phone number.
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
         PAGINATION
    ===================================================== -->

    <?php if ($totalPages > 1): ?>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4 no-print">

            <p class="text-xs text-slate-400">
                Page <?= $currentPage ?> of <?= $totalPages ?>
            </p>

            <div class="flex items-center gap-1">

                <?php if ($currentPage > 1): ?>

                    <a
                        href="<?= e($buildPageUrl(1)) ?>"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10"
                        title="First Page"
                    >
                        <i class="fas fa-angles-left"></i>
                    </a>

                    <a
                        href="<?= e($buildPageUrl($currentPage - 1)) ?>"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10"
                    >
                        <i class="fas fa-chevron-left mr-1"></i>
                        Previous
                    </a>

                <?php endif; ?>

                <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                    <a
                        href="<?= e($buildPageUrl($p)) ?>"
                        class="px-3 py-2 rounded-lg text-xs font-semibold transition <?= $p === $currentPage
                            ? 'bg-indigo-500 text-white'
                            : 'bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10' ?>"
                    >
                        <?= $p ?>
                    </a>

                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>

                    <a
                        href="<?= e($buildPageUrl($currentPage + 1)) ?>"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10"
                    >
                        Next
                        <i class="fas fa-chevron-right ml-1"></i>
                    </a>

                    <a
                        href="<?= e($buildPageUrl($totalPages)) ?>"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10"
                        title="Last Page"
                    >
                        <i class="fas fa-angles-right"></i>
                    </a>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

    <!-- ====================================================
         PRINT FOOTER
    ===================================================== -->

    <div class="hidden print:block mt-5 pt-3 border-t border-gray-300">

        <div class="flex justify-between text-xs text-gray-600">

            <span>
                PrintERP - Customer Report
            </span>

            <span>
                Generated on <?= date('d M Y, h:i A') ?>
            </span>

        </div>

    </div>

</div>

<!-- ========================================================
     CSV EXPORT
========================================================= -->

<script>
function exportCSV() {

    const table = document.getElementById('reportTable');

    const rows = table.querySelectorAll('tr');

    let csv = [];

    rows.forEach((row) => {

        const cols = row.querySelectorAll('th, td');

        // Ignore empty-state row
        if (cols.length !== 8) {
            return;
        }

        const rowData = [];

        cols.forEach((col, index) => {

            // Ignore serial number
            if (index === 0) {
                return;
            }

            let value = col.innerText
                .replace(/\s+/g, ' ')
                .trim();

            // Remove rupee symbol
            value = value.replace(/₹/g, '').trim();

            // CSV escaping
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No customer records available to export.');
        return;
    }

    // UTF-8 BOM for Excel
    const csvContent = '\uFEFF' + csv.join('\r\n');

    const blob = new Blob(
        [csvContent],
        { type: 'text/csv;charset=utf-8;' }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');

    link.href = url;

    link.download = 'customer_report_<?= date('Y-m-d') ?>_page_<?= $currentPage ?>.csv';

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
