
<?php
// ============================================================
// FILE: products/add_product.php
// HSN CODE ADDED - WITHOUT SIZE
// SEARCHABLE CATEGORY + ADD CATEGORY POPUP
// ============================================================

require_once '../config/config.php';
require_permission('products');

$page_title = 'Add Product';
$error = '';

// ============================================================
// ADD CATEGORY HANDLER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_category') {

        $name = trim($_POST['new_category_name'] ?? '');
        $icon = trim($_POST['new_category_icon'] ?? 'fa-tag');

        if ($name === '') {

            set_flash('error', 'Category name required.');

        } else {

            $chk = $conn->prepare("
                SELECT id FROM categories
                WHERE category_name = ?
            ");
            $chk->bind_param('s', $name);
            $chk->execute();

            if ($chk->get_result()->num_rows > 0) {

                set_flash('error', 'Category already exists.');

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO categories
                    (category_name, icon, is_active)
                    VALUES (?, ?, 1)
                ");

                $stmt->bind_param('ss', $name, $icon);

                if ($stmt->execute()) {

                    $_SESSION['new_cat_id'] = $stmt->insert_id;
                    set_flash('success', 'Category added!');

                } else {

                    set_flash('error', 'DB Error: ' . $conn->error);
                }

                $stmt->close();
            }

            $chk->close();
        }

        redirect('add_product.php');
    }
}

