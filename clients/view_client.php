
<?php
// ============================================================
// FILE: clients/view_client.php
// DESCRIPTION: View Client Details + Google Maps
// ============================================================

require_once '../config/config.php';
require_permission('clients');

$page_title = 'View Client';

// ------------------------------------------------------------
// 1. GET CLIENT ID
// ------------------------------------------------------------

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('clients_list.php');
}

// ------------------------------------------------------------
// 2. FETCH CLIENT DETAILS
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT *
    FROM clients
    WHERE id = ?
    AND is_active = 1
    LIMIT 1
");

if (!$stmt) {
    set_flash('error', 'Unable to fetch client details.');
    redirect('clients_list.php');
}

$stmt->bind_param('i', $id);
$stmt->execute();

$client = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$client) {
    set_flash('error', 'Client not found.');
    redirect('clients_list.php');
}

// ------------------------------------------------------------
// 3. PREPARE ADDRESS
// ------------------------------------------------------------

$address = trim($client['address'] ?? '');

$mapQuery = $address !== '' ? $address : 'Nagpur Maharashtra';

$mapEmbedUrl = 'https://maps.google.com/maps?q='
    . rawurlencode($mapQuery)
    . '&z=15&output=embed';

$googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query='
    . rawurlencode($mapQuery);

// ------------------------------------------------------------
// 4. HEADER
// ------------------------------------------------------------

include '../includes/header.php';

?>

<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">

    <div>

        <h1 class="text-2xl font-bold text-white">
            Client Details
        </h1>

        <p class="text-xs text-slate-400 mt-1">
            Complete customer information · ID #<?= $id ?>
        </p>

    </div>

    <div class="flex items-center gap-2">

        <a
            href="edit_client.php?id=<?= $id ?>"
            class="px-4 py-2 rounded-lg bg-indigo-500/15 border border-indigo-500/30 text-indigo-300 text-xs font-semibold hover:bg-indigo-500/25 transition"
        >
            <i class="fas fa-edit mr-1"></i>
            Edit Client
        </a>

        <a
            href="clients_list.php"
            class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition"
        >
            <i class="fas fa-arrow-left mr-1"></i>
            Back
        </a>

    </div>

</div>


<!-- =========================================================
     CLIENT PROFILE CARD
========================================================= -->

<div class="glass-dark rounded-2xl p-5 mb-5">

    <div class="flex flex-wrap items-center gap-4">

        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-lg shadow-indigo-500/20">

            <?= strtoupper(substr($client['client_name'], 0, 1)) ?>

        </div>

        <div class="flex-1 min-w-0">

            <h2 class="text-xl font-bold text-white">
                <?= e($client['client_name']) ?>
            </h2>

            <p class="text-xs text-slate-400 mt-1">
                Client ID: #<?= $id ?>
            </p>

        </div>

        <span class="px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">

            <i class="fas fa-check-circle mr-1"></i>
            Active Client

        </span>

    </div>

</div>


<!-- =========================================================
     DETAILS + MAP
========================================================= -->

