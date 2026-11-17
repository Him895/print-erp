
<?php
// ============================================================
// FILE: reports/vendor_report.php
// VENDOR REPORT
// Search + Status Filter + Payment Filter + Pagination
// Full Filtered Summary + CSV Export + Print/PDF
// ============================================================

require_once '../config/config.php';
require_permission('reports.vendor');
$page_title = 'Vendor Report';

// ------------------------------------------------------------
// 1. FILTERS
// ------------------------------------------------------------

$search = trim($_GET['search'] ?? '');
$vendor_status = trim($_GET['vendor_status'] ?? 'active');
$payment_status = trim($_GET['payment_status'] ?? '');

$allowedVendorStatuses = ['active', 'inactive', 'all'];
$allowedPaymentStatuses = ['due', 'paid', 'all'];

if (!in_array($vendor_status, $allowedVendorStatuses, true)) {
    $vendor_status = 'active';
}

if (!in_array($payment_status, $allowedPaymentStatuses, true)) {
    $payment_status = '';
}

// ------------------------------------------------------------
// 2. PAGINATION
// ------------------------------------------------------------

$perPage = 25;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

// ------------------------------------------------------------
// 3. COMMON WHERE CONDITIONS
// ------------------------------------------------------------

$where = "
    WHERE (
        ? = ''
        OR v.vendor_name LIKE ?
        OR v.phone LIKE ?
    )
";

$params = [
    $search,
    '%' . $search . '%',
    '%' . $search . '%'
];

$types = 'sss';

// Vendor active status
if ($vendor_status === 'active') {
    $where .= " AND v.is_active = 1 ";
} elseif ($vendor_status === 'inactive') {
    $where .= " AND v.is_active = 0 ";
}

// Payment status
if ($payment_status === 'due') {
    $where .= "
        AND COALESCE(purchase_summary.total_due, 0) > 0
    ";
} elseif ($payment_status === 'paid') {
    $where .= "
        AND COALESCE(purchase_summary.total_due, 0) <= 0
    ";
}

// ------------------------------------------------------------
// 4. COMMON QUERY
// ------------------------------------------------------------

$baseQuery = "
    FROM vendors v

    LEFT JOIN (
        SELECT
            vendor_id,
            COUNT(*) AS purchase_count,
            COALESCE(SUM(grand_total), 0) AS total_business,
            COALESCE(SUM(paid_amount), 0) AS total_paid,
            COALESCE(SUM(due_amount), 0) AS total_due

        FROM product_purchase

        GROUP BY vendor_id
    ) purchase_summary
        ON purchase_summary.vendor_id = v.id

    $where
";

// ------------------------------------------------------------
// 5. SUMMARY - ALL FILTERED VENDORS
// ------------------------------------------------------------

$summarySql = "
    SELECT
        COUNT(*) AS total_vendors,

        COALESCE(SUM(purchase_summary.purchase_count), 0)
            AS total_purchases,

        COALESCE(SUM(purchase_summary.total_business), 0)
            AS total_business,

        COALESCE(SUM(purchase_summary.total_paid), 0)
            AS total_paid,

        COALESCE(SUM(purchase_summary.total_due), 0)
            AS total_due,

        SUM(
            CASE
                WHEN COALESCE(purchase_summary.total_due, 0) > 0
                THEN 1 ELSE 0
            END
        ) AS vendors_with_due,

        SUM(
            CASE
                WHEN COALESCE(purchase_summary.total_due, 0) <= 0
                THEN 1 ELSE 0
            END
        ) AS vendors_fully_paid

    $baseQuery
";

$summaryStmt = $conn->prepare($summarySql);

if (!$summaryStmt) {
    die('Unable to prepare vendor summary query.');
}

$summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();

$summary = $summaryStmt->get_result()->fetch_assoc();

$summaryStmt->close();

$totalVendors = (int)($summary['total_vendors'] ?? 0);
$totalPurchases = (int)($summary['total_purchases'] ?? 0);

