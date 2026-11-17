
<?php
// ============================================================
// FILE: dashboard.php
// DESCRIPTION: Dynamic Permission-Based Dashboard
// OPTIMIZED VERSION
// ============================================================

require_once 'config/config.php';
require_permission('dashboard');

// ============================================================
// 1. USER DETAILS
// ============================================================

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$full_name = $_SESSION['full_name'] ?? 'User';

$isAdmin = is_admin();

// ============================================================
// 2. PERMISSION CHECKS
// ============================================================

$canProducts   = has_permission('products');
$canCategories = has_permission('categories');
$canVendors    = has_permission('vendors');
$canPurchases  = has_permission('purchases');
$canClients    = has_permission('clients');
$canSales      = has_permission('sales');
$canServices   = has_permission('services');
$canPayments   = has_permission('payments');

$canSalesReport    = has_permission('reports.sales');
$canPurchaseReport = has_permission('reports.purchase');
$canProfitReport   = has_permission('reports.profit');
$canStockReport    = has_permission('reports.stock');
$canServiceReport  = has_permission('reports.service');
$canClientReport   = has_permission('reports.client');
$canVendorReport   = has_permission('reports.vendor');

$canAnyReport = (
    $canSalesReport ||
    $canPurchaseReport ||
    $canProfitReport ||
    $canStockReport ||
    $canServiceReport ||
    $canClientReport ||
    $canVendorReport
);

// ============================================================
// 3. HELPER FUNCTIONS
// ============================================================

function dashboard_fetch_one($conn, $sql, $default = [])
{
    $result = $conn->query($sql);

    if (!$result) {
        error_log('Dashboard SQL Error: ' . $conn->error);
        return $default;
    }

    return $result->fetch_assoc() ?: $default;
}

