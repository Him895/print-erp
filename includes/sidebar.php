
<?php
// ============================================================
// FILE: includes/sidebar.php
// DESCRIPTION: Dynamic Permission-Based Sidebar
// Admin = Full Access
// Other Users = Assigned Permissions Only
// ============================================================

require_login();

// Current user details
$s_role = $_SESSION['role'] ?? 'sales';

$current = basename($_SERVER['PHP_SELF']);
$parent  = basename(dirname($_SERVER['PHP_SELF']));

// Get logged-in user's permissions
$user_permissions = get_user_permissions();

// Convert permissions into quick lookup array
$allowed_permissions = array_flip($user_permissions);

// ------------------------------------------------------------
// ALL MENU DEFINITIONS
// ------------------------------------------------------------

$all_menu = [

    'dashboard' => [
        'icon'  => 'fa-tachometer-alt',
        'label' => 'Dashboard',
        'link'  => 'dashboard.php',
        'color' => 'indigo'
    ],

    'products' => [
        'icon'  => 'fa-tshirt',
        'label' => 'Products',
        'link'  => 'products/products_list.php',
        'color' => 'rose'
    ],

    'categories' => [
        'icon'  => 'fa-tags',
        'label' => 'Categories',
        'link'  => 'categories/categories_list.php',
        'color' => 'sky'
    ],

    'vendors' => [
        'icon'  => 'fa-truck',
        'label' => 'Vendors',
        'link'  => 'vendors/vendors_list.php',
        'color' => 'purple'
    ],

    'purchases' => [
        'icon'  => 'fa-shopping-cart',
        'label' => 'Purchases',
        'link'  => 'product_purchase/purchases_list.php',
        'color' => 'orange'
    ],

    'clients' => [
        'icon'  => 'fa-users',
        'label' => 'Customers',
        'link'  => 'clients/clients_list.php',
        'color' => 'blue'
    ],

    'sales' => [
        'icon'  => 'fa-file-invoice-dollar',
        'label' => 'Sales',
        'link'  => 'sales/sales_list.php',
        'color' => 'emerald'
    ],

    'services' => [
        'icon'  => 'fa-print',
        'label' => 'Services',
        'link'  => 'services/services_list.php',
        'color' => 'amber'
    ],

    'payments' => [
        'icon'  => 'fa-money-bill-wave',
        'label' => 'Payments',
        'link'  => 'payments/payments_list.php',
        'color' => 'teal'
    ],

    'reports' => [
        'icon'  => 'fa-chart-bar',
        'label' => 'Reports',
        'link'  => '#',
        'color' => 'sky'
    ],

    'users' => [
        'icon'  => 'fa-users-cog',
        'label' => 'Users',
        'link'  => 'users/users_list.php',
        'color' => 'red'
    ],

    // NEW: ROLE MANAGEMENT
    'roles' => [
        'icon'  => 'fa-user-shield',
        'label' => 'Role Management',
        'link'  => 'roles/roles_list.php',
        'color' => 'green'
    ],

];


// ------------------------------------------------------------
// REPORT SUBMENU DEFINITIONS
// ------------------------------------------------------------

$report_links = [

    [
        'key'   => 'reports.sales',
        'label' => 'Sales Report',
        'link'  => 'reports/sales_report.php'
    ],

    [
        'key'   => 'reports.purchase',
        'label' => 'Purchase Report',
        'link'  => 'reports/purchase_report.php'
    ],

    [
        'key'   => 'reports.profit',
        'label' => 'Profit & Loss',
        'link'  => 'reports/profit_report.php'
    ],

    [
        'key'   => 'reports.stock',
        'label' => 'Stock Report',
        'link'  => 'reports/stock_report.php'
    ],

    [
        'key'   => 'reports.service',
        'label' => 'Service Report',
        'link'  => 'reports/service_report.php'
    ],

    [
        'key'   => 'reports.client',
        'label' => 'Client Report',
        'link'  => 'reports/client_report.php'
    ],

    [
        'key'   => 'reports.vendor',
        'label' => 'Vendor Report',
        'link'  => 'reports/vendor_report.php'
    ],
];


// ------------------------------------------------------------
// BUILD VISIBLE MENU
// ------------------------------------------------------------

$visible = [];

