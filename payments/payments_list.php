
<?php
// ============================================================
// FILE: payments/payments_list.php
// COMMON PAYMENTS LEDGER
// Sale + Direct Service + Purchase
//
// Features:
// 1. Actual payment rows show common payment receipts.
// 2. Legacy/initial payment rows do not show receipts.
// 3. No Sale Invoice button in this ledger.
// 4. Due buttons appear only on the latest payment row.
// 5. Current due appears only once per transaction.
// 6. Latest added payment appears first.
// 7. Avoid double counting payments.
// 8. Database pagination: 10 / 25 / 50 / 100.
// 9. Summary cards calculate totals across ALL entries.
// ============================================================

require_once '../config/config.php';
require_permission('payments');
$page_title = 'Payments';

// ============================================================
// PAGINATION SETTINGS
// ============================================================

$allowedPerPage = [10, 25, 50, 100];

$perPage = (int)($_GET['per_page'] ?? 10);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

$currentPage = max(1, (int)($_GET['page'] ?? 1));

// ============================================================
// BASE TRANSACTIONS QUERY
// ============================================================

$baseSql = "

    -- 1. SALES: OLD / INITIAL PAID AMOUNT

    SELECT
        CONCAT('SAL-', s.id) AS unique_id,
        s.sale_no AS ref_no,
        s.sale_date AS date,
        c.client_name AS party_name,
        'Receivable' AS type,

        GREATEST(
            s.paid_amount - COALESCE(r.rec, 0),
            0
        ) AS amount,

        CASE
            WHEN COALESCE(r.rec, 0) = 0 THEN s.due_amount
            ELSE NULL
        END AS due,

        s.payment_mode,
        s.status,
        'Sale' AS module,
        'sale' AS ref_type,
        s.id AS ref_id,
        0 AS payment_id

    FROM sales s

    LEFT JOIN clients c
        ON s.client_id = c.id

    LEFT JOIN (
        SELECT ref_id, SUM(amount) AS rec
        FROM payments
        WHERE ref_type = 'sale'
          AND status = 'confirmed'
        GROUP BY ref_id
    ) r ON r.ref_id = s.id

    WHERE s.status <> 'cancelled'
      AND (
          GREATEST(s.paid_amount - COALESCE(r.rec, 0), 0) > 0
          OR (s.due_amount > 0 AND r.rec IS NULL)
      )


    UNION ALL


    -- 2. PURCHASES: OLD / INITIAL PAID AMOUNT

    SELECT
        CONCAT('PUR-', p.id) AS unique_id,
        p.purchase_no AS ref_no,
        p.purchase_date AS date,
        v.vendor_name AS party_name,
        'Payable' AS type,

        GREATEST(
            p.paid_amount - COALESCE(r.rec, 0),
            0
        ) AS amount,

        CASE
            WHEN COALESCE(r.rec, 0) = 0 THEN p.due_amount
            ELSE NULL
        END AS due,

        p.payment_mode,
        'completed' AS status,
        'Purchase' AS module,
        'purchase' AS ref_type,
        p.id AS ref_id,
        0 AS payment_id

    FROM product_purchase p

    LEFT JOIN vendors v
        ON p.vendor_id = v.id

    LEFT JOIN (
        SELECT ref_id, SUM(amount) AS rec
        FROM payments
        WHERE ref_type = 'purchase'
          AND status = 'confirmed'
        GROUP BY ref_id
    ) r ON r.ref_id = p.id

    WHERE
        GREATEST(p.paid_amount - COALESCE(r.rec, 0), 0) > 0
        OR (p.due_amount > 0 AND r.rec IS NULL)


    UNION ALL


    -- 3. DIRECT SERVICES: OLD / INITIAL PAID AMOUNT

    SELECT
        CONCAT('SRV-', sv.id) AS unique_id,
        sv.service_no AS ref_no,
        sv.service_date AS date,
        c.client_name AS party_name,
        'Receivable' AS type,

        GREATEST(
            sv.paid_amount - COALESCE(r.rec, 0),
            0
        ) AS amount,

        CASE
            WHEN COALESCE(r.rec, 0) = 0 THEN sv.due_amount
            ELSE NULL
        END AS due,

        sv.payment_mode,
        sv.status,
        'Service' AS module,
        'service' AS ref_type,
        sv.id AS ref_id,
        0 AS payment_id

    FROM services sv

    LEFT JOIN clients c
        ON sv.client_id = c.id

    LEFT JOIN (
        SELECT ref_id, SUM(amount) AS rec
        FROM payments
        WHERE ref_type = 'service'
          AND status = 'confirmed'
        GROUP BY ref_id
    ) r ON r.ref_id = sv.id

    WHERE sv.sale_id IS NULL
      AND (
          GREATEST(sv.paid_amount - COALESCE(r.rec, 0), 0) > 0
          OR (sv.due_amount > 0 AND r.rec IS NULL)
      )


    UNION ALL


    -- 4. ACTUAL PAYMENT RECORDS

    SELECT
        CONCAT('PAY-', pay.id) AS unique_id,
        pay.payment_no AS ref_no,
        pay.payment_date AS date,

        COALESCE(
            c1.client_name,
            v.vendor_name,
            c2.client_name,
            'Walk-in'
        ) AS party_name,

        IF(
            pay.ref_type IN ('sale', 'service'),
            'Receivable',
            'Payable'
        ) AS type,

        pay.amount,

        CASE
            WHEN pay.id = latest_pay.latest_id THEN
                COALESCE(
                    s.due_amount,
                    sv.due_amount,
                    p.due_amount,
                    0
                )
            ELSE NULL
        END AS due,

        pay.payment_mode,
        pay.status,

        CASE
            WHEN pay.ref_type = 'sale' THEN 'Sale'
            WHEN pay.ref_type = 'service' THEN 'Service'
            WHEN pay.ref_type = 'purchase' THEN 'Purchase'
            ELSE 'Other'
        END AS module,

        pay.ref_type,
        pay.ref_id,
        pay.id AS payment_id

    FROM payments pay

    LEFT JOIN sales s
        ON pay.ref_type = 'sale'
        AND pay.ref_id = s.id

    LEFT JOIN clients c1
        ON s.client_id = c1.id

    LEFT JOIN services sv
        ON pay.ref_type = 'service'
        AND pay.ref_id = sv.id

    LEFT JOIN clients c2
        ON sv.client_id = c2.id

    LEFT JOIN product_purchase p
        ON pay.ref_type = 'purchase'
        AND pay.ref_id = p.id

    LEFT JOIN vendors v
        ON p.vendor_id = v.id

    LEFT JOIN (
        SELECT
            ref_type,
            ref_id,
            MAX(id) AS latest_id
        FROM payments
        WHERE status = 'confirmed'
        GROUP BY ref_type, ref_id
    ) latest_pay
        ON latest_pay.ref_type = pay.ref_type
        AND latest_pay.ref_id = pay.ref_id

    WHERE pay.status = 'confirmed'
      AND (
          pay.ref_type <> 'service'
          OR sv.sale_id IS NULL
      )
