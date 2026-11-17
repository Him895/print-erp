
<?php
// ============================================================
// FILE: clients/edit_client.php
// DESCRIPTION: Edit Client with Google Maps Preview
// ============================================================

require_once '../config/config.php';
require_permission('clients');

$page_title = 'Edit Client';

// ------------------------------------------------------------
// 1. GET CLIENT ID
// ------------------------------------------------------------

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('clients_list.php');
}

// ------------------------------------------------------------
// 2. FETCH CLIENT
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT *
    FROM clients
    WHERE id = ?
    AND is_active = 1
    LIMIT 1
");

if (!$stmt) {
    set_flash('error', 'Unable to fetch client.');
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

$error = '';

// ------------------------------------------------------------
// 3. HANDLE FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['client_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gst = trim($_POST['gst_no'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {

        $error = 'Client name required.';

    } else {

        $stmt = $conn->prepare("
            UPDATE clients
            SET
                client_name = ?,
                phone = ?,
                email = ?,
                gst_no = ?,
                address = ?
            WHERE id = ?
            AND is_active = 1
        ");

        if (!$stmt) {

            $error = 'Unable to prepare update query.';

        } else {

            $stmt->bind_param(
                'sssssi',
                $name,
                $phone,
                $email,
                $gst,
                $address,
                $id
            );

            if ($stmt->execute()) {

                $stmt->close();

                set_flash(
                    'success',
                    'Client updated successfully!'
                );

                redirect('clients_list.php');

            } else {

                $error = 'Update failed: ' . $stmt->error;
                $stmt->close();

            }

        }

    }

    // Preserve submitted values if validation fails
    $client['client_name'] = $name;
    $client['phone'] = $phone;
    $client['email'] = $email;
    $client['gst_no'] = $gst;
    $client['address'] = $address;

}

// ------------------------------------------------------------
// 4. HEADER
// ------------------------------------------------------------

include '../includes/header.php';

?>

<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="flex items-center justify-between gap-3 mb-5">

    <div>

        <h1 class="text-2xl font-bold text-white">
            Edit Client
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            Update customer details · ID #<?= $id ?>
        </p>

    </div>

    <a
        href="clients_list.php"
        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 text-xs hover:bg-white/10 transition"
    >
        <i class="fas fa-arrow-left mr-1"></i>
        Back
    </a>

</div>


<!-- =========================================================
     ERROR MESSAGE
========================================================= -->

<?php if ($error): ?>

    <div class="mb-4 rounded-xl p-3 text-sm bg-rose-500/15 border border-rose-500/30 text-rose-300">

        <i class="fas fa-exclamation-circle mr-2"></i>

        <?= e($error) ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     EDIT CLIENT FORM
========================================================= -->

<form
    method="POST"
    class="glass-dark rounded-2xl p-5 max-w-5xl"