foreach ($all_menu as $key => $menu) {

    // Reports dropdown: show only if at least one report is allowed.
    if ($key === 'reports') {

        $has_report_access = false;

        foreach ($report_links as $report) {

            if (isset($allowed_permissions[$report['key']])) {
                $has_report_access = true;
                break;
            }
        }

        if ($has_report_access) {
            $visible[$key] = $menu;
        }

        continue;
    }


    // User Management is Admin-only.
    if ($key === 'users') {

        if (is_admin()) {
            $visible[$key] = $menu;
        }

        continue;
    }


    // Role Management is Admin-only.
    if ($key === 'roles') {

        if (is_admin()) {
            $visible[$key] = $menu;
        }

        continue;
    }


    // Normal menu permission check.
    if (isset($allowed_permissions[$key])) {
        $visible[$key] = $menu;
    }
}


// ------------------------------------------------------------
// COLOR CLASSES
// ------------------------------------------------------------

$icon_colors = [

    'indigo'  => 'color-indigo',
    'rose'    => 'color-rose',
    'purple'  => 'color-purple',
    'orange'  => 'color-orange',
    'blue'    => 'color-blue',
    'emerald' => 'color-emerald',
    'amber'   => 'color-amber',
    'teal'    => 'color-teal',
    'green'   => 'color-green',
    'sky'     => 'color-sky',
    'red'     => 'color-red',

];


// ------------------------------------------------------------
// ACTIVE PAGE CHECK
// ------------------------------------------------------------

$is_report_active = ($parent === 'reports');

?>

<style>

    .color-indigo {
        background: rgba(99,102,241,0.15);
        color: #a5b4fc;
    }

    .color-rose {
        background: rgba(244,63,94,0.15);
        color: #fda4af;
    }

    .color-purple {
        background: rgba(139,92,246,0.15);
        color: #c4b5fd;
    }

    .color-orange {
        background: rgba(251,146,60,0.15);
        color: #fdba74;
    }

    .color-blue {
        background: rgba(96,165,250,0.15);
        color: #93c5fd;
    }

    .color-emerald {
        background: rgba(52,211,153,0.15);
        color: #6ee7b7;
    }

    .color-amber {
        background: rgba(251,191,36,0.15);
        color: #fcd34d;
    }

    .color-teal {
        background: rgba(45,212,191,0.15);
        color: #5eead4;
    }

    .color-green {
        background: rgba(52,211,153,0.15);
        color: #6ee7b7;
    }

    .color-sky {
        background: rgba(56,189,248,0.15);
        color: #7dd3fc;
    }

    .color-red {
        background: rgba(239,68,68,0.15);
        color: #fca5a5;
    }


    /* Reports Dropdown */

    .sub-menu {
        display: none;
        padding-left: 2.5rem;
        margin-top: 0.25rem;
        margin-bottom: 0.5rem;
    }

    .sub-menu.show {
        display: block;
    }

    .sub-link {
        display: block;
        padding: 0.4rem 0.5rem;
        font-size: 0.75rem;
        color: #94a3b8;
        border-radius: 0.4rem;
        text-decoration: none;
        transition: all 0.2s;
    }

    .sub-link:hover {
        color: #fff;
        background: rgba(255,255,255,0.05);
    }

    .sub-link.active {
        color: #7dd3fc;
        background: rgba(56,189,248,0.1);
        font-weight: 500;
    }

</style>


<!-- ============================================================
     SIDEBAR
============================================================ -->

