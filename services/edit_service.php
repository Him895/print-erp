
<?php
// ============================================================
// FILE: services/edit_service.php
// MULTIPLE CUSTOMER ITEMS + MULTIPLE PRINTS
// Preserves existing payment history
// No product sale / No stock deduction
// ============================================================

require_once '../config/config.php';
require_permission('services');
$page_title = 'Edit Print Service';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('services_list.php');
}

// ------------------------------------------------------------
// FETCH SERVICE
// ------------------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$service) {
    set_flash('error', 'Service not found.');
    redirect('services_list.php');
}

// ------------------------------------------------------------
// FETCH CLIENTS
// ------------------------------------------------------------
$clients = $conn->query("
    SELECT id, client_name
    FROM clients
    WHERE is_active = 1
    ORDER BY client_name
")->fetch_all(MYSQLI_ASSOC);

// ------------------------------------------------------------
// FETCH SERVICE ITEMS
// ------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT *
    FROM service_items
    WHERE service_id = ?
    ORDER BY id ASC
");
$stmt->bind_param('i', $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Legacy service fallback if no item rows exist
if (empty($items)) {
    $items[] = [
        'item_name' => 'Customer Item',
        'qty' => (int)$service['qty'],
        'print_charge_per_pc' => (float)$service['print_charge_per_pc'],
        'print_type' => $service['print_type'] ?? '',
        'print_notes' => ''
    ];
}

$error = '';

// ------------------------------------------------------------
// HANDLE UPDATE
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $client_id = (int)($_POST['client_id'] ?? 0);
    $service_date = trim($_POST['service_date'] ?? '');
    $gst_percent = (float)($_POST['gst_percent'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $item_names = $_POST['item_name'] ?? [];
    $item_qtys = $_POST['item_qty'] ?? [];
    $item_rates = $_POST['item_rate'] ?? [];
    $item_types = $_POST['item_print_type'] ?? [];
    $item_notes = $_POST['item_notes'] ?? [];

    // Payment history is managed separately.
    // Never overwrite existing payment records from this form.
    $paid_amount = (float)$service['paid_amount'];

    $payment_mode = $service['payment_mode'];

    $new_items = [];
    $sub_total = 0;
    $total_qty = 0;

    if ($client_id <= 0) {
        $error = 'Client select karna zaroori hai.';
    } elseif ($service_date === '') {
        $error = 'Service date required hai.';
    } elseif ($gst_percent < 0 || $gst_percent > 100) {
        $error = 'Invalid GST percentage.';
    } else {

        // Build and validate item rows
        foreach ($item_names as $key => $item_name) {

            $item_name = trim($item_name);
            $qty = (int)($item_qtys[$key] ?? 0);
            $rate = (float)($item_rates[$key] ?? 0);
            $print_type = trim($item_types[$key] ?? '');
            $print_note = trim($item_notes[$key] ?? '');

            // Ignore completely empty rows
            if ($item_name === '' && $qty <= 0 && $rate <= 0) {
                continue;
            }

            if ($item_name === '') {
                $error = 'Har item ka naam enter karo.';
                break;
            }

            if ($qty <= 0 || $rate < 0) {
                $error = 'Item quantity 0 se zyada aur rate valid hona chahiye.';
                break;
            }

            $line_total = $qty * $rate;

            $new_items[] = [
                'item_name' => $item_name,
                'qty' => $qty,
                'rate' => $rate,
                'print_type' => $print_type,
                'print_notes' => $print_note,
                'line_total' => $line_total
            ];

            $sub_total += $line_total;
            $total_qty += $qty;
        }

        if (!$error && empty($new_items)) {
            $error = 'Kam se kam ek item add karo.';
        }

        if (!$error) {

            $gst_amount = ($sub_total * $gst_percent) / 100;
            $total_amount = $sub_total + $gst_amount;

            // Keep previous payment total unchanged.
            $due_amount = max(0, $total_amount - $paid_amount);

            // Prevent editing total below already received amount.
            if ($total_amount < $paid_amount) {
                $error = 'Grand Total received payment se kam nahi ho sakta.';
            }
        }

        if (!$error) {

            $conn->begin_transaction();

            try {

                // ------------------------------------------------
                // UPDATE SERVICE MASTER
                // ------------------------------------------------

                $average_rate = $total_qty > 0
                    ? $sub_total / $total_qty
                    : 0;

                $print_types = array_unique(array_filter(
                    array_column($new_items, 'print_type')
                ));

                $main_print_type = count($print_types) > 1
                    ? 'Multiple'
                    : ($print_types[0] ?? '');

                $stmt = $conn->prepare("
                    UPDATE services SET
                        client_id = ?,
                        print_type = ?,
                        qty = ?,
                        print_charge_per_pc = ?,
                        sub_total = ?,
                        gst_percent = ?,
                        gst_amount = ?,
                        total_amount = ?,
                        due_amount = ?,
                        notes = ?,
                        service_date = ?
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new Exception('Service update prepare failed: ' . $conn->error);
                }

                $stmt->bind_param(
                    'isiddddddssi',
                    $client_id,
                    $main_print_type,
                    $total_qty,
                    $average_rate,
                    $sub_total,
                    $gst_percent,
                    $gst_amount,
                    $total_amount,
                    $due_amount,
                    $notes,
                    $service_date,
                    $id
                );

                if (!$stmt->execute()) {
                    throw new Exception('Service update failed: ' . $stmt->error);
                }

                $stmt->close();

                // ------------------------------------------------
                // REPLACE SERVICE ITEMS
                // ------------------------------------------------

                $deleteStmt = $conn->prepare("
                    DELETE FROM service_items
                    WHERE service_id = ?
                ");

                $deleteStmt->bind_param('i', $id);

                if (!$deleteStmt->execute()) {
                    throw new Exception('Unable to update service items.');
                }

                $deleteStmt->close();

                $insertStmt = $conn->prepare("
                    INSERT INTO service_items (
                        service_id,
                        item_name,
                        qty,
                        print_charge_per_pc,
                        print_charge_total,
                        print_type,
                        print_notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$insertStmt) {
                    throw new Exception('Item insert prepare failed: ' . $conn->error);
                }

                foreach ($new_items as $item) {

                    $item_name = $item['item_name'];
                    $qty = $item['qty'];
                    $rate = $item['rate'];
                    $line_total = $item['line_total'];
                    $print_type = $item['print_type'];
                    $print_note = $item['print_notes'];

                    $insertStmt->bind_param(
                        'isiddss',
                        $id,
                        $item_name,
                        $qty,
                        $rate,
                        $line_total,
                        $print_type,
                        $print_note
                    );

                    if (!$insertStmt->execute()) {
                        throw new Exception('Item insert failed: ' . $insertStmt->error);
                    }
                }

                $insertStmt->close();

                // IMPORTANT:
                // Existing payments are not deleted or recreated.
                // paid_amount and payment_mode remain unchanged.

                $conn->commit();

                set_flash('success', 'Print service updated successfully!');
                redirect('view_service.php?id=' . $id);

            } catch (Exception $e) {

                $conn->rollback();
                $error = 'DB Error: ' . $e->getMessage();
            }
        }
    }

    // Refresh form values after validation error
    $service['client_id'] = $client_id;
    $service['service_date'] = $service_date;
    $service['gst_percent'] = $gst_percent;
    $service['notes'] = $notes;

    if (!empty($new_items)) {
        $items = [];

        foreach ($new_items as $item) {
            $items[] = [
                'item_name' => $item['item_name'],
                'qty' => $item['qty'],
                'print_charge_per_pc' => $item['rate'],
                'print_type' => $item['print_type'],
                'print_notes' => $item['print_notes']
            ];
        }
    }
}

include '../includes/header.php';
?>

<style>
    .edit-input {
        width: 100%;
        background: rgba(255,255,255,.045);
        border: 1px solid rgba(255,255,255,.10);
        border-radius: 10px;
        padding: 10px 12px;
        color: #e2e8f0;
        font-size: 13px;
        outline: none;
        transition: .2s;
    }

    .edit-input:focus {
        border-color: rgba(99,102,241,.65);
        box-shadow: 0 0 0 3px rgba(99,102,241,.10);
    }

    .edit-input option {
        background: #1e293b;
        color: white;
    }

    .edit-label {
        display: block;
        color: #94a3b8;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .item-card {
        background: rgba(255,255,255,.025);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 14px;
        padding: 15px;
    }
</style>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-white">Edit Print Service</h1>
        <p class="text-xs text-slate-400 mt-1">
            Service #<?= e($service['service_no']) ?>
        </p>
    </div>

    <a href="view_service.php?id=<?= $id ?>"
       class="px-4 py-2 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-5 rounded-xl p-4 text-sm bg-rose-500/10 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" id="serviceForm" class="max-w-5xl">

    <div class="glass-dark rounded-2xl p-5 mb-5">

        <h2 class="text-sm font-semibold text-white mb-4">
            <i class="fas fa-user-circle text-indigo-400 mr-2"></i>
            Customer & Service Details
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

            <div>
                <label class="edit-label">Customer *</label>
                <select name="client_id" required class="edit-input">
                    <option value="">Select Customer</option>

                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= (int)$service['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['client_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="edit-label">Service Date *</label>
                <input type="date"
                       name="service_date"
                       required
                       value="<?= e($service['service_date']) ?>"
                       class="edit-input">
            </div>

            <div>
                <label class="edit-label">Service Number</label>
                <input type="text"
                       readonly
                       value="<?= e($service['service_no']) ?>"
                       class="edit-input opacity-60">
            </div>

        </div>
    </div>

    <!-- CUSTOMER ITEMS -->

    <div class="glass-dark rounded-2xl p-5 mb-5">

        <div class="flex items-center justify-between gap-3 mb-5">
            <div>
                <h2 class="text-sm font-semibold text-white">
                    <i class="fas fa-tshirt text-indigo-400 mr-2"></i>
                    Customer Items & Print Details
                </h2>
                <p class="text-xs text-slate-500 mt-1">
                    Edit existing items or add new items
                </p>
            </div>

            <button type="button"
                    onclick="addItem()"
                    class="px-3 py-2 rounded-lg bg-indigo-500/15 border border-indigo-500/30 text-indigo-300 text-xs hover:bg-indigo-500/25">
                <i class="fas fa-plus mr-1"></i> Add Item
            </button>
        </div>

        <div id="itemsContainer" class="space-y-4">

            <?php foreach ($items as $index => $item): ?>

                <div class="item-card service-item">

                    <div class="flex justify-between items-center mb-4">
                        <span class="text-xs font-semibold text-indigo-300 item-heading">
                            Item <?= $index + 1 ?>
                        </span>

                        <button type="button"
                                onclick="removeItem(this)"
                                class="text-xs text-rose-400 hover:text-rose-300">
                            <i class="fas fa-trash mr-1"></i> Remove
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">

                        <div>
                            <label class="edit-label">Item Name *</label>
                            <input type="text"
                                   name="item_name[]"
                                   required
                                   value="<?= e($item['item_name'] ?? '') ?>"
                                   placeholder="e.g. Customer T-Shirt"
                                   class="edit-input">
                        </div>

                        <div>
                            <label class="edit-label">Print Type</label>
                            <input type="text"
                                   name="item_print_type[]"
                                   value="<?= e($item['print_type'] ?? '') ?>"
                                   placeholder="Logo / Text / Full Print"
                                   class="edit-input">
                        </div>

                        <div>
                            <label class="edit-label">Quantity *</label>
                            <input type="number"
                                   name="item_qty[]"
                                   min="1"
                                   step="1"
                                   required
                                   value="<?= (int)$item['qty'] ?>"
                                   oninput="calculateTotal()"
                                   class="edit-input item-qty">
                        </div>

                        <div>
                            <label class="edit-label">Print Rate Per Piece (₹) *</label>
                            <input type="number"
                                   name="item_rate[]"
                                   min="0"
                                   step="0.01"
                                   required
                                   value="<?= e($item['print_charge_per_pc']) ?>"
                                   oninput="calculateTotal()"
                                   class="edit-input item-rate">
                        </div>

                        <div class="md:col-span-2">
                            <label class="edit-label">Print Notes / Instructions</label>
                            <input type="text"
                                   name="item_notes[]"
                                   value="<?= e($item['print_notes'] ?? '') ?>"
                                   placeholder="Enter print instructions..."
                                   class="edit-input">
                        </div>

                    </div>

                    <div class="flex justify-between border-t border-white/5 pt-3">
                        <span class="text-xs text-slate-400">Item Total</span>
                        <strong class="text-sm text-emerald-400 item-total">₹0.00</strong>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>
    </div>

    <!-- GST AND PAYMENT -->

    <div class="glass-dark rounded-2xl p-5 mb-5">

        <h2 class="text-sm font-semibold text-white mb-4">
            <i class="fas fa-calculator text-indigo-400 mr-2"></i>
            GST & Payment Summary
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

            <div>
                <label class="edit-label">GST Percentage</label>
                <select name="gst_percent"
                        id="gstPercent"
                        onchange="calculateTotal()"
                        class="edit-input">

                    <?php foreach ([0, 5, 12, 18, 28] as $gst): ?>
                        <option value="<?= $gst ?>"
                            <?= (float)$service['gst_percent'] === (float)$gst ? 'selected' : '' ?>>
                            <?= $gst ?>% GST
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div>
                <label class="edit-label">Already Paid (₹)</label>
                <input type="text"
                       readonly
                       value="<?= e(number_format((float)$service['paid_amount'], 2)) ?>"
                       class="edit-input text-emerald-300 opacity-70">
                <p class="text-[10px] text-slate-500 mt-1">
                    Payment history is managed separately.
                </p>
            </div>

            <div>
                <label class="edit-label">Due Amount (₹)</label>
                <input type="text"
                       id="dueAmount"
                       readonly
                       class="edit-input text-rose-300">
            </div>

        </div>

        <div class="mt-5 bg-white/5 border border-white/10 rounded-xl p-4 space-y-3">

            <div class="flex justify-between text-xs">
                <span class="text-slate-400">Total Quantity</span>
                <strong id="totalQty" class="text-white">0</strong>
            </div>

            <div class="flex justify-between text-xs">
                <span class="text-slate-400">Subtotal</span>
                <strong id="subTotalDisplay" class="text-white">₹0.00</strong>
            </div>

            <div class="flex justify-between text-xs">
                <span class="text-slate-400">GST Amount</span>
                <strong id="gstDisplay" class="text-indigo-300">₹0.00</strong>
            </div>

            <div class="border-t border-white/10 pt-3 flex justify-between items-center">
                <span class="text-sm font-semibold text-slate-200">Grand Total</span>
                <strong id="grandTotalDisplay" class="text-xl text-emerald-400">₹0.00</strong>
            </div>

        </div>

    </div>

    <!-- GENERAL NOTES -->

    <div class="glass-dark rounded-2xl p-5 mb-5">

        <label class="edit-label">Service Notes / General Instructions</label>

        <textarea name="notes"
                  rows="3"
                  placeholder="Enter general service instructions..."
                  class="edit-input"><?= e($service['notes'] ?? '') ?></textarea>

    </div>

    <!-- ACTIONS -->

    <div class="flex flex-wrap gap-3">
        <button type="submit"
                class="px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 transition">
            <i class="fas fa-save mr-2"></i>
            Update Service
        </button>

        <a href="view_service.php?id=<?= $id ?>"
           class="px-5 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10">
            Cancel
        </a>
    </div>

</form>

<script>
const existingPaid = <?= json_encode((float)$service['paid_amount']) ?>;

function formatMoney(amount) {
    return '₹' + Number(amount).toFixed(2);
}

function calculateTotal() {

    let subtotal = 0;
    let totalQty = 0;

    document.querySelectorAll('.service-item').forEach((item) => {

        const qty = parseFloat(item.querySelector('.item-qty').value) || 0;
        const rate = parseFloat(item.querySelector('.item-rate').value) || 0;

        const lineTotal = qty * rate;

        subtotal += lineTotal;
        totalQty += qty;

        item.querySelector('.item-total').textContent = formatMoney(lineTotal);
    });

    const gstPercent = parseFloat(document.getElementById('gstPercent').value) || 0;
    const gstAmount = subtotal * gstPercent / 100;
    const grandTotal = subtotal + gstAmount;
    const due = Math.max(0, grandTotal - existingPaid);

    document.getElementById('totalQty').textContent = totalQty;
    document.getElementById('subTotalDisplay').textContent = formatMoney(subtotal);
    document.getElementById('gstDisplay').textContent = formatMoney(gstAmount);
    document.getElementById('grandTotalDisplay').textContent = formatMoney(grandTotal);
    document.getElementById('dueAmount').value = formatMoney(due);
}

function addItem() {

    const container = document.getElementById('itemsContainer');

    const item = document.createElement('div');
    item.className = 'item-card service-item';

    item.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <span class="text-xs font-semibold text-indigo-300 item-heading">
                New Item
            </span>

            <button type="button"
                    onclick="removeItem(this)"
                    class="text-xs text-rose-400 hover:text-rose-300">
                <i class="fas fa-trash mr-1"></i> Remove
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">

            <div>
                <label class="edit-label">Item Name *</label>
                <input type="text"
                       name="item_name[]"
                       required
                       placeholder="e.g. Customer T-Shirt"
                       class="edit-input">
            </div>

            <div>
                <label class="edit-label">Print Type</label>
                <input type="text"
                       name="item_print_type[]"
                       placeholder="Logo / Text / Full Print"
                       class="edit-input">
            </div>

            <div>
                <label class="edit-label">Quantity *</label>
                <input type="number"
                       name="item_qty[]"
                       min="1"
                       step="1"
                       required
                       value="1"
                       oninput="calculateTotal()"
                       class="edit-input item-qty">
            </div>

            <div>
                <label class="edit-label">Print Rate Per Piece (₹) *</label>
                <input type="number"
                       name="item_rate[]"
                       min="0"
                       step="0.01"
                       required
                       value="0"
                       oninput="calculateTotal()"
                       class="edit-input item-rate">
            </div>

            <div class="md:col-span-2">
                <label class="edit-label">Print Notes / Instructions</label>
                <input type="text"
                       name="item_notes[]"
                       placeholder="Enter print instructions..."
                       class="edit-input">
            </div>

        </div>

        <div class="flex justify-between border-t border-white/5 pt-3">
            <span class="text-xs text-slate-400">Item Total</span>
            <strong class="text-sm text-emerald-400 item-total">₹0.00</strong>
        </div>
    `;

    container.appendChild(item);
    updateItemNumbers();
    calculateTotal();
}

function removeItem(button) {

    const items = document.querySelectorAll('.service-item');

    if (items.length <= 1) {
        alert('At least one item required hai.');
        return;
    }

    button.closest('.service-item').remove();

    updateItemNumbers();
    calculateTotal();
}

function updateItemNumbers() {

    document.querySelectorAll('.service-item').forEach((item, index) => {
        const heading = item.querySelector('.item-heading');

        if (heading) {
            heading.textContent = 'Item ' + (index + 1);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateItemNumbers();
    calculateTotal();
});
</script>

<?php include '../includes/footer.php'; ?>
