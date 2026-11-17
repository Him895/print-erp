
<?php
// ============================================================
// FILE: payments/add_payment.php
// COMMON DUE PAYMENT:
// Sale     -> Customer se payment receive
// Service  -> Customer se payment receive
// Purchase -> Vendor ko payment pay
//
// Examples:
// ?type=receivable&sale_id=12
// ?type=receivable&service_id=15
// ?type=payable&purchase_id=5
//
// All payments redirect to:
// view_payment_receipt.php?id=PAYMENT_ID
// ============================================================

require_once '../config/config.php';
require_permission('payments');
$page_title = 'Add Payment';
$error = '';

$type = $_GET['type'] ?? 'receivable';

$pre_sale_id     = (int)($_GET['sale_id'] ?? 0);
$pre_service_id  = (int)($_GET['service_id'] ?? 0);
$pre_purchase_id = (int)($_GET['purchase_id'] ?? 0);

$pre_ref_key = '';

if ($pre_sale_id > 0) {
    $type = 'receivable';
    $pre_ref_key = 'sale:' . $pre_sale_id;
} elseif ($pre_service_id > 0) {
    $type = 'receivable';
    $pre_ref_key = 'service:' . $pre_service_id;
} elseif ($pre_purchase_id > 0) {
    $type = 'payable';
    $pre_ref_key = 'purchase:' . $pre_purchase_id;
}

if (!in_array($type, ['receivable', 'payable'], true)) {
    $type = 'receivable';
}

