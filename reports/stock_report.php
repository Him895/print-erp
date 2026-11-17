
<?php
// ============================================================
// FILE: reports/stock_report.php
// STOCK REPORT
// Current Inventory + Stock Valuation + Filters + Pagination
// ============================================================

require_once '../config/config.php';
require_permission('reports.stock');
$page_title = 'Stock Report';

// ------------------------------------------------------------
// FILTERS
// ------------------------------------------------------------

$search = (isset($_GET['search']) && is_string($_GET['search']))
    ? trim($_GET['search'])
    : '';

$category_id = (isset($_GET['category']) && is_scalar($_GET['category']))
    ? (int) $_GET['category']
    : 0;

$stock_status = (isset($_GET['stock_status']) && is_string($_GET['stock_status']))
    ? trim($_GET['stock_status'])
    : 'all';

$allowed_statuses = ['all', 'in_stock', 'low_stock', 'out_of_stock'];

if (!in_array($stock_status, $allowed_statuses, true)) {
    $stock_status = 'all';
}

// ------------------------------------------------------------
// PAGINATION
// ------------------------------------------------------------

$per_page = 25;

$current_page = isset($_GET['page']) && is_scalar($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$total_records = 0;
$total_pages = 1;
$offset = 0;

$products = [];

// ------------------------------------------------------------
// SUMMARY
// ------------------------------------------------------------

$totalItems = 0;
$totalPurchaseValue = 0;
$totalSaleValue = 0;

$totalProducts = 0;
$inStockProducts = 0;
$lowStockProducts = 0;
$outOfStockProducts = 0;

// ------------------------------------------------------------
// FETCH CATEGORIES
// ------------------------------------------------------------

$categories = [];

$categoryResult = $conn->query("
    SELECT id, category_name
    FROM categories
    ORDER BY category_name ASC
");

if ($categoryResult) {
    $categories = $categoryResult->fetch_all(MYSQLI_ASSOC);
}

// ------------------------------------------------------------
// BUILD FILTER CONDITIONS
// ------------------------------------------------------------

$where = ["p.is_active = 1"];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "(p.product_name LIKE ? OR p.color LIKE ?)";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'ss';
}

if ($category_id > 0) {

    $where[] = "p.category_id = ?";

    $params[] = $category_id;

    $types .= 'i';
}

if ($stock_status === 'in_stock') {
    $where[] = "p.stock_qty > 10";
} elseif ($stock_status === 'low_stock') {
    $where[] = "p.stock_qty > 0 AND p.stock_qty <= 10";
} elseif ($stock_status === 'out_of_stock') {
    $where[] = "p.stock_qty <= 0";
}

$whereSql = implode(' AND ', $where);

// ------------------------------------------------------------
// SUMMARY QUERY
// Summary respects selected filters.
// ------------------------------------------------------------

$summarySql = "
    SELECT

        COUNT(*) AS total_products,

        COALESCE(SUM(p.stock_qty), 0) AS total_items,

        COALESCE(
            SUM(
                p.stock_qty * COALESCE(p.purchase_price, 0)
            ),
            0
        ) AS total_purchase_value,

        COALESCE(
            SUM(
                p.stock_qty * COALESCE(p.sale_price, 0)
            ),
            0
        ) AS total_sale_value,

        COALESCE(
            SUM(CASE WHEN p.stock_qty > 10 THEN 1 ELSE 0 END),
            0
        ) AS in_stock_products,

        COALESCE(
            SUM(CASE WHEN p.stock_qty > 0 AND p.stock_qty <= 10 THEN 1 ELSE 0 END),
            0
        ) AS low_stock_products,

        COALESCE(
            SUM(CASE WHEN p.stock_qty <= 0 THEN 1 ELSE 0 END),
            0
        ) AS out_of_stock_products

    FROM products p

    WHERE $whereSql
";

$summaryStmt = $conn->prepare($summarySql);

if ($summaryStmt) {

    if (!empty($params)) {
        $summaryStmt->bind_param($types, ...$params);
    }

    if ($summaryStmt->execute()) {

        $summary = $summaryStmt->get_result()->fetch_assoc();

        if ($summary) {

            $totalProducts = (int) $summary['total_products'];
            $totalItems = (float) $summary['total_items'];

            $totalPurchaseValue = (float) $summary['total_purchase_value'];
            $totalSaleValue = (float) $summary['total_sale_value'];

            $inStockProducts = (int) $summary['in_stock_products'];
            $lowStockProducts = (int) $summary['low_stock_products'];
            $outOfStockProducts = (int) $summary['out_of_stock_products'];
        }

    }

    $summaryStmt->close();
}

// ------------------------------------------------------------
// TOTAL RECORDS
// ------------------------------------------------------------

$total_records = $totalProducts;

$total_pages = max(1, (int) ceil($total_records / $per_page));

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

$offset = ($current_page - 1) * $per_page;

// ------------------------------------------------------------
// FETCH CURRENT PAGE PRODUCTS
// ------------------------------------------------------------

if ($total_records > 0) {

    $sql = "
        SELECT
            p.*,
            c.category_name

        FROM products p

        LEFT JOIN categories c
            ON p.category_id = c.id

        WHERE $whereSql

        ORDER BY
            p.stock_qty ASC,
            p.product_name ASC,
            p.id DESC

        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        $queryTypes = $types . 'ii';

        $queryParams = $params;
        $queryParams[] = $per_page;
        $queryParams[] = $offset;

        $stmt->bind_param($queryTypes, ...$queryParams);

        if ($stmt->execute()) {
            $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $stmt->close();
    }
}

// ------------------------------------------------------------
// PAGINATION URL
// ------------------------------------------------------------

$buildPageUrl = function ($page) use (
    $search,
    $category_id,
    $stock_status
) {

    return '?' . http_build_query([
        'search' => $search,
        'category' => $category_id,
        'stock_status' => $stock_status,
        'page' => $page
    ]);
};

// ------------------------------------------------------------
// DISPLAY RANGE
// ------------------------------------------------------------

$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_records);

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

    .report-table th,
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

        .print-area .text-slate-400,
        .print-area .text-slate-300,
        .print-area .text-slate-500 {
            color: #555 !important;
        }

        .print-area .text-emerald-300,
        .print-area .text-emerald-400 {
            color: #047857 !important;
        }

        .print-area .text-rose-300,
        .print-area .text-rose-400 {
            color: #b91c1c !important;
        }

        .print-area .text-amber-300,
        .print-area .text-amber-400 {
            color: #b45309 !important;
        }

        .print-area .text-indigo-300 {
            color: #4338ca !important;
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

        <h1 class="text-2xl font-bold text-white tracking-tight">
            Stock Report
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Current inventory status and stock valuation
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
            Export Current Page
        </button>

    </div>

</div>

<!-- ========================================================
     FILTERS
========================================================= -->

<form
    method="GET"
    class="glass-dark rounded-2xl p-4 mb-5 flex flex-wrap items-end gap-3 no-print"
>

    <div class="flex-1 min-w-[180px]">

        <label class="text-[10px] text-slate-400 block mb-1">
            Search Product
        </label>

        <input
            type="text"
            name="search"
            value="<?= e($search) ?>"
            placeholder="Product name or color..."
            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200"
        >

    </div>

    <div class="min-w-[160px]">

        <label class="text-[10px] text-slate-400 block mb-1">
            Category
        </label>

        <select
            name="category"
            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200"
        >

            <option value="0" class="bg-slate-900">
                All Categories
            </option>

            <?php foreach ($categories as $category): ?>

                <option
                    value="<?= (int) $category['id'] ?>"
                    <?= $category_id === (int) $category['id'] ? 'selected' : '' ?>
                    class="bg-slate-900"
                >
                    <?= e($category['category_name']) ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="min-w-[150px]">

        <label class="text-[10px] text-slate-400 block mb-1">
            Stock Status
        </label>

        <select
            name="stock_status"
            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200"
        >

            <option value="all" <?= $stock_status === 'all' ? 'selected' : '' ?> class="bg-slate-900">
                All Stock
            </option>

            <option value="in_stock" <?= $stock_status === 'in_stock' ? 'selected' : '' ?> class="bg-slate-900">
                In Stock
            </option>

            <option value="low_stock" <?= $stock_status === 'low_stock' ? 'selected' : '' ?> class="bg-slate-900">
                Low Stock
            </option>

            <option value="out_of_stock" <?= $stock_status === 'out_of_stock' ? 'selected' : '' ?> class="bg-slate-900">
                Out of Stock
            </option>

        </select>

    </div>

    <button
        type="submit"
        class="px-5 py-2 rounded-lg bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-semibold transition"
    >
        <i class="fas fa-filter mr-1"></i>
        Apply
    </button>

    <a
        href="stock_report.php"
        class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-semibold hover:bg-white/10 transition"
    >
        <i class="fas fa-rotate-left mr-1"></i>
        Reset
    </a>

</form>

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
            Stock Report
        </p>

        <p class="text-xs text-slate-400 mt-1">
            Stock as on <?= date('d M Y') ?>
        </p>

        <hr class="my-3">

    </div>

    <!-- ====================================================
         SUMMARY CARDS
    ===================================================== -->

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-5">

        <!-- TOTAL PRODUCTS -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Total Products
                </div>

                <i class="fas fa-boxes-stacked text-indigo-300"></i>

            </div>

            <div class="text-2xl font-bold text-white mt-2">
                <?= number_format($totalProducts) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Active product records
            </div>

        </div>

        <!-- TOTAL QUANTITY -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Total Stock Quantity
                </div>

                <i class="fas fa-cubes text-emerald-300"></i>

            </div>

            <div class="text-2xl font-bold text-white mt-2">
                <?= number_format($totalItems, 2) ?>
                <span class="text-sm text-slate-400 font-medium">Units</span>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Current available stock
            </div>

        </div>

        <!-- STOCK COST VALUE -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Stock Value (Cost)
                </div>

                <i class="fas fa-wallet text-rose-300"></i>

            </div>

            <div class="text-2xl font-bold text-rose-300 mt-2">
                <?= money($totalPurchaseValue) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Quantity × purchase price
            </div>

        </div>

        <!-- ESTIMATED SALE VALUE -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Estimated Sale Value
                </div>

                <i class="fas fa-chart-line text-emerald-300"></i>

            </div>

            <div class="text-2xl font-bold text-emerald-300 mt-2">
                <?= money($totalSaleValue) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Quantity × sale price
            </div>

        </div>

        <!-- LOW STOCK -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Low Stock Products
                </div>

                <i class="fas fa-triangle-exclamation text-amber-300"></i>

            </div>

            <div class="text-2xl font-bold text-amber-300 mt-2">
                <?= number_format($lowStockProducts) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Stock between 1 and 10
            </div>

        </div>

        <!-- OUT OF STOCK -->

        <div class="report-card p-4">

            <div class="flex items-center justify-between">

                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
                    Out of Stock
                </div>

                <i class="fas fa-circle-xmark text-rose-300"></i>

            </div>

            <div class="text-2xl font-bold text-rose-300 mt-2">
                <?= number_format($outOfStockProducts) ?>
            </div>

            <div class="text-[10px] text-slate-400 mt-1">
                Zero or negative stock
            </div>

        </div>

    </div>

    <!-- ====================================================
         PRODUCT TABLE
    ===================================================== -->

    <div class="glass-dark rounded-2xl overflow-hidden">

        <div class="p-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-2">

            <div>

                <h3 class="text-sm font-bold text-white">
                    Inventory Details
                </h3>

                <p class="text-[10px] text-slate-400 mt-1">
                    Current product-wise stock and valuation
                </p>

            </div>

            <div class="text-xs text-slate-400">

                Products:

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
                            Product Name
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Category
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold">
                            Color
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-center">
                            Stock
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-center">
                            Status
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Purchase Rate
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Cost Value
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Sale Rate
                        </th>

                        <th class="px-4 py-3 text-slate-400 font-semibold text-right">
                            Sale Value
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($products)): ?>

                        <?php foreach ($products as $index => $p):

                            $stockQty = (float) ($p['stock_qty'] ?? 0);

                            $purchasePrice = (float) ($p['purchase_price'] ?? 0);

                            $salePrice = (float) ($p['sale_price'] ?? 0);

                            $costVal = $stockQty * $purchasePrice;

                            $saleVal = $stockQty * $salePrice;

                            if ($stockQty <= 0) {

                                $stockClass = 'text-rose-400 font-bold';
                                $statusClass = 'bg-rose-500/10 text-rose-300 border-rose-500/20';
                                $statusLabel = 'Out of Stock';

                            } elseif ($stockQty <= 10) {

                                $stockClass = 'text-amber-400 font-bold';
                                $statusClass = 'bg-amber-500/10 text-amber-300 border-amber-500/20';
                                $statusLabel = 'Low Stock';

                            } else {

                                $stockClass = 'text-emerald-400 font-bold';
                                $statusClass = 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20';
                                $statusLabel = 'In Stock';

                            }

                        ?>

                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="px-4 py-3 text-slate-500">
                                    <?= $offset + $index + 1 ?>
                                </td>

                                <td class="px-4 py-3 text-white font-semibold">
                                    <?= e($p['product_name']) ?>
                                </td>

                                <td class="px-4 py-3 text-slate-300">
                                    <?= e($p['category_name'] ?? '-') ?>
                                </td>

                                <td class="px-4 py-3 text-slate-400">
                                    <?= e($p['color'] ?? '-') ?>
                                </td>

                                <td class="px-4 py-3 text-center <?= $stockClass ?>">
                                    <?= number_format($stockQty, 2) ?>
                                </td>

                                <td class="px-4 py-3 text-center">

                                    <span class="inline-flex px-2 py-1 rounded-md border text-[10px] font-semibold <?= $statusClass ?>">
                                        <?= e($statusLabel) ?>
                                    </span>

                                </td>

                                <td class="px-4 py-3 text-right text-slate-300">
                                    <?= money($purchasePrice) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-slate-400">
                                    <?= money($costVal) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-slate-300">
                                    <?= money($salePrice) ?>
                                </td>

                                <td class="px-4 py-3 text-right text-emerald-300 font-semibold">
                                    <?= money($saleVal) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="10" class="px-4 py-12 text-center">

                                <div class="flex flex-col items-center">

                                    <div class="w-12 h-12 rounded-full bg-white/5 flex items-center justify-center mb-3">

                                        <i class="fas fa-box-open text-xl text-slate-500"></i>

                                    </div>

                                    <div class="text-sm font-semibold text-slate-300">
                                        No Products Found
                                    </div>

                                    <p class="text-xs text-slate-500 mt-1">
                                        No active products match the selected filters.
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

                    products

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
                    $end_page = min($total_pages, $current_page + 2);

                    if ($start_page > 1):

                    ?>

                        <a href="<?= e($buildPageUrl(1)) ?>" class="pagination-link">
                            1
                        </a>

                        <?php if ($start_page > 2): ?>

                            <span class="text-slate-500 px-1">
                                ...
                            </span>

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

                            <span class="text-slate-500 px-1">
                                ...
                            </span>

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

    <!-- ====================================================
         STOCK VALUATION NOTE
    ===================================================== -->

    <div class="mt-4 p-3 rounded-xl bg-amber-500/5 border border-amber-500/15">

        <p class="text-[10px] text-amber-300 font-semibold mb-1">

            <i class="fas fa-circle-info mr-1"></i>
            Stock Valuation Note

        </p>

        <p class="text-[10px] text-slate-400 leading-relaxed">

            Stock Cost Value is calculated using current stock quantity multiplied
            by the product's current purchase price. Estimated Sale Value uses
            current stock quantity multiplied by the current sale price.
            These are inventory estimates and do not represent actual realized revenue
            or profit.

        </p>

    </div>

    <!-- PRINT FOOTER -->

    <div class="hidden print:block mt-5 text-xs text-slate-400 text-right">
        Generated on <?= date('d-m-Y h:i A') ?>
    </div>

</div>

<!-- ========================================================
     CSV EXPORT - CURRENT FILTERED PAGE
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
        if (cells.length !== 10) {
            return;
        }

        const rowData = [];

        cells.forEach((cell, index) => {

            // Skip serial number
            if (index === 0) {
                return;
            }

            let value = cell.innerText.trim();

            // Remove currency symbol and commas from money columns
            if ([5, 6, 7, 8, 9].includes(index)) {
                value = value.replace(/[₹,]/g, '').trim();
            }

            // Escape CSV quotes
            value = '"' + value.replace(/"/g, '""') + '"';

            rowData.push(value);

        });

        csv.push(rowData.join(','));

    });

    if (csv.length <= 1) {
        alert('No stock records available to export.');
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
    a.download = 'stock_report_<?= date('Ymd_His') ?>.csv';

    document.body.appendChild(a);

    a.click();

    document.body.removeChild(a);

    URL.revokeObjectURL(url);
}
</script>

<?php include '../includes/footer.php'; ?>
