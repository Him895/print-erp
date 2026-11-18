
<?php
// ============================================================
// FILE: services/add_service.php
// CUSTOMER-OWNED ITEMS - MULTIPLE ITEMS + MULTIPLE PRINTS
// No product sale and no stock deduction
// GST + Initial Payment
// ============================================================

require_once '../config/config.php';
require_permission('services');
$page_title = 'Add Print Service';
$error = '';

$allowed_gst = ['0' => 0, '5' => 5, '12' => 12, '18' => 18, '28' => 28];
$allowed_modes = ['cash', 'upi', 'bank', 'card'];

// Default form values
$old_items = [];
$old_client_id = 0;
$old_service_date = date('Y-m-d');
$old_gst = '18';
$old_paid_amount = '0';
$old_payment_mode = 'cash';
$old_notes = '';

$customers = $conn->query("
    SELECT id, customer_name, phone
    FROM customers
    WHERE is_active = 1
    ORDER BY customer_name
")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// FORM SUBMISSION
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $client_raw = $_POST['client_id'] ?? 0;
    $old_client_id = filter_var($client_raw, FILTER_VALIDATE_INT) ?: 0;

    $date_raw = $_POST['service_date'] ?? '';
    $old_service_date = is_scalar($date_raw) ? trim((string)$date_raw) : '';

    $gst_raw = $_POST['gst_percent'] ?? '18';
    $old_gst = is_scalar($gst_raw) ? trim((string)$gst_raw) : '';

    $gst_percent = array_key_exists($old_gst, $allowed_gst)
        ? $allowed_gst[$old_gst]
        : -1;

    $paid_raw = $_POST['paid_amount'] ?? '0';
    $old_paid_amount = is_scalar($paid_raw) ? trim((string)$paid_raw) : '';

    $paid_is_numeric = (
        $old_paid_amount !== '' &&
        is_numeric($old_paid_amount)
    );

    $paid_amount = $paid_is_numeric ? (float)$old_paid_amount : -1;

    $mode_raw = $_POST['payment_mode'] ?? 'cash';
    $old_payment_mode = is_scalar($mode_raw)
        ? trim((string)$mode_raw)
        : '';

    $notes_raw = $_POST['notes'] ?? '';
    $old_notes = is_scalar($notes_raw)
        ? trim((string)$notes_raw)
        : '';

    $items_in = $_POST['items'] ?? [];
    $old_items = is_array($items_in) ? $items_in : [];

    $items_to_save = [];
    $all_print_types = [];
    $combined_notes = '';

    $total_qty = 0;
    $print_total = 0;

    // --------------------------------------------------------
    // DATE VALIDATION
    // --------------------------------------------------------

    $date_object = DateTime::createFromFormat('!Y-m-d', $old_service_date);
    $date_errors = DateTime::getLastErrors();

    $valid_date = (
        $date_object instanceof DateTime &&
        $date_object->format('Y-m-d') === $old_service_date &&
        (
            $date_errors === false ||
            (
                $date_errors['warning_count'] === 0 &&
                $date_errors['error_count'] === 0
            )
        )
    );

    // --------------------------------------------------------
    // CUSTOMER VALIDATION
    // --------------------------------------------------------

    $client_is_valid = false;

    if ($old_client_id > 0) {
        $customerCheck = $conn->prepare("
            SELECT id
            FROM customers
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");

        if ($customerCheck) {
            $customerCheck->bind_param('i', $old_client_id);
            $customerCheck->execute();
            $customerCheck->store_result();

            $client_is_valid = ($customerCheck->num_rows === 1);

            $customerCheck->close();
        }
    }

    // --------------------------------------------------------
    // BASIC VALIDATION
    // --------------------------------------------------------

    if (!$client_is_valid) {
        $error = 'Please select a valid active customer.';
    } elseif (!$valid_date) {
        $error = 'Please select a valid service date.';
    } elseif ($gst_percent < 0) {
        $error = 'Please select a valid GST percentage.';
    } elseif (!in_array($old_payment_mode, $allowed_modes, true)) {
        $error = 'Please select a valid payment mode.';
    } elseif (!is_array($items_in) || empty($items_in)) {
        $error = 'Please add at least one customer item.';
    } elseif (!$paid_is_numeric || !is_finite($paid_amount) || $paid_amount < 0) {
        $error = 'Please enter a valid non-negative paid amount.';
    } elseif (mb_strlen($old_notes) > 1000) {
        $error = 'General notes cannot exceed 1000 characters.';
    }

    // --------------------------------------------------------
    // CUSTOMER ITEMS AND PRINT DETAILS VALIDATION
    // --------------------------------------------------------

    if ($error === '') {

        foreach ($items_in as $it) {

            if (!is_array($it)) {
                $error = 'Invalid customer item details.';
                break;
            }

            $item_name_raw = $it['item_name'] ?? '';
            $qty_raw = $it['qty'] ?? '';

            $item_name = is_scalar($item_name_raw)
                ? trim((string)$item_name_raw)
                : '';

            $qty_string = is_scalar($qty_raw)
                ? trim((string)$qty_raw)
                : '';

            // Ignore completely blank item rows.
            if ($item_name === '' && $qty_string === '') {
                continue;
            }

            if ($item_name === '') {
                $error = 'Please enter the name for every customer item.';
                break;
            }

            if (mb_strlen($item_name) > 150) {
                $error = 'Item name cannot exceed 150 characters.';
                break;
            }

            if (
                $qty_string === '' ||
                !ctype_digit($qty_string) ||
                (int)$qty_string <= 0
            ) {
                $error = "Please enter a valid quantity for item '$item_name'.";
                break;
            }

            $qty = (int)$qty_string;

            if ($qty > 100000) {
                $error = 'Item quantity is too large.';
                break;
            }

            $prints_in = $it['prints'] ?? [];

            if (!is_array($prints_in) || empty($prints_in)) {
                $error = "Please add at least one print detail for item '$item_name'.";
                break;
            }

            $item_rate = 0;
            $item_types = [];
            $item_notes = [];

            foreach ($prints_in as $pr) {

                if (!is_array($pr)) {
                    $error = "Invalid print details for item '$item_name'.";
                    break 2;
                }

                $position_raw = $pr['position'] ?? '';
                $type_raw = $pr['type'] ?? '';
                $charge_raw = $pr['charge'] ?? '0';
                $instruction_raw = $pr['note'] ?? '';

                $position = is_scalar($position_raw)
                    ? trim((string)$position_raw)
                    : '';

                $type = is_scalar($type_raw)
                    ? trim((string)$type_raw)
                    : '';

                $instruction = is_scalar($instruction_raw)
                    ? trim((string)$instruction_raw)
                    : '';

                if (
                    !is_scalar($charge_raw) ||
                    trim((string)$charge_raw) === '' ||
                    !is_numeric($charge_raw)
                ) {
                    $error = 'Print charge must be a valid non-negative number.';
                    break 2;
                }

                $charge = (float)$charge_raw;

                if (
                    !is_finite($charge) ||
                    $charge < 0 ||
                    $charge > 100000000
                ) {
                    $error = 'Print charge must be a valid non-negative number.';
                    break 2;
                }

                if (mb_strlen($position) > 100 || mb_strlen($type) > 100) {
                    $error = 'Print position or type is too long.';
                    break 2;
                }

                if (mb_strlen($instruction) > 500) {
                    $error = 'Print instructions cannot exceed 500 characters.';
                    break 2;
                }

                // Ignore untouched blank print rows.
                if (
                    $position === '' &&
                    $type === '' &&
                    $charge <= 0 &&
                    $instruction === ''
                ) {
                    continue;
                }

                $item_rate += $charge;

                $label = trim(
                    $position . ($type !== '' ? ': ' . $type : ''),
                    ': '
                );

                if ($label === '') {
                    $label = 'Custom Print';
                }

                $item_types[] = $label;

                $line = $label .
                    ' (Rs.' . number_format($charge, 2) .
                    '/pc x ' . $qty .
                    ' = Rs.' . number_format($charge * $qty, 2) . ')';

                if ($instruction !== '') {
                    $line .= ' - ' . $instruction;
                }

                $item_notes[] = $line;
            }

            if ($error !== '') {
                break;
            }

            if ($item_rate <= 0 || empty($item_types)) {
                $error = "Please add at least one positive print charge for item '$item_name'.";
                break;
            }

            $item_print_total = round($item_rate * $qty, 2);

            $total_qty += $qty;
            $print_total += $item_print_total;

            $items_to_save[] = [
                'item_name' => $item_name,
                'qty' => $qty,
                'rate' => round($item_rate, 2),
                'print_total' => $item_print_total,
                'print_type' => implode(', ', array_unique($item_types)),
                'print_notes' => implode("\n", $item_notes)
            ];

            $all_print_types = array_merge(
                $all_print_types,
                $item_types
            );

            $combined_notes .=
                "\n\nITEM: $item_name (Qty: $qty)\n" .
                implode("\n", $item_notes);
        }
    }

    if ($error === '' && empty($items_to_save)) {
        $error = 'Please add valid customer items and print details.';
    }

    // --------------------------------------------------------
    // CALCULATE TOTALS
    // --------------------------------------------------------

    $sub_total = round($print_total, 2);
    $gst_amount = round(($sub_total * max(0, $gst_percent)) / 100, 2);
    $total_amount = round($sub_total + $gst_amount, 2);

    $paid_amount = round(max(0, $paid_amount), 2);
    $due_amount = round($total_amount - $paid_amount, 2);

    if ($error === '' && $paid_amount > $total_amount) {
        $error = 'Paid amount cannot exceed the Grand Total.';
    }

    // --------------------------------------------------------
    // SAVE SERVICE
    // --------------------------------------------------------

    if ($error === '') {

        $print_type = implode(', ', array_unique($all_print_types));

        $average_rate = $total_qty > 0
            ? round($print_total / $total_qty, 2)
            : 0;

        $full_notes = "CUSTOMER-OWNED ITEMS - PRINT SERVICE\n";
        $full_notes .= "Total Customer Items: " . count($items_to_save) . "\n";
        $full_notes .= "Total Pieces: $total_qty\n";
        $full_notes .= "\nPRINT DETAILS:\n" . $combined_notes;

        if ($old_notes !== '') {
            $full_notes .= "\n\nGENERAL NOTES: " . $old_notes;
        }

        $transaction_started = false;

        try {

            if (!$conn->begin_transaction()) {
                throw new Exception('Unable to start database transaction.');
            }

            $transaction_started = true;

            $service_no = generate_no(
                $conn,
                'services',
                'service_no',
                'SRV'
            );

            $status = 'pending';

            // 1. Insert main service.
            $stmt = $conn->prepare("
                INSERT INTO services
                (
                    service_no,
                    client_id,
                    sale_id,
                    service_type,
                    print_type,
                    qty,
                    print_charge_per_pc,
                    sub_total,
                    gst_percent,
                    gst_amount,
                    total_amount,
                    paid_amount,
                    due_amount,
                    payment_mode,
                    notes,
                    status,
                    service_date
                )
                VALUES (
                    ?, ?, NULL, 'custom_print',
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param(
                'sisiddiddddssss',
                $service_no,
                $old_client_id,
                $print_type,
                $total_qty,
                $average_rate,
                $sub_total,
                $gst_percent,
                $gst_amount,
                $total_amount,
                $paid_amount,
                $due_amount,
                $old_payment_mode,
                $full_notes,
                $status,
                $old_service_date
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $service_id = $stmt->insert_id;
            $stmt->close();

            // 2. Insert customer-owned service items.
            $itemStmt = $conn->prepare("
                INSERT INTO service_items
                (
                    service_id,
                    item_name,
                    qty,
                    print_charge_per_pc,
                    print_charge_total,
                    print_type,
                    print_notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$itemStmt) {
                throw new Exception($conn->error);
            }

            foreach ($items_to_save as $item) {

                $itemStmt->bind_param(
                    'isiddss',
                    $service_id,
                    $item['item_name'],
                    $item['qty'],
                    $item['rate'],
                    $item['print_total'],
                    $item['print_type'],
                    $item['print_notes']
                );

                if (!$itemStmt->execute()) {
                    throw new Exception($itemStmt->error);
                }
            }

            $itemStmt->close();

            // 3. Insert initial advance payment, if received.
            $payment_id = 0;

            if ($paid_amount > 0) {

                $payment_no = generate_no(
                    $conn,
                    'payments',
                    'payment_no',
                    'PAY'
                );

                $payStmt = $conn->prepare("
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
                    VALUES (
                        ?, 'service', ?, ?, ?, ?, ?,
                        'confirmed', 'Payment against Service'
                    )
                ");

                if (!$payStmt) {
                    throw new Exception($conn->error);
                }

                $payStmt->bind_param(
                    'siidss',
                    $payment_no,
                    $service_id,
                    $old_client_id,
                    $paid_amount,
                    $old_payment_mode,
                    $old_service_date
                );

                if (!$payStmt->execute()) {
                    throw new Exception($payStmt->error);
                }

                $payment_id = $payStmt->insert_id;
                $payStmt->close();
            }

            // 4. Commit transaction.
            if (!$conn->commit()) {
                throw new Exception('Unable to commit service transaction.');
            }

            $transaction_started = false;

            set_flash(
                'success',
                "Print Service ($service_no) created successfully!"
            );

            if ($payment_id > 0) {

                redirect(
                    'view_service_receipt.php?id=' .
                    $payment_id .
                    '&service_id=' . $service_id
                );

            } else {

                redirect('services_list.php');
            }

        } catch (Throwable $e) {

            if ($transaction_started) {
                $conn->rollback();
            }

            error_log(
                'Add Print Service Error: ' . $e->getMessage()
            );

            $error = 'Unable to create the print service. Please check the details and try again.';
        }
    }
}

include '../includes/header.php';
?>

<link
    href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
    rel="stylesheet"
/>

<style>
.select2-container {
    width: 100% !important;
}

.select2-container--default .select2-selection--single {
    background-color: rgba(255,255,255,.05) !important;
    border: 1px solid rgba(255,255,255,.1) !important;
    border-radius: 8px !important;
    height: 38px !important;
    padding: 4px 6px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #e2e8f0 !important;
    line-height: 28px !important;
    font-size: 13px !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px !important;
}

.select2-dropdown {
    background-color: #1e293b !important;
    border: 1px solid rgba(255,255,255,.15) !important;
    color: #e2e8f0 !important;
}

.select2-container--default .select2-search--dropdown .select2-search__field {
    background-color: #334155 !important;
    color: white !important;
    border: 1px solid rgba(255,255,255,.15) !important;
    border-radius: 6px !important;
}

.select2-results__option {
    font-size: 12px !important;
    padding: 7px 10px !important;
}

.select2-container--default .select2-results__option--highlighted {
    background-color: #6366f1 !important;
    color: white !important;
}

.customer-item {
    background: rgba(255,255,255,.025);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
}

.item-input,
.pi-input,
.pi-select {
    background: #1e293b;
    color: #e2e8f0;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    padding: 8px;
    font-size: 12px;
    width: 100%;
    outline: none;
}

.item-input:focus,
.pi-input:focus,
.pi-select:focus {
    border-color: rgba(99,102,241,.7);
}

.pi-select option {
    background: #1e293b;
    color: white;
}

.pi-charge {
    color: #fcd34d;
    text-align: right;
}

.print-item {
    display: grid;
    grid-template-columns: 130px 130px 90px 1fr 28px;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

@media(max-width:768px) {
    .print-item {
        grid-template-columns: 1fr 1fr;
    }

    .print-item .pi-note-wrap {
        grid-column: 1 / -1;
    }
}
</style>

<!-- PAGE HEADER -->

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">
            Add Print Service
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Printing service for customer-owned items (No Product Sale)
        </p>
    </div>

    <a href="services_list.php"
       class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST"
      class="glass-dark rounded-2xl p-5 max-w-5xl"
      id="serviceForm">

    <!-- CUSTOMER DETAILS -->

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 pb-6 border-b border-white/10">

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Customer *
            </label>

            <select name="client_id" id="customerSelect" class="w-full" required>
                <option value="">-- Select Customer --</option>

                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= ((int)$old_client_id === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= e($c['customer_name']) ?>
                        <?= !empty($c['phone']) ? ' — ' . e($c['phone']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Service Date *
            </label>

            <input type="date"
                   name="service_date"
                   required
                   value="<?= e($old_service_date) ?>"
                   class="item-input">
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Total Pieces
            </label>

            <input type="text"
                   id="totalQtyDisplay"
                   readonly
                   value="0"
                   class="item-input text-amber-300">
        </div>

    </div>

    <!-- CUSTOMER ITEMS -->

    <div class="mb-6">

        <div class="flex items-center justify-between mb-3">

            <div>
                <h3 class="text-sm font-semibold text-white">
                    <i class="fas fa-box-open text-amber-300 mr-1"></i>
                    Customer Items
                </h3>

                <p class="text-[10px] text-slate-500 mt-1">
                    Customers must bring their own items. Enter the item name manually.
                </p>
            </div>

            <button type="button"
                    onclick="addRow()"
                    class="px-3 py-2 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold">
                <i class="fas fa-plus mr-1"></i>
                Add Item
            </button>

        </div>

        <div id="itemsContainer"></div>

        <div class="text-[10px] text-slate-500">
            Examples: Shirt, Bottle, Mug, Cap, T-shirt, Bag, etc.
        </div>

    </div>

    <!-- GENERAL NOTES -->

    <div class="mb-6">
        <label class="text-xs text-slate-400 mb-1 block">
            General Notes (Optional)
        </label>

        <input type="text"
               name="notes"
               value="<?= e($old_notes) ?>"
               maxlength="1000"
               placeholder="Delivery date, packing instructions, etc."
               class="item-input">
    </div>

    <!-- GST AND PAYMENT -->

    <div class="border-t border-white/10 pt-6 mb-6">

        <h3 class="text-sm font-semibold text-white mb-4">
            <i class="fas fa-wallet text-emerald-300 mr-1"></i>
            GST & Payment Details
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-5">

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    GST %
                </label>

                <select name="gst_percent"
                        id="gstPercent"
                        onchange="calculateGrandTotal()"
                        class="item-input">

                    <?php foreach ([0, 5, 12, 18, 28] as $g): ?>
                        <option value="<?= $g ?>"
                            <?= (string)$old_gst === (string)$g ? 'selected' : '' ?>>
                            <?= $g ?>% <?= $g == 0 ? '(No GST)' : 'GST' ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    Paid Amount (₹)
                </label>

                <input type="number"
                       step="0.01"
                       min="0"
                       name="paid_amount"
                       id="paidAmount"
                       value="<?= e($old_paid_amount) ?>"
                       oninput="calculateDue()"
                       class="item-input text-emerald-300">
            </div>

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    Due Amount (₹)
                </label>

                <input type="text"
                       id="dueAmountDisplay"
                       readonly
                       value="0.00"
                       class="item-input text-rose-300">
            </div>

            <div>
                <label class="text-xs text-slate-400 mb-1 block">
                    Payment Mode
                </label>

                <select name="payment_mode" class="item-input">
                    <?php
                    $modes = [
                        'cash' => 'Cash',
                        'upi' => 'UPI',
                        'bank' => 'Bank Transfer',
                        'card' => 'Card'
                    ];

                    foreach ($modes as $key => $label):
                    ?>
                        <option value="<?= e($key) ?>"
                            <?= $old_payment_mode === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <!-- TOTAL SUMMARY -->

        <div class="flex justify-end">

            <div class="bg-white/5 rounded-lg p-4 px-6 border border-white/10 text-right min-w-[280px]">

                <div class="text-xs text-slate-400 flex justify-between mb-2">
                    <span>Total Items:</span>
                    <span id="itemCountDisplay" class="text-white">0</span>
                </div>

                <div class="text-xs text-slate-400 flex justify-between mb-2">
                    <span>Total Pieces:</span>
                    <span id="qtyDisplay" class="text-white">0</span>
                </div>

                <div class="text-xs text-slate-400 flex justify-between mb-2">
                    <span>Print Total:</span>
                    <span id="printTotalDisplay" class="text-amber-300">₹0.00</span>
                </div>

                <div class="text-xs text-slate-400 flex justify-between mb-2 border-t border-white/5 pt-2">
                    <span>Sub Total:</span>
                    <span id="subTotalDisplay" class="text-white">₹0.00</span>
                </div>

                <div class="text-xs text-slate-400 flex justify-between mb-2">
                    <span>GST Amount:</span>
                    <span id="gstDisplay" class="text-indigo-300">₹0.00</span>
                </div>

                <div class="text-sm text-slate-300 font-semibold flex justify-between border-t border-white/10 pt-2">
                    <span>Grand Total:</span>
                    <span id="grandTotalDisplay" class="text-emerald-400 text-lg">₹0.00</span>
                </div>

            </div>

        </div>

    </div>

    <!-- BUTTONS -->

    <div class="flex items-center gap-2 pt-4 border-t border-white/5">

        <button type="submit"
                class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold">
            <i class="fas fa-save mr-1"></i>
            Create Print Job
        </button>

        <a href="services_list.php"
           class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm">
            Cancel
        </a>

    </div>

</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const PRINT_POSITIONS = [
    'Front',
    'Back',
    'Left Chest',
    'Right Chest',
    'Left Sleeve',
    'Right Sleeve',
    'Neck / Collar',
    'Pocket',
    'Other'
];

const PRINT_TYPES = [
    'Logo',
    'Name / Text',
    'Number',
    'Full Design',
    'Other'
];

const OLD_ITEMS = <?= json_encode(
    array_values($old_items),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

let rowCounter = 0;

// ------------------------------------------------------------
// HTML ESCAPING
// ------------------------------------------------------------

function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildOptions(list, selected) {
    return list.map(value => `
        <option value="${esc(value)}" ${value === selected ? 'selected' : ''}>
            ${esc(value)}
        </option>
    `).join('');
}

// ------------------------------------------------------------
// INITIALIZATION
// ------------------------------------------------------------

$(document).ready(function() {

    $('#customerSelect').select2({
        placeholder: 'Search customer by name or phone...',
        allowClear: true,
        width: '100%'
    });

    // Restore previously submitted items after validation errors.
    if (Array.isArray(OLD_ITEMS) && OLD_ITEMS.length > 0) {

        [...OLD_ITEMS].reverse().forEach(item => {
            addRow(item, false);
        });

    } else {

        addRow();

    }

    calculateGrandTotal();
});

// ------------------------------------------------------------
// UPDATE ITEM NUMBERS
// ------------------------------------------------------------

function updateItemNumbers() {

    document.querySelectorAll(
        '#itemsContainer > .customer-item'
    ).forEach((card, index) => {

        const heading = card.querySelector('.item-heading');

        if (heading) {
            heading.innerHTML =
                '<i class="fas fa-box text-indigo-300 mr-1"></i> Customer Item #' +
                (index + 1);
        }

    });

}

// ------------------------------------------------------------
// ADD CUSTOMER ITEM
// ------------------------------------------------------------

function addRow(itemData = null, shouldScroll = true) {

    const idx = rowCounter++;

    const container = document.getElementById('itemsContainer');

    const card = document.createElement('div');

    card.className = 'customer-item';
    card.id = 'item_' + idx;
    card.dataset.idx = idx;

    card.innerHTML = `
        <div class="flex items-center justify-between mb-3">

            <h4 class="text-sm font-semibold text-white item-heading">
                <i class="fas fa-box text-indigo-300 mr-1"></i>
                Customer Item
            </h4>

            <button type="button"
                    onclick="removeRow(${idx})"
                    class="w-7 h-7 rounded bg-rose-500/15 text-rose-300"
                    title="Remove Item">
                <i class="fas fa-trash text-xs"></i>
            </button>

        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">

            <div>
                <label class="text-[11px] text-slate-400 mb-1 block">
                    Item Name *
                </label>

                <input type="text"
                       name="items[${idx}][item_name]"
                       required
                       maxlength="150"
                       placeholder="e.g. Shirt, Bottle, Mug"
                       class="item-input">
            </div>

            <div>
                <label class="text-[11px] text-slate-400 mb-1 block">
                    Quantity (Pieces) *
                </label>

                <input type="number"
                       name="items[${idx}][qty]"
                       min="1"
                       max="100000"
                       step="1"
                       value="1"
                       required
                       oninput="calculateGrandTotal()"
                       class="item-input item-qty">
            </div>

        </div>

        <div class="bg-white/5 rounded-lg p-3 border border-white/10">

            <div class="flex items-center justify-between mb-3">

                <div>
                    <h5 class="text-xs font-semibold text-amber-300">
                        <i class="fas fa-print mr-1"></i>
                        Print Details
                    </h5>

                    <p class="text-[10px] text-slate-500 mt-1">
                        Charges per piece × item quantity
                    </p>
                </div>

                <button type="button"
                        onclick="addPrintItem(${idx})"
                        class="px-2.5 py-1.5 rounded bg-amber-500/15 text-amber-300 text-[11px]">
                    <i class="fas fa-plus mr-1"></i>
                    Add Print
                </button>

            </div>

            <div class="hidden md:grid text-[10px] text-slate-500 pb-2"
                 style="grid-template-columns:130px 130px 90px 1fr 28px;gap:8px">

                <span>Position</span>
                <span>Type</span>
                <span class="text-right">₹ / pc</span>
                <span>Instructions</span>
                <span></span>

            </div>

            <div class="print-items"
                 id="prints_${idx}"
                 data-next="0">
            </div>

            <p class="print-empty text-[10px] text-slate-500">
                No print added. Click Add Print.
            </p>

        </div>

        <div class="flex justify-end mt-3">

            <div class="text-right">

                <p class="text-[10px] text-slate-500">
                    Item Print Total
                </p>

                <p class="text-lg font-semibold text-emerald-300 item-total">
                    ₹0.00
                </p>

            </div>

        </div>
    `;

    // New items appear at the top.
    container.prepend(card);

    if (itemData && typeof itemData === 'object') {

        const nameInput = card.querySelector('[name$="[item_name]"]');
        const qtyInput = card.querySelector('.item-qty');

        if (nameInput) {
            nameInput.value = String(itemData.item_name ?? '');
        }

        if (qtyInput) {
            qtyInput.value = String(itemData.qty ?? '1');
        }

        const savedPrints = Array.isArray(itemData.prints)
            ? itemData.prints
            : [];

        if (savedPrints.length > 0) {

            savedPrints.forEach(printData => {
                addPrintItem(idx, printData);
            });

        } else {

            addPrintItem(idx);

        }

    } else {

        addPrintItem(idx);

    }

    updateItemNumbers();
    calculateGrandTotal();

    if (shouldScroll) {
        card.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// ------------------------------------------------------------
// REMOVE CUSTOMER ITEM
// ------------------------------------------------------------

function removeRow(idx) {

    const card = document.getElementById('item_' + idx);

    if (card) {
        card.remove();
    }

    updateItemNumbers();
    calculateGrandTotal();
}

// ------------------------------------------------------------
// ADD PRINT ROW
// ------------------------------------------------------------

function addPrintItem(idx, printData = null) {

    const wrap = document.getElementById('prints_' + idx);

    if (!wrap) return;

    const k = parseInt(wrap.dataset.next, 10);

    wrap.dataset.next = k + 1;

    const defPos = k === 0
        ? 'Front'
        : (k === 1 ? 'Back' : 'Other');

    const div = document.createElement('div');

    div.className = 'print-item';
    div.id = `pi_${idx}_${k}`;

    div.innerHTML = `

        <div>
            <select name="items[${idx}][prints][${k}][position]"
                    class="pi-select">
                ${buildOptions(
                    PRINT_POSITIONS,
                    printData?.position || defPos
                )}
            </select>
        </div>

        <div>
            <select name="items[${idx}][prints][${k}][type]"
                    class="pi-select">
                ${buildOptions(
                    PRINT_TYPES,
                    printData?.type || 'Logo'
                )}
            </select>
        </div>

        <div>
            <input type="number"
                   name="items[${idx}][prints][${k}][charge]"
                   min="0"
                   max="100000000"
                   step="0.01"
                   value="${esc(printData?.charge ?? 0)}"
                   placeholder="₹ / pc"
                   oninput="calculateGrandTotal()"
                   class="pi-input pi-charge">
        </div>

        <div class="pi-note-wrap">
            <input type="text"
                   name="items[${idx}][prints][${k}][note]"
                   value="${esc(printData?.note ?? '')}"
                   maxlength="500"
                   placeholder="Instructions: Logo center, size 4 inches..."
                   class="pi-input">
        </div>

        <button type="button"
                onclick="removePrintItem(${idx}, ${k})"
                class="w-7 h-7 rounded bg-rose-500/15 text-rose-300"
                title="Remove Print">
            <i class="fas fa-trash text-[10px]"></i>
        </button>

    `;

    wrap.appendChild(div);

    togglePrintEmpty(idx);
    calculateGrandTotal();
}

// ------------------------------------------------------------
// REMOVE PRINT ROW
// ------------------------------------------------------------

function removePrintItem(idx, k) {

    const el = document.getElementById(`pi_${idx}_${k}`);

    if (el) {
        el.remove();
    }

    togglePrintEmpty(idx);
    calculateGrandTotal();
}

// ------------------------------------------------------------
// EMPTY PRINT MESSAGE
// ------------------------------------------------------------

function togglePrintEmpty(idx) {

    const wrap = document.getElementById('prints_' + idx);

    const empty = document.querySelector(
        `#item_${idx} .print-empty`
    );

    if (empty && wrap) {
        empty.style.display = wrap.children.length
            ? 'none'
            : 'block';
    }
}

// ------------------------------------------------------------
// CALCULATE ALL TOTALS
// ------------------------------------------------------------

function calculateGrandTotal() {

    let totalQty = 0;
    let printTotal = 0;
    let itemCount = 0;

    document.querySelectorAll(
        '#itemsContainer > .customer-item'
    ).forEach(card => {

        itemCount++;

        const qtyInput = card.querySelector('.item-qty');

        const qty = Math.max(
            0,
            parseInt(qtyInput.value, 10) || 0
        );

        totalQty += qty;

        let printPerPc = 0;

        card.querySelectorAll('.pi-charge').forEach(input => {

            printPerPc += Math.max(
                0,
                parseFloat(input.value) || 0
            );

        });

        const itemTotal = Math.round(
            printPerPc * qty * 100
        ) / 100;

        printTotal += itemTotal;

        card.querySelector('.item-total').textContent =
            '₹' + itemTotal.toFixed(2);

    });

    const gstPercent = parseInt(
        document.getElementById('gstPercent').value,
        10
    ) || 0;

    const subTotal = Math.round(printTotal * 100) / 100;

    const gstAmount = Math.round(
        (subTotal * gstPercent / 100) * 100
    ) / 100;

    const grandTotal = Math.round(
        (subTotal + gstAmount) * 100
    ) / 100;

    document.getElementById('totalQtyDisplay').value =
        totalQty;

    document.getElementById('itemCountDisplay').textContent =
        itemCount;

    document.getElementById('qtyDisplay').textContent =
        totalQty;

    document.getElementById('printTotalDisplay').textContent =
        '₹' + subTotal.toFixed(2);

    document.getElementById('subTotalDisplay').textContent =
        '₹' + subTotal.toFixed(2);

    document.getElementById('gstDisplay').textContent =
        '₹' + gstAmount.toFixed(2);

    document.getElementById('grandTotalDisplay').textContent =
        '₹' + grandTotal.toFixed(2);

    calculateDue();
}

// ------------------------------------------------------------
// CALCULATE DUE
// ------------------------------------------------------------

function calculateDue() {

    const grandTotal = parseFloat(
        document.getElementById('grandTotalDisplay')
            .textContent.replace('₹', '')
    ) || 0;

    const paidAmount = Math.max(
        0,
        parseFloat(document.getElementById('paidAmount').value) || 0
    );

    const due = Math.max(0, grandTotal - paidAmount);

    document.getElementById('dueAmountDisplay').value =
        due.toFixed(2);
}

// ------------------------------------------------------------
// FORM VALIDATION
// ------------------------------------------------------------

document.getElementById('serviceForm').addEventListener(
    'submit',
    function(e) {

        const cards = document.querySelectorAll(
            '#itemsContainer > .customer-item'
        );

        if (cards.length === 0) {

            e.preventDefault();

            alert('Please add at least one customer item.');

            return;
        }

        let valid = true;

        cards.forEach(card => {

            const itemName = card.querySelector(
                '[name*="[item_name]"]'
            ).value.trim();

            const qty = parseInt(
                card.querySelector('.item-qty').value,
                10
            ) || 0;

            let rate = 0;

            card.querySelectorAll('.pi-charge').forEach(input => {
                rate += parseFloat(input.value) || 0;
            });

            if (!itemName || qty <= 0 || rate <= 0) {
                valid = false;
            }

        });

        const paidInput = document.getElementById('paidAmount');

        const paidAmount = parseFloat(paidInput.value);

        const grandTotal = parseFloat(
            document.getElementById('grandTotalDisplay')
                .textContent.replace('₹', '')
        ) || 0;

        if (!valid) {

            e.preventDefault();

            alert(
                'Please enter an item name, quantity, and at least one positive print charge for every item.'
            );

            return;
        }

        if (
            paidInput.value.trim() === '' ||
            !Number.isFinite(paidAmount) ||
            paidAmount < 0 ||
            paidAmount > grandTotal
        ) {

            e.preventDefault();

            alert('Please enter a valid paid amount. It cannot exceed the Grand Total.');

        }

    }
);
</script>

<?php include '../includes/footer.php'; ?>
