
<?php
// ============================================================
// FILE: reports/service_report.php
// SERVICE REPORT
// Date Filter + Search + Status Filter + Pagination
// Service + Service Items Print Type
// Full Filtered Summary + CSV Export + Print/PDF
// ============================================================

require_once '../config/config.php';
require_permission('reports.service');
$page_title = 'Service Report';

// ------------------------------------------------------------
// 1. FILTERS & VALIDATION
// ------------------------------------------------------------

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

$from_date = $_GET['from'] ?? $defaultFrom;
$to_date   = $_GET['to'] ?? $defaultTo;

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$allowedStatuses = [
    'pending',
    'in_progress',
    'completed',
    'delivered'
];

if (!in_array($status_filter, $allowedStatuses, true)) {
    $status_filter = '';
}

// Validate dates
$fromValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)
    && checkdate(
        (int) substr($from_date, 5, 2),
        (int) substr($from_date, 8, 2),
        (int) substr($from_date, 0, 4)
    );

$toValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)
    && checkdate(
        (int) substr($to_date, 5, 2),
        (int) substr($to_date, 8, 2),
        (int) substr($to_date, 0, 4)
    );

if (!$fromValid) {
    $from_date = $defaultFrom;
}

if (!$toValid) {
    $to_date = $defaultTo;
}

if ($from_date > $to_date) {
    $temp = $from_date;
    $from_date = $to_date;
    $to_date = $temp;
}

// ------------------------------------------------------------
// 2. PAGINATION
// ------------------------------------------------------------

$perPage = 25;

$currentPage = max(1, (int) ($_GET['page'] ?? 1));

// ------------------------------------------------------------
// 3. COMMON WHERE CONDITION
// ------------------------------------------------------------

$where = "
    WHERE sv.service_date >= ?
    AND sv.service_date < DATE_ADD(?, INTERVAL 1 DAY)

    AND (
        ? = ''
        OR sv.service_no LIKE ?
        OR c.client_name LIKE ?
    )

    AND (
        ? = ''
        OR sv.status = ?
    )
";

$searchPattern = '%' . $search . '%';

$params = [
    $from_date,
    $to_date,
    $search,
    $searchPattern,
    $searchPattern,
    $status_filter,
    $status_filter
];

$types = 'sssssss';

// ------------------------------------------------------------
// 4. SUMMARY - ALL FILTERED RECORDS
// ------------------------------------------------------------

$summarySql = "
    SELECT
        COUNT(*) AS total_jobs,

        COALESCE(SUM(sv.total_amount), 0) AS total_amount,

        COALESCE(SUM(sv.paid_amount), 0) AS total_paid,

        COALESCE(SUM(sv.due_amount), 0) AS total_due,

        SUM(
            CASE
                WHEN sv.status = 'pending'
                THEN 1 ELSE 0
            END
        ) AS pending_jobs,

        SUM(
            CASE
                WHEN sv.status = 'in_progress'
                THEN 1 ELSE 0
            END
        ) AS in_progress_jobs,

        SUM(
            CASE
                WHEN sv.status IN ('completed', 'delivered')
                THEN 1 ELSE 0
            END
        ) AS completed_jobs

    FROM services sv

    LEFT JOIN clients c
        ON sv.client_id = c.id

    $where
";

$summaryStmt = $conn->prepare($summarySql);

if (!$summaryStmt) {
    die('Unable to prepare service summary query.');
}

$summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();

$summary = $summaryStmt->get_result()->fetch_assoc();

$summaryStmt->close();

$totalJobs = (int) ($summary['total_jobs'] ?? 0);

$totalAmount = (float) ($summary['total_amount'] ?? 0);
$totalPaid   = (float) ($summary['total_paid'] ?? 0);
$totalDue    = (float) ($summary['total_due'] ?? 0);