";

// ============================================================
// TOTAL ENTRIES COUNT
// ============================================================

$countSql = "
    SELECT COUNT(*) AS total
    FROM (
        $baseSql
    ) AS ledger_count
";

$countResult = $conn->query($countSql);

if (!$countResult) {
    die('Payments count failed: ' . e($conn->error));
}

$totalEntries = (int)($countResult->fetch_assoc()['total'] ?? 0);

// ============================================================
// CALCULATE TOTAL PAGES
// ============================================================

$totalPages = max(1, (int)ceil($totalEntries / $perPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

// ============================================================
// SUMMARY - ALL ENTRIES, NOT JUST CURRENT PAGE
// ============================================================

$summarySql = "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN type = 'Receivable' THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS total_receivable,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'Payable' THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS total_payable

    FROM (
        $baseSql
    ) AS ledger_summary
";

$summaryResult = $conn->query($summarySql);

if (!$summaryResult) {
    die('Payments summary failed: ' . e($conn->error));
}

$summary = $summaryResult->fetch_assoc();

$totalReceivable = (float)($summary['total_receivable'] ?? 0);
$totalPayable = (float)($summary['total_payable'] ?? 0);

// ============================================================
// CUSTOMER PENDING DUE
// Sales + Direct Services
// ============================================================

$saleDueResult = $conn->query("
    SELECT COALESCE(SUM(due_amount), 0) AS d
    FROM sales
    WHERE status <> 'cancelled'
");

$saleDue = (float)(
    $saleDueResult->fetch_assoc()['d'] ?? 0
);

$srvDueResult = $conn->query("
    SELECT COALESCE(SUM(due_amount), 0) AS d
    FROM services
    WHERE sale_id IS NULL
");

$srvDue = (float)(
    $srvDueResult->fetch_assoc()['d'] ?? 0
);

$totalPendingDue = $saleDue + $srvDue;

// ============================================================
// FETCH ONLY CURRENT PAGE ENTRIES
// ============================================================

$sql = "
    $baseSql
    ORDER BY payment_id DESC, date DESC, unique_id DESC
    LIMIT $perPage OFFSET $offset
";

$result = $conn->query($sql);

if (!$result) {
    die('Payments query failed: ' . e($conn->error));
}

$transactions = $result->fetch_all(MYSQLI_ASSOC);

// ============================================================
// PAGINATION RANGE
// ============================================================

$showingFrom = $totalEntries > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalEntries);

// Pagination URL helper
$paginationUrl = function ($page) use ($perPage) {
    return '?' . http_build_query([
        'page' => $page,
        'per_page' => $perPage
    ]);
};

include '../includes/header.php';
?>

<!-- ============================================================
     PAGE HEADER
============================================================ -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Payments Ledger
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            All customer receipts, vendor payments and outstanding balances
        </p>
    </div>

    <div class="flex gap-2 flex-wrap">

        <a href="add_payment.php?type=receivable"
           class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-sm font-semibold hover:bg-emerald-500/30 transition-all">

            <i class="fas fa-arrow-down"></i>
            Receive Payment

        </a>

        <a href="add_payment.php?type=payable"
           class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-300 text-sm font-semibold hover:bg-rose-500/30 transition-all">

            <i class="fas fa-arrow-up"></i>
            Make Payment

        </a>

    </div>

</div>

<!-- ============================================================
     SUMMARY CARDS
============================================================ -->

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">

    <div class="glass-dark rounded-xl p-4 border border-white/10">

        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Total Received
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-emerald-300 to-emerald-500 bg-clip-text text-transparent mt-1">
            <?= money($totalReceivable) ?>
        </div>

    </div>

    <div class="glass-dark rounded-xl p-4 border border-white/10">

        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Total Paid
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-rose-300 to-rose-500 bg-clip-text text-transparent mt-1">
            <?= money($totalPayable) ?>
        </div>

    </div>

    <div class="glass-dark rounded-xl p-4 border border-white/10">

        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Customer Outstanding Balance
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-amber-300 to-amber-500 bg-clip-text text-transparent mt-1">
            <?= money($totalPendingDue) ?>
        </div>

    </div>

</div>

<!-- ============================================================
     PAGINATION TOP / ENTRIES CONTROL
============================================================ -->

<div class="glass-dark rounded-xl border border-white/10 p-3 mb-4">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">

        <div class="text-xs text-slate-400">
            Showing
            <span class="text-white font-semibold">
                <?= $showingFrom ?>
            </span>
            to
            <span class="text-white font-semibold">
                <?= $showingTo ?>
            </span>
            of
            <span class="text-white font-semibold">
                <?= number_format($totalEntries) ?>
            </span>
            entries
        </div>

        <form method="GET" class="flex items-center gap-2">

            <input type="hidden" name="page" value="1">

            <label for="per_page" class="text-xs text-slate-400 whitespace-nowrap">
                Show Entries:
            </label>

            <select
                name="per_page"
                id="per_page"
                onchange="this.form.submit()"
                class="bg-slate-800 border border-white/10 text-white text-xs rounded-lg px-3 py-2 outline-none focus:border-indigo-400">

                <?php foreach ([10, 25, 50, 100] as $limit): ?>

                    <option
                        value="<?= $limit ?>"
                        <?= $perPage === $limit ? 'selected' : '' ?>
                        class="bg-slate-800 text-white">

                        <?= $limit ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </form>

    </div>

</div>

<!-- ============================================================
     TRANSACTIONS TABLE
============================================================ -->

<div class="glass-dark rounded-2xl overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-xs">

            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Date
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Reference No.
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Party
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Module
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Payment Mode
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-right">
                        Received
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-right">
                        Paid
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-right">
                        Outstanding Balance
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-center">
                        Actions
                    </th>

                </tr>
            </thead>

            <tbody>

                <?php if (!empty($transactions)): ?>

                    <?php foreach ($transactions as $t):

                        $pid = (int)$t['payment_id'];
                        $rid = (int)$t['ref_id'];

                        $hasDueRow = ($t['due'] !== null);
                        $due = $hasDueRow ? (float)$t['due'] : 0;

                        $amount = (float)$t['amount'];

                        $is_receivable = ($t['type'] === 'Receivable');

                    ?>

                        <tr class="border-b border-white/5 hover:bg-white/3 transition">

                            <!-- DATE -->

                            <td class="px-4 py-3 text-slate-300 whitespace-nowrap">
                                <?= fmt_date($t['date']) ?>
                            </td>

                            <!-- REFERENCE -->

                            <td class="px-4 py-3">

                                <span class="px-2 py-0.5 rounded bg-white/5 border border-white/10 text-[10px] font-mono text-slate-300">
                                    <?= e($t['ref_no']) ?>
                                </span>

                            </td>

                            <!-- PARTY -->

                            <td class="px-4 py-3 text-white font-medium">
                                <?= e($t['party_name'] ?? 'Walk-in') ?>
                            </td>

                            <!-- MODULE -->

                            <td class="px-4 py-3">

                                <?php if ($t['module'] === 'Sale'): ?>

                                    <span class="text-indigo-300">
                                        <i class="fas fa-file-invoice-dollar mr-1"></i>
                                        Sale
                                    </span>

                                <?php elseif ($t['module'] === 'Service'): ?>

                                    <span class="text-amber-400">
                                        <i class="fas fa-print mr-1"></i>
                                        Service
                                    </span>

                                <?php else: ?>

                                    <span class="text-rose-300">
                                        <i class="fas fa-shopping-cart mr-1"></i>
                                        Purchase
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- PAYMENT MODE -->

                            <td class="px-4 py-3 text-slate-400 uppercase text-[10px]">
                                <?= e($t['payment_mode'] ?? '-') ?>
                            </td>

                            <!-- RECEIVABLE -->

                            <td class="px-4 py-3 text-right">

                                <?php if ($is_receivable && $amount > 0): ?>

                                    <span class="text-emerald-300 font-bold text-sm">
                                        +<?= money($amount) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-slate-600">-</span>

                                <?php endif; ?>

                            </td>

                            <!-- PAYABLE -->

                            <td class="px-4 py-3 text-right">

                                <?php if (!$is_receivable && $amount > 0): ?>

                                    <span class="text-rose-300 font-bold text-sm">
                                        -<?= money($amount) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-slate-600">-</span>

                                <?php endif; ?>

                            </td>

                            <!-- DUE -->

                            <td class="px-4 py-3 text-right">

                                <?php if (!$hasDueRow): ?>

                                    <span class="text-slate-600">-</span>

                                <?php elseif ($due > 0): ?>

                                    <span class="text-amber-400 font-bold">
                                        <?= money($due) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-emerald-500 text-[10px]">
                                        <i class="fas fa-check-circle"></i>
                                        Cleared
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ACTIONS -->

                            <td class="px-4 py-3">

                                <div class="flex items-center justify-center gap-1.5">

                                    <!-- PAYMENT RECEIPT -->

                                    <?php if ($pid > 0): ?>

                                        <a href="view_payment_receipt.php?id=<?= $pid ?>"
                                           class="w-8 h-8 rounded-lg bg-emerald-500/15 text-emerald-300 inline-flex items-center justify-center hover:bg-emerald-500/30 transition"
                                           title="View Payment Receipt">

                                            <i class="fas fa-receipt text-xs"></i>

                                        </a>

                                    <?php endif; ?>


                                    <!-- SALE DUE -->

                                    <?php if (
                                        $hasDueRow &&
                                        $t['ref_type'] === 'sale' &&
                                        $due > 0
                                    ): ?>

                                        <a href="add_payment.php?type=receivable&sale_id=<?= $rid ?>"
                                           class="px-2 h-7 rounded-lg bg-amber-500/15 text-amber-300 inline-flex items-center justify-center text-[10px] font-semibold hover:bg-amber-500/30 transition"
                                           title="Receive Outstanding Payment">

                                            Receive Due

                                        </a>

                                    <?php endif; ?>


                                    <!-- DIRECT SERVICE DUE -->

                                    <?php if (
                                        $hasDueRow &&
                                        $t['ref_type'] === 'service' &&
                                        $due > 0
                                    ): ?>

                                        <a href="add_payment.php?type=receivable&service_id=<?= $rid ?>"
                                           class="px-2 h-7 rounded-lg bg-amber-500/15 text-amber-300 inline-flex items-center justify-center text-[10px] font-semibold hover:bg-amber-500/30 transition"
                                           title="Receive Service Payment">

                                            Receive Due

                                        </a>

                                    <?php endif; ?>


                                    <!-- PURCHASE DUE -->

                                    <?php if (
                                        $hasDueRow &&
                                        $t['ref_type'] === 'purchase' &&
                                        $due > 0
                                    ): ?>

                                        <a href="add_payment.php?type=payable&purchase_id=<?= $rid ?>"
                                           class="px-2 h-7 rounded-lg bg-rose-500/15 text-rose-300 inline-flex items-center justify-center text-[10px] font-semibold hover:bg-rose-500/30 transition"
                                           title="Pay Outstanding Vendor Balance">

                                            Pay Due

                                        </a>

                                    <?php endif; ?>


                                    <?php if ($pid <= 0 && (!$hasDueRow || $due <= 0)): ?>

                                        <span class="text-[10px] text-slate-600">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9">

                            <div class="text-center py-12 text-slate-500">

                                <i class="fas fa-money-bill-wave text-3xl block mb-2 opacity-30"></i>

                                <div class="text-sm">
                                    No transactions found
                                </div>

                            </div>

                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

    <!-- ========================================================
         BOTTOM PAGINATION
    ======================================================== -->

    <?php if ($totalEntries > 0): ?>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-white/10">

            <div class="text-xs text-slate-400">
                Page
                <span class="text-white font-semibold">
                    <?= $currentPage ?>
                </span>
                of
                <span class="text-white font-semibold">
                    <?= $totalPages ?>
                </span>
            </div>

            <div class="flex items-center flex-wrap justify-center gap-1">

                <!-- FIRST / PREVIOUS -->

                <?php if ($currentPage > 1): ?>

                    <a href="<?= e($paginationUrl(1)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center"
                       title="First Page">

                        <i class="fas fa-angle-double-left text-xs"></i>

                    </a>

                    <a href="<?= e($paginationUrl($currentPage - 1)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center">

                        <i class="fas fa-chevron-left text-xs"></i>

                    </a>

                <?php else: ?>

                    <span class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/5 text-slate-600 inline-flex items-center justify-center opacity-50">

                        <i class="fas fa-angle-double-left text-xs"></i>

                    </span>

                    <span class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/5 text-slate-600 inline-flex items-center justify-center opacity-50">

                        <i class="fas fa-chevron-left text-xs"></i>

                    </span>

                <?php endif; ?>


                <!-- PAGE NUMBERS -->

                <?php

                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);

                if ($startPage > 1):
                ?>

                    <a href="<?= e($paginationUrl(1)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center text-xs">

                        1

                    </a>

                    <?php if ($startPage > 2): ?>

                        <span class="px-1 text-slate-500">...</span>

                    <?php endif; ?>

                <?php endif; ?>


                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                    <?php if ($p === $currentPage): ?>

                        <span class="min-w-8 h-8 px-2 rounded-lg bg-indigo-500 text-white font-bold inline-flex items-center justify-center text-xs">

                            <?= $p ?>

                        </span>

                    <?php else: ?>

                        <a href="<?= e($paginationUrl($p)) ?>"
                           class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center text-xs">

                            <?= $p ?>

                        </a>

                    <?php endif; ?>

                <?php endfor; ?>


                <?php if ($endPage < $totalPages): ?>

                    <?php if ($endPage < $totalPages - 1): ?>

                        <span class="px-1 text-slate-500">...</span>

                    <?php endif; ?>

                    <a href="<?= e($paginationUrl($totalPages)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center text-xs">

                        <?= $totalPages ?>

                    </a>

                <?php endif; ?>


                <!-- NEXT / LAST -->

                <?php if ($currentPage < $totalPages): ?>

                    <a href="<?= e($paginationUrl($currentPage + 1)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center">

                        <i class="fas fa-chevron-right text-xs"></i>

                    </a>

                    <a href="<?= e($paginationUrl($totalPages)) ?>"
                       class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 inline-flex items-center justify-center"
                       title="Last Page">

                        <i class="fas fa-angle-double-right text-xs"></i>

                    </a>

                <?php else: ?>

                    <span class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/5 text-slate-600 inline-flex items-center justify-center opacity-50">

                        <i class="fas fa-chevron-right text-xs"></i>

                    </span>

                    <span class="min-w-8 h-8 px-2 rounded-lg bg-white/5 border border-white/5 text-slate-600 inline-flex items-center justify-center opacity-50">

                        <i class="fas fa-angle-double-right text-xs"></i>

                    </span>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>