function dashboard_fetch_all($conn, $sql)
{
    $result = $conn->query($sql);

    if (!$result) {
        error_log('Dashboard SQL Error: ' . $conn->error);
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

// ============================================================
// 4. DEFAULT STATISTICS
// ============================================================

$productStats = [
    'total' => 0,
    'total_stock' => 0,
    'tshirt_count' => 0,
    'shirt_count' => 0,
    'bottle_count' => 0
];

$salesStats = [
    'total_sales' => 0,
    'total_revenue' => 0,
    'total_print_revenue' => 0,
    'today_revenue' => 0,
    'today_count' => 0
];

$serviceStats = [
    'total' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'delivered' => 0
];

$clientCount = 0;
$vendorCount = 0;

$paymentStats = [
    'total_received' => 0,
    'total_paid' => 0,
    'confirmed_count' => 0
];

// ============================================================
// 5. FETCH PRODUCT STATISTICS
// ============================================================

if ($canProducts) {

    $productStats = dashboard_fetch_one($conn, "
        SELECT
            COUNT(p.id) AS total,

            COALESCE(SUM(p.stock_qty), 0) AS total_stock,

            COALESCE(SUM(
                CASE
                    WHEN c.category_name LIKE '%T-Shirt%'
                    THEN 1 ELSE 0
                END
            ), 0) AS tshirt_count,

            COALESCE(SUM(
                CASE
                    WHEN c.category_name LIKE '%Shirt%'
                    AND c.category_name NOT LIKE '%T-Shirt%'
                    THEN 1 ELSE 0
                END
            ), 0) AS shirt_count,

            COALESCE(SUM(
                CASE
                    WHEN c.category_name LIKE '%Bottle%'
                    THEN 1 ELSE 0
                END
            ), 0) AS bottle_count

        FROM products p

        LEFT JOIN categories c
            ON p.category_id = c.id

        WHERE p.is_active = 1
    ", $productStats);
}

// ============================================================
// 6. FETCH SALES STATISTICS
// ============================================================

if ($canSales) {

    $salesStats = dashboard_fetch_one($conn, "
        SELECT
            COUNT(*) AS total_sales,

            COALESCE(SUM(grand_total), 0) AS total_revenue,

            COALESCE(SUM(print_total), 0) AS total_print_revenue,

            COALESCE(SUM(
                CASE
                    WHEN sale_date >= CURDATE()
                     AND sale_date < CURDATE() + INTERVAL 1 DAY
                    THEN grand_total
                    ELSE 0
                END
            ), 0) AS today_revenue,

            COUNT(
                CASE
                    WHEN sale_date >= CURDATE()
                     AND sale_date < CURDATE() + INTERVAL 1 DAY
                    THEN 1
                END
            ) AS today_count

        FROM sales

        WHERE status != 'cancelled'
    ", $salesStats);
}

// ============================================================
// 7. FETCH SERVICE STATISTICS
// ============================================================

if ($canServices) {

    $serviceStats = dashboard_fetch_one($conn, "
        SELECT
            COUNT(*) AS total,

            COALESCE(SUM(
                CASE
                    WHEN status = 'pending'
                    THEN 1 ELSE 0
                END
            ), 0) AS pending,

            COALESCE(SUM(
                CASE
                    WHEN status = 'in_progress'
                    THEN 1 ELSE 0
                END
            ), 0) AS in_progress,

            COALESCE(SUM(
                CASE
                    WHEN status = 'completed'
                    THEN 1 ELSE 0
                END
            ), 0) AS completed,

            COALESCE(SUM(
                CASE
                    WHEN status = 'delivered'
                    THEN 1 ELSE 0
                END
            ), 0) AS delivered

        FROM services
    ", $serviceStats);
}

// ============================================================
// 8. FETCH CLIENT COUNT
// ============================================================

if ($canClients) {

    $clientData = dashboard_fetch_one($conn, "
        SELECT COUNT(*) AS total
        FROM clients
        WHERE is_active = 1
    ");

    $clientCount = (int)($clientData['total'] ?? 0);
}

// ============================================================
// 9. FETCH VENDOR COUNT
// ============================================================

if ($canVendors) {

    $vendorData = dashboard_fetch_one($conn, "
        SELECT COUNT(*) AS total
        FROM vendors
        WHERE is_active = 1
    ");

    $vendorCount = (int)($vendorData['total'] ?? 0);
}

// ============================================================
// 10. FETCH PAYMENT STATISTICS
// ============================================================

if ($canPayments) {

    $paymentStats = dashboard_fetch_one($conn, "
        SELECT

            COALESCE(SUM(
                CASE
                    WHEN ref_type = 'sale'
                    AND status = 'confirmed'
                    THEN amount ELSE 0
                END
            ), 0) AS total_received,

            COALESCE(SUM(
                CASE
                    WHEN ref_type = 'purchase'
                    AND status = 'confirmed'
                    THEN amount ELSE 0
                END
            ), 0) AS total_paid,

            COUNT(
                CASE
                    WHEN status = 'confirmed'
                    THEN 1
                END
            ) AS confirmed_count

        FROM payments
    ", $paymentStats);
}

// ============================================================
// 11. CHART DATA
// ============================================================

$salesChartData = [
    'labels' => [],
    'values' => []
];

$productChartData = [
    'labels' => ['T-Shirts', 'Shirts', 'Bottles'],
    'values' => [
        (int)$productStats['tshirt_count'],
        (int)$productStats['shirt_count'],
        (int)$productStats['bottle_count']
    ],
    'colors' => ['#818cf8', '#34d399', '#fbbf24']
];

$serviceChartData = [
    'labels' => ['Pending', 'In Progress', 'Completed', 'Delivered'],
    'values' => [
        (int)$serviceStats['pending'],
        (int)$serviceStats['in_progress'],
        (int)$serviceStats['completed'],
        (int)$serviceStats['delivered']
    ],
    'colors' => ['#fbbf24', '#818cf8', '#34d399', '#14b8a6']
];

$paymentChartData = [
    'labels' => ['Received (IN)', 'Paid (OUT)'],
    'values' => [
        (float)$paymentStats['total_received'],
        (float)$paymentStats['total_paid']
    ],
    'colors' => ['#34d399', '#f87171']
];

// ------------------------------------------------------------
// JSON ENCODING FLAGS
// ------------------------------------------------------------

$jsonFlags = (
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

// Monthly Sales Chart

if ($canSales) {

    $rows = dashboard_fetch_all($conn, "
        SELECT
            DATE_FORMAT(sale_date, '%b %Y') AS month,

            DATE_FORMAT(sale_date, '%Y-%m') AS month_key,

            COALESCE(SUM(grand_total), 0) AS total

        FROM sales

        WHERE status != 'cancelled'

        AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)

        GROUP BY DATE_FORMAT(sale_date, '%Y-%m')

        ORDER BY month_key ASC
    ");

    foreach ($rows as $row) {

        $salesChartData['labels'][] = $row['month'];

        $salesChartData['values'][] = (float)$row['total'];
    }
}

// ============================================================
// 12. RECENT ACTIVITIES
// ============================================================

$recentSales = [];
$recentServices = [];
$recentPayments = [];

// ------------------------------------------------------------
// RECENT SALES
// ------------------------------------------------------------

if ($canSales) {

    $recentSales = dashboard_fetch_all($conn, "
        SELECT
            s.id,
            s.sale_no,
            s.grand_total,
            s.status,
            c.client_name,
            s.created_at

        FROM sales s

        LEFT JOIN clients c
            ON s.client_id = c.id

        ORDER BY s.created_at DESC

        LIMIT 5
    ");
}

// ------------------------------------------------------------
// RECENT SERVICES
// ------------------------------------------------------------

if ($canServices) {

    $recentServices = dashboard_fetch_all($conn, "
        SELECT
            sv.id,
            sv.service_no,
            sv.total_amount,
            sv.status,
            c.client_name,
            sv.created_at

        FROM services sv

        LEFT JOIN clients c
            ON sv.client_id = c.id

        ORDER BY sv.created_at DESC

        LIMIT 5
    ");
}

// ------------------------------------------------------------
// RECENT PAYMENTS
// ------------------------------------------------------------

if ($canPayments) {

    $recentPayments = dashboard_fetch_all($conn, "
        SELECT
            p.id,
            p.payment_no,
            p.amount,
            p.payment_mode,
            p.ref_type,
            p.created_at,
            c.client_name

        FROM payments p

        LEFT JOIN clients c
            ON p.client_id = c.id

        ORDER BY p.created_at DESC

        LIMIT 5
    ");
}

// ============================================================
// 13. DYNAMIC QUICK ACCESS
// ============================================================

$quickAccess = [];

// Products
if ($canProducts) {

    $quickAccess[] = [
        'icon' => 'fa-tshirt',
        'label' => 'Products',
        'link' => 'products/products_list.php',
        'color' => 'rose'
    ];
}

// Categories
if ($canCategories) {

    $quickAccess[] = [
        'icon' => 'fa-tags',
        'label' => 'Categories',
        'link' => 'categories/categories_list.php',
        'color' => 'sky'
    ];
}

// Vendors
if ($canVendors) {

    $quickAccess[] = [
        'icon' => 'fa-truck',
        'label' => 'Vendors',
        'link' => 'vendors/vendors_list.php',
        'color' => 'purple'
    ];
}

// Purchases
if ($canPurchases) {

    $quickAccess[] = [
        'icon' => 'fa-shopping-cart',
        'label' => 'Purchases',
        'link' => 'product_purchase/purchases_list.php',
        'color' => 'orange'
    ];
}

// Clients
if ($canClients) {

    $quickAccess[] = [
        'icon' => 'fa-users',
        'label' => 'Clients',
        'link' => 'clients/clients_list.php',
        'color' => 'blue'
    ];
}

// Sales
if ($canSales) {

    $quickAccess[] = [
        'icon' => 'fa-file-invoice-dollar',
        'label' => 'Sales',
        'link' => 'sales/sales_list.php',
        'color' => 'emerald'
    ];
}

// Services
if ($canServices) {

    $quickAccess[] = [
        'icon' => 'fa-print',
        'label' => 'Services',
        'link' => 'services/services_list.php',
        'color' => 'amber'
    ];
}

// Payments
if ($canPayments) {

    $quickAccess[] = [
        'icon' => 'fa-money-bill-wave',
        'label' => 'Payments',
        'link' => 'payments/payments_list.php',
        'color' => 'teal'
    ];
}

// ------------------------------------------------------------
// REPORTS
// ------------------------------------------------------------

if ($canSalesReport) {

    $quickAccess[] = [
        'icon' => 'fa-chart-line',
        'label' => 'Sales Report',
        'link' => 'reports/sales_report.php',
        'color' => 'sky'
    ];
}

if ($canPurchaseReport) {

    $quickAccess[] = [
        'icon' => 'fa-file-invoice',
        'label' => 'Purchase Report',
        'link' => 'reports/purchase_report.php',
        'color' => 'orange'
    ];
}

if ($canProfitReport) {

    $quickAccess[] = [
        'icon' => 'fa-chart-pie',
        'label' => 'Profit Report',
        'link' => 'reports/profit_report.php',
        'color' => 'emerald'
    ];
}

if ($canStockReport) {

    $quickAccess[] = [
        'icon' => 'fa-boxes',
        'label' => 'Stock Report',
        'link' => 'reports/stock_report.php',
        'color' => 'indigo'
    ];
}

if ($canServiceReport) {

    $quickAccess[] = [
        'icon' => 'fa-print',
        'label' => 'Service Report',
        'link' => 'reports/service_report.php',
        'color' => 'amber'
    ];
}

if ($canClientReport) {

    $quickAccess[] = [
        'icon' => 'fa-user-chart',
        'label' => 'Client Report',
        'link' => 'reports/client_report.php',
        'color' => 'blue'
    ];
}

if ($canVendorReport) {

    $quickAccess[] = [
        'icon' => 'fa-truck',
        'label' => 'Vendor Report',
        'link' => 'reports/vendor_report.php',
        'color' => 'purple'
    ];
}

// ------------------------------------------------------------
// ADMIN-ONLY MODULES
// ------------------------------------------------------------

if ($isAdmin) {

    $quickAccess[] = [
        'icon' => 'fa-users-cog',
        'label' => 'Users',
        'link' => 'users/users_list.php',
        'color' => 'red'
    ];

    $quickAccess[] = [
        'icon' => 'fa-user-shield',
        'label' => 'Roles',
        'link' => 'roles/roles_list.php',
        'color' => 'indigo'
    ];
}

// ============================================================
// 14. GREETING
// ============================================================

$hour = (int)date('H');

if ($hour < 12) {

    $greeting = 'Good Morning ☀️';

} elseif ($hour < 17) {

    $greeting = 'Good Afternoon 🌤️';

} elseif ($hour < 21) {

    $greeting = 'Good Evening 🌅';

} else {

    $greeting = 'Good Night 🌙';
}

$currentDate = date('l, d M Y | h:i A');

$page_title = 'Dashboard';

include 'includes/header.php';

?>

<!-- ============================================================
     DASHBOARD CONTENT
============================================================ -->

<!-- GREETING -->

<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">

    <div class="flex items-center gap-4">

        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500/40 to-purple-500/40 flex items-center justify-center icon-glow backdrop-blur-sm border border-white/20 flex-shrink-0">

            <i class="fas fa-tachometer-alt text-2xl text-indigo-200"></i>

        </div>

        <div>

            <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight glow-text">
                Dashboard
            </h1>

            <p class="text-slate-300 text-sm font-light flex items-center gap-2 flex-wrap">

                <i class="fas fa-arrow-right text-indigo-400 text-xs"></i>

                <?= e($greeting) ?>,

                <span class="font-medium text-white">
                    <?= e($full_name) ?>
                </span>

            </p>

        </div>

    </div>

    <div class="text-xs text-slate-400 bg-white/5 px-3 py-1.5 rounded-full border border-white/5 self-start md:self-auto">

        <i class="far fa-calendar-alt mr-1"></i>

        <?= e($currentDate) ?>

    </div>

</div>


<!-- WELCOME BANNER -->

<div class="bg-gradient-to-r from-indigo-500/12 to-purple-500/6 border border-indigo-500/15 rounded-2xl p-4 md:p-5 mb-6">

    <div class="flex items-center gap-4">

        <div class="w-10 h-10 rounded-full bg-indigo-500/20 flex items-center justify-center flex-shrink-0">

            <i class="fas fa-star text-indigo-400"></i>

        </div>

        <div>

            <p class="text-sm text-slate-300">

                Welcome back,

                <span class="font-semibold text-white">
                    <?= e($full_name) ?>
                </span>!

                Here's what's happening with your business today.

            </p>

            <p class="text-xs text-slate-400/80 mt-1">

                Your dashboard is showing information based on your assigned permissions.

            </p>

        </div>

    </div>

</div>


<!-- ============================================================
     MAIN STAT CARDS
============================================================ -->

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">

    <!-- PRODUCTS -->

    <?php if ($canProducts): ?>

        <div class="glass-card rounded-2xl p-4 hover:bg-white/8 hover:border-indigo-500/25 transition-all duration-300 group">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-2xl md:text-3xl font-bold text-indigo-300">
                        <?= (int)$productStats['total'] ?>
                    </div>

                    <div class="text-[10px] uppercase tracking-wider text-slate-400 font-medium mt-1">
                        Total Products
                    </div>

                </div>

                <div class="w-12 h-12 rounded-xl bg-indigo-500/15 text-indigo-300 flex items-center justify-center text-lg group-hover:scale-110 transition-all">

                    <i class="fas fa-tshirt"></i>

                </div>

            </div>

            <div class="flex gap-2 mt-2 text-[10px] text-slate-400 flex-wrap">

                <span>T: <?= (int)$productStats['tshirt_count'] ?></span>
                <span>S: <?= (int)$productStats['shirt_count'] ?></span>
                <span>B: <?= (int)$productStats['bottle_count'] ?></span>

            </div>

        </div>

    <?php endif; ?>


    <!-- SALES REVENUE -->

    <?php if ($canSales): ?>

        <div class="glass-card rounded-2xl p-4 hover:bg-white/8 hover:border-emerald-500/25 transition-all duration-300 group">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-xl md:text-2xl font-bold text-emerald-300">
                        <?= money($salesStats['total_revenue']) ?>
                    </div>

                    <div class="text-[10px] uppercase tracking-wider text-slate-400 font-medium mt-1">
                        Total Revenue
                    </div>

                </div>

                <div class="w-12 h-12 rounded-xl bg-emerald-500/15 text-emerald-300 flex items-center justify-center text-lg group-hover:scale-110 transition-all">

                    <i class="fas fa-file-invoice-dollar"></i>

                </div>

            </div>

            <div class="text-[10px] text-slate-400 mt-2">

                <?= (int)$salesStats['total_sales'] ?> sales

                · Print: <?= money($salesStats['total_print_revenue']) ?>

            </div>

        </div>

    <?php endif; ?>


    <!-- CLIENTS -->

    <?php if ($canClients): ?>

        <div class="glass-card rounded-2xl p-4 hover:bg-white/8 hover:border-blue-500/25 transition-all duration-300 group">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-2xl md:text-3xl font-bold text-blue-300">
                        <?= (int)$clientCount ?>
                    </div>

                    <div class="text-[10px] uppercase tracking-wider text-slate-400 font-medium mt-1">
                        Total Clients
                    </div>

                </div>

                <div class="w-12 h-12 rounded-xl bg-blue-500/15 text-blue-300 flex items-center justify-center text-lg group-hover:scale-110 transition-all">

                    <i class="fas fa-users"></i>

                </div>

            </div>

            <div class="text-[10px] text-slate-400 mt-2">
                Active customers
            </div>

        </div>

    <?php endif; ?>


    <!-- PAYMENTS -->

    <?php if ($canPayments): ?>

        <div class="glass-card rounded-2xl p-4 hover:bg-white/8 hover:border-amber-500/25 transition-all duration-300 group">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-xl md:text-2xl font-bold text-amber-300">
                        <?= money($paymentStats['total_received']) ?>
                    </div>

                    <div class="text-[10px] uppercase tracking-wider text-slate-400 font-medium mt-1">
                        Total Received
                    </div>

                </div>

                <div class="w-12 h-12 rounded-xl bg-amber-500/15 text-amber-300 flex items-center justify-center text-lg group-hover:scale-110 transition-all">

                    <i class="fas fa-money-bill-wave"></i>

                </div>

            </div>

            <div class="flex gap-2 mt-2 text-[10px]">

                <span class="text-emerald-400">
                    +<?= money($paymentStats['total_received']) ?>
                </span>

                <span class="text-rose-400">
                    -<?= money($paymentStats['total_paid']) ?>
                </span>

            </div>

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     MINI STAT CARDS
============================================================ -->

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">

    <?php if ($canVendors): ?>

        <div class="glass-card rounded-xl p-3 text-center">

            <div class="text-[10px] text-slate-400 uppercase tracking-wide">
                Vendors
            </div>

            <div class="text-xl font-bold text-white mt-0.5">
                <?= (int)$vendorCount ?>
            </div>

        </div>

    <?php endif; ?>


    <?php if ($canServices): ?>

        <div class="glass-card rounded-xl p-3 text-center">

            <div class="text-[10px] text-slate-400 uppercase tracking-wide">
                Pending Services
            </div>

            <div class="text-xl font-bold text-amber-300 mt-0.5">
                <?= (int)$serviceStats['pending'] ?>
            </div>

        </div>

        <div class="glass-card rounded-xl p-3 text-center">

            <div class="text-[10px] text-slate-400 uppercase tracking-wide">
                Total Services
            </div>

            <div class="text-xl font-bold text-indigo-300 mt-0.5">
                <?= (int)$serviceStats['total'] ?>
            </div>

        </div>

    <?php endif; ?>


    <?php if ($canSales): ?>

        <div class="glass-card rounded-xl p-3 text-center">

            <div class="text-[10px] text-slate-400 uppercase tracking-wide">
                Today's Sales
            </div>

            <div class="text-xl font-bold text-emerald-300 mt-0.5">
                <?= (int)$salesStats['today_count'] ?>
            </div>

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     CHARTS
============================================================ -->

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

    <!-- SALES CHART -->

    <?php if ($canSales): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-chart-line text-emerald-400"></i>

                    Monthly Sales

                </h3>

                <span class="text-[10px] text-slate-400 bg-white/5 px-2 py-1 rounded-full">
                    Last 6 months
                </span>

            </div>

            <div style="position:relative;height:220px;">
                <canvas id="salesChart"></canvas>
            </div>

        </div>

    <?php endif; ?>


    <!-- PRODUCT CHART -->

    <?php if ($canProducts): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-tshirt text-indigo-400"></i>

                    Product Stock

                </h3>

                <span class="text-[10px] text-slate-400 bg-white/5 px-2 py-1 rounded-full">
                    By category
                </span>

            </div>

            <div style="position:relative;height:220px;">
                <canvas id="productChart"></canvas>
            </div>

        </div>

    <?php endif; ?>


    <!-- SERVICE CHART -->

    <?php if ($canServices): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-print text-amber-400"></i>

                    Service Status

                </h3>

                <span class="text-[10px] text-slate-400 bg-white/5 px-2 py-1 rounded-full">
                    Print jobs
                </span>

            </div>

            <div style="position:relative;height:200px;">
                <canvas id="serviceChart"></canvas>
            </div>

        </div>

    <?php endif; ?>


    <!-- PAYMENT CHART -->

    <?php if ($canPayments): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-money-bill-wave text-teal-400"></i>

                    Payment Summary

                </h3>

                <span class="text-[10px] text-slate-400 bg-white/5 px-2 py-1 rounded-full">
                    Total
                </span>

            </div>

            <div style="position:relative;height:200px;">
                <canvas id="paymentChart"></canvas>
            </div>

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     QUICK ACCESS
============================================================ -->

<div class="mb-6">

    <h2 class="text-xs font-medium text-slate-400 tracking-wider uppercase mb-3 flex items-center gap-2">

        <i class="fas fa-rocket text-indigo-400"></i>

        Quick Access

    </h2>


    <?php if (!empty($quickAccess)): ?>

        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 gap-3">

            <?php foreach ($quickAccess as $item): ?>

                <a
                    href="<?= BASE_URL . $item['link'] ?>"
                    class="group bg-white/3 border border-white/6 rounded-xl p-3 text-center hover:bg-white/8 hover:border-indigo-500/25 hover:-translate-y-1 transition-all duration-300 relative overflow-hidden"
                >

                    <div class="w-12 h-12 mx-auto rounded-xl flex items-center justify-center mb-2 text-lg color-<?= e($item['color']) ?> group-hover:scale-110 transition-transform">

                        <i class="fas <?= e($item['icon']) ?>"></i>

                    </div>

                    <div class="text-[11px] font-medium text-slate-200">
                        <?= e($item['label']) ?>
                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="glass-dark rounded-xl p-6 text-center text-slate-500 text-xs">

            No modules assigned to your account.

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     RECENT ACTIVITIES
============================================================ -->

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">


    <!-- RECENT SALES -->

    <?php if ($canSales): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-file-invoice-dollar text-emerald-400"></i>

                    Recent Sales

                </h3>

                <a
                    href="<?= BASE_URL ?>sales/sales_list.php"
                    class="text-[10px] text-indigo-400 hover:text-indigo-300"
                >
                    View all →
                </a>

            </div>


            <?php if (!empty($recentSales)): ?>

                <?php foreach ($recentSales as $s): ?>

                    <div class="py-2 border-b border-white/4 last:border-0 hover:bg-white/3 rounded transition-all">

                        <div class="flex items-center justify-between gap-2">

                            <div class="min-w-0">

                                <div class="text-xs text-white truncate">
                                    <?= e($s['sale_no']) ?>
                                </div>

                                <div class="text-[10px] text-slate-400 truncate">
                                    <?= e($s['client_name'] ?? '-') ?>
                                </div>

                            </div>

                            <div class="text-right flex-shrink-0">

                                <div class="text-xs text-emerald-400">
                                    <?= money($s['grand_total']) ?>
                                </div>

                                <span class="text-[9px] px-1.5 py-0.5 rounded-full
                                    <?= $s['status'] === 'completed'
                                        ? 'bg-emerald-500/20 text-emerald-300'
                                        : ($s['status'] === 'cancelled'
                                            ? 'bg-rose-500/20 text-rose-300'
                                            : 'bg-amber-500/20 text-amber-300')
                                    ?>">

                                    <?= e(ucfirst($s['status'])) ?>

                                </span>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="text-center text-slate-500 py-6 text-xs">

                    <i class="fas fa-file-invoice-dollar text-2xl block mb-2 opacity-30"></i>

                    No sales yet

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- RECENT SERVICES -->

    <?php if ($canServices): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-print text-amber-400"></i>

                    Recent Services

                </h3>

                <a
                    href="<?= BASE_URL ?>services/services_list.php"
                    class="text-[10px] text-indigo-400 hover:text-indigo-300"
                >
                    View all →
                </a>

            </div>


            <?php if (!empty($recentServices)): ?>

                <?php foreach ($recentServices as $sv): ?>

                    <div class="py-2 border-b border-white/4 last:border-0 hover:bg-white/3 rounded transition-all">

                        <div class="flex items-center justify-between gap-2">

                            <div class="min-w-0">

                                <div class="text-xs text-white truncate">
                                    <?= e($sv['service_no']) ?>
                                </div>

                                <div class="text-[10px] text-slate-400 truncate">
                                    <?= e($sv['client_name'] ?? '-') ?>
                                </div>

                            </div>

                            <div class="text-right flex-shrink-0">

                                <div class="text-xs text-amber-400">
                                    <?= money($sv['total_amount']) ?>
                                </div>

                                <span class="text-[9px] px-1.5 py-0.5 rounded-full
                                    <?= in_array($sv['status'], ['completed', 'delivered'], true)
                                        ? 'bg-emerald-500/20 text-emerald-300'
                                        : ($sv['status'] === 'in_progress'
                                            ? 'bg-indigo-500/20 text-indigo-300'
                                            : 'bg-amber-500/20 text-amber-300')
                                    ?>">

                                    <?= e(ucfirst(str_replace('_', ' ', $sv['status']))) ?>

                                </span>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="text-center text-slate-500 py-6 text-xs">

                    <i class="fas fa-print text-2xl block mb-2 opacity-30"></i>

                    No services yet

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- RECENT PAYMENTS -->

    <?php if ($canPayments): ?>

        <div class="glass-dark rounded-2xl p-4">

            <div class="flex items-center justify-between mb-3">

                <h3 class="text-sm font-medium text-slate-300 flex items-center gap-2">

                    <i class="fas fa-money-bill-wave text-teal-400"></i>

                    Recent Payments

                </h3>

                <a
                    href="<?= BASE_URL ?>payments/payments_list.php"
                    class="text-[10px] text-indigo-400 hover:text-indigo-300"
                >
                    View all →
                </a>

            </div>


            <?php if (!empty($recentPayments)): ?>

                <?php foreach ($recentPayments as $p): ?>

                    <div class="py-2 border-b border-white/4 last:border-0 hover:bg-white/3 rounded transition-all">

                        <div class="flex items-center justify-between gap-2">

                            <div class="min-w-0">

                                <div class="text-xs text-white truncate">
                                    <?= e($p['payment_no']) ?>
                                </div>

                                <div class="text-[10px] text-slate-400 truncate">

                                    <?= e($p['client_name'] ?? 'Vendor') ?>

                                    · <?= e(ucfirst($p['payment_mode'] ?? '')) ?>

                                </div>

                            </div>

                            <div class="text-right flex-shrink-0">

                                <div class="text-xs text-teal-400">
                                    <?= money($p['amount']) ?>
                                </div>

                                <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300">

                                    <?= e(ucfirst($p['ref_type'] ?? '')) ?>

                                </span>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="text-center text-slate-500 py-6 text-xs">

                    <i class="fas fa-money-bill-wave text-2xl block mb-2 opacity-30"></i>

                    No payments yet

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     CHARTS SCRIPT
============================================================ -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded.');
        return;
    }

    Chart.defaults.color = '#94a3b8';
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = 10;

    // --------------------------------------------------------
    // MONTHLY SALES
    // --------------------------------------------------------

    const salesCanvas = document.getElementById('salesChart');

    if (salesCanvas) {

        new Chart(salesCanvas, {

            type: 'line',

            data: {

                labels: <?= json_encode($salesChartData['labels'], $jsonFlags) ?>,

                datasets: [{

                    label: 'Revenue',

                    data: <?= json_encode($salesChartData['values'], $jsonFlags) ?>,

                    backgroundColor: 'rgba(99,102,241,0.1)',

                    borderColor: '#818cf8',

                    borderWidth: 2,

                    pointBackgroundColor: '#818cf8',

                    pointBorderColor: '#fff',

                    pointBorderWidth: 2,

                    pointRadius: 4,

                    tension: 0.3,

                    fill: true

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {
                    legend: { display: false }
                },

                scales: {

                    y: {

                        beginAtZero: true,

                        grid: {
                            color: 'rgba(255,255,255,0.05)'
                        },

                        ticks: {
                            callback: value => '₹' + value.toLocaleString()
                        }

                    },

                    x: {
                        grid: { display: false }
                    }

                }

            }

        });

    }


    // --------------------------------------------------------
    // PRODUCT STOCK
    // --------------------------------------------------------

    const productCanvas = document.getElementById('productChart');

    if (productCanvas) {

        new Chart(productCanvas, {

            type: 'doughnut',

            data: {

                labels: <?= json_encode($productChartData['labels'], $jsonFlags) ?>,

                datasets: [{

                    data: <?= json_encode($productChartData['values'], $jsonFlags) ?>,

                    backgroundColor: <?= json_encode($productChartData['colors'], $jsonFlags) ?>,

                    borderWidth: 2,

                    borderColor: 'rgba(15,23,42,0.8)'

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {
                            padding: 10,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }

                    }

                },

                cutout: '60%'

            }

        });

    }


    // --------------------------------------------------------
    // SERVICE STATUS
    // --------------------------------------------------------

    const serviceCanvas = document.getElementById('serviceChart');

    if (serviceCanvas) {

        new Chart(serviceCanvas, {

            type: 'doughnut',

            data: {

                labels: <?= json_encode($serviceChartData['labels'], $jsonFlags) ?>,

                datasets: [{

                    data: <?= json_encode($serviceChartData['values'], $jsonFlags) ?>,

                    backgroundColor: <?= json_encode($serviceChartData['colors'], $jsonFlags) ?>,

                    borderWidth: 2,

                    borderColor: 'rgba(15,23,42,0.8)'

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        position: 'bottom',

                        labels: {
                            padding: 10,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }

                    }

                },

                cutout: '60%'

            }

        });

    }


    // --------------------------------------------------------
    // PAYMENT SUMMARY
    // --------------------------------------------------------

    const paymentCanvas = document.getElementById('paymentChart');

    if (paymentCanvas) {

        new Chart(paymentCanvas, {

            type: 'bar',

            data: {

                labels: <?= json_encode($paymentChartData['labels'], $jsonFlags) ?>,

                datasets: [{

                    label: 'Amount',

                    data: <?= json_encode($paymentChartData['values'], $jsonFlags) ?>,

                    backgroundColor: <?= json_encode($paymentChartData['colors'], $jsonFlags) ?>,

                    borderWidth: 0,

                    borderRadius: 6

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {
                    legend: { display: false }
                },

                scales: {

                    y: {

                        beginAtZero: true,

                        grid: {
                            color: 'rgba(255,255,255,0.05)'
                        },

                        ticks: {
                            callback: value => '₹' + value.toLocaleString()
                        }

                    },

                    x: {
                        grid: { display: false }
                    }

                }

            }

        });

    }

});
</script>


<?php include 'includes/footer.php'; ?>