<aside id="sidebar"
    class="fixed md:sticky top-0 left-0 z-30 h-screen w-64 -translate-x-full md:translate-x-0 transition-transform glass-dark border-r border-white/5 flex flex-col">


    <!-- LOGO -->

    <div class="px-5 py-5 border-b border-white/5 flex items-center gap-3">

        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/40 to-purple-500/40 flex items-center justify-center border border-white/20 icon-glow">

            <i class="fas fa-tshirt text-indigo-200"></i>

        </div>

        <div>

            <div class="text-white font-bold text-base leading-tight">
                <?= e(SITE_NAME) ?>
            </div>

            <div class="text-[10px] text-slate-400">
                <?= e(SITE_VERSION) ?>
            </div>

        </div>

        <button
            onclick="document.getElementById('sidebar').classList.add('-translate-x-full')"
            class="md:hidden ml-auto text-slate-400 hover:text-white">

            <i class="fas fa-times"></i>

        </button>

    </div>


    <!-- MENU -->

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">

        <div class="text-[10px] text-slate-500 uppercase tracking-wider px-3 mb-2 font-semibold">
            Menu
        </div>


        <?php foreach ($visible as $key => $m): ?>


            <?php

            // Active normal menu check
            $is_active = (
                ($key === 'dashboard' && $current === 'dashboard.php')
                ||
                (
                    $key !== 'dashboard'
                    &&
                    $key !== 'reports'
                    &&
                    (
                        $parent === $key
                        ||
                        strpos(
                            $_SERVER['PHP_SELF'],
                            '/' . $key . '/'
                        ) !== false
                    )
                )
            );

            ?>


            <?php if ($key === 'reports'): ?>


                <!-- REPORTS DROPDOWN -->

                <div>

                    <button
                        type="button"
                        onclick="toggleReports()"
                        class="side-link w-full text-left flex items-center justify-between <?= $is_report_active ? 'active' : '' ?>">

                        <div class="flex items-center gap-3">

                            <span class="side-icon <?= $icon_colors[$m['color']] ?? '' ?>">

                                <i class="fas <?= e($m['icon']) ?>"></i>

                            </span>

                            <span>
                                <?= e($m['label']) ?>
                            </span>

                        </div>

                        <i
                            class="fas fa-chevron-down text-[10px] transition-transform duration-200"
                            id="reportChevron">
                        </i>

                    </button>


                    <!-- REPORT SUBMENU -->

                    <div
                        class="sub-menu <?= $is_report_active ? 'show' : '' ?>"
                        id="reportsSubmenu">


                        <?php foreach ($report_links as $rl): ?>


                            <?php

                            if (!isset($allowed_permissions[$rl['key']])) {
                                continue;
                            }

                            $rl_basename = basename($rl['link']);

                            $is_sub_active = ($current === $rl_basename);

                            ?>


                            <a
                                href="<?= BASE_URL . $rl['link'] ?>"
                                class="sub-link <?= $is_sub_active ? 'active' : '' ?>">

                                <?= e($rl['label']) ?>

                            </a>


                        <?php endforeach; ?>


                    </div>

                </div>


            <?php else: ?>


                <!-- NORMAL MENU LINKS -->

                <a
                    href="<?= BASE_URL . $m['link'] ?>"
                    class="side-link <?= $is_active ? 'active' : '' ?>">

                    <span class="side-icon <?= $icon_colors[$m['color']] ?? '' ?>">

                        <i class="fas <?= e($m['icon']) ?>"></i>

                    </span>

                    <span>
                        <?= e($m['label']) ?>
                    </span>

                </a>


            <?php endif; ?>


        <?php endforeach; ?>


    </nav>


    <!-- FOOTER -->

    <div class="px-4 py-3 border-t border-white/5 text-[10px] text-slate-500 flex items-center justify-between">

        <span>
            © <?= date('Y') ?> <?= e(SITE_NAME) ?>
        </span>

        <span class="flex items-center gap-1">

            <i class="fas fa-circle text-emerald-400/60 text-[5px]"></i>

            online

        </span>

    </div>


</aside>


<!-- MOBILE OVERLAY -->

<div
    onclick="document.getElementById('sidebar').classList.add('-translate-x-full')"
    class="md:hidden fixed inset-0 bg-black/50 z-20 hidden"
    id="sideOverlay">
</div>


<!-- JAVASCRIPT -->

<script>

    function toggleReports() {

        const submenu = document.getElementById('reportsSubmenu');

        const chevron = document.getElementById('reportChevron');

        if (!submenu || !chevron) {
            return;
        }

        if (submenu.classList.contains('show')) {

            submenu.classList.remove('show');

            chevron.style.transform = 'rotate(0deg)';

        } else {

            submenu.classList.add('show');

            chevron.style.transform = 'rotate(180deg)';

        }

    }


    window.addEventListener('DOMContentLoaded', function () {

        const submenu = document.getElementById('reportsSubmenu');

        const chevron = document.getElementById('reportChevron');

        if (submenu && chevron && submenu.classList.contains('show')) {

            chevron.style.transform = 'rotate(180deg)';

        }

    });

</script>
