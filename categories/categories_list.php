<?php
// ============================================================
// FILE: categories/categories_list.php (PREMIUM)
// ============================================================
require_once '../config/config.php';
require_permission('categories');
$page_title = 'Categories';

// Fetch all
$categories = $conn->query("SELECT c.*, 
    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
    FROM categories c ORDER BY c.created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Quick summary stats
$totalCategories  = count($categories);
$totalProducts    = 0;
foreach ($categories as $c) {
    $totalProducts += (int)($c['product_count'] ?? 0);
}

include '../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     PREMIUM STYLES
     ═══════════════════════════════════════════════════════════ -->
<style>
    .cat-bg { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
    .cat-blob { position: absolute; border-radius: 50%; filter: blur(90px); opacity: 0.25; animation: catBlobFloat 22s ease-in-out infinite; }
    .cat-blob.b1 { width: 420px; height: 420px; background: radial-gradient(circle, #6366f1, transparent 70%); top: -150px; left: -120px; }
    .cat-blob.b2 { width: 380px; height: 380px; background: radial-gradient(circle, #a855f7, transparent 70%); bottom: -140px; right: -80px; animation-delay: -8s; }
    @keyframes catBlobFloat { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(50px, -40px) scale(1.15); } }

    .cat-stat { position: relative; background: rgba(15,23,42,0.55); backdrop-filter: blur(16px) saturate(180%); -webkit-backdrop-filter: blur(16px) saturate(180%); border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 1rem 1.1rem; transition: all 0.3s cubic-bezier(0.22,1,0.36,1); overflow: hidden; }
    .cat-stat::before { content: ''; position: absolute; top: 0; left: 15%; right: 15%; height: 2px; background: linear-gradient(90deg, transparent, var(--accent, #818cf8), transparent); opacity: 0.7; }
    .cat-stat:hover { transform: translateY(-3px); border-color: var(--accent, #818cf8); box-shadow: 0 20px 40px -20px var(--accent, #818cf8); }

    .cat-table-wrap { position: relative; background: rgba(15,23,42,0.55); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.07); border-radius: 18px; overflow: hidden; }
    .cat-table-wrap::before { content: ''; position: absolute; top: 0; left: 10%; right: 10%; height: 1px; background: linear-gradient(90deg, transparent, rgba(165,180,252,0.5), transparent); pointer-events: none; }

    .cat-row { transition: all 0.25s ease; position: relative; }
    .cat-row:hover { background: rgba(99,102,241,0.06); }
    .cat-row:hover .cat-icon { transform: scale(1.1) rotate(-6deg); }
    .cat-icon { transition: transform 0.3s cubic-bezier(0.22,1,0.36,1); }

    .cat-action { width: 30px; height: 30px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; opacity: 0.7; }
    .cat-row:hover .cat-action { opacity: 1; }
    .cat-action:hover { transform: scale(1.1); }

    .cat-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.02em; }
    .cat-pill-count{ background: rgba(99,102,241,0.15); color: #a5b4fc; }

    .cat-search { position: relative; flex: 1; max-width: 320px; }
    .cat-search input { width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 0.55rem 0.85rem 0.55rem 2.4rem; font-size: 0.82rem; color: #e2e8f0; outline: none; transition: all 0.25s; }
    .cat-search input::placeholder { color: #475569; }
    .cat-search input:focus { background: rgba(99,102,241,0.06); border-color: rgba(129,140,248,0.5); box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
    .cat-search i { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #475569; font-size: 0.8rem; transition: color 0.2s; pointer-events: none; }
    .cat-search:focus-within i { color: #818cf8; }

    .view-toggle { display: inline-flex; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 3px; gap: 2px; }
    .view-toggle button { padding: 0.4rem 0.65rem; border-radius: 7px; font-size: 0.72rem; color: #64748b; background: transparent; border: none; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 4px; }
    .view-toggle button:hover { color: #94a3b8; }
    .view-toggle button.active { background: rgba(99,102,241,0.2); color: #a5b4fc; }

    .cat-card { position: relative; background: rgba(15,23,42,0.55); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 1.1rem; transition: all 0.3s cubic-bezier(0.22,1,0.36,1); overflow: hidden; }
    .cat-card::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 50% 0%, var(--accent, #818cf8), transparent 70%); opacity: 0; transition: opacity 0.35s; pointer-events: none; }
    .cat-card:hover { transform: translateY(-4px); border-color: var(--accent, #818cf8); box-shadow: 0 25px 50px -20px var(--accent, #818cf8); }
    .cat-card:hover::before { opacity: 0.08; }
    .cat-card:hover .cat-card-icon { transform: scale(1.15) rotate(-8deg); }
    .cat-card-icon { transition: transform 0.4s cubic-bezier(0.22,1,0.36,1); }

    .cat-empty { text-align: center; padding: 3.5rem 1rem; color: #64748b; }
    .cat-empty i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 0.75rem; }

    .del-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(6px); z-index: 100; display: none; align-items: center; justify-content: center; padding: 1rem; animation: fadeIn 0.2s ease; }
    .del-modal-backdrop.show { display: flex; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .del-modal { background: rgba(15,23,42,0.95); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.1); border-radius: 18px; max-width: 400px; width: 100%; padding: 1.5rem; animation: modalIn 0.3s cubic-bezier(0.22,1,0.36,1); }
    @keyframes modalIn { from { opacity: 0; transform: scale(0.92) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

    .cat-fade-up { animation: catFadeUp 0.5s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes catFadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .cat-d1 { animation-delay: 0.04s; }
    .cat-d2 { animation-delay: 0.08s; }
    .cat-d3 { animation-delay: 0.12s; }
    .cat-d4 { animation-delay: 0.16s; }

    .hidden-view { display: none !important; }

    @media (max-width: 767px) { .cat-table-desktop { display: none; } .cat-cards-mobile { display: block; } }
    @media (min-width: 768px) { .cat-table-desktop { display: block; } .cat-cards-mobile { display: none; } }
    
    .icon-default { background: rgba(99,102,241,0.15); color: #a5b4fc; }
</style>

<div class="cat-bg">
    <div class="cat-blob b1"></div>
    <div class="cat-blob b2"></div>
</div>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 cat-fade-up">
    <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500/40 to-purple-500/40 flex items-center justify-center border border-white/15 shadow-lg shadow-indigo-500/20">
            <i class="fas fa-tags text-xl text-indigo-200"></i>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Categories</h1>
            <p class="text-xs text-slate-400 mt-0.5">Product categories (T-Shirt, Shirt, Bottle, etc.)</p>
        </div>
    </div>
    <a href="add_category.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">
        <i class="fas fa-plus-circle"></i> Add Category
    </a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
    <div class="cat-stat cat-fade-up cat-d1" style="--accent:#818cf8">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Categories</div>
                <div class="text-2xl font-bold bg-gradient-to-r from-indigo-300 to-indigo-500 bg-clip-text text-transparent mt-0.5"><?= $totalCategories ?></div>
            </div>
            <div class="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-300 flex items-center justify-center">
                <i class="fas fa-tags text-xs"></i>
            </div>
        </div>
    </div>
    <div class="cat-stat cat-fade-up cat-d4" style="--accent:#a855f7">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Total Products</div>
                <div class="text-2xl font-bold bg-gradient-to-r from-purple-300 to-purple-500 bg-clip-text text-transparent mt-0.5"><?= $totalProducts ?></div>
            </div>
            <div class="w-9 h-9 rounded-lg bg-purple-500/15 text-purple-300 flex items-center justify-center">
                <i class="fas fa-tshirt text-xs"></i>
            </div>
        </div>
    </div>
</div>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 cat-fade-up cat-d3">
    <div class="cat-search">
        <i class="fas fa-search"></i>
        <input type="text" id="catSearch" placeholder="Search categories..." oninput="filterCategories(this.value)">
    </div>
    <div class="flex items-center gap-2">
        <span class="text-[11px] text-slate-500" id="resultCount">
            Showing <span class="text-slate-300 font-medium"><?= $totalCategories ?></span> of <?= $totalCategories ?>
        </span>
        <div class="view-toggle">
            <button type="button" class="active" onclick="setView('table', this)" title="Table view">
                <i class="fas fa-list"></i> <span class="hidden sm:inline">Table</span>
            </button>
            <button type="button" onclick="setView('cards', this)" title="Card view">
                <i class="fas fa-th-large"></i> <span class="hidden sm:inline">Cards</span>
            </button>
        </div>
    </div>
</div>

<div class="cat-table-wrap cat-fade-up cat-d4" id="tableView">
    <div class="overflow-x-auto cat-table-desktop">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left border-b border-white/10 bg-white/3">
                    <th class="px-4 py-3 text-slate-400 font-medium">#</th>
                    <th class="px-4 py-3 text-slate-400 font-medium">Category</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Products</th>
                    <th class="px-4 py-3 text-slate-400 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="catTableBody">
                <?php if (!empty($categories)): ?>
                    <?php foreach ($categories as $i => $c): ?>
                        <tr class="cat-row border-b border-white/5" data-name="<?= strtolower(e($c['category_name'])) ?>">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="cat-icon w-9 h-9 rounded-lg flex items-center justify-center text-[13px] icon-default">
                                        <i class="fas <?= e($c['icon']) ?>"></i>
                                    </span>
                                    <span class="text-white font-medium"><?= e($c['category_name']) ?></span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="../products/products_list.php?category=<?= $c['id'] ?>" class="cat-pill cat-pill-count hover:bg-indigo-500/25 transition">
                                    <i class="fas fa-cube"></i> <?= $c['product_count'] ?>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="edit_category.php?id=<?= $c['id'] ?>" class="cat-action bg-indigo-500/15 text-indigo-300 hover:bg-indigo-500/30" title="Edit">
                                        <i class="fas fa-edit text-[10px]"></i>
                                    </a>
                                    <button type="button" class="cat-action bg-rose-500/15 text-rose-300 hover:bg-rose-500/30"
                                        onclick="confirmDelete(<?= $c['id'] ?>, '<?= e(addslashes($c['category_name'])) ?>')" title="Delete">
                                        <i class="fas fa-trash text-[10px]"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">
                            <div class="cat-empty">
                                <i class="fas fa-tags"></i>
                                <div class="text-sm">No categories yet</div>
                                <a href="add_category.php" class="inline-block mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline">Add your first category →</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cat-cards-mobile p-3 space-y-2">
        <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $c): ?>
                <div class="cat-row rounded-xl bg-white/3 border border-white/5 p-3 flex items-center justify-between gap-3" data-name="<?= strtolower(e($c['category_name'])) ?>">
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <span class="cat-icon w-9 h-9 rounded-lg flex items-center justify-center text-[13px] flex-shrink-0 icon-default">
                            <i class="fas <?= e($c['icon']) ?>"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="text-white font-medium text-sm truncate"><?= e($c['category_name']) ?></div>
                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span class="cat-pill cat-pill-count !text-[9px]"><i class="fas fa-cube"></i><?= $c['product_count'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <a href="edit_category.php?id=<?= $c['id'] ?>" class="cat-action bg-indigo-500/15 text-indigo-300 hover:bg-indigo-500/30">
                            <i class="fas fa-edit text-[10px]"></i>
                        </a>
                        <button type="button" class="cat-action bg-rose-500/15 text-rose-300 hover:bg-rose-500/30"
                            onclick="confirmDelete(<?= $c['id'] ?>, '<?= e(addslashes($c['category_name'])) ?>')">
                            <i class="fas fa-trash text-[10px]"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="cat-empty">
                <i class="fas fa-tags"></i>
                <div class="text-sm">No categories yet</div>
            </div>
        <?php endif; ?>
    </div>

    <div id="noResults" class="hidden">
        <div class="cat-empty">
            <i class="fas fa-search"></i>
            <div class="text-sm">No categories match your search</div>
            <button type="button" onclick="document.getElementById('catSearch').value=''; filterCategories('');" class="mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline">Clear search</button>
        </div>
    </div>
</div>

<div class="hidden-view" id="cardView">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3" id="catCardGrid">
        <?php foreach ($categories as $c): $accent = '#818cf8'; ?>
            <div class="cat-card" data-name="<?= strtolower(e($c['category_name'])) ?>" style="--accent: <?= $accent ?>">
                <div class="flex items-start justify-between mb-3 relative z-10">
                    <span class="cat-card-icon w-11 h-11 rounded-xl flex items-center justify-center text-lg icon-default">
                        <i class="fas <?= e($c['icon']) ?>"></i>
                    </span>
                    <div class="flex items-center gap-1">
                        <a href="edit_category.php?id=<?= $c['id'] ?>" class="cat-action bg-indigo-500/15 text-indigo-300 hover:bg-indigo-500/30" title="Edit">
                            <i class="fas fa-edit text-[10px]"></i>
                        </a>
                        <button type="button" class="cat-action bg-rose-500/15 text-rose-300 hover:bg-rose-500/30"
                            onclick="confirmDelete(<?= $c['id'] ?>, '<?= e(addslashes($c['category_name'])) ?>')" title="Delete">
                            <i class="fas fa-trash text-[10px]"></i>
                        </button>
                    </div>
                </div>
                <div class="text-white font-semibold text-sm truncate relative z-10"><?= e($c['category_name']) ?></div>
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-white/5 relative z-10">
                    <a href="../products/products_list.php?category=<?= $c['id'] ?>" class="text-[10px] text-slate-400 hover:text-indigo-300 transition flex items-center gap-1">
                        <i class="fas fa-cube"></i> <?= $c['product_count'] ?> products
                    </a>
                    <span class="text-[9px] text-slate-600"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '' ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="noResultsCards" class="hidden">
        <div class="cat-empty">
            <i class="fas fa-search"></i>
            <div class="text-sm">No categories match your search</div>
        </div>
    </div>
</div>

<div class="del-modal-backdrop" id="deleteModal">
    <div class="del-modal">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-11 h-11 rounded-xl bg-rose-500/15 text-rose-400 flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-lg"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white">Delete Category?</h3>
                <p class="text-[11px] text-slate-400">This action cannot be undone</p>
            </div>
        </div>
        <p class="text-xs text-slate-300 mb-4">
            Are you sure you want to delete <span class="font-semibold text-white" id="deleteCatName"></span>?
            Products linked to this category may also be affected.
        </p>
        <div class="flex items-center gap-2">
            <button type="button" onclick="closeDeleteModal()" class="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs font-medium hover:bg-white/10 transition">
                Cancel
            </button>
            <a id="deleteConfirmBtn" href="#" class="flex-1 px-4 py-2 rounded-lg bg-gradient-to-r from-rose-500 to-red-500 text-white text-xs font-semibold text-center hover:-translate-y-0.5 hover:shadow-lg hover:shadow-rose-500/30 transition">
                <i class="fas fa-trash mr-1"></i> Delete
            </a>
        </div>
    </div>
</div>

<script>
let currentView = 'table';
function setView(view, btn) {
    currentView = view;
    document.querySelectorAll('.view-toggle button').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const tableView = document.getElementById('tableView');
    const cardView  = document.getElementById('cardView');

    if (view === 'table') {
        tableView.classList.remove('hidden-view');
        cardView.classList.add('hidden-view');
    } else {
        tableView.classList.add('hidden-view');
        cardView.classList.remove('hidden-view');
    }
    const q = document.getElementById('catSearch').value;
    filterCategories(q);
}

function filterCategories(q) {
    q = (q || '').toLowerCase().trim();
    let visibleCount = 0;

    document.querySelectorAll('#catTableBody tr[data-name]').forEach(tr => {
        const match = tr.dataset.name.includes(q);
        tr.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    document.querySelectorAll('.cat-cards-mobile .cat-row[data-name]').forEach(row => {
        const match = row.dataset.name.includes(q);
        row.style.display = match ? '' : 'none';
    });

    document.querySelectorAll('#catCardGrid .cat-card[data-name]').forEach(card => {
        const match = card.dataset.name.includes(q);
        card.style.display = match ? '' : 'none';
    });

    const noResTable = document.getElementById('noResults');
    const noResCards = document.getElementById('noResultsCards');
    const realTotal  = <?= (int)$totalCategories ?>;
    const isSearching = q.length > 0;

    if (isSearching && visibleCount === 0 && realTotal > 0) {
        noResTable.classList.remove('hidden');
        noResCards.classList.remove('hidden');
    } else {
        noResTable.classList.add('hidden');
        noResCards.classList.add('hidden');
    }

    const label = document.getElementById('resultCount');
    if (label) {
        if (isSearching) {
            label.innerHTML = `Showing <span class="text-slate-300 font-medium">${visibleCount}</span> of ${realTotal}`;
        } else {
            label.innerHTML = `Showing <span class="text-slate-300 font-medium">${realTotal}</span> of ${realTotal}`;
        }
    }
}

function confirmDelete(id, name) {
    document.getElementById('deleteCatName').textContent = name;
    document.getElementById('deleteConfirmBtn').href = 'delete_category.php?id=' + id;
    document.getElementById('deleteModal').classList.add('show');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}
document.getElementById('deleteModal').addEventListener('click', (e) => {
    if (e.target.id === 'deleteModal') closeDeleteModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDeleteModal();
});
</script>

<?php include '../includes/footer.php'; ?>