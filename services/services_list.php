
<?php
// ============================================================
// FILE: services/services_list.php
// PRINT SERVICES MANAGEMENT
// Receipt: Available before and after completion
// Invoice: Available only after completed / delivered
// PAGINATION ADDED
// ============================================================

require_once '../config/config.php';
require_permission('services');
$page_title = 'Services / Print Jobs';

// ------------------------------------------------------------
// PAGINATION SETTINGS
// ------------------------------------------------------------

$allowedPerPage = [10, 25, 50, 100];

$perPage = (int)($_GET['per_page'] ?? 10);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$page = max(1, (int)($_GET['page'] ?? 1));

// ------------------------------------------------------------
// SUMMARY STATS - ALL SERVICES
// ------------------------------------------------------------

$statsSql = "
    SELECT
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_jobs,
        SUM(CASE WHEN status IN ('completed', 'delivered') THEN 1 ELSE 0 END) AS completed_jobs
    FROM services
";

$statsResult = $conn->query($statsSql);

if (!$statsResult) {
    die('Unable to fetch service statistics: ' . $conn->error);
}

$stats = $statsResult->fetch_assoc();

$totalJobs = (int)($stats['total_jobs'] ?? 0);
$pendingJobs = (int)($stats['pending_jobs'] ?? 0);
$inProgressJobs = (int)($stats['in_progress_jobs'] ?? 0);
$completedJobs = (int)($stats['completed_jobs'] ?? 0);

// ------------------------------------------------------------
// PAGINATION CALCULATION
// ------------------------------------------------------------

