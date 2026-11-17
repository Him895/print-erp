<?php
// ============================================================
// FILE: product_purchase/edit_purchase.php
// ============================================================
require_once '../config/config.php';
require_permission('purchases');
$page_title = 'Edit Purchase';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('purchases_list.php');

// Fetch main purchase
$stmt = $conn->prepare("SELECT * FROM product_purchase WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$purchase) { set_flash('error', 'Purchase not found.'); redirect('purchases_list.php'); }

// Fetch existing items
$itemsStmt = $conn->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
$itemsStmt->bind_param('i', $id);
$itemsStmt->execute();
$existing_items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Fetch vendors and products
$vendors = $conn->query("SELECT * FROM vendors WHERE is_active = 1 ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT * FROM products WHERE is_active = 1 ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);

$error = '';

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
            // Payment calculation
            $due_amount = $grand_total - $paid_amount;
            if ($due_amount < 0) $due_amount = 0;

            $conn->begin_transaction();

            try {
                // 1. Revert Old Stock (-)
                $revertStmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
                foreach ($existing_items as $old_item) {
                    $revertStmt->bind_param('ii', $old_item['qty'], $old_item['product_id']);
                    $revertStmt->execute();
                }
                $revertStmt->close();

                // 2. Delete Old Items
                $delStmt = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
                $delStmt->bind_param('i', $id);
                $delStmt->execute();
                $delStmt->close();

                // 3. Update Main Purchase Record (With Payment Details)
                $stmt = $conn->prepare("UPDATE product_purchase SET 
                    vendor_id=?, grand_total=?, paid_amount=?, due_amount=?, payment_mode=?, purchase_date=? 
                    WHERE id=?");
                $stmt->bind_param('idddssi', $vendor_id, $grand_total, $paid_amount, $due_amount, $payment_mode, $purchase_date, $id);
                $stmt->execute();
                $stmt->close();

                // 4. Insert New Items & Update Stock (+)
                $itemStmt = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                $stockStmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");

                foreach ($items_to_save as $item) {
                    $itemStmt->bind_param('iiidd', $id, $item['product_id'], $item['qty'], $item['rate'], $item['amount']);
                    $itemStmt->execute();

                    $stockStmt->bind_param('ii', $item['qty'], $item['product_id']);
                    $stockStmt->execute();
                }
                
                $itemStmt->close();
                $stockStmt->close();

                $conn->commit();
                set_flash('success', "Purchase updated successfully! Stock & Payment adjusted.");
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

<style>
    select { background-color: #1e293b !important; color: #fff !important; }
    select option { background-color: #1e293b !important; color: #fff !important; }
</style>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Edit Purchase</h1>
        <p class="text-xs text-slate-400 mt-0.5">Order #<?= e($purchase['purchase_no']) ?></p>
    </div>
    <a href="purchases_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-5xl" id="purchaseForm">
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 pb-6 border-b border-white/10">
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Vendor *</label>
            <select name="vendor_id" required class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
                <option value="">-- Select Vendor --</option>
                <?php foreach ($vendors as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= $purchase['vendor_id'] == $v['id'] ? 'selected' : '' ?>><?= e($v['vendor_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Purchase Date *</label>
            <input type="date" name="purchase_date" required value="<?= e($purchase['purchase_date']) ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
        </div>
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Payment Mode</label>
            <select name="payment_mode" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
                <option value="cash" <?= $purchase['payment_mode'] == 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="upi" <?= $purchase['payment_mode'] == 'upi' ? 'selected' : '' ?>>UPI</option>
                <option value="bank" <?= $purchase['payment_mode'] == 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="card" <?= $purchase['payment_mode'] == 'card' ? 'selected' : '' ?>>Card</option>
            </select>
        </div>
    </div>

    <div class="mb-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-sm font-semibold text-white">Purchase Items</h3>
            <button type="button" onclick="addRow()" class="px-3 py-1.5 rounded-lg bg-indigo-500/20 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/30 transition">
                <i class="fas fa-plus mr-1"></i> Add Product Row
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs" id="itemsTable">
                <thead>
                    <tr class="text-left border-b border-white/10">
                        <th class="pb-2 text-slate-400 font-medium w-1/2">Product</th>
                        <th class="pb-2 text-slate-400 font-medium text-center w-20">Qty</th>
                        <th class="pb-2 text-slate-400 font-medium text-right w-28">Rate (₹)</th>
                        <th class="pb-2 text-slate-400 font-medium text-right w-28">Amount (₹)</th>
                        <th class="pb-2 text-slate-400 font-medium text-center w-10"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Payment & Grand Total -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-6">
        <div class="bg-white/5 rounded-lg p-4 border border-white/10 w-full md:w-1/2">
            <h3 class="text-sm font-semibold text-white mb-3">Vendor Payment</h3>
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">Paid Amount (₹) *</label>
                    <input type="number" step="1" min="0" name="paid_amount" id="paidAmount" value="<?= (int)$purchase['paid_amount'] ?>" oninput="calculateDue()" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-emerald-300 outline-none focus:border-indigo-500/50">
                </div>
                <div class="flex-1">
                    <label class="text-xs text-slate-400 mb-1 block">Due Amount (₹)</label>
                    <input type="text" id="dueAmountDisplay" readonly value="<?= (int)$purchase['due_amount'] ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-rose-300 outline-none cursor-not-allowed">
                </div>
            </div>
        </div>

        <div class="bg-white/5 rounded-lg p-3 px-5 border border-white/10 text-right w-full md:w-auto">
            <span class="text-xs text-slate-400 block mb-1">Grand Total</span>
            <span class="text-xl font-bold text-emerald-300" id="grandTotalDisplay">₹0</span>
        </div>
    </div>

    <div class="flex items-center gap-2 pt-4 border-t border-white/5">
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">
            <i class="fas fa-save mr-1"></i> Update Purchase
        </button>
        <a href="purchases_list.php" class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition">Cancel</a>
    </div>
</form>

<script>
const productsList = <?= json_encode($products) ?>;
const existingItems = <?= json_encode($existing_items) ?>;

function addRow(selectedProductId = '', qty = 1, rate = 0) {
    const tbody = document.getElementById('itemsBody');
    const rowId = Date.now() + Math.random().toString(36).substr(2, 5);

    const row = document.createElement('tr');
    row.className = 'border-b border-white/5 hover:bg-white/3 transition';
    row.id = 'row_' + rowId;

    let optionsHtml = '<option value="">-- Select Product --</option>';
    productsList.forEach(p => {
        const selected = (p.id == selectedProductId) ? 'selected' : '';
        optionsHtml += `<option value="${p.id}" data-price="${p.purchase_price}" ${selected}>${p.product_name} (Stock: ${p.stock_qty})</option>`;
    });

    row.innerHTML = `
        <td class="py-2 pr-2">
            <select name="product_id[]" required onchange="onProductChange(this)" class="w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-slate-200 outline-none focus:border-indigo-500/50">
                ${optionsHtml}
            </select>
        </td>
        <td class="py-2 px-1">
            <input type="number" name="qty[]" min="1" value="${qty}" required oninput="calculateRow('${rowId}')" class="w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-center text-slate-200 outline-none focus:border-indigo-500/50">
        </td>
        <td class="py-2 px-1">
            <input type="number" step="1" min="0" name="rate[]" required value="${rate}" oninput="calculateRow('${rowId}')" class="w-full bg-white/5 border border-white/10 rounded-lg px-2 py-1.5 text-xs text-right text-slate-200 outline-none focus:border-indigo-500/50 row-rate">
        </td>
        <td class="py-2 px-1 text-right text-emerald-300 font-semibold row-amount">₹0</td>
        <td class="py-2 pl-2 text-center">
            <button type="button" onclick="removeRow('${rowId}')" class="w-6 h-6 rounded bg-rose-500/15 text-rose-300 hover:bg-rose-500/30 transition">
                <i class="fas fa-times text-[10px]"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    calculateRow(rowId);
}

function removeRow(rowId) {
    document.getElementById('row_' + rowId).remove();
    calculateGrandTotal();
}

function onProductChange(select) {
    const price = select.options[select.selectedIndex].getAttribute('data-price');
    const row = select.closest('tr');
    if (price) row.querySelector('.row-rate').value = Math.round(price);
    calculateRow(row.id.replace('row_', ''));
}

function calculateRow(rowId) {
    const row = document.getElementById('row_' + rowId);
    const qty = parseFloat(row.querySelector('input[name="qty[]"]').value) || 0;
    const rate = parseFloat(row.querySelector('input[name="rate[]"]').value) || 0;
    const amount = qty * rate;
    row.querySelector('.row-amount').textContent = '₹' + amount.toFixed(0);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let total = 0;
    document.querySelectorAll('.row-amount').forEach(el => {
        total += parseFloat(el.textContent.replace('₹', '')) || 0;
    });
    document.getElementById('grandTotalDisplay').textContent = '₹' + total.toFixed(0);
    calculateDue();
}

function calculateDue() {
    const grandTotalStr = document.getElementById('grandTotalDisplay').textContent.replace('₹', '');
    const grandTotal = parseFloat(grandTotalStr) || 0;
    const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
    
    let due = grandTotal - paid;
    if (due < 0) due = 0;
    
    document.getElementById('dueAmountDisplay').value = due.toFixed(0);
}

window.onload = function() {
    if (existingItems.length > 0) {
        existingItems.forEach(item => {
            addRow(item.product_id, item.qty, item.rate);
        });
    } else {
        addRow();
    }
};
</script>

<?php include '../includes/footer.php'; ?>