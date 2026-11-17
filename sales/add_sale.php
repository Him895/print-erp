
<?php
// ============================================================
// FILE: sales/add_sale.php
// Searchable Client + Product dropdowns (Select2)
// MULTIPLE PRINT ITEMS per product (position + type + charge + instruction)
// ============================================================
require_once '../config/config.php';
require_permission('sales');
$page_title = 'New Sale';
$error = '';

// Fetch Clients & Products
$clients  = $conn->query("SELECT * FROM clients WHERE is_active = 1 ORDER BY client_name")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT * FROM products WHERE is_active = 1 AND stock_qty > 0 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

$product_names = [];
foreach ($products as $p) { $product_names[(int)$p['id']] = $p['product_name']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id   = (int)($_POST['client_id'] ?? 0);
    $sale_date   = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $gst_percent = (float)($_POST['gst_percent'] ?? 0);

    $payment_mode = $_POST['payment_mode'] ?? 'cash';
    $paid_amount  = (float)($_POST['paid_amount'] ?? 0);

    // Structure: items[idx][product_id|qty|rate|prints][k][position|type|charge|note]
    $items_in = $_POST['items'] ?? [];

    if (empty($items_in) || !is_array($items_in)) {
        $error = 'Kam se kam ek product select karein.';
    } else {
        $product_total = 0;
        $print_total   = 0;
        $items_to_save = [];

        foreach ($items_in as $it) {
            $p_id = (int)($it['product_id'] ?? 0);
            $qty  = (int)($it['qty'] ?? 0);
            $rate = (float)($it['rate'] ?? 0);

            if ($p_id <= 0 || $qty <= 0 || $rate <= 0) continue;

            // ---- Multiple print entries for this product ----
            $print_charge = 0;
            $type_parts   = [];
            $note_lines   = [];
            $prints_in    = (isset($it['prints']) && is_array($it['prints'])) ? $it['prints'] : [];

            foreach ($prints_in as $pr) {
                $pos    = trim($pr['position'] ?? '');
                $type   = trim($pr['type'] ?? '');
                $charge = (float)($pr['charge'] ?? 0);
                $note   = trim($pr['note'] ?? '');

                if ($pos === '' && $type === '' && $charge <= 0 && $note === '') continue;

                $print_charge += $charge * $qty;
                $label = trim($pos . ($type !== '' ? ': ' . $type : ''), ': ');
                $type_parts[] = $label;

                $line = $label . ' (Rs.' . round($charge) . '/pc x ' . $qty . ' = Rs.' . round($charge * $qty) . ')';
                if ($note !== '') $line .= ' - ' . $note;
                $note_lines[] = $line;
            }

            $amount = $qty * $rate;
            $product_total += $amount;
            $print_total   += $print_charge;

            $items_to_save[] = [
                'product_id'   => $p_id,
                'product_name' => $product_names[$p_id] ?? ('Product #' . $p_id),
                'qty'          => $qty,
                'rate'         => $rate,
                'amount'       => $amount,
                'print_charge' => $print_charge,
                'print_type'   => implode(', ', $type_parts),
                'print_notes'  => implode("\n", $note_lines)
            ];
        }

        if (empty($items_to_save)) {
            $error = 'Sahi product, qty aur rate bharein.';
        } else {
            $sub_total   = $product_total + $print_total;
            $gst_amount  = round(($sub_total * $gst_percent) / 100);
            $grand_total = $sub_total + $gst_amount;

            $due_amount = $grand_total - $paid_amount;
            $status     = ($due_amount <= 0) ? 'completed' : 'pending';
            if ($due_amount < 0) $due_amount = 0;

            $sale_no = generate_no($conn, 'sales', 'sale_no', 'SAL');

            $conn->begin_transaction();

            try {
                // 1. Insert Sale
                $stmt = $conn->prepare("INSERT INTO sales 
                    (sale_no, client_id, product_total, print_total, sub_total, gst_percent, gst_amount, grand_total, status, payment_mode, paid_amount, due_amount, sale_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('siddddddssdds', $sale_no, $client_id, $product_total, $print_total, $sub_total, $gst_percent, $gst_amount, $grand_total, $status, $payment_mode, $paid_amount, $due_amount, $sale_date);
                $stmt->execute();
                $sale_id = $stmt->insert_id;
                $stmt->close();

                // 2. Insert Sale Items + Deduct Stock
                $itemStmt  = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, qty, rate, amount, print_charge_total, print_type, print_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stockStmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");

                foreach ($items_to_save as $item) {
                    $itemStmt->bind_param('iiidddss', $sale_id, $item['product_id'], $item['qty'], $item['rate'], $item['amount'], $item['print_charge'], $item['print_type'], $item['print_notes']);
                    $itemStmt->execute();

                    $stockStmt->bind_param('ii', $item['qty'], $item['product_id']);
                    $stockStmt->execute();
                }
                $itemStmt->close();
                $stockStmt->close();

                // 3. AUTO SERVICE JOB GENERATION
                if ($print_total > 0 && $client_id > 0) {
                    $service_no = generate_no($conn, 'services', 'service_no', 'SRV');

                    $combined_notes = "Auto-generated from Sale: $sale_no\n\nPrint Details:\n";
                    foreach ($items_to_save as $item) {
                        if ($item['print_charge'] > 0 || $item['print_notes'] !== '') {
                            $combined_notes .= "\n# {$item['product_name']} (Qty: {$item['qty']})\n";
                            foreach (explode("\n", $item['print_notes']) as $ln) {
                                if (trim($ln) !== '') $combined_notes .= "  - $ln\n";
                            }
                        }
                    }

                    $srvStmt = $conn->prepare("INSERT INTO services 
                        (service_no, client_id, sale_id, service_type, qty, print_charge_per_pc, total_amount, notes, status, service_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");

                    $srv_qty  = 1;
                    $srv_rate = $print_total;
                    $srv_type = 'custom_print';

                    $srvStmt->bind_param('siisiddss', $service_no, $client_id, $sale_id, $srv_type, $srv_qty, $srv_rate, $print_total, $combined_notes, $sale_date);
                    $srvStmt->execute();
                    $service_job_id = $srvStmt->insert_id;
                    $srvStmt->close();

                    $updSale = $conn->prepare("UPDATE sales SET service_job_id = ? WHERE id = ?");
                    $updSale->bind_param('ii', $service_job_id, $sale_id);
                    $updSale->execute();
                    $updSale->close();
                }

                // 4. Payment record
                $payment_id = 0;
                if ($paid_amount > 0) {
                    $payment_no = generate_no($conn, 'payments', 'payment_no', 'PAY');
                    $notes_dummy = 'Sale payment';
                    $pStmt = $conn->prepare("INSERT INTO payments 
                        (payment_no, ref_type, ref_id, client_id, amount, payment_mode, payment_date, notes) 
                        VALUES (?, 'sale', ?, ?, ?, ?, ?, ?)");
                    $pStmt->bind_param('siidsss', $payment_no, $sale_id, $client_id, $paid_amount, $payment_mode, $sale_date, $notes_dummy);
                    $pStmt->execute();
                    $payment_id = $pStmt->insert_id;
                    $pStmt->close();
                }

                $conn->commit();
                set_flash('success', "Sale ($sale_no) created! Amount Paid: ₹$paid_amount, Due: ₹$due_amount");

                // 5. REDIRECT LOGIC
                if ($paid_amount > 0 && $payment_id > 0) {
                    redirect('view_sale_receipt.php?id=' . $payment_id . '&sale_id=' . $sale_id);
                } else {
                    redirect('view_sale_invoice.php?id=' . $sale_id);
                }

            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

include '../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    /* ===== SELECT2 DARK THEME ===== */
    .select2-container--default .select2-selection--single {
        background-color: #1e293b !important;
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
        overflow: hidden;
    }

    .select2-container--default .select2-search--dropdown .select2-search__field {
        background-color: #0f172a !important;
        border: 1px solid rgba(255,255,255,0.15) !important;
        color: #fff !important;
        border-radius: 0.4rem !important;
        padding: 6px !important;
    }

    .select2-container--default .select2-results__option {
        color: #e2e8f0 !important;
        background-color: #1e293b !important;
        font-size: 12px !important;
        padding: 8px 10px !important;
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #6366f1 !important;
        color: #fff !important;
    }

    .select2-container--default .select2-results__option[aria-selected=true] {
        background-color: #334155 !important;
        color: #fff !important;
    }

    .select2-container { width: 100% !important; }

    /* ===== PAYMENT MODE + GST DROPDOWNS ===== */
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
        color: #f1f5f9 !important;
    }

    select.dark-dropdown:focus {
        outline: none;
        border-color: rgba(99,102,241,0.7) !important;
        box-shadow: 0 0 0 2px rgba(99,102,241,0.12);
    }

    /* ===== PRINT ITEM STYLES ===== */
    select.pi-select, input.pi-input {
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

    select.pi-select:focus, input.pi-input:focus {
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
        .print-item { grid-template-columns: 1fr 1fr; }
        .print-item .pi-note-wrap { grid-column: 1 / -1; }
    }
</style>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">New Sale</h1>
        <p class="text-xs text-slate-400 mt-0.5">Customer ko maal bechen (Print, GST & Payment)</p>
    </div>
    <a href="sales_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-5xl" id="saleForm">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 pb-6 border-b border-white/10">
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Client <span class="text-rose-400">*</span></label>
            <select name="client_id" id="clientSelect" class="w-full">
                <option value="0">-- Walk-in Customer --</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= e($c['client_name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">Sale Date *</label>
            <input type="date" name="sale_date" required value="<?= date('Y-m-d') ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">Payment Mode</label>
            <select name="payment_mode" class="dark-dropdown w-full px-3 py-2 text-sm">
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank">Bank Transfer</option>
                <option value="card">Card</option>
            </select>
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">GST %</label>
            <select name="gst_percent" id="gstPercent" class="dark-dropdown w-full px-3 py-2 text-sm" onchange="calculateGrandTotal()">
                <option value="0">0% (No GST)</option>
                <option value="5">5% GST</option>
                <option value="12">12% GST</option>
                <option value="18" selected>18% GST</option>
                <option value="28">28% GST</option>
            </select>
        </div>
    </div>

    <div class="mb-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-sm font-semibold text-white">Products</h3>
            <button type="button" onclick="addRow()" class="px-3 py-1.5 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">
                <i class="fas fa-plus mr-1"></i> Add Product Row
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs" id="itemsTable">
                <thead>
                    <tr class="text-left border-b border-white/10">
                        <th class="pb-2 text-slate-400 font-medium w-1/3">Product</th>
                        <th class="pb-2 text-slate-400 font-medium text-center w-16">Qty</th>
                        <th class="pb-2 text-slate-400 font-medium text-right w-24">Rate (₹)</th>
                        <th class="pb-2 text-slate-400 font-medium text-right w-24">Print Total</th>
                        <th class="pb-2 text-slate-400 font-medium text-right w-24">Amount</th>
                        <th class="pb-2 text-slate-400 font-medium text-center w-8"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-6">
        <div class="bg-white/5 rounded-lg p-4 border border-white/10 w-full md:w-1/2">
            <h3 class="text-sm font-semibold text-white mb-3">Payment Details</h3>
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">Paid Amount (₹) *</label>
                    <input type="number" step="1" min="0" name="paid_amount" id="paidAmount" required value="0" oninput="calculateDue()" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-emerald-300 outline-none focus:border-indigo-500/50">
                </div>
                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">Due Amount (₹)</label>
                    <input type="text" id="dueAmountDisplay" readonly value="0" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-rose-300 outline-none cursor-not-allowed">
                </div>
            </div>
            <p class="text-[10px] text-slate-500 mt-2">Agar pura paisa de raha hai, toh Paid Amount me Grand Total daal de.</p>
        </div>

        <div class="bg-white/5 rounded-lg p-4 px-6 border border-white/10 text-right min-w-[250px] w-full md:w-auto">
            <div class="text-xs text-slate-400 flex justify-between mb-1"><span>Product Total:</span> <span id="prodTotalDisplay" class="text-white">₹0</span></div>
            <div class="text-xs text-slate-400 flex justify-between mb-1"><span>Print Total:</span> <span id="printTotalDisplay" class="text-amber-300">₹0</span></div>
            <div class="text-xs text-slate-400 flex justify-between mb-1 border-t border-white/5 pt-1"><span>Sub Total:</span> <span id="subTotalDisplay" class="text-white">₹0</span></div>
            <div class="text-xs text-slate-400 flex justify-between mb-2"><span>GST Amount:</span> <span id="gstDisplay" class="text-indigo-300">₹0</span></div>
            <div class="text-sm text-slate-300 font-semibold flex justify-between border-t border-white/10 pt-2"><span>Grand Total:</span> <span id="grandTotalDisplay" class="text-emerald-400 text-lg">₹0</span></div>
        </div>
    </div>

    <div class="flex items-center gap-2 pt-4 border-t border-white/5">
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">
            <i class="fas fa-save mr-1"></i> Save Sale
        </button>
        <a href="sales_list.php" class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition">Cancel</a>
    </div>
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const productsList = <?= json_encode($products) ?>;

const PRINT_POSITIONS = ['Front', 'Back', 'Left Chest', 'Right Chest', 'Left Sleeve', 'Right Sleeve', 'Neck / Collar', 'Pocket', 'Other'];
const PRINT_TYPES     = ['Logo', 'Name / Text', 'Number', 'Full Design', 'Other'];

let rowCounter = 0;

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

$(document).ready(function() {
    $('#clientSelect').select2({
        placeholder: 'Search client by name or phone...',
        allowClear: true,
        width: '100%'
    });
    addRow();
});

function applyProductSelect2() {
    $('.product-select').each(function() {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ placeholder: 'Search product...', width: '100%' });
            $(this).on('select2:select', function() { onProductChange(this); });
        }
    });
}

function addRow() {
    const idx = rowCounter++;
    const tbody = document.getElementById('itemsBody');

    let optionsHtml = '<option value="">-- Select Product --</option>';
    productsList.forEach(p => {
        optionsHtml += `<option value="${p.id}" data-price="${esc(p.sale_price)}" data-print="${esc(p.print_charge)}" data-stock="${esc(p.stock_qty)}">${esc(p.product_name)} (Stock: ${esc(p.stock_qty)})</option>`;
    });

    const row = document.createElement('tr');
    row.className = 'item-main-row border-t border-white/10';
    row.id = 'row_' + idx;
    row.dataset.idx = idx;
    row.innerHTML = `
        <td class="py-2 pr-2">
            <select name="items[${idx}][product_id]" required class="product-select w-full" data-idx="${idx}">
                ${optionsHtml}
            </select>
        </td>
        <td class="py-2 px-1">
            <input type="number" name="items[${idx}][qty]" min="1" value="1" required oninput="calculateGrandTotal()" class="row-qty w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-center text-slate-200 outline-none focus:border-indigo-500/50">
        </td>
        <td class="py-2 px-1">
            <input type="number" step="1" min="0" name="items[${idx}][rate]" required oninput="calculateGrandTotal()" class="row-rate w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-right text-slate-200 outline-none focus:border-indigo-500/50">
        </td>
        <td class="py-2 px-1 text-right text-amber-300 font-semibold row-print-total">₹0</td>
        <td class="py-2 px-1 text-right text-emerald-300 font-semibold row-amount">₹0</td>
        <td class="py-2 pl-2 text-center">
            <button type="button" onclick="removeRow(${idx})" class="w-6 h-6 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition">
                <i class="fas fa-times text-[10px]"></i>
            </button>
        </td>
    `;

    const printRow = document.createElement('tr');
    printRow.className = 'item-print-row';
    printRow.id = 'printrow_' + idx;
    printRow.innerHTML = `
        <td colspan="6" class="pb-4">
            <div class="bg-white/5 rounded-lg p-3 border border-white/10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[11px] text-slate-300 font-semibold">
                        <i class="fas fa-print mr-1 text-amber-300"></i> Print Items
                        <span class="text-slate-500 font-normal">(charge per piece hai, Qty se automatically multiply hoga)</span>
                    </span>
                    <button type="button" onclick="addPrintItem(${idx})" class="px-2.5 py-1 rounded-md bg-amber-500/15 text-amber-300 text-[11px] font-semibold hover:bg-amber-500/25 transition">
                        <i class="fas fa-plus mr-1"></i> Add Print
                    </button>
                </div>
                <div class="print-items" id="prints_${idx}" data-next="0"></div>
                <p class="print-empty text-[10px] text-slate-500">Koi print nahi. Print chahiye to "Add Print" dabayein.</p>
            </div>
        </td>
    `;

    tbody.prepend(row, printRow);
    applyProductSelect2();
}

function removeRow(idx) {
    const r = document.getElementById('row_' + idx);
    const p = document.getElementById('printrow_' + idx);
    if (r) r.remove();
    if (p) p.remove();
    calculateGrandTotal();
}

function buildOptions(list, selected) {
    return list.map(v => `<option value="${esc(v)}" ${v === selected ? 'selected' : ''}>${esc(v)}</option>`).join('');
}

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

    const defPos = defaults.position || (k === 0 ? 'Front' : (k === 1 ? 'Back' : 'Other'));

    const div = document.createElement('div');
    div.className = 'print-item';
    div.id = `pi_${idx}_${k}`;
    div.innerHTML = `
        <div>
            <select name="items[${idx}][prints][${k}][position]" class="pi-select" title="Kahan print hoga">
                ${buildOptions(PRINT_POSITIONS, defPos)}
            </select>
        </div>
        <div>
            <select name="items[${idx}][prints][${k}][type]" class="pi-select" title="Print ka type">
                ${buildOptions(PRINT_TYPES, defaults.type || 'Logo')}
            </select>
        </div>
        <div>
            <input type="number" step="1" min="0" name="items[${idx}][prints][${k}][charge]" value="${defCharge}" placeholder="₹ / pc" oninput="calculateGrandTotal()" class="pi-input pi-charge" title="Is print ka charge per piece (₹)">
        </div>
        <div class="pi-note-wrap">
            <input type="text" name="items[${idx}][prints][${k}][note]" placeholder="Instruction: e.g. company logo center me, size 4 inch" class="pi-input">
        </div>
        <button type="button" onclick="removePrintItem(${idx}, ${k})" class="w-7 h-7 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition" title="Remove print">
            <i class="fas fa-trash text-[10px]"></i>
        </button>
    `;
    wrap.appendChild(div);
    togglePrintEmpty(idx);
    calculateGrandTotal();
}

function removePrintItem(idx, k) {
    const el = document.getElementById(`pi_${idx}_${k}`);
    if (el) el.remove();
    togglePrintEmpty(idx);
    calculateGrandTotal();
}

function togglePrintEmpty(idx) {
    const wrap = document.getElementById('prints_' + idx);
    const empty = document.querySelector(`#printrow_${idx} .print-empty`);
    if (empty) empty.style.display = wrap.children.length ? 'none' : 'block';
}

function onProductChange(select) {
    const opt = select.options[select.selectedIndex];
    const price = opt ? opt.getAttribute('data-price') : null;
    const idx = select.dataset.idx;
    const row = document.getElementById('row_' + idx);

    if (price) row.querySelector('.row-rate').value = Math.round(price);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let prodTotal = 0;
    let printTotal = 0;

    document.querySelectorAll('#itemsBody > tr.item-main-row').forEach(row => {
        const idx = row.dataset.idx;
        const qty  = parseFloat(row.querySelector('.row-qty').value) || 0;
        const rate = parseFloat(row.querySelector('.row-rate').value) || 0;
        const amount = qty * rate;

        let printPerPc = 0;
        document.querySelectorAll(`#prints_${idx} .pi-charge`).forEach(inp => {
            printPerPc += parseFloat(inp.value) || 0;
        });
        const rowPrint = printPerPc * qty;

        row.querySelector('.row-amount').textContent = '₹' + amount.toFixed(0);
        row.querySelector('.row-print-total').innerHTML = '₹' + rowPrint.toFixed(0) +
            (printPerPc > 0 ? `<div class="text-[9px] text-slate-500 font-normal">₹${printPerPc.toFixed(0)} × ${qty}</div>` : '');

        prodTotal  += amount;
        printTotal += rowPrint;
    });

    const subTotal = prodTotal + printTotal;
    const gstPercent = parseFloat(document.getElementById('gstPercent').value) || 0;
    const gstAmount = Math.round((subTotal * gstPercent) / 100);
    const grandTotal = subTotal + gstAmount;

    document.getElementById('prodTotalDisplay').textContent = '₹' + prodTotal.toFixed(0);
    document.getElementById('printTotalDisplay').textContent = '₹' + printTotal.toFixed(0);
    document.getElementById('subTotalDisplay').textContent = '₹' + subTotal.toFixed(0);
    document.getElementById('gstDisplay').textContent = '₹' + gstAmount.toFixed(0);
    document.getElementById('grandTotalDisplay').textContent = '₹' + grandTotal.toFixed(0);

    calculateDue();
}

function calculateDue() {
    const grandTotalStr = document.getElementById('grandTotalDisplay').textContent.replace('₹', '');
    const grandTotal = parseFloat(grandTotalStr) || 0;
    const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;

    let due = grandTotal - paidAmount;
    if (due < 0) due = 0;

    document.getElementById('dueAmountDisplay').value = due.toFixed(0);
}
</script>

<?php include '../includes/footer.php'; ?>