>

    <div class="space-y-5">

        <!-- CLIENT NAME -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Client Name *
            </label>

            <input
                type="text"
                name="client_name"
                required
                value="<?= e($client['client_name']) ?>"
                placeholder="Client name"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>


        <!-- PHONE + EMAIL -->

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <div>

                <label class="text-xs text-slate-400 mb-1 block">
                    Phone
                </label>

                <input
                    type="text"
                    name="phone"
                    value="<?= e($client['phone'] ?? '') ?>"
                    placeholder="Phone number"
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                >

            </div>


            <div>

                <label class="text-xs text-slate-400 mb-1 block">
                    Email
                </label>

                <input
                    type="email"
                    name="email"
                    value="<?= e($client['email'] ?? '') ?>"
                    placeholder="Email address"
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
                >

            </div>

        </div>


        <!-- GST NUMBER -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                GST No (Optional)
            </label>

            <input
                type="text"
                name="gst_no"
                value="<?= e($client['gst_no'] ?? '') ?>"
                placeholder="GST Number"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>


        <!-- =================================================
             ADDRESS + GOOGLE MAP
        ================================================== -->

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

            <!-- ADDRESS -->

            <div>

                <label class="text-xs text-slate-400 mb-2 block">

                    <i class="fas fa-map-marker-alt mr-1 text-indigo-400"></i>

                    Client Address

                </label>

                <textarea
                    name="address"
                    id="clientAddress"
                    rows="6"
                    placeholder="Enter complete client address..."
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-3 text-sm text-slate-200 outline-none focus:border-indigo-500/50 resize-none"
                ><?= e($client['address'] ?? '') ?></textarea>


                <p class="text-[11px] text-slate-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Edit address to update map preview automatically.
                </p>


                <div
                    id="mapStatus"
                    class="text-xs text-slate-500 mt-3"
                >

                    <?php if (!empty($client['address'])): ?>

                        <i class="fas fa-map-marker-alt text-emerald-400 mr-1"></i>
                        Existing client address loaded.

                    <?php else: ?>

                        <i class="fas fa-info-circle mr-1"></i>
                        Enter client address to view location.

                    <?php endif; ?>

                </div>

            </div>


            <!-- GOOGLE MAP PREVIEW -->

            <div>

                <div class="flex items-center justify-between mb-2">

                    <label class="text-xs text-slate-400 block">

                        <i class="fas fa-globe mr-1 text-emerald-400"></i>

                        Google Map Preview

                    </label>

                    <span class="text-[10px] text-emerald-400">

                        <i class="fas fa-circle text-[6px] mr-1"></i>

                        Live Preview

                    </span>

                </div>


                <div class="rounded-xl overflow-hidden border border-white/10 bg-slate-900">

                    <iframe
                        id="clientMap"
                        src="<?= !empty($client['address'])
                            ? 'https://maps.google.com/maps?q=' . rawurlencode($client['address']) . '&z=15&output=embed'
                            : 'https://maps.google.com/maps?q=Nagpur%20Maharashtra&z=13&output=embed'
                        ?>"
                        width="100%"
                        height="245"
                        style="border:0; display:block;"
                        loading="lazy"
                        allowfullscreen
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Client Location Map"
                    ></iframe>

                </div>


                <a
                    id="openGoogleMap"
                    href="<?= !empty($client['address'])
                        ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($client['address'])
                        : 'https://www.google.com/maps/search/?api=1&query=Nagpur%20Maharashtra'
                    ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 text-xs text-indigo-400 hover:text-indigo-300 mt-3 transition"
                >

                    <i class="fas fa-external-link-alt"></i>

                    Open in Google Maps

                </a>

            </div>

        </div>


        <!-- =================================================
             FORM BUTTONS
        ================================================== -->

        <div class="flex flex-wrap items-center gap-2 pt-4 border-t border-white/5">

            <button
                type="submit"
                class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm font-semibold hover:-translate-y-0.5 hover:shadow-lg hover:shadow-indigo-500/30 transition-all"
            >

                <i class="fas fa-save mr-1"></i>

                Update Client

            </button>


            <a
                href="clients_list.php"
                class="px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-300 text-sm hover:bg-white/10 transition"
            >
                Cancel
            </a>

        </div>

    </div>

</form>


<!-- =========================================================
     GOOGLE MAP AUTO UPDATE SCRIPT
========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const addressInput = document.getElementById('clientAddress');

    const mapFrame = document.getElementById('clientMap');

    const mapStatus = document.getElementById('mapStatus');

    const openGoogleMap = document.getElementById('openGoogleMap');

    let typingTimer = null;


    // --------------------------------------------------------
    // UPDATE MAP
    // --------------------------------------------------------

    function updateMap() {

        const address = addressInput.value.trim();

        if (address.length < 5) {

            mapStatus.innerHTML = `
                <i class="fas fa-info-circle mr-1"></i>
                Enter at least 5 characters.
            `;

            return;

        }


        const encodedAddress = encodeURIComponent(address);


        const mapUrl =
            'https://maps.google.com/maps?q=' +
            encodedAddress +
            '&t=&z=15&ie=UTF8&iwloc=&output=embed';


        // Update map iframe
        mapFrame.src = mapUrl;


        // Update Google Maps external link
        openGoogleMap.href =
            'https://www.google.com/maps/search/?api=1&query=' +
            encodedAddress;


        mapStatus.innerHTML = `
            <i class="fas fa-check-circle text-emerald-400 mr-1"></i>
            Map updated for entered address.
        `;

    }


    // --------------------------------------------------------
    // AUTOMATIC MAP UPDATE
    // --------------------------------------------------------

    addressInput.addEventListener('input', function () {

        clearTimeout(typingTimer);

        mapStatus.innerHTML = `
            <i class="fas fa-spinner fa-spin mr-1"></i>
            Waiting for address...
        `;


        // Wait until typing stops
        typingTimer = setTimeout(function () {

            updateMap();

        }, 1000);

    });


});

</script>


<?php include '../includes/footer.php'; ?>
