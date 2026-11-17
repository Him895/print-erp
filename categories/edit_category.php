<?php
// ============================================================
// FILE: categories/edit_category.php
// ============================================================
require_once '../config/config.php';
require_permission('categories');
$page_title = 'Edit Category';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('categories_list.php');

// Fetch existing category
$stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$cat = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cat) { set_flash('error', 'Category not found.'); redirect('categories_list.php'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['category_name'] ?? '');
    $icon     = trim($_POST['icon'] ?? 'fa-tag');

    if ($name === '') {
        $error = 'Category name required.';
    } else {
        // Duplicate check (khud ko chhod ke)
        $chk = $conn->prepare("SELECT id FROM categories WHERE category_name = ? AND id != ?");
        $chk->bind_param('si', $name, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'Ye category pehle se hai.';
        } else {
            // Update query (has_size hata diya)
            $stmt = $conn->prepare("UPDATE categories SET category_name=?, icon=? WHERE id=?");
            $stmt->bind_param('ssi', $name, $icon, $id);
            if ($stmt->execute()) {
                set_flash('success', 'Category updated successfully!');
                redirect('categories_list.php');
            } else {
                $error = 'Update failed: ' . $conn->error;
            }
            $stmt->close();
        }
        $chk->close();
    }
    // Error aane par form me purani value save rahe
    $cat = array_merge($cat, $_POST);
}

$icons = ['fa-tag','fa-tshirt','fa-user-tie','fa-wine-bottle','fa-box','fa-cube','fa-gift','fa-mug-hot','fa-hat-cowboy','fa-shoe-prints','fa-print'];

include '../includes/header.php';
?>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Edit Category</h1>
        <p class="text-xs text-slate-400 mt-0.5">Update product category</p>
    </div>
    <a href="categories_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">
        <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
    </div>
<?php endif; ?>

<form method="POST" class="glass-dark rounded-2xl p-5 max-w-2xl">
    <div class="space-y-4">
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Category Name *</label>
            <input type="text" name="category_name" required value="<?= e($cat['category_name']) ?>" placeholder="e.g. Cap, Mug, Hoodie" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
        </div>

        <div>
            <label class="text-xs text-slate-400 mb-1 block">Icon (FontAwesome class)</label>
            <select name="icon" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
                <?php foreach ($icons as $ic): ?>
                    <option value="<?= $ic ?>" <?= $cat['icon'] === $ic ? 'selected' : '' ?>><?= $ic ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-center gap-2 pt-4 border-t border-white/5">
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all">
                <i class="fas fa-save mr-1"></i> Update Category
            </button>
            <a href="categories_list.php" class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition">Cancel</a>
        </div>
    </div>
</form>

<?php include '../includes/footer.php'; ?>