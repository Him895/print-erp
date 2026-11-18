
<?php
// ============================================================
// FILE: sales/edit_sale.php
// Add Sale jaisa hi form: multiple print items per product
// (position + type + charge per piece + instruction)
// Saves via update_sale.php
// ============================================================

require_once '../config/config.php';
require_permission('sales');

$page_title = 'Edit Sale';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 1. FETCH MAIN SALE (+ LINKED SERVICE STATUS)
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT 
        s.*,
        sj.status AS service_status,
        sj.service_no
    FROM sales s
    LEFT JOIN services sj 
        ON s.service_job_id = sj.id
    WHERE s.id = ?
");

$stmt->bind_param('i', $id);
$stmt->execute();

$sale = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$sale) {
    set_flash('error', 'Sale not found.');
    redirect('sales_list.php');
}

// ------------------------------------------------------------
// 2. CHECK WHETHER SALE CAN BE EDITED
// ------------------------------------------------------------

$edit_blocked = '';

if ($sale['status'] === 'cancelled') {

    $edit_blocked = 'Cancelled sale edit nahi ho sakti.';

} elseif (
    !empty($sale['service_job_id']) &&
    !empty($sale['service_status']) &&
    $sale['service_status'] !== 'pending'
) {

    $edit_blocked = "Is sale ki print service ab '" . $sale['service_status'] . "' hai, isliye sale edit nahi ho sakti.";
}

// ------------------------------------------------------------
// 3. FETCH EXISTING SALE ITEMS
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT *
    FROM sale_items
    WHERE sale_id = ?
    ORDER BY id
");

$stmt->bind_param('i', $id);
$stmt->execute();

$sale_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// ------------------------------------------------------------
// 4. FETCH CUSTOMERS & PRODUCTS
// Existing items ke products inactive ho tab bhi list me aayen
// ------------------------------------------------------------

