
<?php
// ============================================================
// FILE: products/products_list.php
// PRODUCT LIST WITH HSN CODE
// ============================================================
require_once '../config/config.php';
require_permission('products');

$page_title = 'Products';

// ============================================================
// SEARCH & FILTER
// ============================================================
$search       = trim($_GET['search'] ?? '');
$category_id  = (int)($_GET['category_id'] ?? 0);
$stock_filter = $_GET['stock'] ?? '';

$where  = "WHERE p.is_active = 1";
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (
        p.product_name LIKE ?
        OR p.color LIKE ?
        OR p.hsn_code LIKE ?
    )";

    $like = "%{$search}%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($category_id > 0) {
    $where .= " AND p.category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}

if ($stock_filter === 'low') {
    $where .= " AND p.stock_qty > 0 AND p.stock_qty <= 10";
} elseif ($stock_filter === 'out') {
    $where .= " AND p.stock_qty <= 0";
} elseif ($stock_filter === 'in') {
    $where .= " AND p.stock_qty > 10";
}

// ============================================================
// FETCH PRODUCTS
// ============================================================
$sql = "
    SELECT
        p.*,
        c.category_name,
        c.icon AS cat_icon
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    $where
    ORDER BY p.created_at DESC, p.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Query preparation failed: ' . e($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// FETCH CATEGORIES
// ============================================================
$all_cats = $conn->query("
    SELECT id, category_name
    FROM categories
    WHERE is_active = 1
    ORDER BY category_name
")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// SUMMARY
// ============================================================
$summary = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(stock_qty), 0) AS total_stock,
        COALESCE(SUM(stock_qty * sale_price), 0) AS stock_value
    FROM products
    WHERE is_active = 1
")->fetch_assoc();

include '../includes/header.php';
?>

<!-- PAGE HEADER -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Products
        </h1>
        <p class="text-xs text-slate-400 mt-0.5">
            Manage products, HSN codes and inventory
        </p>
    </div>

    <a href="add_product.php"
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">
        <i class="fas fa-plus-circle"></i>
        Add Product
    </a>
</div>

<!-- SUMMARY CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">

    <div class="glass-card rounded-xl p-3">
        <div class="text-[10px] text-slate-400 uppercase tracking-wide">
            Total Products
        </div>
        <div class="text-xl font-bold text-indigo-300 mt-1">
            <?= (int)$summary['total'] ?>
        </div>
    </div>

    <div class="glass-card rounded-xl p-3">
        <div class="text-[10px] text-slate-400 uppercase tracking-wide">
            Total Stock
        </div>
        <div class="text-xl font-bold text-emerald-300 mt-1">
            <?= (int)$summary['total_stock'] ?>
        </div>
    </div>

    <div class="glass-card rounded-xl p-3">
        <div class="text-[10px] text-slate-400 uppercase tracking-wide">
            Stock Value
        </div>
        <div class="text-xl font-bold text-amber-300 mt-1">
            <?= money($summary['stock_value']) ?>
        </div>
    </div>

</div>

<!-- FILTERS -->
<form method="GET"
      class="glass-dark rounded-2xl p-3 mb-4 flex flex-wrap gap-2 items-center">

    <div class="relative flex-1 min-w-[180px]">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>

        <input
            type="text"
            name="search"
            value="<?= e($search) ?>"
            placeholder="Search name, color or HSN code..."
            class="w-full bg-white/5 border border-white/10 rounded-lg pl-8 pr-3 py-2 text-xs text-slate-200 outline-none focus:border-indigo-500/50"
        >
    </div>

    <select
        name="category_id"
        class="bg-slate-900 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-200 outline-none focus:border-indigo-500/50">

        <option value="0">All Categories</option>

        <?php foreach ($all_cats as $c): ?>
            <option
                value="<?= (int)$c['id'] ?>"
                <?= $category_id === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['category_name']) ?>
            </option>
        <?php endforeach; ?>

    </select>

    <select
        name="stock"
        class="bg-slate-900 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-200 outline-none focus:border-indigo-500/50">

        <option value="">All Stock</option>

        <option value="in" <?= $stock_filter === 'in' ? 'selected' : '' ?>>
            In Stock (&gt;10)
        </option>

        <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>
            Low Stock (1-10)
        </option>

        <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>>
            Out of Stock
        </option>

    </select>

    <button type="submit"
            class="px-4 py-2 rounded-lg bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-medium hover:bg-indigo-500/30 transition">
        <i class="fas fa-filter mr-1"></i>
        Filter
    </button>

    <?php if ($search !== '' || $category_id > 0 || $stock_filter !== ''): ?>
        <a href="products_list.php"
           class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-400 text-xs hover:text-white transition">
            <i class="fas fa-times mr-1"></i>
            Clear
        </a>
    <?php endif; ?>

</form>