$payment_modes = ['cash', 'upi', 'bank', 'card'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $type = $_POST['type'] ?? 'receivable';
    $ref_key = trim($_POST['ref_key'] ?? '');

    $amount = (float)($_POST['amount'] ?? 0);
    $payment_mode = $_POST['payment_mode'] ?? 'cash';
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $notes = trim($_POST['notes'] ?? '');

    $pre_ref_key = $ref_key;

    if (!in_array($type, ['receivable', 'payable'], true)) {
        $error = 'Invalid payment type.';
    } elseif (!preg_match('/^(sale|service|purchase):[0-9]+$/', $ref_key)) {
        $error = 'Please select a valid invoice.';
    } elseif (
        ($type === 'receivable' && !preg_match('/^(sale|service):[0-9]+$/', $ref_key)) ||
        ($type === 'payable' && !preg_match('/^purchase:[0-9]+$/', $ref_key))
    ) {
        $error = 'Selected invoice does not match payment type.';
    } elseif ($amount <= 0) {
        $error = 'Please enter a valid payment amount.';
    } elseif (!in_array($payment_mode, $payment_modes, true)) {
        $error = 'Invalid payment mode.';
    } elseif (
        !DateTime::createFromFormat('Y-m-d', $payment_date) ||
        DateTime::createFromFormat('Y-m-d', $payment_date)->format('Y-m-d') !== $payment_date
    ) {
        $error = 'Please select a valid payment date.';
    } else {

        [$ref_type, $ref_id] = explode(':', $ref_key);
        $ref_id = (int)$ref_id;

        $conn->begin_transaction();

        try {

            $payment_no = generate_no($conn, 'payments', 'payment_no', 'PAY');
            $payment_id = 0;

            // =================================================
            // SALE PAYMENT
            // =================================================
            if ($ref_type === 'sale' && $type === 'receivable') {

                $stmt = $conn->prepare("
                    SELECT *
                    FROM sales
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param('i', $ref_id);
                $stmt->execute();

                $ref = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$ref) {
                    throw new Exception('Sale invoice not found.');
                }

                $current_due = (float)$ref['due_amount'];

                if ($current_due <= 0) {
                    throw new Exception('This sale invoice is already fully paid.');
                }

                if ($amount > $current_due) {
                    throw new Exception('Amount exceeds remaining due amount.');
                }

                $client_id = (int)$ref['client_id'];

                $stmt = $conn->prepare("
                    INSERT INTO payments
                    (
                        payment_no,
                        ref_type,
                        ref_id,
                        client_id,
                        amount,
                        payment_mode,
                        payment_date,
                        status,
                        notes
                    )
                    VALUES (?, 'sale', ?, ?, ?, ?, ?, 'confirmed', ?)
                ");

                $stmt->bind_param(
                    'siidsss',
                    $payment_no,
                    $ref_id,
                    $client_id,
                    $amount,
                    $payment_mode,
                    $payment_date,
                    $notes
                );

                $stmt->execute();
                $payment_id = $stmt->insert_id;
                $stmt->close();

                $new_paid = (float)$ref['paid_amount'] + $amount;
                $new_due = max(0, $current_due - $amount);

                $new_status = ($new_due <= 0) ? 'completed' : 'pending';

                $upd = $conn->prepare("
                    UPDATE sales
                    SET paid_amount = ?, due_amount = ?, status = ?
                    WHERE id = ?
                ");

                $upd->bind_param(
                    'ddsi',
                    $new_paid,
                    $new_due,
                    $new_status,
                    $ref_id
                );

                $upd->execute();
                $upd->close();
            }

            // =================================================
            // DIRECT SERVICE PAYMENT
            // =================================================
            elseif ($ref_type === 'service' && $type === 'receivable') {

                $stmt = $conn->prepare("
                    SELECT *
                    FROM services
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param('i', $ref_id);
                $stmt->execute();

                $ref = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$ref) {
                    throw new Exception('Service record not found.');
                }

                $current_due = (float)$ref['due_amount'];

                if ($current_due <= 0) {
                    throw new Exception('This service is already fully paid.');
                }

                if ($amount > $current_due) {
                    throw new Exception('Amount exceeds remaining due amount.');
                }

                $client_id = (int)$ref['client_id'];

                $stmt = $conn->prepare("
                    INSERT INTO payments
                    (
                        payment_no,
                        ref_type,
                        ref_id,
                        client_id,
                        amount,
                        payment_mode,
                        payment_date,
                        status,
                        notes
                    )
                    VALUES (?, 'service', ?, ?, ?, ?, ?, 'confirmed', ?)
                ");

                $stmt->bind_param(
                    'siidsss',
                    $payment_no,
                    $ref_id,
                    $client_id,
                    $amount,
                    $payment_mode,
                    $payment_date,
                    $notes
                );

                $stmt->execute();
                $payment_id = $stmt->insert_id;
                $stmt->close();

                $new_paid = (float)$ref['paid_amount'] + $amount;
                $new_due = max(0, $current_due - $amount);

                $upd = $conn->prepare("
                    UPDATE services
                    SET paid_amount = ?, due_amount = ?
                    WHERE id = ?
                ");

                $upd->bind_param(
                    'ddi',
                    $new_paid,
                    $new_due,
                    $ref_id
                );

                $upd->execute();
                $upd->close();
            }

            // =================================================
            // PURCHASE PAYMENT
            // =================================================
            elseif ($ref_type === 'purchase' && $type === 'payable') {

                $stmt = $conn->prepare("
                    SELECT *
                    FROM product_purchase
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param('i', $ref_id);
                $stmt->execute();

                $ref = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$ref) {
                    throw new Exception('Purchase invoice not found.');
                }

                $current_due = (float)$ref['due_amount'];

                if ($current_due <= 0) {
                    throw new Exception('This purchase is already fully paid.');
                }

                if ($amount > $current_due) {
                    throw new Exception('Amount exceeds remaining due amount.');
                }

                $stmt = $conn->prepare("
                    INSERT INTO payments
                    (
                        payment_no,
                        ref_type,
                        ref_id,
                        client_id,
                        amount,
                        payment_mode,
                        payment_date,
                        status,
                        notes
                    )
                    VALUES (?, 'purchase', ?, NULL, ?, ?, ?, 'confirmed', ?)
                ");

                $stmt->bind_param(
                    'sidsss',
                    $payment_no,
                    $ref_id,
                    $amount,
                    $payment_mode,
                    $payment_date,
                    $notes
                );

                $stmt->execute();
                $payment_id = $stmt->insert_id;
                $stmt->close();

                $new_paid = (float)$ref['paid_amount'] + $amount;
                $new_due = max(0, $current_due - $amount);

                $upd = $conn->prepare("
                    UPDATE product_purchase
                    SET paid_amount = ?, due_amount = ?
                    WHERE id = ?
                ");

                $upd->bind_param(
                    'ddi',
                    $new_paid,
                    $new_due,
                    $ref_id
                );

                $upd->execute();
                $upd->close();
            } else {
                throw new Exception('Invalid payment reference.');
            }

            $conn->commit();

            set_flash('success', "Payment ($payment_no) recorded successfully!");

            // All payment types open the same receipt page.
            redirect('view_payment_receipt.php?id=' . $payment_id);

        } catch (Exception $e) {

            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// ============================================================
// FETCH PENDING INVOICES
// ============================================================

if ($type === 'receivable') {

    // Customer Sale + Direct Service
    $invoices = $conn->query("
        SELECT
            s.id,
            'sale' AS ref_type,
            s.sale_no AS no,
            s.due_amount,
            c.client_name AS name,
            'Sale' AS source_label
        FROM sales s
        LEFT JOIN clients c ON s.client_id = c.id
        WHERE s.due_amount > 0
          AND s.status <> 'cancelled'

        UNION ALL

        SELECT
            sv.id,
            'service' AS ref_type,
            sv.service_no AS no,
            sv.due_amount,
            c.client_name AS name,
            'Service' AS source_label
        FROM services sv
        LEFT JOIN clients c ON sv.client_id = c.id
        WHERE sv.due_amount > 0

        ORDER BY id DESC
    ")->fetch_all(MYSQLI_ASSOC);

} else {

    // Vendor Purchase
    $invoices = $conn->query("
        SELECT
            p.id,
            'purchase' AS ref_type,
            p.purchase_no AS no,
            p.due_amount,
            v.vendor_name AS name,
            'Purchase' AS source_label
        FROM product_purchase p
        LEFT JOIN vendors v ON p.vendor_id = v.id
        WHERE p.due_amount > 0
        ORDER BY p.id DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Add Payment</h1>
        <p class="text-xs text-slate-400 mt-0.5">
            Record customer or vendor due payment
        </p>
    </div>

    <a href="payments_list.php"
       class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<!-- PAYMENT TYPE -->
<div class="flex gap-2 mb-5 flex-wrap">

    <a href="?type=receivable"
       class="px-4 py-2 rounded-lg text-sm font-semibold transition <?= $type === 'receivable' ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' ?>">
        <i class="fas fa-arrow-down mr-1"></i>
        Receive (Customer)
    </a>

    <a href="?type=payable"
       class="px-4 py-2 rounded-lg text-sm font-semibold transition <?= $type === 'payable' ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' ?>">
        <i class="fas fa-arrow-up mr-1"></i>
        Pay (Vendor)
    </a>

</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-2xl">

    <input type="hidden" name="type" value="<?= e($type) ?>">

    <div class="space-y-4">

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                <?= $type === 'receivable' ? 'Select Customer Invoice / Service *' : 'Select Vendor Purchase *' ?>
            </label>

            <select
                name="ref_key"
                id="refSelect"
                required
                onchange="updateAmount(this)"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">

                <option value="">-- Select --</option>

                <?php foreach ($invoices as $inv): ?>
                    <?php $option_key = $inv['ref_type'] . ':' . $inv['id']; ?>

                    <option
                        value="<?= e($option_key) ?>"
                        data-due="<?= (float)$inv['due_amount'] ?>"
                        <?= $option_key === $pre_ref_key ? 'selected' : '' ?>>

                        <?= e($inv['source_label']) ?> -
                        <?= e($inv['no']) ?> -
                        <?= e($inv['name'] ?? 'Walk-in') ?>
                        (Due: <?= money($inv['due_amount']) ?>)

                    </option>
                <?php endforeach; ?>

            </select>

            <?php if (empty($invoices)): ?>
                <p class="text-[10px] text-rose-400 mt-1">
                    <i class="fas fa-info-circle"></i>
                    Koi pending <?= $type === 'receivable' ? 'customer invoice/service' : 'vendor purchase' ?> nahi hai.
                </p>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    Amount (₹) *
                </label>

                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="amount"
                    id="amountInput"
                    required
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white font-bold outline-none focus:border-indigo-500/50">

                <p class="text-[10px] text-slate-500 mt-1" id="dueHint"></p>
            </div>

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    Payment Mode
                </label>

                <select
                    name="payment_mode"
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">

                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank">Bank</option>
                    <option value="card">Card</option>

                </select>
            </div>

        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Payment Date
            </label>

            <input
                type="date"
                name="payment_date"
                required
                value="<?= e(date('Y-m-d')) ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Notes (Optional)
            </label>

            <input
                type="text"
                name="notes"
                maxlength="500"
                placeholder="e.g. Cash received from customer"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none">
        </div>

        <button
            type="submit"
            class="w-full py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold mt-4 <?= empty($invoices) ? 'opacity-50 cursor-not-allowed' : '' ?>"
            <?= empty($invoices) ? 'disabled' : '' ?>>

            <i class="fas fa-save mr-1"></i>
            Save Payment & Open Receipt

        </button>

    </div>
</form>

<script>
function updateAmount(select) {

    const opt = select.options[select.selectedIndex];
    const due = opt ? opt.getAttribute('data-due') : null;

    const input = document.getElementById('amountInput');
    const hint = document.getElementById('dueHint');

    if (due !== null && due !== '') {

        const dueAmount = parseFloat(due);

        input.value = dueAmount.toFixed(2);
        input.max = dueAmount.toFixed(2);

        hint.textContent = 'Maximum payable/receivable due: ₹' + dueAmount.toFixed(2);

    } else {

        input.value = '';
        input.removeAttribute('max');
        hint.textContent = '';
    }
}

window.addEventListener('DOMContentLoaded', function () {

    const select = document.getElementById('refSelect');

    if (select && select.value) {
        updateAmount(select);
    }
});
</script>

<style>
select {
    background-color: #1e293b !important;
    color: #fff !important;
}

select option {
    background-color: #1e293b !important;
    color: #fff !important;
}
</style>

<?php include '../includes/footer.php'; ?>