// Customers
$customers = $conn->query("
    SELECT *
    FROM customers
    WHERE is_active = 1
    ORDER BY customer_name
")->fetch_all(MYSQLI_ASSOC);

// Existing product IDs
$existing_pids = array_values(
    array_unique(
        array_map('intval', array_column($sale_items, 'product_id'))
    )
);

$extra = !empty($existing_pids)
    ? ' OR id IN (' . implode(',', $existing_pids) . ')'
    : '';

// Products
$products = $conn->query("
    SELECT *
    FROM products
    WHERE is_active = 1
    " . $extra . "
    ORDER BY product_name
")->fetch_all(MYSQLI_ASSOC);

// ------------------------------------------------------------
// 5. PARSE SAVED PRINT DETAILS
// ------------------------------------------------------------

// Naya format:
// "Front: Logo (Rs.50/pc x 2 = Rs.100) - note"
//
// Purana format:
// print_type = "Logo" / "Logo, Full Print"
// print_notes = free text

function parse_prints_for_edit($it) {

    $notes = trim((string)($it['print_notes'] ?? ''));
    $total = (float)($it['print_charge_total'] ?? 0);
    $qty   = max(1, (int)($it['qty'] ?? 1));

    $out = [];
    $legacy = false;

    if ($notes !== '') {

        foreach (preg_split('/\r?\n/', $notes) as $ln) {

            $ln = trim($ln);

            if ($ln === '') {
                continue;
            }

            if (
                preg_match(
                    '/^(.*?) \(Rs\.(\d+(?:\.\d+)?)\/pc x \d+ = Rs\.\d+(?:\.\d+)?\)(?: - (.*))?$/s',
                    $ln,
                    $m
                )
            ) {

                $parts = explode(': ', $m[1], 2);

                $out[] = [
                    'position' => $parts[0],
                    'type'     => $parts[1] ?? '',
                    'charge'   => (float)$m[2],
                    'note'     => $m[3] ?? ''
                ];

            } else {

                $legacy = true;
                break;
            }
        }
    }

    if (($legacy || empty($out)) && ($total > 0 || $notes !== '')) {

        $map = [
            'Text'       => 'Name / Text',
            'Full Print' => 'Full Design'
        ];

        $first = trim(explode(',', (string)($it['print_type'] ?? ''))[0]);

        $type = $map[$first] ?? ($first !== '' ? $first : 'Other');

        $out = [[
            'position' => 'Other',
            'type'     => $type,
            'charge'   => round($total / $qty),
            'note'     => $notes
        ]];
    }

    return $out;
}

// ------------------------------------------------------------
// 6. PREPARE EDIT ITEMS FOR JAVASCRIPT
// ------------------------------------------------------------

$edit_items = [];

foreach ($sale_items as $it) {

    $edit_items[] = [
        'product_id' => (int)$it['product_id'],
        'qty'        => (int)$it['qty'],
        'rate'       => (float)$it['rate'],
        'prints'     => parse_prints_for_edit($it),
    ];
}

include '../includes/header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    /* ===== SELECT2 DARK THEME FIX ===== */

    .select2-container--default .select2-selection--single {
        background-color: rgba(255,255,255,0.05) !important;
        border: 1px solid rgba(255,255,255,0.1) !important;
        border-radius: 0.5rem !important;
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
        border: 1px solid rgba(255,255,255,0.15) !important;
        border-radius: 0.5rem !important;
        color: #e2e8f0 !important;
    }

    .select2-container--default .select2-search--dropdown .select2-search__field {
        background-color: rgba(255,255,255,0.05) !important;
        border: 1px solid rgba(255,255,255,0.15) !important;
        color: #fff !important;
        border-radius: 0.4rem !important;
        padding: 6px !important;
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #6366f1 !important;
        color: #fff !important;
    }

    .select2-container--default .select2-results__option[aria-selected=true] {
        background-color: rgba(99,102,241,0.3) !important;
    }

    .select2-results__option {
        font-size: 12px !important;
        padding: 6px 10px !important;
    }

    .select2-container {
        width: 100% !important;
    }

    /* ===== PRINT ITEM STYLES ===== */

    select.pi-select,
    input.pi-input {
        background-color: #1e293b;
        color: #e2e8f0;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 6px;
        padding: 6px 8px;
        font-size: 12px;
        outline: none;
        width: 100%;
    }

    select.pi-select option {
        background-color: #1e293b;
        color: #fff;
    }

    select.pi-select:focus,
    input.pi-input:focus {
        border-color: rgba(99,102,241,0.6);
    }

    input.pi-charge {
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

    @media (max-width: 768px) {
        .print-item {
            grid-template-columns: 1fr 1fr;
        }

        .print-item .pi-note-wrap {
            grid-column: 1 / -1;
        }
    }

    select.dark-select {
        background-color: #1e293b !important;
        color: #fff !important;
    }

    select.dark-select option {
        background-color: #1e293b !important;
        color: #fff !important;
    }
</style>

<!-- ============================================================
     PAGE HEADER
============================================================ -->

<div class="flex items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white">Edit Sale</h1>

        <p class="text-xs text-slate-400 mt-0.5">
            Invoice #<?= e($sale['sale_no']) ?>
            <?= !empty($sale['service_no'])
                ? ' · Service ' . e($sale['service_no']) . ' (' . e($sale['service_status']) . ')'
                : '' ?>
        </p>
    </div>

    <a href="sales_list.php"
       class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">

        <i class="fas fa-arrow-left mr-1"></i> Back

    </a>

</div>

<!-- ============================================================
     ERROR MESSAGE
============================================================ -->

<?php if (isset($_SESSION['error'])): ?>

    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">

        <i class="fas fa-exclamation-circle mr-2"></i>

        <?= e($_SESSION['error']); unset($_SESSION['error']); ?>

    </div>

<?php endif; ?>

<!-- ============================================================
     EDIT BLOCK MESSAGE
============================================================ -->

<?php if ($edit_blocked): ?>

    <div class="mb-4 rounded-xl p-3 text-sm bg-amber-500/15 border border-amber-500/30 text-amber-300">

        <i class="fas fa-lock mr-2"></i>

        <?= e($edit_blocked) ?>

        <a href="view_sale_invoice.php?id=<?= $id ?>" class="underline ml-2">
            Invoice dekhein
        </a>

    </div>

<?php endif; ?>

<!-- ============================================================
     EDIT SALE FORM
============================================================ -->

<form action="update_sale.php"
      method="POST"
      class="glass-dark rounded-2xl p-5 max-w-5xl <?= $edit_blocked ? 'opacity-60 pointer-events-none' : '' ?>"
      id="saleForm">

    <input type="hidden" name="sale_id" value="<?= $id ?>">

    <!-- SALE BASIC DETAILS -->

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 pb-6 border-b border-white/10">

        <!-- CUSTOMER -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Customer
            </label>

            <select name="client_id" id="customerSelect" class="w-full">

                <option value="0">-- Walk-in Customer --</option>

                <?php foreach ($customers as $c): ?>

                    <option value="<?= $c['id'] ?>"
                        <?= $sale['client_id'] == $c['id'] ? 'selected' : '' ?>>

                        <?= e($c['customer_name']) ?>

                        <?= !empty($c['phone']) ? ' — ' . e($c['phone']) : '' ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <!-- SALE DATE -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Sale Date *
            </label>

            <input type="date"
                   name="sale_date"
                   required
                   value="<?= e($sale['sale_date']) ?>"
                   class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">

        </div>

        <!-- PAYMENT MODE -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Payment Mode (pehli payment)
            </label>

            <input type="text"
                   readonly
                   value="<?= e(strtoupper($sale['payment_mode'] ?? '')) ?>"
                   class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-400 outline-none cursor-not-allowed">

        </div>

        <!-- GST -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                GST %
            </label>

            <select name="gst_percent"
                    id="gstPercent"
                    class="dark-select w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                    onchange="calculateGrandTotal()">

                <?php foreach ([0 => '0% (No GST)', 5 => '5% GST', 12 => '12% GST', 18 => '18% GST', 28 => '28% GST'] as $g => $lbl): ?>

                    <option value="<?= $g ?>"
                        <?= (float)$sale['gst_percent'] == $g ? 'selected' : '' ?>>

                        <?= $lbl ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

    </div>

    <!-- ========================================================
         PRODUCTS
    ========================================================= -->

    <div class="mb-4">

        <div class="flex justify-between items-center mb-3">

            <h3 class="text-sm font-semibold text-white">
                Products
            </h3>

            <button type="button"
                    onclick="addRow()"
                    class="px-3 py-1.5 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">

                <i class="fas fa-plus mr-1"></i> Add Product Row

            </button>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-xs" id="itemsTable">

                <thead>

                    <tr class="text-left border-b border-white/10">

                        <th class="pb-2 text-slate-400 font-medium w-1/3">
                            Product
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-center w-16">
                            Qty
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-right w-24">
                            Rate (₹)
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-right w-24">
                            Print Total
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-right w-24">
                            Amount
                        </th>

                        <th class="pb-2 text-slate-400 font-medium text-center w-8"></th>

                    </tr>

                </thead>

                <tbody id="itemsBody"></tbody>

            </table>

        </div>

    </div>

    <!-- ========================================================
         PAYMENT DETAILS + TOTALS
    ========================================================= -->

    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-6">

        <!-- PAYMENT DETAILS -->

        <div class="bg-white/5 rounded-lg p-4 border border-white/10 w-full md:w-1/2">

            <h3 class="text-sm font-semibold text-white mb-3">
                Payment Details
            </h3>

            <div class="flex gap-4">

                <div class="flex-1">

                    <label class="text-xs text-slate-400 mb-1 block">
                        Paid Amount (₹)
                    </label>

                    <input type="text"
                           readonly
                           value="<?= (int)$sale['paid_amount'] ?>"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-emerald-300 outline-none cursor-not-allowed">

                </div>

                <div class="flex-1">

                    <label class="text-xs text-slate-400 mb-1 block">
                        Due Amount (₹)
                    </label>

                    <input type="text"
                           id="dueAmountDisplay"
                           readonly
                           value="<?= (int)$sale['due_amount'] ?>"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-rose-300 outline-none cursor-not-allowed">

                </div>

            </div>

            <p class="text-[10px] text-slate-500 mt-2">

                Paid amount yahan se nahi badalta. Naya paisa lene ke liye

                <a href="../payments/add_payment.php?type=receivable&sale_id=<?= $id ?>"
                   class="text-indigo-300 underline">

                    Receive Payment

                </a>

                use karein.

            </p>

            <p class="text-[10px] text-rose-400 mt-1 hidden" id="overpaidWarn">

                Grand Total paid amount se kam hai, save nahi hoga.

            </p>

        </div>

        <!-- TOTAL SUMMARY -->

        <div class="bg-white/5 rounded-lg p-4 px-6 border border-white/10 text-right min-w-[250px] w-full md:w-auto">

            <div class="text-xs text-slate-400 flex justify-between mb-1">

                <span>Product Total:</span>

                <span id="prodTotalDisplay" class="text-white">₹0</span>

            </div>

            <div class="text-xs text-slate-400 flex justify-between mb-1">

                <span>Print Total:</span>

                <span id="printTotalDisplay" class="text-amber-300">₹0</span>

            </div>

            <div class="text-xs text-slate-400 flex justify-between mb-1 border-t border-white/5 pt-1">

                <span>Sub Total:</span>

                <span id="subTotalDisplay" class="text-white">₹0</span>

            </div>

            <div class="text-xs text-slate-400 flex justify-between mb-2">

                <span>GST Amount:</span>

                <span id="gstDisplay" class="text-indigo-300">₹0</span>

            </div>

            <div class="text-sm text-slate-300 font-semibold flex justify-between border-t border-white/10 pt-2">

                <span>Grand Total:</span>

                <span id="grandTotalDisplay" class="text-emerald-400 text-lg">₹0</span>

            </div>

        </div>

    </div>

    <!-- ========================================================
         FORM ACTIONS
    ========================================================= -->

    <div class="flex items-center gap-2 pt-4 border-t border-white/5">

        <button type="submit"
                class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">

            <i class="fas fa-save mr-1"></i> Update Sale

        </button>

        <a href="sales_list.php"
           class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition">

            Cancel

        </a>

    </div>

</form>

<!-- ============================================================
     JQUERY + SELECT2
============================================================ -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const productsList  = <?= json_encode($products) ?>;
const existingItems = <?= json_encode($edit_items) ?>;
const PAID_AMOUNT   = <?= (float)$sale['paid_amount'] ?>;

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

let rowCounter = 0;

// ------------------------------------------------------------
// HTML ESCAPE
// ------------------------------------------------------------

function esc(s) {

    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ------------------------------------------------------------
// DOCUMENT READY
// ------------------------------------------------------------

$(document).ready(function() {

    $('#customerSelect').select2({
        placeholder: 'Search customer...',
        width: '100%'
    });

    if (existingItems.length > 0) {

        existingItems.forEach(item => addRow(item, true));

    } else {

        addRow();
    }

    calculateGrandTotal();
});

// ------------------------------------------------------------
// PRODUCT SELECT2
// ------------------------------------------------------------

function applyProductSelect2() {

    $('.product-select').each(function() {

        if (!$(this).hasClass('select2-hidden-accessible')) {

            $(this).select2({
                placeholder: 'Search product...',
                width: '100%'
            });

            $(this).on('select2:select', function() {
                onProductChange(this);
            });
        }
    });
}

// ------------------------------------------------------------
// ADD PRODUCT ROW
// data = {product_id, qty, rate, prints:[...]}
// append=true => existing items load
// ------------------------------------------------------------

function addRow(data, append) {

    data = data || {};

    const idx = rowCounter++;

    const tbody = document.getElementById('itemsBody');

    let optionsHtml = '<option value="">-- Select Product --</option>';

    productsList.forEach(p => {

        const sel = (
            data.product_id &&
            parseInt(p.id) === parseInt(data.product_id)
        ) ? 'selected' : '';

        optionsHtml += `
            <option
                value="${p.id}"
                data-price="${esc(p.sale_price)}"
                data-print="${esc(p.print_charge)}"
                data-stock="${esc(p.stock_qty)}"
                ${sel}>
                ${esc(p.product_name)} (Stock: ${esc(p.stock_qty)})
            </option>
        `;
    });

    const qtyVal = data.qty ? data.qty : 1;

    const rateVal = (
        data.rate !== undefined &&
        data.rate !== null
    ) ? Math.round(data.rate) : '';

    // --------------------------------------------------------
    // MAIN PRODUCT ROW
    // --------------------------------------------------------

    const row = document.createElement('tr');

    row.className = 'item-main-row border-t border-white/10';
    row.id = 'row_' + idx;
    row.dataset.idx = idx;

    row.innerHTML = `
        <td class="py-2 pr-2">
            <select
                name="items[${idx}][product_id]"
                required
                class="product-select w-full"
                data-idx="${idx}">
                ${optionsHtml}
            </select>
        </td>

        <td class="py-2 px-1">
            <input
                type="number"
                name="items[${idx}][qty]"
                min="1"
                value="${qtyVal}"
                required
                oninput="calculateGrandTotal()"
                class="row-qty w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-center text-slate-200 outline-none focus:border-indigo-500/50">
        </td>

        <td class="py-2 px-1">
            <input
                type="number"
                step="1"
                min="0"
                name="items[${idx}][rate]"
                value="${rateVal}"
                required
                oninput="calculateGrandTotal()"
                class="row-rate w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-right text-slate-200 outline-none focus:border-indigo-500/50">
        </td>

        <td class="py-2 px-1 text-right text-amber-300 font-semibold row-print-total">
            ₹0
        </td>

        <td class="py-2 px-1 text-right text-emerald-300 font-semibold row-amount">
            ₹0
        </td>

        <td class="py-2 pl-2 text-center">
            <button
                type="button"
                onclick="removeRow(${idx})"
                class="w-6 h-6 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition">
                <i class="fas fa-times text-[10px]"></i>
            </button>
        </td>
    `;

    // --------------------------------------------------------
    // PRINT SECTION ROW
    // --------------------------------------------------------

    const printRow = document.createElement('tr');

    printRow.className = 'item-print-row';
    printRow.id = 'printrow_' + idx;

    printRow.innerHTML = `
        <td colspan="6" class="pb-4">

            <div class="bg-white/5 rounded-lg p-3 border border-white/10">

                <div class="flex items-center justify-between mb-2">

                    <span class="text-[11px] text-slate-300 font-semibold">

                        <i class="fas fa-print mr-1 text-amber-300"></i>
                        Print Items

                        <span class="text-slate-500 font-normal">
                            (charge per piece hai, Qty se automatically multiply hoga)
                        </span>

                    </span>

                    <button
                        type="button"
                        onclick="addPrintItem(${idx})"
                        class="px-2.5 py-1 rounded-md bg-amber-500/15 text-amber-300 text-[11px] font-semibold hover:bg-amber-500/25 transition">

                        <i class="fas fa-plus mr-1"></i> Add Print

                    </button>

                </div>

                <div
                    class="print-items"
                    id="prints_${idx}"
                    data-next="0">
                </div>

                <p class="print-empty text-[10px] text-slate-500">
                    Koi print nahi. Print chahiye to "Add Print" dabayein.
                </p>

            </div>

        </td>
    `;

    if (append) {

        tbody.append(row, printRow);

    } else {

        tbody.prepend(row, printRow);
    }

    applyProductSelect2();

    // Restore saved print items
    if (Array.isArray(data.prints)) {

        data.prints.forEach(p => addPrintItem(idx, p));
    }

    calculateGrandTotal();
}

// ------------------------------------------------------------
// REMOVE PRODUCT ROW
// ------------------------------------------------------------

function removeRow(idx) {

    const r = document.getElementById('row_' + idx);
    const p = document.getElementById('printrow_' + idx);

    if (r) r.remove();
    if (p) p.remove();

    calculateGrandTotal();
}

// ------------------------------------------------------------
// BUILD SELECT OPTIONS
// ------------------------------------------------------------

function buildOptions(list, selected) {

    const items = list.slice();

    if (selected && !items.includes(selected)) {
        items.push(selected);
    }

    return items.map(v => `
        <option value="${esc(v)}" ${v === selected ? 'selected' : ''}>
            ${esc(v)}
        </option>
    `).join('');
}

// ------------------------------------------------------------
// ADD PRINT ITEM
// ------------------------------------------------------------

function addPrintItem(idx, defaults) {

    defaults = defaults || {};

    const wrap = document.getElementById('prints_' + idx);

    const k = parseInt(wrap.dataset.next);

    wrap.dataset.next = k + 1;

    let defCharge = defaults.charge;

    if (defCharge === undefined) {

        const sel = document.querySelector(`#row_${idx} .product-select`);

        const opt = sel && sel.options[sel.selectedIndex];

        const pc = opt ? parseFloat(opt.getAttribute('data-print')) : 0;

        defCharge = (k === 0 && pc > 0) ? Math.round(pc) : 0;
    }

    const defPos = defaults.position || (
        k === 0 ? 'Front' : (k === 1 ? 'Back' : 'Other')
    );

    const defType = (
        defaults.type !== undefined &&
        defaults.type !== ''
    ) ? defaults.type : 'Logo';

    const div = document.createElement('div');

    div.className = 'print-item';
    div.id = `pi_${idx}_${k}`;

    div.innerHTML = `
        <div>
            <select
                name="items[${idx}][prints][${k}][position]"
                class="pi-select"
                title="Kahan print hoga">
                ${buildOptions(PRINT_POSITIONS, defPos)}
            </select>
        </div>

        <div>
            <select
                name="items[${idx}][prints][${k}][type]"
                class="pi-select"
                title="Print ka type">
                ${buildOptions(PRINT_TYPES, defType)}
            </select>
        </div>

        <div>
            <input
                type="number"
                step="1"
                min="0"
                name="items[${idx}][prints][${k}][charge]"
                value="${defCharge}"
                placeholder="₹ / pc"
                oninput="calculateGrandTotal()"
                class="pi-input pi-charge"
                title="Is print ka charge per piece (₹)">
        </div>

        <div class="pi-note-wrap">
            <input
                type="text"
                name="items[${idx}][prints][${k}][note]"
                value="${esc(defaults.note || '')}"
                placeholder="Instruction: e.g. company logo center me, size 4 inch"
                class="pi-input">
        </div>

        <button
            type="button"
            onclick="removePrintItem(${idx}, ${k})"
            class="w-7 h-7 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition"
            title="Remove print">

            <i class="fas fa-trash text-[10px]"></i>

        </button>
    `;

    wrap.appendChild(div);

    togglePrintEmpty(idx);

    calculateGrandTotal();
}

// ------------------------------------------------------------
// REMOVE PRINT ITEM
// ------------------------------------------------------------

function removePrintItem(idx, k) {

    const el = document.getElementById(`pi_${idx}_${k}`);

    if (el) el.remove();

    togglePrintEmpty(idx);

    calculateGrandTotal();
}

// ------------------------------------------------------------
// TOGGLE EMPTY PRINT MESSAGE
// ------------------------------------------------------------

function togglePrintEmpty(idx) {

    const wrap = document.getElementById('prints_' + idx);

    const empty = document.querySelector(
        `#printrow_${idx} .print-empty`
    );

    if (empty) {
        empty.style.display = wrap.children.length ? 'none' : 'block';
    }
}

// ------------------------------------------------------------
// PRODUCT CHANGE
// ------------------------------------------------------------

function onProductChange(select) {

    const opt = select.options[select.selectedIndex];

    const price = opt ? opt.getAttribute('data-price') : null;

    const idx = select.dataset.idx;

    const row = document.getElementById('row_' + idx);

    if (price) {
        row.querySelector('.row-rate').value = Math.round(price);
    }

    calculateGrandTotal();
}

// ------------------------------------------------------------
// CALCULATE GRAND TOTAL
// ------------------------------------------------------------

function calculateGrandTotal() {

    let prodTotal = 0;
    let printTotal = 0;

    document.querySelectorAll('#itemsBody > tr.item-main-row').forEach(row => {

        const idx = row.dataset.idx;

        const qty = parseFloat(row.querySelector('.row-qty').value) || 0;

        const rate = parseFloat(row.querySelector('.row-rate').value) || 0;

        const amount = qty * rate;

        let printPerPc = 0;

        document.querySelectorAll(`#prints_${idx} .pi-charge`).forEach(inp => {

            printPerPc += parseFloat(inp.value) || 0;
        });

        const rowPrint = printPerPc * qty;

        row.querySelector('.row-amount').textContent =
            '₹' + amount.toFixed(0);

        row.querySelector('.row-print-total').innerHTML =
            '₹' + rowPrint.toFixed(0) +
            (
                printPerPc > 0
                    ? `<div class="text-[9px] text-slate-500 font-normal">₹${printPerPc.toFixed(0)} × ${qty}</div>`
                    : ''
            );

        prodTotal += amount;

        printTotal += rowPrint;
    });

    const subTotal = prodTotal + printTotal;

    const gstPercent = parseFloat(
        document.getElementById('gstPercent').value
    ) || 0;

    const gstAmount = Math.round(
        (subTotal * gstPercent) / 100
    );

    const grandTotal = subTotal + gstAmount;

    document.getElementById('prodTotalDisplay').textContent =
        '₹' + prodTotal.toFixed(0);

    document.getElementById('printTotalDisplay').textContent =
        '₹' + printTotal.toFixed(0);

    document.getElementById('subTotalDisplay').textContent =
        '₹' + subTotal.toFixed(0);

    document.getElementById('gstDisplay').textContent =
        '₹' + gstAmount.toFixed(0);

    document.getElementById('grandTotalDisplay').textContent =
        '₹' + grandTotal.toFixed(0);

    calculateDue(grandTotal);
}

// ------------------------------------------------------------
// CALCULATE DUE AMOUNT
// ------------------------------------------------------------

function calculateDue(grandTotal) {

    if (grandTotal === undefined) {

        grandTotal = parseFloat(
            document.getElementById('grandTotalDisplay')
                .textContent.replace('₹', '')
        ) || 0;
    }

    const due = grandTotal - PAID_AMOUNT;

    document.getElementById('dueAmountDisplay').value =
        Math.max(due, 0).toFixed(0);

    document.getElementById('overpaidWarn').classList.toggle(
        'hidden',
        due >= 0
    );
}
</script>

<?php include '../includes/footer.php'; ?>