<div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

    <!-- CLIENT INFORMATION -->

    <div class="lg:col-span-2 glass-dark rounded-2xl p-5">

        <div class="flex items-center gap-2 mb-5">

            <div class="w-9 h-9 rounded-xl bg-indigo-500/15 flex items-center justify-center">

                <i class="fas fa-user text-indigo-400"></i>

            </div>

            <div>

                <h3 class="text-sm font-bold text-white">
                    Basic Information
                </h3>

                <p class="text-[10px] text-slate-500">
                    Registered client details
                </p>

            </div>

        </div>


        <div class="space-y-4">

            <!-- NAME -->

            <div class="pb-3 border-b border-white/5">

                <p class="text-[11px] text-slate-500 mb-1">
                    Client Name
                </p>

                <p class="text-sm text-slate-200 font-medium">
                    <?= e($client['client_name']) ?>
                </p>

            </div>


            <!-- PHONE -->

            <div class="pb-3 border-b border-white/5">

                <p class="text-[11px] text-slate-500 mb-1">
                    Phone Number
                </p>

                <?php if (!empty($client['phone'])): ?>

                    <a
                        href="tel:<?= e($client['phone']) ?>"
                        class="text-sm text-slate-200 hover:text-indigo-400 transition"
                    >

                        <i class="fas fa-phone text-emerald-400 mr-2"></i>

                        <?= e($client['phone']) ?>

                    </a>

                <?php else: ?>

                    <p class="text-sm text-slate-500">Not provided</p>

                <?php endif; ?>

            </div>


            <!-- EMAIL -->

            <div class="pb-3 border-b border-white/5">

                <p class="text-[11px] text-slate-500 mb-1">
                    Email Address
                </p>

                <?php if (!empty($client['email'])): ?>

                    <a
                        href="mailto:<?= e($client['email']) ?>"
                        class="text-sm text-slate-200 hover:text-indigo-400 transition break-all"
                    >

                        <i class="fas fa-envelope text-indigo-400 mr-2"></i>

                        <?= e($client['email']) ?>

                    </a>

                <?php else: ?>

                    <p class="text-sm text-slate-500">Not provided</p>

                <?php endif; ?>

            </div>


            <!-- GST -->

            <div class="pb-3 border-b border-white/5">

                <p class="text-[11px] text-slate-500 mb-1">
                    GST Number
                </p>

                <p class="text-sm text-slate-200">
                    <?= !empty($client['gst_no'])
                        ? e($client['gst_no'])
                        : '<span class="text-slate-500">Not provided</span>'
                    ?>
                </p>

            </div>


            <!-- ADDRESS -->

            <div>

                <p class="text-[11px] text-slate-500 mb-2">
                    Registered Address
                </p>

                <div class="flex items-start gap-2">

                    <i class="fas fa-map-marker-alt text-rose-400 mt-1"></i>

                    <p class="text-sm text-slate-300 leading-relaxed whitespace-pre-line">
                        <?= $address !== ''
                            ? e($address)
                            : 'Address not provided'
                        ?>
                    </p>

                </div>

            </div>

        </div>

    </div>


    <!-- GOOGLE MAP -->

    <div class="lg:col-span-3 glass-dark rounded-2xl p-5">

        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">

            <div class="flex items-center gap-2">

                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 flex items-center justify-center">

                    <i class="fas fa-map-marked-alt text-emerald-400"></i>

                </div>

                <div>

                    <h3 class="text-sm font-bold text-white">
                        Client Location
                    </h3>

                    <p class="text-[10px] text-slate-500">
                        Google Maps Preview
                    </p>

                </div>

            </div>

            <?php if ($address !== ''): ?>

                <a
                    href="<?= e($googleMapsUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="px-3 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs hover:bg-emerald-500/20 transition"
                >

                    <i class="fas fa-external-link-alt mr-1"></i>

                    Open Maps

                </a>

            <?php endif; ?>

        </div>


        <div class="rounded-xl overflow-hidden border border-white/10 bg-slate-900">

            <?php if ($address !== ''): ?>

                <iframe
                    src="<?= e($mapEmbedUrl) ?>"
                    width="100%"
                    height="390"
                    style="border:0; display:block;"
                    loading="lazy"
                    allowfullscreen
                    referrerpolicy="no-referrer-when-downgrade"
                    title="Client Location"
                ></iframe>

            <?php else: ?>

                <div class="h-[390px] flex flex-col items-center justify-center text-center p-5">

                    <div class="w-14 h-14 rounded-2xl bg-white/5 flex items-center justify-center mb-3">

                        <i class="fas fa-map-marker-alt text-2xl text-slate-500"></i>

                    </div>

                    <p class="text-sm font-semibold text-slate-300">
                        No Address Available
                    </p>

                    <p class="text-xs text-slate-500 mt-1">
                        Add a client address to display its map location.
                    </p>

                    <a
                        href="edit_client.php?id=<?= $id ?>"
                        class="mt-4 px-4 py-2 rounded-lg bg-indigo-500/15 border border-indigo-500/30 text-indigo-300 text-xs hover:bg-indigo-500/25 transition"
                    >

                        <i class="fas fa-edit mr-1"></i>
                        Add Address

                    </a>

                </div>

            <?php endif; ?>

        </div>


        <p class="text-[10px] text-slate-500 mt-3">

            <i class="fas fa-info-circle mr-1"></i>

            Map location is based on the saved address. Exact pin accuracy depends on the address entered.

        </p>

    </div>

</div>


<!-- =========================================================
     FOOTER ACTION
========================================================= -->

<div class="flex justify-end mt-5">

    <a
        href="edit_client.php?id=<?= $id ?>"
        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 transition-all"
    >

        <i class="fas fa-edit mr-1"></i>

        Edit Client Details

    </a>

</div>


<?php include '../includes/footer.php'; ?>