<!-- PRODUCTS TABLE -->
<div class="glass-dark rounded-2xl overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-xs">

            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">

                    <th class="px-3 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-3 py-3 text-slate-400 font-medium">Product</th>
                    <th class="px-3 py-3 text-slate-400 font-medium">HSN Code</th>
                    <th class="px-3 py-3 text-slate-400 font-medium">Category</th>
                    <th class="px-3 py-3 text-slate-400 font-medium">Color</th>
                    <th class="px-3 py-3 text-slate-400 font-medium text-right">Purchase</th>
                    <th class="px-3 py-3 text-slate-400 font-medium text-right">Sale</th>
                    <th class="px-3 py-3 text-slate-400 font-medium text-right">Print</th>
                    <th class="px-3 py-3 text-slate-400 font-medium text-center">Stock</th>
                    <th class="px-3 py-3 text-slate-400 font-medium text-center">Actions</th>

                </tr>
            </thead>

            <tbody>

                <?php if (!empty($products)): ?>

                    <?php foreach ($products as $i => $p):

                        $stockQty = (int)($p['stock_qty'] ?? 0);

                        if ($stockQty <= 0) {
                            $stockClass = 'bg-rose-500/20 text-rose-300';
                            $stockLabel = 'Out';
                        } elseif ($stockQty <= 10) {
                            $stockClass = 'bg-amber-500/20 text-amber-300';
                            $stockLabel = 'Low';
                        } else {
                            $stockClass = 'bg-emerald-500/20 text-emerald-300';
                            $stockLabel = 'In Stock';
                        }

                        $catName = $p['category_name'] ?? 'Unknown';
                        $catIcon = $p['cat_icon'] ?? 'fa-tag';

                        $catColor = 'indigo';

                        if (
                            stripos($catName, 'shirt') !== false
                            && stripos($catName, 't-shirt') === false
                        ) {
                            $catColor = 'emerald';
                        } elseif (stripos($catName, 'bottle') !== false) {
                            $catColor = 'amber';
                        }

                    ?>

                        <tr class="border-b border-white/5 hover:bg-white/3 transition">

                            <td class="px-3 py-3 text-slate-500">
                                <?= $i + 1 ?>
                            </td>

                            <!-- PRODUCT -->
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">

                                    <span class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center text-slate-300">
                                        <i class="fas <?= e($catIcon) ?>"></i>
                                    </span>

                                    <span class="text-white font-medium whitespace-nowrap">
                                        <?= e($p['product_name']) ?>
                                    </span>

                                </div>
                            </td>

                            <!-- HSN -->
                            <td class="px-3 py-3">
                                <?php if (!empty($p['hsn_code'])): ?>
                                    <span class="inline-flex px-2 py-1 rounded-md bg-cyan-500/10 border border-cyan-500/20 text-cyan-300 font-mono">
                                        <?= e($p['hsn_code']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-500">Not added</span>
                                <?php endif; ?>
                            </td>

                            <!-- CATEGORY -->
                            <td class="px-3 py-3">
                                <span class="text-[10px] px-2 py-1 rounded-full bg-<?= $catColor ?>-500/15 text-<?= $catColor ?>-300 whitespace-nowrap">
                                    <?= e($catName) ?>
                                </span>
                            </td>

                            <!-- COLOR -->
                            <td class="px-3 py-3 text-slate-300">
                                <?= e($p['color'] ?? '-') ?>
                            </td>

                            <!-- PURCHASE -->
                            <td class="px-3 py-3 text-right text-slate-300 whitespace-nowrap">
                                <?= money($p['purchase_price']) ?>
                            </td>

                            <!-- SALE -->
                            <td class="px-3 py-3 text-right text-emerald-300 font-medium whitespace-nowrap">
                                <?= money($p['sale_price']) ?>
                            </td>

                            <!-- PRINT -->
                            <td class="px-3 py-3 text-right text-amber-300 whitespace-nowrap">
                                <?= money($p['print_charge']) ?>
                            </td>

                            <!-- STOCK -->
                            <td class="px-3 py-3 text-center">
                                <div class="flex flex-col items-center gap-1">
                                    <span class="text-[10px] px-2 py-1 rounded-full font-semibold <?= $stockClass ?>">
                                        <?= $stockQty ?>
                                    </span>
                                    <span class="text-[9px] text-slate-500">
                                        <?= $stockLabel ?>
                                    </span>
                                </div>
                            </td>

                            <!-- ACTIONS -->
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-center gap-1">

                                    <a
                                        href="edit_product.php?id=<?= (int)$p['id'] ?>"
                                        class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 flex items-center justify-center hover:bg-indigo-500/30 transition"
                                        title="Edit Product">

                                        <i class="fas fa-edit text-[10px]"></i>
                                    </a>

                                    <a
                                        href="delete_product.php?id=<?= (int)$p['id'] ?>"
                                        onclick="return confirm('Delete this product?')"
                                        class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 flex items-center justify-center hover:bg-rose-500/30 transition"
                                        title="Delete Product">

                                        <i class="fas fa-trash text-[10px]"></i>
                                    </a>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="10" class="px-3 py-12 text-center text-slate-500">

                            <i class="fas fa-box-open text-3xl block mb-2 opacity-30"></i>

                            No products found.

                            <a href="add_product.php" class="text-indigo-400 underline">
                                Add Product
                            </a>

                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
