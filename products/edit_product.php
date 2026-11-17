
<?php
// ============================================================
// FILE: products/edit_product.php
// HSN CODE ADDED - WITHOUT SIZE
// ============================================================

require_once '../config/config.php';
require_permission('products');

$page_title = 'Edit Product';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('products_list.php');
}

// ============================================================
// FETCH PRODUCT
// ============================================================
$stmt = $conn->prepare("
    SELECT *
    FROM products
    WHERE id = ? AND is_active = 1
");

$stmt->bind_param('i', $id);
$stmt->execute();

$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('products_list.php');
}

$error = '';

// ============================================================
// UPDATE PRODUCT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name        = trim($_POST['product_name'] ?? '');
    $hsn_code    = trim($_POST['hsn_code'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $color       = trim($_POST['color'] ?? '');
    $purchase    = (float)($_POST['purchase_price'] ?? 0);
    $sale        = (float)($_POST['sale_price'] ?? 0);
    $print       = (float)($_POST['print_charge'] ?? 0);
    $stock       = (int)($_POST['stock_qty'] ?? 0);

    // Validation
    if ($name === '' || $category_id <= 0) {

        $error = 'Name aur category required.';

    } elseif ($sale <= 0) {

        $error = 'Sale price 0 se bada hona chahiye.';

    } elseif ($purchase < 0 || $print < 0 || $stock < 0) {

        $error = 'Price aur stock negative nahi ho sakte.';

    } else {

        // ====================================================
        // FETCH CATEGORY NAME
        // ====================================================
        $catStmt = $conn->prepare("
            SELECT category_name
            FROM categories
            WHERE id = ?
        ");

        $catStmt->bind_param('i', $category_id);
        $catStmt->execute();

        $catRow = $catStmt->get_result()->fetch_assoc();
        $catStmt->close();

        if (!$catRow) {

            $error = 'Selected category not found.';

        } else {

            // =================================================
            // LEGACY CATEGORY ENUM MAPPING
            // =================================================
            $legacyCat = 'tshirt';

            $lc = strtolower($catRow['category_name']);

            if (strpos($lc, 'shirt') !== false) {
                $legacyCat = 'shirt';
            } elseif (strpos($lc, 'bottle') !== false) {
                $legacyCat = 'bottle';
            }

            // =================================================
            // UPDATE PRODUCT WITH HSN CODE
            // =================================================
            $stmt = $conn->prepare("
                UPDATE products SET
                    product_name = ?,
                    hsn_code = ?,
                    category = ?,
                    category_id = ?,
                    color = ?,
                    purchase_price = ?,
                    sale_price = ?,
                    print_charge = ?,
                    stock_qty = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'sssisdddii',
                $name,
                $hsn_code,
                $legacyCat,
                $category_id,
                $color,
                $purchase,
                $sale,
                $print,
                $stock,
                $id
            );

            if ($stmt->execute()) {

                $stmt->close();

                set_flash('success', 'Product updated successfully!');
                redirect('products_list.php');

            } else {

                $error = 'Update failed: ' . $stmt->error;
                $stmt->close();
            }
        }
    }

    // Preserve entered values if validation/update fails
    $product['product_name']   = $name;
    $product['hsn_code']       = $hsn_code;
    $product['category_id']    = $category_id;
    $product['color']          = $color;
    $product['purchase_price'] = $purchase;
    $product['sale_price']     = $sale;
    $product['print_charge']   = $print;
    $product['stock_qty']      = $stock;
}

// ============================================================
// FETCH CATEGORIES
// ============================================================
$categories = $conn->query("
    SELECT *
    FROM categories
    ORDER BY category_name
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<!-- PAGE HEADER -->
<div class="flex items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white">
            Edit Product
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            ID #<?= (int)$id ?>
        </p>
    </div>

    <a
        href="products_list.php"
        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition"
    >
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>

</div>

<!-- ERROR MESSAGE -->
<?php if ($error): ?>

    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">

        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= e($error) ?>

    </div>

<?php endif; ?>

<!-- ============================================================ -->
<!-- EDIT PRODUCT FORM -->
<!-- ============================================================ -->
<form method="POST" class="glass-dark rounded-2xl p-5 max-w-3xl">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <!-- PRODUCT NAME -->
        <div class="md:col-span-2">

            <label class="text-xs text-slate-400 mb-1 block">
                Product Name *
            </label>

            <input
                type="text"
                name="product_name"
                required
                value="<?= e($product['product_name']) ?>"
                placeholder="Enter product name"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <!-- HSN CODE -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                HSN Code
            </label>

            <input
                type="text"
                name="hsn_code"
                maxlength="20"
                value="<?= e($product['hsn_code'] ?? '') ?>"
                placeholder="e.g. 6109"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

            <p class="text-[10px] text-slate-500 mt-1">
                Enter applicable GST HSN code
            </p>

        </div>

        <!-- CATEGORY -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Category *
            </label>

            <select
                name="category_id"
                required
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                style="color-scheme: dark;"
            >

                <option value="">
                    -- Select Category --
                </option>

                <?php foreach ($categories as $c): ?>

                    <option
                        value="<?= (int)$c['id'] ?>"
                        <?= (int)$product['category_id'] === (int)$c['id'] ? 'selected' : '' ?>
                    >
                        <?= e($c['category_name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <!-- COLOR -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Color
            </label>

            <input
                type="text"
                name="color"
                value="<?= e($product['color'] ?? '') ?>"
                placeholder="e.g. White"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <!-- PURCHASE PRICE -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Purchase Price (₹)
            </label>

            <input
                type="number"
                step="0.01"
                min="0"
                name="purchase_price"
                value="<?= e($product['purchase_price'] ?? '0') ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <!-- SALE PRICE -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Sale Price (₹) *
            </label>

            <input
                type="number"
                step="0.01"
                min="0"
                name="sale_price"
                required
                value="<?= e($product['sale_price'] ?? '0') ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <!-- PRINT CHARGE -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Default Print Charge (₹)
            </label>

            <input
                type="number"
                step="0.01"
                min="0"
                name="print_charge"
                value="<?= e($product['print_charge'] ?? '0') ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

        <!-- STOCK -->
        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Stock Qty
            </label>

            <input
                type="number"
                min="0"
                name="stock_qty"
                value="<?= e($product['stock_qty'] ?? '0') ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>

    </div>

    <!-- BUTTONS -->
    <div class="flex items-center gap-2 mt-5 pt-4 border-t border-white/5">

        <button
            type="submit"
            class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all"
        >
            <i class="fas fa-save mr-1"></i>
            Update Product
        </button>

        <a
            href="products_list.php"
            class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition"
        >
            Cancel
        </a>

    </div>

</form>

<?php include '../includes/footer.php'; ?>