$pendingJobs = (int) ($summary['pending_jobs'] ?? 0);
$inProgressJobs = (int) ($summary['in_progress_jobs'] ?? 0);
$completedJobs = (int) ($summary['completed_jobs'] ?? 0);

$totalPages = max(1, (int) ceil($totalJobs / $perPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

// ------------------------------------------------------------
// 5. FETCH SERVICE RECORDS
//    service_items.print_type is aggregated per service.
//    One service will always appear as one report row.
// ------------------------------------------------------------

$sql = "
    SELECT
        sv.*,

        c.client_name,

        si.item_print_types

    FROM services sv

    LEFT JOIN clients c
        ON sv.client_id = c.id

    LEFT JOIN (
        SELECT
            service_id,

            GROUP_CONCAT(
                NULLIF(TRIM(print_type), '')
                ORDER BY id
                SEPARATOR ' | '
            ) AS item_print_types

        FROM service_items

        GROUP BY service_id

    ) si
        ON si.service_id = sv.id

    $where

    ORDER BY sv.service_date DESC, sv.id DESC

    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to prepare service report query.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// ------------------------------------------------------------
// 6. HELPER: GET PRINT TYPE
//    Priority:
//    1. service_items.print_type
//    2. services.print_type
//    3. Old notes
//    4. service_type
// ------------------------------------------------------------

function getServicePrintType($service)
{
    // 1. Item-wise print types
    if (!empty(trim($service['item_print_types'] ?? ''))) {
        return trim($service['item_print_types']);
    }

    // 2. Parent service print_type
    if (!empty(trim($service['print_type'] ?? ''))) {
        return trim($service['print_type']);
    }

    // 3. Old records: notes fallback
    $notes = trim($service['notes'] ?? '');

    if ($notes !== '') {

        if (preg_match('/Print Type:\s*(.+)/i', $notes, $matches)) {
            return trim($matches[1]);
        }
    }

    // 4. Service type fallback
    if (
        !empty($service['service_type'])
        && $service['service_type'] !== 'custom_print'
    ) {
        return ucwords(
            str_replace('_', ' ', $service['service_type'])
        );
    }

    return '-';
}

// ------------------------------------------------------------
// 7. HELPER: STATUS LABEL / CLASS
// ------------------------------------------------------------

function getServiceStatusClass($status)
{
    switch ($status) {

        case 'completed':
        case 'delivered':
            return 'bg-emerald-500/20 text-emerald-300';

        case 'in_progress':
            return 'bg-indigo-500/20 text-indigo-300';

        case 'pending':
            return 'bg-amber-500/20 text-amber-300';

        default:
            return 'bg-slate-500/20 text-slate-300';
    }
}

function getServiceStatusLabel($status)
{
    return ucwords(
        str_replace('_', ' ', $status ?: 'Unknown')
    );
}

// ------------------------------------------------------------
// 8. PAGINATION URL
// ------------------------------------------------------------

$queryParams = [
    'from' => $from_date,
    'to' => $to_date,
    'search' => $search,
    'status' => $status_filter
];

$buildPageUrl = function ($page) use ($queryParams) {

    $query = $queryParams;

    $query['page'] = $page;

    return '?' . http_build_query($query);
};

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

        .print-area .status-badge {
            background: #f3f4f6 !important;
            color: #111827 !important;
            border: 1px solid #d1d5db;
        }

        .no-print {
            display: none !important;
        }

        .print-area .print-heading {
            display: block !important;
        }
    }
</style>

<!-- PAGE HEADER -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Service Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Print jobs, service status and payment analysis
        </p>
    </div>

    <div class="flex gap-2">

        <button
            onclick="window.print()"
            type="button"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition"
        >
            <i class="fas fa-print mr-1"></i>
            Print / PDF
        </button>

        <button
            onclick="exportCSV()"
            type="button"
            class="px-4 py-2 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-semibold hover:bg-emerald-500/30 transition"
        >
            <i class="fas fa-file-excel mr-1"></i>
            Export CSV
        </button>

    </div>

</div>

<!-- FILTERS -->

<form method="GET" class="glass-dark rounded-2xl p-4 mb-5 no-print">

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                From Date
            </label>

            <input
                type="date"
                name="from"
                value="<?= e($from_date) ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
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
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
        </div>

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                Search Job / Client
            </label>

            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Job no. or client name"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
        </div>

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                Service Status
            </label>

            <select
                name="status"
                class="w-full bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
                <option value="">All Statuses</option>

                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>
                    Pending
                </option>

                <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>
                    In Progress
                </option>

                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>
                    Completed
                </option>

                <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>
                    Delivered
                </option>

            </select>
        </div>

        <div class="flex gap-2">

            <button
                type="submit"
                class="flex-1 px-4 py-2 rounded-lg bg-indigo-500 text-white text-xs font-semibold hover:bg-indigo-600 transition"
            >
                <i class="fas fa-filter mr-1"></i>
                Apply
            </button>

            <a
                href="service_report.php"
                class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
                title="Reset Filters"
            >
                <i class="fas fa-rotate-left"></i>
            </a>

        </div>

    </div>

</form>

<!-- PRINTABLE AREA -->

<div class="print-area">

    <!-- PRINT HEADER -->

    <div class="hidden print-heading mb-6 text-center">

        <h2 class="text-2xl font-bold">
            PrintERP
        </h2>

        <p class="text-sm mt-1">
            Service Report
        </p>

        <p class="text-xs mt-1">
            Period: <?= fmt_date($from_date) ?> to <?= fmt_date($to_date) ?>
        </p>

        <?php if ($search !== ''): ?>
            <p class="text-xs mt-1">
                Search: <?= e($search) ?>
            </p>
        <?php endif; ?>

        <?php if ($status_filter !== ''): ?>
            <p class="text-xs mt-1">
                Status: <?= e(getServiceStatusLabel($status_filter)) ?>
            </p>
        <?php endif; ?>

        <hr class="my-3">

    </div>

    <!-- SUMMARY CARDS -->

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Jobs
            </div>

            <div class="text-2xl font-bold text-white mt-1">
                <?= number_format($totalJobs) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                All filtered jobs
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Service Amount
            </div>

            <div class="text-2xl font-bold text-emerald-300 mt-1">
                <?= money($totalAmount) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Total job value
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
                Payments received
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
                Remaining customer due
            </div>

        </div>

    </div>

    <!-- STATUS SUMMARY -->

    <div class="grid grid-cols-3 gap-3 mb-5">

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Pending
            </div>

            <div class="text-xl font-bold text-amber-300 mt-1">
                <?= number_format($pendingJobs) ?>
            </div>

        </div>

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                In Progress
            </div>

            <div class="text-xl font-bold text-indigo-300 mt-1">
                <?= number_format($inProgressJobs) ?>
            </div>

        </div>

        <div class="glass-dark rounded-xl p-3 border border-white/10">

            <div class="text-[10px] text-slate-400 uppercase font-semibold">
                Completed / Delivered
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-1">
                <?= number_format($completedJobs) ?>
            </div>

        </div>

    </div>

    <!-- TABLE HEADING -->

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">

        <div>
            <h2 class="text-sm font-bold text-white">
                Service Job Details
            </h2>

            <p class="text-[10px] text-slate-400 mt-1">
                Showing
                <?= $totalJobs > 0 ? number_format($offset + 1) : 0 ?>
                -
                <?= number_format(min($offset + $perPage, $totalJobs)) ?>
                of <?= number_format($totalJobs) ?> jobs
            </p>
        </div>

        <div class="text-[10px] text-slate-400">
            Report generated: <?= date('d M Y, h:i A') ?>
        </div>

    </div>

    <!-- DATA TABLE -->

    <div class="glass-dark rounded-2xl overflow-hidden border border-white/10">

        <div class="overflow-x-auto">

            <table class="w-full text-xs" id="reportTable">

                <thead>
                    <tr class="text-left border-b border-white/10 bg-white/5">

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            #
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Date
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Job No.
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Client
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Print Type
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-center whitespace-nowrap">
                            Status
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Paid
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Due
                        </th>

                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($services)): ?>

                        <?php foreach ($services as $index => $s): ?>

                            <?php
                                $printType = getServicePrintType($s);

                                $status = $s['status'] ?? '';

                                $statusClass = getServiceStatusClass($status);

                                $clientName = !empty($s['client_name'])
                                    ? $s['client_name']
                                    : 'Walk-in';

                                $serviceDate = $s['service_date'] ?? '';

                                $total = (float) ($s['total_amount'] ?? 0);
                                $paid  = (float) ($s['paid_amount'] ?? 0);
                                $due   = (float) ($s['due_amount'] ?? 0);
                            ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-slate-500">
                                    <?= $offset + $index + 1 ?>
                                </td>

                                <td class="px-4 py-3 text-slate-300 whitespace-nowrap">
                                    <?= $serviceDate ? fmt_date($serviceDate) : '-' ?>
                                </td>

                                <td class="px-4 py-3 text-indigo-300 font-semibold whitespace-nowrap">
                                    <?= e($s['service_no'] ?? '-') ?>
                                </td>

                                <td class="px-4 py-3 text-white font-medium whitespace-nowrap">
                                    <?= e($clientName) ?>
                                </td>

                                <td class="px-4 py-3 text-slate-400 text-[10px] uppercase font-medium">
                                    <?= e($printType) ?>
                                </td>

                                <td class="px-4 py-3 text-center whitespace-nowrap">

                                    <span class="status-badge px-2 py-1 rounded-full <?= $statusClass ?> text-[10px] uppercase font-semibold">
                                        <?= e(getServiceStatusLabel($status)) ?>
                                    </span>

                                </td>

                                <td class="px-4 py-3 text-right text-white font-semibold whitespace-nowrap">
                                    <?= money($total) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300 whitespace-nowrap">
                                    <?= money($paid) ?>
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap <?= $due > 0 ? 'text-rose-400 font-bold' : 'text-slate-500' ?>">
                                    <?= money($due) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center">

                                <div class="text-slate-500">

                                    <i class="fas fa-file-circle-xmark text-3xl mb-3"></i>

                                    <p class="text-sm font-semibold">
                                        No Service Jobs Found
                                    </p>

                                    <p class="text-xs mt-1">
                                        Try changing the date range or search filters.
                                    </p>

                                </div>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- PAGINATION -->

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

    <!-- REPORT FOOTER -->

    <div class="hidden print:block mt-5 pt-3 border-t border-gray-300">

        <div class="flex justify-between text-xs text-gray-600">

            <span>
                PrintERP - Service Report
            </span>

            <span>
                Generated on <?= date('d M Y, h:i A') ?>
            </span>

        </div>

    </div>

</div>

<!-- CSV EXPORT -->

<script>
function exportCSV() {

    const table = document.getElementById('reportTable');

    const rows = table.querySelectorAll('tr');

    let csv = [];

    rows.forEach((row) => {

        const cols = row.querySelectorAll('th, td');

        // Ignore empty-state row
        if (cols.length !== 9) {
            return;
        }

        const rowData = [];

        cols.forEach((col, index) => {

            // Ignore serial number column
            if (index === 0) {
                return;
            }

            let value = col.innerText
                .replace(/\s+/g, ' ')
                .trim();

            value = value.replace(/₹/g, '').trim();

            // CSV escaping
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No service records available to export.');
        return;
    }

    const csvContent = '\uFEFF' + csv.join('\r\n');

    const blob = new Blob(
        [csvContent],
        { type: 'text/csv;charset=utf-8;' }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');

    link.href = url;

    link.download = 'service_report_<?= date('Y-m-d') ?>_page_<?= $currentPage ?>.csv';

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