$totalBusiness = (float)($summary['total_business'] ?? 0);
$totalPaid = (float)($summary['total_paid'] ?? 0);
$totalDue = (float)($summary['total_due'] ?? 0);

$vendorsWithDue = (int)($summary['vendors_with_due'] ?? 0);
$vendorsFullyPaid = (int)($summary['vendors_fully_paid'] ?? 0);

// ------------------------------------------------------------
// 6. PAGINATION CALCULATION
// ------------------------------------------------------------

$totalPages = max(1, (int)ceil($totalVendors / $perPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

// ------------------------------------------------------------
// 7. FETCH VENDORS - CURRENT PAGE
// ------------------------------------------------------------

$sql = "
    SELECT
        v.*,

        COALESCE(purchase_summary.purchase_count, 0)
            AS purchase_count,

        COALESCE(purchase_summary.total_business, 0)
            AS total_business,

        COALESCE(purchase_summary.total_paid, 0)
            AS total_paid,

        COALESCE(purchase_summary.total_due, 0)
            AS total_due

    $baseQuery

    ORDER BY
        total_due DESC,
        total_business DESC,
        v.vendor_name ASC

    LIMIT $perPage OFFSET $offset
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to prepare vendor report query.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// ------------------------------------------------------------
// 8. HELPER FUNCTIONS
// ------------------------------------------------------------

function getVendorPaymentStatus($due)
{
    return (float)$due > 0 ? 'Outstanding' : 'Fully Paid';
}

function getVendorPaymentClass($due)
{
    return (float)$due > 0
        ? 'bg-rose-500/20 text-rose-300'
        : 'bg-emerald-500/20 text-emerald-300';
}

// ------------------------------------------------------------
// 9. PAGINATION URL
// ------------------------------------------------------------

$queryParams = [
    'search' => $search,
    'vendor_status' => $vendor_status,
    'payment_status' => $payment_status
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
            padding: 10px;
            background: white !important;
            color: #111827 !important;
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

        .print-heading {
            display: block !important;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<!-- PAGE HEADER -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 no-print">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Vendor Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Vendor purchases, payments and outstanding payable analysis
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

<!-- FILTERS -->

<form method="GET" class="glass-dark rounded-2xl p-4 mb-5 no-print">

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                Search Vendor
            </label>

            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Vendor name or phone"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
        </div>

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                Vendor Status
            </label>

            <select
                name="vendor_status"
                class="w-full bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
                <option value="active" <?= $vendor_status === 'active' ? 'selected' : '' ?>>
                    Active Vendors
                </option>

                <option value="inactive" <?= $vendor_status === 'inactive' ? 'selected' : '' ?>>
                    Inactive Vendors
                </option>

                <option value="all" <?= $vendor_status === 'all' ? 'selected' : '' ?>>
                    All Vendors
                </option>
            </select>
        </div>

        <div>
            <label class="text-[10px] text-slate-400 block mb-1">
                Payment Status
            </label>

            <select
                name="payment_status"
                class="w-full bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none"
            >
                <option value="" <?= $payment_status === '' ? 'selected' : '' ?>>
                    All Payment Status
                </option>

                <option value="due" <?= $payment_status === 'due' ? 'selected' : '' ?>>
                    Outstanding Only
                </option>

                <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>
                    Fully Paid
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
                href="vendor_report.php"
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
            Vendor Payable Report
        </p>

        <p class="text-xs mt-1">
            As on <?= date('d M Y') ?>
        </p>

        <?php if ($search !== ''): ?>
            <p class="text-xs mt-1">
                Search: <?= e($search) ?>
            </p>
        <?php endif; ?>

        <hr class="my-3">

    </div>

    <!-- SUMMARY CARDS -->

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Vendors
            </div>

            <div class="text-2xl font-bold text-white mt-1">
                <?= number_format($totalVendors) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Filtered vendors
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Purchases
            </div>

            <div class="text-2xl font-bold text-indigo-300 mt-1">
                <?= number_format($totalPurchases) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Purchase transactions
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Purchase Value
            </div>

            <div class="text-2xl font-bold text-emerald-300 mt-1">
                <?= money($totalBusiness) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Total purchase amount
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Payable
            </div>

            <div class="text-2xl font-bold text-rose-300 mt-1">
                <?= money($totalDue) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Remaining vendor balance
            </div>

        </div>

    </div>

    <!-- PAYMENT SUMMARY -->

    <div class="grid grid-cols-2 gap-3 mb-5">

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Total Paid
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-1">
                <?= money($totalPaid) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Payments recorded against purchases
            </div>

        </div>

        <div class="glass-dark rounded-xl p-4 border border-white/10">

            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                Vendors With Outstanding
            </div>

            <div class="text-xl font-bold text-rose-300 mt-1">
                <?= number_format($vendorsWithDue) ?>
            </div>

            <div class="text-[10px] text-slate-500 mt-1">
                Fully paid vendors: <?= number_format($vendorsFullyPaid) ?>
            </div>

        </div>

    </div>

    <!-- TABLE HEADING -->

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">

        <div>

            <h2 class="text-sm font-bold text-white">
                Vendor Details
            </h2>

            <p class="text-[10px] text-slate-400 mt-1">

                Showing
                <?= $totalVendors > 0 ? number_format($offset + 1) : 0 ?>
                -
                <?= number_format(min($offset + $perPage, $totalVendors)) ?>
                of <?= number_format($totalVendors) ?> vendors

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
                            Vendor Name
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium whitespace-nowrap">
                            Phone
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-center whitespace-nowrap">
                            Purchases
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total Purchase
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Total Paid
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-right whitespace-nowrap">
                            Payable
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-medium text-center whitespace-nowrap">
                            Payment Status
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($vendors)): ?>

                        <?php foreach ($vendors as $index => $v): ?>

                            <?php
                                $vendorName = $v['vendor_name'] ?? 'Unknown Vendor';

                                $phone = !empty($v['phone'])
                                    ? $v['phone']
                                    : '-';

                                $purchaseCount = (int)($v['purchase_count'] ?? 0);

                                $business = (float)($v['total_business'] ?? 0);
                                $paid = (float)($v['total_paid'] ?? 0);
                                $due = (float)($v['total_due'] ?? 0);

                                $paymentClass = getVendorPaymentClass($due);
                                $paymentLabel = getVendorPaymentStatus($due);
                            ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-slate-500">
                                    <?= $offset + $index + 1 ?>
                                </td>

                                <td class="px-4 py-3 text-white font-semibold whitespace-nowrap">
                                    <?= e($vendorName) ?>
                                </td>

                                <td class="px-4 py-3 text-slate-300 whitespace-nowrap">
                                    <?= e($phone) ?>
                                </td>

                                <td class="px-4 py-3 text-center text-indigo-300 font-semibold">
                                    <?= number_format($purchaseCount) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-white font-medium whitespace-nowrap">
                                    <?= money($business) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300 whitespace-nowrap">
                                    <?= money($paid) ?>
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap <?= $due > 0 ? 'text-rose-400 font-bold' : 'text-slate-500' ?>">
                                    <?= money($due) ?>
                                </td>

                                <td class="px-4 py-3 text-center whitespace-nowrap">

                                    <span class="status-badge px-2 py-1 rounded-full <?= $paymentClass ?> text-[10px] uppercase font-semibold">
                                        <?= e($paymentLabel) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="8" class="px-4 py-10 text-center">

                                <div class="text-slate-500">

                                    <i class="fas fa-truck-field text-3xl mb-3"></i>

                                    <p class="text-sm font-semibold">
                                        No Vendors Found
                                    </p>

                                    <p class="text-xs mt-1">
                                        Try changing your search or filters.
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
                PrintERP - Vendor Report
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

        // Ignore empty state row
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

            // Remove currency symbol for spreadsheet numeric values
            value = value.replace(/₹/g, '').trim();

            // Escape CSV values
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No vendor records available to export.');
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

    link.download = 'vendor_report_<?= date('Y-m-d') ?>_page_<?= $currentPage ?>.csv';

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
