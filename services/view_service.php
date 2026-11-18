
<?php
// ============================================================
// FILE: services/view_service.php
// ============================================================
require_once '../config/config.php';
require_permission('services');
$page_title = 'Manage Print Job';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('services_list.php');

// Fetch Service Details
$stmt = $conn->prepare("SELECT sv.*, c.customer_name, c.phone, s.sale_no 
    FROM services sv
    LEFT JOIN customers c ON sv.client_id = c.id
    LEFT JOIN sales s ON sv.sale_id = s.id
    WHERE sv.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$service) { set_flash('error', 'Service job not found.'); redirect('services_list.php'); }

include '../includes/header.php';
?>

<style>
    /* Dropdown Dark Mode Fix */
    select { 
        background-color: #1e293b !important; 
        color: #fff !important; 
    }
    select option { 
        background-color: #1e293b !important; 
        color: #fff !important; 
        padding: 8px;
    }
</style>

<div class="flex items-center justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-white">Print Job Details</h1>
        <p class="text-xs text-slate-400 mt-0.5">#<?= e($service['service_no']) ?></p>
    </div>
    <a href="services_list.php" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition">
        <i class="fas fa-arrow-left mr-1"></i> Back to List
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
    <!-- Customer Info -->
    <div class="glass-dark rounded-2xl p-5 border border-white/5">
        <h3 class="text-xs font-semibold text-indigo-400 mb-3 uppercase tracking-wider">Customer Details</h3>
        <div class="text-lg font-bold text-white mb-1"><?= e($service['customer_name'] ?? 'Walk-in Customer') ?></div>
        <?php if($service['phone']): ?><div class="text-sm text-slate-300"><i class="fas fa-phone text-[10px] mr-2 text-slate-500"></i><?= e($service['phone']) ?></div><?php endif; ?>
        <?php if($service['sale_no']): ?>
            <div class="mt-3 pt-3 border-t border-white/5">
                <div class="text-[10px] text-slate-500 mb-1">Linked Sale:</div>
                <a href="../sales/view_sale.php?id=<?= $service['sale_id'] ?>" class="text-indigo-400 hover:text-indigo-300 underline text-sm">
                    <?= e($service['sale_no']) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Job Info & Payment -->
    <div class="glass-dark rounded-2xl p-5 border border-white/5">
        <h3 class="text-xs font-semibold text-amber-400 mb-3 uppercase tracking-wider">Job & Payment Info</h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-400">Date:</span> <span class="text-white"><?= fmt_date($service['service_date']) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">Qty:</span> <span class="text-white font-medium"><?= $service['qty'] ?> pcs</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Sub Total:</span> <span class="text-white"><?= money($service['sub_total']) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">GST (<?= (int)$service['gst_percent'] ?>%):</span> <span class="text-slate-300"><?= money($service['gst_amount']) ?></span></div>
            <div class="flex justify-between border-t border-white/10 pt-2 mt-2">
                <span class="text-slate-400 font-semibold">Total Amount:</span> 
                <span class="text-emerald-300 font-bold text-base"><?= money($service['total_amount']) ?></span>
            </div>
            <div class="flex justify-between"><span class="text-slate-400">Paid:</span> <span class="text-emerald-400"><?= money($service['paid_amount']) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-400">Due:</span> <span class="<?= $service['due_amount'] > 0 ? 'text-rose-400' : 'text-slate-500' ?>"><?= money($service['due_amount']) ?></span></div>
        </div>
    </div>

    <!-- Update Status -->
    <div class="glass-dark rounded-2xl p-5 border border-indigo-500/30 bg-indigo-500/5">
        <h3 class="text-xs font-semibold text-white mb-3 uppercase tracking-wider">Update Status</h3>
        <form action="update_service_status.php" method="POST" class="space-y-3">
            <input type="hidden" name="service_id" value="<?= $id ?>">
            <select name="status" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 outline-none focus:border-indigo-500/50">
                <option value="pending" <?= $service['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="in_progress" <?= $service['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="completed" <?= $service['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="delivered" <?= $service['status'] == 'delivered' ? 'selected' : '' ?>>Delivered</option>
            </select>
            <button type="submit" class="w-full py-2 rounded-lg bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-semibold transition">
                Update Status
            </button>
        </form>
    </div>
</div>

<!-- Print Instructions (Combined Notes) -->
<div class="glass-dark rounded-2xl p-5 border border-white/5">
    <h3 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
        <i class="fas fa-print text-amber-400"></i> Print Instructions / Details
    </h3>
    <div class="bg-black/30 rounded-lg p-4 border border-white/5 text-sm text-slate-300 whitespace-pre-wrap font-mono leading-relaxed">
        <?php if (!empty(trim($service['notes']))): ?>
            <?= nl2br(e($service['notes'])) ?>
        <?php else: ?>
            <span class="text-slate-500 italic">No specific instructions provided for this job.</span>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
