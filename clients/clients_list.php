
<?php
// ============================================================
// FILE: clients/clients_list.php
// DESCRIPTION: Customer List with Google Maps Location Link
// ============================================================

require_once '../config/config.php';
require_permission('clients');

$page_title = 'Customers';

// ------------------------------------------------------------
// 1. FETCH ALL ACTIVE CLIENTS
// ------------------------------------------------------------

$clients = [];

$result = $conn->query("
    SELECT *
    FROM clients
    WHERE is_active = 1
    ORDER BY created_at DESC
");

if ($result) {
    $clients = $result->fetch_all(MYSQLI_ASSOC);
}

$totalClients = count($clients);

include '../includes/header.php';
?>

<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
            Customers
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            Manage your customers and their locations
        </p>
    </div>

    <a
        href="add_client.php"
        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/40 transition-all"
    >
        <i class="fas fa-plus-circle"></i>
        Add Customer
    </a>

</div>


<!-- =========================================================
     SUMMARY CARD
========================================================= -->

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

    <div class="glass-dark rounded-xl p-4">

        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">
            Total Clients
        </div>

        <div class="text-2xl font-bold bg-gradient-to-r from-blue-300 to-blue-500 bg-clip-text text-transparent mt-1">
            <?= $totalClients ?>
        </div>

        <div class="text-[10px] text-slate-500 mt-1">
            Active customers
        </div>

    </div>

</div>


<!-- =========================================================
     CLIENT TABLE
========================================================= -->

<div class="glass-dark rounded-2xl overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-xs">

            <thead>

                <tr class="text-left border-b border-white/10 bg-white/3">

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        #
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Customer Name
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        Contact
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium">
                        GST No
                    </th>

                    <th class="px-4 py-3 text-slate-400 font-medium text-center">
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php if (!empty($clients)): ?>

                    <?php foreach ($clients as $i => $c): ?>

                        <?php
                        $clientAddress = trim($c['address'] ?? '');

                        $mapUrl = '';

                        if ($clientAddress !== '') {
                            $mapUrl = 'https://www.google.com/maps/search/?api=1&query='
                                . rawurlencode($clientAddress);
                        }
                        ?>

                        <tr class="border-b border-white/5 hover:bg-white/3 transition">

                            <!-- SERIAL NUMBER -->

                            <td class="px-4 py-3 text-slate-500">
                                <?= $i + 1 ?>
                            </td>


                            <!-- CUSTOMER DETAILS -->

                            <td class="px-4 py-3">

                                <div class="flex items-center gap-2.5">

                                    <span class="w-9 h-9 rounded-lg flex items-center justify-center text-[13px] bg-blue-500/15 text-blue-300 shrink-0">
                                        <i class="fas fa-user"></i>
                                    </span>

                                    <div class="min-w-0">

                                        <span class="text-white font-medium block">
                                            <?= e($c['client_name']) ?>
                                        </span>

                                        <?php if ($clientAddress !== ''): ?>

                                            <a
                                                href="<?= e($mapUrl) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-[10px] text-slate-400 hover:text-indigo-300 block truncate max-w-[220px] mt-1 transition"
                                                title="Open client address in Google Maps"
                                            >
                                                <i class="fas fa-map-marker-alt text-rose-400 mr-1"></i>
                                                <?= e($clientAddress) ?>
                                            </a>

                                        <?php else: ?>

                                            <span class="text-[10px] text-slate-500 block mt-1">
                                                Address not available
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>


                            <!-- CONTACT DETAILS -->

                            <td class="px-4 py-3 text-slate-300">

                                <?php if (!empty($c['phone'])): ?>

                                    <div class="mb-1">
                                        <i class="fas fa-phone text-[10px] mr-1 text-slate-500"></i>
                                        <?= e($c['phone']) ?>
                                    </div>

                                <?php endif; ?>


                                <?php if (!empty($c['email'])): ?>

                                    <div>
                                        <i class="fas fa-envelope text-[10px] mr-1 text-slate-500"></i>
                                        <?= e($c['email']) ?>
                                    </div>

                                <?php endif; ?>


                                <?php if (empty($c['phone']) && empty($c['email'])): ?>

                                    <span class="text-slate-500">-</span>

                                <?php endif; ?>

                            </td>


                            <!-- GST NUMBER -->

                            <td class="px-4 py-3 text-slate-400">
                                <?= e($c['gst_no'] ?: '-') ?>
                            </td>


                            <!-- ACTIONS -->

                            
<!-- ACTIONS -->

<td class="px-4 py-3">

    <div class="flex items-center justify-center gap-1.5">

        <!-- VIEW CLIENT -->

        <a
            href="view_client.php?id=<?= (int) $c['id'] ?>"
            class="w-7 h-7 rounded-lg bg-sky-500/15 text-sky-300 flex items-center justify-center hover:bg-sky-500/30 transition"
            title="View Client"
        >
            <i class="fas fa-eye text-[10px]"></i>
        </a>


        <!-- GOOGLE MAPS LOCATION -->

        <?php if ($clientAddress !== ''): ?>

            <a
                href="<?= e($mapUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-300 flex items-center justify-center hover:bg-emerald-500/30 transition"
                title="View Location on Google Maps"
            >
                <i class="fas fa-map-marker-alt text-[10px]"></i>
            </a>

        <?php endif; ?>


        <!-- EDIT CLIENT -->

        <a
            href="edit_client.php?id=<?= (int) $c['id'] ?>"
            class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-300 flex items-center justify-center hover:bg-indigo-500/30 transition"
            title="Edit Client"
        >
            <i class="fas fa-edit text-[10px]"></i>
        </a>


        <!-- DELETE CLIENT -->

        <a
            href="delete_client.php?id=<?= (int) $c['id'] ?>"
            onclick="return confirm('Delete this client?')"
            class="w-7 h-7 rounded-lg bg-rose-500/15 text-rose-300 flex items-center justify-center hover:bg-rose-500/30 transition"
            title="Delete Client"
        >
            <i class="fas fa-trash text-[10px]"></i>
        </a>

    </div>

</td>


                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="5">

                            <div class="text-center py-12 text-slate-500">

                                <i class="fas fa-users text-3xl block mb-2 opacity-30"></i>

                                <div class="text-sm">
                                    No clients yet
                                </div>

                                <a
                                    href="add_client.php"
                                    class="inline-block mt-2 text-indigo-400 hover:text-indigo-300 text-xs underline"
                                >
                                    Add your first client →
                                </a>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php include '../includes/footer.php'; ?>
