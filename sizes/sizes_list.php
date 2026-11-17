<?php
// ============================================================
// FILE: sizes/sizes_list.php
// ============================================================
require_once '../config/config.php';
require_login();

$page_title = 'Sizes';

// Fetch all sizes
$sizes = $conn->query("SELECT * FROM sizes WHERE is_active = 1 ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
$totalSizes = count($sizes);

include '../includes/header.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Sizes</h1>
        <p class="text-xs text-slate-400 mt-0.5">Manage product sizes (S, M, L, XL, 500ml, 1L, etc.)</p>
    </div>
    <a href="add_size.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all">
        <i class="fas fa-plus-circle"></i> Add Size
    </a>
</div>

<!-- Grid View of Sizes -->
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
    <?php if (!empty($sizes)): ?>
        <?php foreach ($sizes as $s): ?>
            <div class="glass-dark rounded-xl p-4 flex flex-col items-center justify-center relative group hover:bg-white/5 transition-all">
                <!-- Size Name -->
                <div class="text-xl font-bold text-white mb-3"><?= e($s['size_name']) ?></div>
                
                <!-- Action Buttons (Show on hover) -->
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <a href="edit_size.php?id=<?= $s['id'] ?>" class="w-8 h-8 rounded-lg bg-indigo-500/15 text-indigo-300 flex items-center justify-center hover:bg-indigo-500/30 transition" title="Edit">
                        <i class="fas fa-edit text-xs"></i>
                    </a>
                    <a href="delete_size.php?id=<?= $s['id'] ?>" onclick="return confirm('Delete this size?')" class="w-8 h-8 rounded-lg bg-rose-500/15 text-rose-300 flex items-center justify-center hover:bg-rose-500/30 transition" title="Delete">
                        <i class="fas fa-trash text-xs"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-span-full glass-dark rounded-2xl p-10 text-center text-slate-500">
            <i class="fas fa-ruler-combined text-4xl block mb-3 opacity-30"></i>
            <p class="text-sm">No sizes added yet.</p>
            <a href="add_size.php" class="inline-block mt-3 text-indigo-400 hover:text-indigo-300 text-sm underline">Add your first size</a>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>