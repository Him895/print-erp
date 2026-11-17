<?php
// ============================================================
// FILE: product_purchase/add_purchase.php (WITH PAYMENT)
// SEARCHABLE VENDOR + PRODUCT DROPDOWNS
// DARK PAYMENT MODE DROPDOWN
// ============================================================

require_once '../config/config.php';
require_permission('purchases');
$page_title = 'Add Purchase';
$error = '';

// Fetch Vendors & Products
$vendors = $conn->query("
    SELECT * FROM vendors
    WHERE is_active = 1
    ORDER BY vendor_name
")->fetch_all(MYSQLI_ASSOC);

$products = $conn->query("
    SELECT * FROM products
    WHERE is_active = 1
    ORDER BY product_name
")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendor_id     = (int)($_POST['vendor_id'] ?? 0);
    $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $payment_mode  = $_POST['payment_mode'] ?? 'cash';
    $paid_amount   = (float)($_POST['paid_amount'] ?? 0);

    $item_product_ids = $_POST['product_id'] ?? [];
    $item_qtys        = $_POST['qty'] ?? [];
    $item_rates       = $_POST['rate'] ?? [];

    if ($vendor_id <= 0) {
        $error = 'Vendor select karna zaroori hai.';
    } elseif (empty($item_product_ids)) {
        $error = 'Kam se kam ek product add karna zaroori hai.';
    } else {
        $grand_total = 0;
        $items_to_save = [];

        foreach ($item_product_ids as $index => $p_id) {
            $p_id = (int)$p_id;
            $qty = (int)($item_qtys[$index] ?? 0);
            $rate = (float)($item_rates[$index] ?? 0);

            if ($p_id > 0 && $qty > 0 && $rate > 0) {
                $amount = $qty * $rate;
                $grand_total += $amount;

                $items_to_save[] = [
                    'product_id' => $p_id,
                    'qty' => $qty,
                    'rate' => $rate,
                    'amount' => $amount
                ];
            }
        }

        if (empty($items_to_save)) {
            $error = 'Sahi product, qty aur rate bharein.';
        } else {
            $due_amount = $grand_total - $paid_amount;

            if ($due_amount < 0) {
                $due_amount = 0;
            }

            $purchase_no = generate_no(
                $conn,
                'product_purchase',
                'purchase_no',
                'PUR'
            );

            $conn->begin_transaction();

            try {
                // 1. Insert Main Purchase Record
                $stmt = $conn->prepare("
                    INSERT INTO product_purchase
                    (purchase_no, vendor_id, grand_total, paid_amount, due_amount, payment_mode, purchase_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    'sidddss',
                    $purchase_no,
                    $vendor_id,
                    $grand_total,
                    $paid_amount,
                    $due_amount,
                    $payment_mode,
                    $purchase_date
                );

                $stmt->execute();
                $purchase_id = $stmt->insert_id;
                $stmt->close();

                // 2. Insert Items & Update Stock
                $itemStmt = $conn->prepare("
                    INSERT INTO purchase_items
                    (purchase_id, product_id, qty, rate, amount)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stockStmt = $conn->prepare("
                    UPDATE products
                    SET stock_qty = stock_qty + ?
                    WHERE id = ?
                ");

                foreach ($items_to_save as $item) {
                    $itemStmt->bind_param(
                        'iiidd',
                        $purchase_id,
                        $item['product_id'],
                        $item['qty'],
                        $item['rate'],
                        $item['amount']
                    );

                    $itemStmt->execute();

                    $stockStmt->bind_param(
                        'ii',
                        $item['qty'],
                        $item['product_id']
                    );

                    $stockStmt->execute();
                }

                $itemStmt->close();
                $stockStmt->close();

                // 3. Commit
                $conn->commit();

                set_flash(
                    'success',
                    "Purchase ($purchase_no) added! Stock updated. Due: " . money($due_amount)
                );

                redirect('purchases_list.php');

            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

include '../includes/header.php';
?>

<!-- Select2 CSS -->
<link
    href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
    rel="stylesheet"
/>

<style>

/* =========================================
   SELECT2 DARK THEME
========================================= */

/* Main dropdown */
.select2-container {
    width: 100% !important;
}

.select2-container--default .select2-selection--single {
    background-color: #1e293b !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    border-radius: 0.5rem !important;
    height: 38px !important;
    padding: 4px 6px !important;
}

.select2-container--default .select2-selection--single
.select2-selection__rendered {
    color: #e2e8f0 !important;
    line-height: 28px !important;
    font-size: 13px !important;
    padding-left: 5px !important;
}

.select2-container--default .select2-selection--single
.select2-selection__placeholder {
    color: #94a3b8 !important;
}

.select2-container--default .select2-selection--single
.select2-selection__arrow {
    height: 36px !important;
}

/* Dropdown options */
.select2-dropdown {
    background-color: #1e293b !important;
    border: 1px solid rgba(255,255,255,0.15) !important;
    border-radius: 8px !important;
    overflow: hidden;
}

.select2-container--default .select2-results__option {
    background-color: #1e293b !important;
    color: #e2e8f0 !important;
    font-size: 12px !important;
    padding: 9px 12px !important;
}

/* Hover option */
.select2-container--default
.select2-results__option--highlighted[aria-selected] {
    background-color: #4f46e5 !important;
    color: #ffffff !important;
}

/* Selected option */
.select2-container--default
.select2-results__option[aria-selected=true] {
    background-color: #334155 !important;
    color: #ffffff !important;
}

/* Search input */
.select2-container--default .select2-search--dropdown {
    padding: 8px !important;
    background-color: #1e293b !important;
}

.select2-container--default
.select2-search--dropdown .select2-search__field {
    background-color: #0f172a !important;
    border: 1px solid rgba(255,255,255,0.15) !important;
    border-radius: 6px !important;
    color: #ffffff !important;
    padding: 8px !important;
    outline: none !important;
}

.select2-container--default
.select2-search--dropdown .select2-search__field:focus {
    border-color: #6366f1 !important;
}

/* No result message */
.select2-container--default .select2-results__message {
    color: #94a3b8 !important;
}

/* =========================================
   PAYMENT MODE DARK DROPDOWN
========================================= */

select.dark-dropdown {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background-color: #1e293b !important;
    color: #f1f5f9 !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    border-radius: 0.5rem !important;

    color-scheme: dark;
    cursor: pointer;
}

select.dark-dropdown option {
    background-color: #1e293b !important;
    color: #ffffff !important;
}

select.dark-dropdown:focus {
    outline: none;
    border-color: rgba(99,102,241,0.7) !important;
    box-shadow: 0 0 0 2px rgba(99,102,241,0.12);
}

</style>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Add Purchase</h1>
        <p class="text-xs text-slate-400 mt-0.5">
            Vendor se multiple products kharidein
        </p>
    </div>

    <a href="purchases_list.php"
       class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-5xl" id="purchaseForm">

    <!-- HEADER INFO -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 pb-6 border-b border-white/10">

        <!-- SEARCHABLE VENDOR -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Vendor *
            </label>

            <select name="vendor_id" id="vendorSelect" required class="w-full">
                <option value="">-- Search Vendor --</option>

                <?php foreach ($vendors as $v): ?>
                    <option
                        value="<?= (int)$v['id'] ?>"
                        <?= (int)($_POST['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>
                    >
                        <?= e($v['vendor_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- PURCHASE DATE -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Purchase Date *
            </label>

            <input
                type="date"
                name="purchase_date"
                required
                value="<?= e($_POST['purchase_date'] ?? date('Y-m-d')) ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >
        </div>

        <!-- PAYMENT MODE -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Payment Mode
            </label>

            <select name="payment_mode"
                    class="dark-dropdown w-full px-3 py-2 text-sm">

                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank">Bank Transfer</option>
                <option value="card">Card</option>

            </select>
        </div>

    </div>

    <!-- PURCHASE ITEMS -->
    <div class="mb-4">

        <div class="flex justify-between items-center mb-3">
            <h3 class="text-sm font-semibold text-white">
                Purchase Items
            </h3>

            <button
                type="button"
                onclick="addRow()"
                class="px-3 py-1.5 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition"
            >
                <i class="fas fa-plus mr-1"></i> Add Product Row
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs" id="itemsTable">

                <thead>
                    <tr class="text-left border-b border-white/10">
                        <th class="pb-2 text-slate-400 font-medium w-1/2">
                            Product
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-center w-20">
                            Qty
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-right w-28">
                            Rate (₹)
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-right w-28">
                            Amount (₹)
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-center w-10"></th>
                    </tr>
                </thead>

                <tbody id="itemsBody"></tbody>

            </table>
        </div>
    </div>

    <!-- PAYMENT & GRAND TOTAL -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-6">

        <div class="bg-white/5 rounded-lg p-4 border border-white/10 w-full md:w-1/2">

            <h3 class="text-sm font-semibold text-white mb-3">
                Vendor Payment
            </h3>

            <div class="flex gap-4">

                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">
                        Paid Amount (₹) *
                    </label>

                    <input
                        type="number"
                        step="1"
                        min="0"
                        name="paid_amount"
                        id="paidAmount"
                        value="<?= e($_POST['paid_amount'] ?? '0') ?>"
                        oninput="calculateDue()"
                        class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-emerald-300 outline-none focus:border-indigo-500/50"
                    >
                </div>

                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">
                        Due Amount (₹)
                    </label>

                    <input
                        type="text"
                        id="dueAmountDisplay"
                        readonly
                        value="0"
                        class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-rose-300 outline-none cursor-not-allowed"
                    >
                </div>

            </div>
        </div>

        <div class="bg-white/5 rounded-lg p-3 px-5 border border-white/10 text-right w-full md:w-auto">

            <span class="text-xs text-slate-400 block mb-1">
                Grand Total
            </span>

            <span class="text-xl font-bold text-emerald-300"
                  id="grandTotalDisplay">
                ₹0
            </span>

        </div>
    </div>

    <!-- ACTION BUTTONS -->
    <div class="flex items-center gap-2 pt-4 border-t border-white/5">

        <button
            type="submit"
            class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all"
        >
            <i class="fas fa-save mr-1"></i> Save Purchase
        </button>

        <a
            href="purchases_list.php"
            class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition"
        >
            Cancel
        </a>

    </div>

</form>

<!-- JQUERY + SELECT2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>

// Products list from PHP to JS
const productsList = <?= json_encode($products) ?>;

// Escape HTML values
function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Initialize searchable vendor dropdown
$(document).ready(function() {

    $('#vendorSelect').select2({
        placeholder: 'Search vendor by name...',
        allowClear: true,
        width: '100%'
    });

    // First product row
    addRow();

});

// Initialize Select2 for dynamically added product dropdowns
function initializeProductSelect2() {

    $('.product-select').each(function() {

        if (!$(this).hasClass('select2-hidden-accessible')) {

            $(this).select2({
                placeholder: 'Search product by name...',
                allowClear: true,
                width: '100%'
            });

            // Product selection event
            $(this).on('select2:select', function() {
                onProductChange(this);
            });

            // Clear selection event
            $(this).on('select2:clear', function() {
                onProductChange(this);
            });

        }

    });

}

// Add Product Row
function addRow() {

    const tbody = document.getElementById('itemsBody');
    const rowId = Date.now() + Math.floor(Math.random() * 1000);

    const row = document.createElement('tr');

    row.className = 'border-b border-white/5 hover:bg-white/3 transition';
    row.id = 'row_' + rowId;

    // Product Options
    let optionsHtml = '<option value="">-- Select Product --</option>';

    productsList.forEach(p => {

        optionsHtml += `
            <option value="${esc(p.id)}"
                    data-price="${esc(p.purchase_price)}">
                ${esc(p.product_name)} (Stock: ${esc(p.stock_qty)})
            </option>
        `;

    });

    row.innerHTML = `

        <td class="py-2 pr-2">

            <select
                name="product_id[]"
                required
                class="product-select w-full"
            >
                ${optionsHtml}
            </select>

        </td>

        <td class="py-2 px-1">

            <input
                type="number"
                name="qty[]"
                min="1"
                value="1"
                required
                oninput="calculateRow('${rowId}')"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-center text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </td>

        <td class="py-2 px-1">

            <input
                type="number"
                step="1"
                min="0"
                name="rate[]"
                required
                oninput="calculateRow('${rowId}')"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-right text-slate-200 outline-none focus:border-indigo-500/50 row-rate"
            >

        </td>

        <td class="py-2 px-1 text-right text-emerald-300 font-semibold row-amount">
            ₹0
        </td>

        <td class="py-2 pl-2 text-center">

            <button
                type="button"
                onclick="removeRow('${rowId}')"
                class="w-6 h-6 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition"
            >
                <i class="fas fa-times text-[10px]"></i>
            </button>

        </td>

    `;

    // New row will appear at the TOP
    tbody.prepend(row);

    // Initialize searchable dropdown on new row
    initializeProductSelect2();

}

// Remove Product Row
function removeRow(rowId) {

    const row = document.getElementById('row_' + rowId);

    if (row) {

        // Destroy Select2 before removing row
        const select = $(row).find('.product-select');

        if (select.hasClass('select2-hidden-accessible')) {
            select.select2('destroy');
        }

        row.remove();

    }

    calculateGrandTotal();

}

// Product Selection
function onProductChange(select) {

    const opt = select.options[select.selectedIndex];

    const price = opt ? opt.getAttribute('data-price') : null;

    const row = select.closest('tr');

    if (!row) return;

    if (price !== null && price !== '') {
        row.querySelector('.row-rate').value = Math.round(parseFloat(price) || 0);
    } else {
        row.querySelector('.row-rate').value = '';
    }

    calculateRow(row.id.replace('row_', ''));

}

// Calculate Individual Row
function calculateRow(rowId) {

    const row = document.getElementById('row_' + rowId);

    if (!row) return;

    const qty = parseFloat(
        row.querySelector('input[name="qty[]"]').value
    ) || 0;

    const rate = parseFloat(
        row.querySelector('input[name="rate[]"]').value
    ) || 0;

    const amount = qty * rate;

    row.querySelector('.row-amount').textContent =
        '₹' + amount.toFixed(0);

    calculateGrandTotal();

}

// Calculate Grand Total
function calculateGrandTotal() {

    let total = 0;

    document.querySelectorAll('.row-amount').forEach(el => {

        const val = parseFloat(
            el.textContent.replace('₹', '')
        ) || 0;

        total += val;

    });

    document.getElementById('grandTotalDisplay').textContent =
        '₹' + total.toFixed(0);

    calculateDue();

}

// Calculate Due
function calculateDue() {

    const grandTotalStr = document
        .getElementById('grandTotalDisplay')
        .textContent
        .replace('₹', '');

    const grandTotal = parseFloat(grandTotalStr) || 0;

    const paid = parseFloat(
        document.getElementById('paidAmount').value
    ) || 0;

    let due = grandTotal - paid;

    if (due < 0) {
        due = 0;
    }

    document.getElementById('dueAmountDisplay').value =
        due.toFixed(0);

}

</script>

<?php include '../includes/footer.php'; ?>