// ============================================================
// MAIN PRODUCT ADD LOGIC
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {

    $name        = trim($_POST['product_name'] ?? '');
    $hsn_code    = trim($_POST['hsn_code'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $color       = trim($_POST['color'] ?? '');
    $purchase    = (float)($_POST['purchase_price'] ?? 0);
    $sale        = (float)($_POST['sale_price'] ?? 0);
    $print       = (float)($_POST['print_charge'] ?? 0);
    $stock       = (int)($_POST['stock_qty'] ?? 0);

    if ($name === '' || $category_id <= 0) {

        $error = 'Product name aur category required.';

    } elseif ($sale <= 0) {

        $error = 'Sale price 0 se bada hona chahiye.';

    } else {

        // Fetch category name for legacy enum column
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

            // Legacy category enum mapping
            $legacyCat = 'tshirt';
            $lc = strtolower($catRow['category_name']);

            if (strpos($lc, 'shirt') !== false) {
                $legacyCat = 'shirt';
            } elseif (strpos($lc, 'bottle') !== false) {
                $legacyCat = 'bottle';
            }

            // Insert product with HSN code
            $stmt = $conn->prepare("
                INSERT INTO products
                (
                    product_name,
                    hsn_code,
                    category,
                    capacity,
                    category_id,
                    color,
                    purchase_price,
                    sale_price,
                    print_charge,
                    stock_qty,
                    is_active
                )
                VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, 1)
            ");

            $stmt->bind_param(
                'sssisdddi',
                $name,
                $hsn_code,
                $legacyCat,
                $category_id,
                $color,
                $purchase,
                $sale,
                $print,
                $stock
            );

            if ($stmt->execute()) {

                $stmt->close();

                set_flash('success', 'Product added successfully!');
                redirect('products_list.php');

            } else {

                $error = 'Insert failed: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}

// ============================================================
// FETCH CATEGORIES
// ============================================================
$categories = $conn->query("
    SELECT *
    FROM categories
    ORDER BY category_name
")->fetch_all(MYSQLI_ASSOC);

// Auto select category after adding new category
$selected_cat = $_POST['category_id'] ?? ($_SESSION['new_cat_id'] ?? '');
unset($_SESSION['new_cat_id']);

// Font Awesome icons
$icons = [
    'fa-tag',
    'fa-tshirt',
    'fa-user-tie',
    'fa-wine-bottle',
    'fa-box',
    'fa-cube',
    'fa-gift',
    'fa-mug-hot',
    'fa-hat-cowboy',
    'fa-shoe-prints',
    'fa-print'
];

include '../includes/header.php';
?>

<style>
    .sd-wrap {
        position: relative;
    }

    .sd-trigger {
        width: 100%;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 0.5rem;
        padding: 0.55rem 0.75rem;
        font-size: 0.875rem;
        color: #cbd5e1;
        text-align: left;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        cursor: pointer;
        transition: all 0.2s;
        outline: none;
    }

    .sd-trigger:hover {
        border-color: rgba(255,255,255,0.18);
        background: rgba(255,255,255,0.07);
    }

    .sd-trigger.sd-open {
        border-color: rgba(99,102,241,0.5);
        box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
    }

    .sd-trigger .sd-text {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .sd-trigger .sd-text.sd-placeholder {
        color: #64748b;
    }

    .sd-trigger .sd-chevron {
        color: #64748b;
        font-size: 0.7rem;
        transition: transform 0.25s;
        flex-shrink: 0;
    }

    .sd-trigger.sd-open .sd-chevron {
        transform: rotate(180deg);
        color: #818cf8;
    }

    .sd-panel {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        z-index: 50;
        background: rgba(15, 23, 42, 0.98);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 0.75rem;
        box-shadow: 0 20px 50px -15px rgba(0,0,0,0.9);
        overflow: hidden;
        opacity: 0;
        transform: translateY(-6px) scale(0.98);
        pointer-events: none;
        transition: opacity 0.2s, transform 0.2s;
    }

    .sd-panel.sd-show {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: auto;
    }

    .sd-search-wrap {
        padding: 0.6rem;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.02);
    }

    .sd-search-wrap i {
        color: #64748b;
        font-size: 0.8rem;
    }

    .sd-search {
        flex: 1;
        background: transparent;
        border: none;
        outline: none;
        color: #e2e8f0;
        font-size: 0.85rem;
        padding: 0.25rem 0;
    }

    .sd-search::placeholder {
        color: #475569;
    }

    .sd-list {
        max-height: 240px;
        overflow-y: auto;
        padding: 0.35rem;
    }

    .sd-list::-webkit-scrollbar {
        width: 6px;
    }

    .sd-list::-webkit-scrollbar-track {
        background: transparent;
    }

    .sd-list::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.1);
        border-radius: 3px;
    }

    .sd-option {
        padding: 0.55rem 0.7rem;
        border-radius: 0.45rem;
        font-size: 0.85rem;
        color: #cbd5e1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        transition: background 0.15s, color 0.15s;
    }

    .sd-option:hover,
    .sd-option.sd-active {
        background: rgba(99,102,241,0.15);
        color: #fff;
    }

    .sd-option.sd-selected {
        background: rgba(99,102,241,0.2);
        color: #a5b4fc;
        font-weight: 500;
    }

    .sd-option.sd-selected::after {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        font-size: 0.7rem;
        color: #818cf8;
    }

    .sd-empty {
        padding: 1.2rem 0.75rem;
        text-align: center;
        font-size: 0.8rem;
        color: #64748b;
    }

    .sd-empty i {
        font-size: 1.5rem;
        display: block;
        margin-bottom: 0.5rem;
        opacity: 0.4;
    }

    .sd-footer {
        border-top: 1px solid rgba(255,255,255,0.06);
        padding: 0.5rem;
        background: rgba(255,255,255,0.02);
    }

    .sd-add-btn {
        width: 100%;
        padding: 0.55rem;
        border-radius: 0.5rem;
        background: rgba(99,102,241,0.1);
        border: 1px dashed rgba(99,102,241,0.35);
        color: #a5b4fc;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
    }

    .sd-add-btn:hover {
        background: rgba(99,102,241,0.2);
        border-color: rgba(129,140,248,0.5);
        color: #c7d2fe;
    }

    /* Modal Tabs */
    .modal-tab {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        color: #94a3b8;
        border-bottom: 2px solid transparent;
        cursor: pointer;
        transition: all 0.2s;
    }

    .modal-tab.active {
        color: #818cf8;
        border-bottom-color: #818cf8;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Edit list row */
    .edit-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem;
        border-radius: 0.5rem;
        background: rgba(255,255,255,0.02);
        margin-bottom: 0.4rem;
        border: 1px solid rgba(255,255,255,0.05);
    }
</style>

<!-- PAGE HEADER -->
<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Add Product</h1>
        <p class="text-xs text-slate-400 mt-0.5">T-shirt / Shirt / Bottle</p>
    </div>

    <a href="products_list.php"
       class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- PRODUCT FORM -->
<!-- ============================================================ -->
<form method="POST" class="glass-dark rounded-2xl p-5 max-w-3xl" id="productForm">

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
                value="<?= e($_POST['product_name'] ?? '') ?>"
                placeholder="e.g. White Round Neck T-Shirt"
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
                value="<?= e($_POST['hsn_code'] ?? '') ?>"
                placeholder="e.g. 6109"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

            <p class="text-[10px] text-slate-500 mt-1">
                Enter applicable GST HSN code
            </p>
        </div>

        <!-- CATEGORY SEARCHABLE -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Category *
            </label>

            <div class="sd-wrap" id="catDropdown">

                <button type="button" class="sd-trigger" data-trigger>
                    <span class="sd-text sd-placeholder" data-text>
                        -- Select Category --
                    </span>
                    <i class="fas fa-chevron-down sd-chevron"></i>
                </button>

                <div class="sd-panel" data-panel>

                    <div class="sd-search-wrap">
                        <i class="fas fa-search"></i>
                        <input
                            type="text"
                            class="sd-search"
                            placeholder="Search categories..."
                            data-search
                        >
                    </div>

                    <div class="sd-list" data-list>

                        <?php foreach ($categories as $c): ?>
                            <div
                                class="sd-option"
                                data-value="<?= (int)$c['id'] ?>"
                                data-label="<?= e($c['category_name']) ?>"
                            >
                                <?= e($c['category_name']) ?>
                            </div>
                        <?php endforeach; ?>

                    </div>

                    <div class="sd-footer flex gap-2">

                        <button
                            type="button"
                            class="sd-add-btn"
                            onclick="openModal('categoryModal', 'add')"
                        >
                            <i class="fas fa-plus-circle"></i> Add New
                        </button>

                        <button
                            type="button"
                            class="sd-add-btn"
                            onclick="openModal('categoryModal', 'edit')"
                        >
                            <i class="fas fa-edit"></i> Edit / List
                        </button>

                    </div>
                </div>

                <input
                    type="hidden"
                    name="category_id"
                    data-hidden
                    required
                    value="<?= e($selected_cat) ?>"
                >

            </div>
        </div>

        <!-- COLOR -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Color
            </label>

            <input
                type="text"
                name="color"
                value="<?= e($_POST['color'] ?? '') ?>"
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
                value="<?= e($_POST['purchase_price'] ?? '0') ?>"
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
                value="<?= e($_POST['sale_price'] ?? '0') ?>"
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
                value="<?= e($_POST['print_charge'] ?? '0') ?>"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >
        </div>

        <!-- OPENING STOCK -->
        <div>
            <label class="text-xs text-slate-400 mb-1 block">
                Opening Stock Qty
            </label>

            <input
                type="number"
                min="0"
                name="stock_qty"
                value="<?= e($_POST['stock_qty'] ?? '0') ?>"
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
            <i class="fas fa-save mr-1"></i> Save Product
        </button>

        <a
            href="products_list.php"
            class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition"
        >
            Cancel
        </a>

    </div>

</form>

<!-- ============================================================ -->
<!-- CATEGORY MODAL -->
<!-- ============================================================ -->
<div
    id="categoryModal"
    class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4"
>
    <div class="glass-dark rounded-2xl w-full max-w-md relative">

        <button
            type="button"
            onclick="closeModal('categoryModal')"
            class="absolute top-4 right-4 text-slate-400 hover:text-white z-10"
        >
            <i class="fas fa-times"></i>
        </button>

        <!-- TABS -->
        <div class="flex border-b border-white/10 pt-2 px-2">

            <div
                class="modal-tab active"
                id="catTabAdd"
                onclick="switchTab('categoryModal', 'add')"
            >
                <i class="fas fa-plus-circle mr-1"></i> Add New
            </div>

            <div
                class="modal-tab"
                id="catTabEdit"
                onclick="switchTab('categoryModal', 'edit')"
            >
                <i class="fas fa-list mr-1"></i> Edit / List
            </div>

        </div>

        <div class="p-6">

            <!-- ADD CATEGORY TAB -->
            <div id="categoryModalAdd" class="tab-content active">

                <form method="POST" class="space-y-4">

                    <input
                        type="hidden"
                        name="action"
                        value="add_category"
                    >

                    <div>
                        <label class="text-xs text-slate-400 mb-1 block">
                            Category Name *
                        </label>

                        <input
                            type="text"
                            name="new_category_name"
                            required
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                            placeholder="e.g. Cap, Mug"
                        >
                    </div>

                    <div>
                        <label class="text-xs text-slate-400 mb-1 block">
                            Icon (FontAwesome class)
                        </label>

                        <select
                            name="new_category_icon"
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                            style="color-scheme:dark;"
                        >
                            <?php foreach ($icons as $ic): ?>
                                <option value="<?= e($ic) ?>">
                                    <?= e($ic) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="w-full py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 transition-all"
                    >
                        <i class="fas fa-plus-circle mr-1"></i> Add Category
                    </button>

                </form>
            </div>

            <!-- EDIT CATEGORY TAB -->
            <div id="categoryModalEdit" class="tab-content">

                <div class="max-h-64 overflow-y-auto pr-1">

                    <?php foreach ($categories as $c): ?>

                        <div class="edit-row">

                            <span class="text-sm text-white">
                                <?= e($c['category_name']) ?>
                            </span>

                            <a
                                href="../categories/edit_category.php?id=<?= (int)$c['id'] ?>"
                                class="text-xs px-2 py-1 rounded bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/30 transition"
                            >
                                <i class="fas fa-edit"></i> Edit Full
                            </a>

                        </div>

                    <?php endforeach; ?>

                    <?php if (empty($categories)): ?>

                        <div class="text-center text-xs text-slate-500 py-4">
                            No categories yet.
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
// ============================================================
// MODAL & TABS
// ============================================================

function openModal(id, defaultTab = 'add') {
    document.getElementById(id).classList.remove('hidden');
    switchTab(id, defaultTab);
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function switchTab(modalId, tabName) {

    const prefix = modalId;

    document.getElementById(prefix + 'Add').classList.remove('active');
    document.getElementById(prefix + 'Edit').classList.remove('active');

    if (tabName === 'add') {

        document.getElementById(prefix + 'Add').classList.add('active');
        document.getElementById('catTabAdd').classList.add('active');
        document.getElementById('catTabEdit').classList.remove('active');

    } else {

        document.getElementById(prefix + 'Edit').classList.add('active');
        document.getElementById('catTabEdit').classList.add('active');
        document.getElementById('catTabAdd').classList.remove('active');
    }
}

// ============================================================
// SEARCHABLE CATEGORY DROPDOWN
// ============================================================

(function () {

    const dropdowns = [];

    function initDropdown(wrapId) {

        const wrap = document.getElementById(wrapId);
        if (!wrap) return;

        const trigger = wrap.querySelector('[data-trigger]');
        const textEl  = wrap.querySelector('[data-text]');
        const panel   = wrap.querySelector('[data-panel]');
        const search  = wrap.querySelector('[data-search]');
        const list    = wrap.querySelector('[data-list]');
        const hidden  = wrap.querySelector('[data-hidden]');

        const options = Array.from(list.querySelectorAll('.sd-option'));

        let activeIndex = -1;
        let visibleOptions = options;

        const inst = {
            wrap,
            trigger,
            textEl,
            panel,
            search,
            list,
            hidden,
            options,
            close
        };

        dropdowns.push(inst);

        // Initial selected category
        const initialVal = hidden.value;

        if (initialVal && initialVal !== '0') {

            const match = options.find(o => o.dataset.value === initialVal);

            if (match) {

                textEl.textContent = match.dataset.label;
                textEl.classList.remove('sd-placeholder');

                options.forEach(o => o.classList.remove('sd-selected'));
                match.classList.add('sd-selected');
            }
        }

        trigger.addEventListener('click', (e) => {

            e.stopPropagation();

            if (panel.classList.contains('sd-show')) {
                close();
            } else {
                open();
            }
        });

        // Search
        search.addEventListener('input', () => {

            const q = search.value.toLowerCase().trim();

            activeIndex = -1;
            visibleOptions = [];

            options.forEach(o => {

                const label = o.dataset.label.toLowerCase();
                const match = q === '' || label.includes(q);

                o.style.display = match ? '' : 'none';

                if (match) {
                    visibleOptions.push(o);
                }
            });

            let empty = list.querySelector('.sd-empty');

            const noResults = visibleOptions.length === 0;

            if (noResults && !empty) {

                empty = document.createElement('div');
                empty.className = 'sd-empty';
                empty.innerHTML = '<i class="fas fa-search"></i>No results found';

                list.appendChild(empty);

            } else if (!noResults && empty) {

                empty.remove();
            }

            options.forEach(o => o.classList.remove('sd-active'));
        });

        // Keyboard navigation
        search.addEventListener('keydown', (e) => {

            if (e.key === 'ArrowDown') {

                e.preventDefault();

                if (visibleOptions.length === 0) return;

                activeIndex = (activeIndex + 1) % visibleOptions.length;
                highlightActive();

            } else if (e.key === 'ArrowUp') {

                e.preventDefault();

                if (visibleOptions.length === 0) return;

                activeIndex = (activeIndex - 1 + visibleOptions.length) % visibleOptions.length;
                highlightActive();

            } else if (e.key === 'Enter') {

                e.preventDefault();

                if (activeIndex >= 0 && visibleOptions[activeIndex]) {
                    selectOption(visibleOptions[activeIndex]);
                }

            } else if (e.key === 'Escape') {

                close();
                trigger.focus();
            }
        });

        function highlightActive() {

            options.forEach(o => o.classList.remove('sd-active'));

            const opt = visibleOptions[activeIndex];

            if (opt) {

                opt.classList.add('sd-active');
                opt.scrollIntoView({ block: 'nearest' });
            }
        }

        options.forEach(opt => {

            opt.addEventListener('click', () => {
                selectOption(opt);
            });

        });

        function selectOption(opt) {

            hidden.value = opt.dataset.value;

            textEl.textContent = opt.dataset.label;
            textEl.classList.remove('sd-placeholder');

            options.forEach(o => o.classList.remove('sd-selected'));
            opt.classList.add('sd-selected');

            close();
        }

        function open() {

            dropdowns.forEach(d => {
                if (d !== inst) d.close();
            });

            trigger.classList.add('sd-open');
            panel.classList.add('sd-show');

            search.value = '';
            search.dispatchEvent(new Event('input'));

            setTimeout(() => search.focus(), 50);
        }

        function close() {

            trigger.classList.remove('sd-open');
            panel.classList.remove('sd-show');

            activeIndex = -1;

            options.forEach(o => o.classList.remove('sd-active'));
        }

        panel.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }

    initDropdown('catDropdown');

    document.addEventListener('click', () => {
        dropdowns.forEach(d => d.close());
    });

    document.addEventListener('keydown', (e) => {

        if (e.key === 'Escape') {
            dropdowns.forEach(d => d.close());
        }
    });

    // Validate category before product submit
    const form = document.getElementById('productForm');

    if (form) {

        form.addEventListener('submit', (e) => {

            const catHidden = document.querySelector('#catDropdown [data-hidden]');

            if (!catHidden.value || catHidden.value === '0') {

                e.preventDefault();

                alert('Please select a category');

                document.querySelector('#catDropdown [data-trigger]').focus();
            }
        });
    }

})();
</script>

<?php include '../includes/footer.php'; ?>