$totalPages = max(1, (int)ceil($totalJobs / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

// ------------------------------------------------------------
// FETCH CURRENT PAGE SERVICES
// ------------------------------------------------------------

$sql = "
    SELECT
        sv.*,
        c.client_name,
        s.sale_no
    FROM services sv
    LEFT JOIN clients c ON sv.client_id = c.id
    LEFT JOIN sales s ON sv.sale_id = s.id
    ORDER BY sv.created_at DESC, sv.id DESC
    LIMIT $perPage OFFSET $offset
";

$result = $conn->query($sql);

if (!$result) {
    die('Unable to fetch services: ' . $conn->error);
}

$services = $result->fetch_all(MYSQLI_ASSOC);

// ------------------------------------------------------------
// SHOWING RECORD RANGE
// ------------------------------------------------------------

$showingFrom = $totalJobs > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalJobs);

// Pagination URL
$paginationBase = 'services_list.php?per_page=' . $perPage . '&page=';

include '../includes/header.php';
?>

<!-- PAGE HEADER -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Print Services
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            Manage all printing jobs
        </p>
    </div>

    <a href="add_service.php"
       class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">

        <i class="fas fa-plus-circle"></i>
        Add Print Job

    </a>

</div>

<!-- SUMMARY STATS -->

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

    <div class="glass-dark rounded-xl p-4 border border-white/10">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Total Jobs
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-blue-300 to-blue-500 bg-clip-text text-transparent mt-1">
            <?= $totalJobs ?>
        </div>
    </div>

    <div class="glass-dark rounded-xl p-4 border border-white/10">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Pending
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-amber-300 to-amber-500 bg-clip-text text-transparent mt-1">
            <?= $pendingJobs ?>
        </div>
    </div>

    <div class="glass-dark rounded-xl p-4 border border-white/10">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            In Progress
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-indigo-300 to-indigo-500 bg-clip-text text-transparent mt-1">
            <?= $inProgressJobs ?>
        </div>
    </div>

    <div class="glass-dark rounded-xl p-4 border border-white/10">
        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Completed
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-emerald-300 to-emerald-500 bg-clip-text text-transparent mt-1">
            <?= $completedJobs ?>
        </div>
    </div>

</div>

<!-- SERVICES TABLE -->

<div class="glass-dark rounded-2xl overflow-hidden">

    <!-- TABLE TOP BAR -->

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 border-b border-white/10">

        <div class="text-xs text-slate-400">
            Showing
            <span class="text-white font-semibold"><?= $showingFrom ?></span>
            to
            <span class="text-white font-semibold"><?= $showingTo ?></span>
            of
            <span class="text-white font-semibold"><?= $totalJobs ?></span>
            services
        </div>

        <form method="GET" action="services_list.php"
              class="flex items-center gap-2">

            <label for="per_page" class="text-xs text-slate-400">
                Show entries:
            </label>

            <select
                name="per_page"
                id="per_page"
                onchange="this.form.submit()"
                class="bg-slate-800 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-slate-200 outline-none focus:border-indigo-500/50"
            >
                <?php foreach ($allowedPerPage as $limit): ?>
                    <option value="<?= $limit ?>"
                        <?= $perPage === $limit ? 'selected' : '' ?>>
                        <?= $limit ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="hidden" name="page" value="1">

        </form>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-xs">

            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">

                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Service No
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Client
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Source
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-right">
                        Amount
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-center">
                        Status
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Date
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-center">
                        Action
                    </th>

                </tr>
            </thead>

            <tbody>

            <?php if (!empty($services)): ?>

                <?php foreach ($services as $i => $srv):

                    // ----------------------------------------
                    // STATUS BADGE
                    // ----------------------------------------

                    $status = $srv['status'] ?? 'pending';

                    $statusClass = 'bg-amber-500/15 text-amber-300';

                    if ($status === 'in_progress') {
                        $statusClass = 'bg-indigo-500/15 text-indigo-300';
                    } elseif ($status === 'completed') {
                        $statusClass = 'bg-emerald-500/15 text-emerald-300';
                    } elseif ($status === 'delivered') {
                        $statusClass = 'bg-teal-500/15 text-teal-300';
                    }

                    // ----------------------------------------
                    // INVOICE PERMISSION
                    // ----------------------------------------

                    $canPrintInvoice = in_array(
                        $status,
                        ['completed', 'delivered'],
                        true
                    );

                    // ----------------------------------------
                    // SOURCE
                    // ----------------------------------------

                    $hasSale = !empty($srv['sale_id']) && !empty($srv['sale_no']);

                ?>

                    <tr class="border-b border-white/5 hover:bg-white/3 transition">

                        <!-- SERIAL NUMBER -->

                        <td class="px-4 py-3 text-slate-500">
                            <?= $offset + $i + 1 ?>
                        </td>

                        <!-- SERVICE NUMBER -->

                        <td class="px-4 py-3 text-indigo-300 font-medium">
                            <?= e($srv['service_no']) ?>
                        </td>

                        <!-- CLIENT -->

                        <td class="px-4 py-3 text-white">
                            <?= e($srv['client_name'] ?? 'Walk-in') ?>
                        </td>

                        <!-- SOURCE -->

                        <td class="px-4 py-3">

                            <?php if ($hasSale): ?>

                                <a href="../sales/view_sale_invoice.php?id=<?= (int)$srv['sale_id'] ?>"
                                   class="text-[10px] text-indigo-400 hover:text-indigo-300 underline">

                                    <i class="fas fa-shopping-bag mr-1"></i>
                                    <?= e($srv['sale_no']) ?>

                                </a>

                            <?php else: ?>

                                <span class="text-[10px] text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded">
                                    Direct Job
                                </span>

                            <?php endif; ?>

                        </td>

                        <!-- AMOUNT -->

                        <td class="px-4 py-3 text-right text-emerald-300 font-semibold">
                            <?= money($srv['total_amount']) ?>
                        </td>

                        <!-- STATUS -->

                        <td class="px-4 py-3 text-center">

                            <span class="px-2 py-0.5 rounded-full <?= $statusClass ?> capitalize text-[10px]">
                                <?= e(str_replace('_', ' ', $status)) ?>
                            </span>

                        </td>

                        <!-- DATE -->

                        <td class="px-4 py-3 text-slate-400">
                            <?= fmt_date($srv['service_date']) ?>
                        </td>

                        <!-- ACTIONS -->

                        <td class="px-4 py-3 text-center">

                            <div class="flex items-center justify-center gap-1">

                                <!-- VIEW DETAILS -->

                                <a href="view_service.php?id=<?= (int)$srv['id'] ?>"
                                   class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 inline-flex items-center justify-center hover:bg-indigo-500/30 transition"
                                   title="View Details">

                                    <i class="fas fa-eye text-[10px]"></i>

                                </a>

                                <!-- EDIT SERVICE -->

                                <a href="edit_service.php?id=<?= (int)$srv['id'] ?>"
                                   class="w-7 h-7 rounded-lg bg-amber-500/15 text-amber-300 inline-flex items-center justify-center hover:bg-amber-500/30 transition"
                                   title="Edit Service">

                                    <i class="fas fa-edit text-[10px]"></i>

                                </a>

                                <!-- PAYMENT RECEIPT: ALL STATUSES -->

                                <a href="view_service_receipt.php?service_id=<?= (int)$srv['id'] ?>"
                                   class="w-7 h-7 rounded-lg bg-teal-500/15 text-teal-300 inline-flex items-center justify-center hover:bg-teal-500/30 transition"
                                   title="Print Payment Receipt">

                                    <i class="fas fa-receipt text-[10px]"></i>

                                </a>

                                <!-- SERVICE INVOICE: COMPLETED / DELIVERED ONLY -->

                                <?php if ($canPrintInvoice): ?>

                                    <a href="view_service_invoice.php?id=<?= (int)$srv['id'] ?>"
                                       class="w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-300 inline-flex items-center justify-center hover:bg-emerald-500/30 transition"
                                       title="Print Service Invoice">

                                        <i class="fas fa-file-invoice text-[10px]"></i>

                                    </a>

                                <?php else: ?>

                                    <span class="w-7 h-7 rounded-lg bg-white/5 text-slate-600 inline-flex items-center justify-center cursor-not-allowed"
                                          title="Invoice available after service completion">

                                        <i class="fas fa-lock text-[10px]"></i>

                                    </span>

                                <?php endif; ?>

                                <!-- DELETE SERVICE -->

                                <a href="delete_service.php?id=<?= (int)$srv['id'] ?>"
                                   onclick="return confirm('Are you sure you want to delete this service job? This will also remove any linked payments.')"
                                   class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 inline-flex items-center justify-center hover:bg-rose-500/30 transition"
                                   title="Delete Service">

                                    <i class="fas fa-trash text-[10px]"></i>

                                </a>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td colspan="8">

                        <div class="text-center py-12 text-slate-500">

                            <i class="fas fa-print text-3xl block mb-2 opacity-30"></i>

                            <div class="text-sm">
                                No print jobs yet
                            </div>

                            <p class="text-[10px] mt-1 text-slate-600">
                                Click "Add Print Job" to create a direct service, or generate via Sales.
                            </p>

                        </div>

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <!-- PAGINATION FOOTER -->

    <?php if ($totalJobs > 0): ?>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-white/10">

            <div class="text-xs text-slate-400">
                Page
                <span class="text-white font-semibold"><?= $page ?></span>
                of
                <span class="text-white font-semibold"><?= $totalPages ?></span>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-1">

                <!-- FIRST PAGE -->

                <?php if ($page > 1): ?>

                    <a href="<?= e($paginationBase . '1') ?>"
                       class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
                        First
                    </a>

                    <a href="<?= e($paginationBase . ($page - 1)) ?>"
                       class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
                        <i class="fas fa-chevron-left"></i>
                        Prev
                    </a>

                <?php else: ?>

                    <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-slate-600 text-xs cursor-not-allowed">
                        First
                    </span>

                    <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-slate-600 text-xs cursor-not-allowed">
                        <i class="fas fa-chevron-left"></i>
                        Prev
                    </span>

                <?php endif; ?>

                <!-- PAGE NUMBERS -->

                <?php

                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                if ($startPage > 1):
                ?>

                    <a href="<?= e($paginationBase . '1') ?>"
                       class="min-w-8 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs text-center hover:bg-white/10 transition">
                        1
                    </a>

                    <?php if ($startPage > 2): ?>
                        <span class="px-1 text-slate-500 text-xs">...</span>
                    <?php endif; ?>

                <?php endif; ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                    <?php if ($p === $page): ?>

                        <span class="min-w-8 px-3 py-1.5 rounded-lg bg-indigo-500 text-white text-xs font-semibold text-center">
                            <?= $p ?>
                        </span>

                    <?php else: ?>

                        <a href="<?= e($paginationBase . $p) ?>"
                           class="min-w-8 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs text-center hover:bg-white/10 transition">
                            <?= $p ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>

                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="px-1 text-slate-500 text-xs">...</span>
                    <?php endif; ?>

                    <a href="<?= e($paginationBase . $totalPages) ?>"
                       class="min-w-8 px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs text-center hover:bg-white/10 transition">
                        <?= $totalPages ?>
                    </a>

                <?php endif; ?>

                <!-- NEXT PAGE -->

                <?php if ($page < $totalPages): ?>

                    <a href="<?= e($paginationBase . ($page + 1)) ?>"
                       class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
                        Next
                        <i class="fas fa-chevron-right ml-1"></i>
                    </a>

                    <a href="<?= e($paginationBase . $totalPages) ?>"
                       class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
                        Last
                    </a>

                <?php else: ?>

                    <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-slate-600 text-xs cursor-not-allowed">
                        Next
                        <i class="fas fa-chevron-right ml-1"></i>
                    </span>

                    <span class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-slate-600 text-xs cursor-not-allowed">
                        Last
                    </span>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>
