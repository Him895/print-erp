
<?php
// ============================================================
// FILE: customers/add_customer.php
// DESCRIPTION: Add Customer with Live Google Map Preview
// ============================================================

require_once '../config/config.php';
require_permission('customers');

$page_title = 'Add Customer';
$error = '';

// ------------------------------------------------------------
// 1. HANDLE FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name    = trim($_POST['customer_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $gst     = trim($_POST['gst_no'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {

        $error = 'Customer name required.';

    } else {

        $stmt = $conn->prepare("
            INSERT INTO customers
            (
                customer_name,
                phone,
                email,
                gst_no,
                address
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {

            $error = 'Unable to prepare customer query.';

        } else {

            $stmt->bind_param(
                'sssss',
                $name,
                $phone,
                $email,
                $gst,
                $address
            );

            if ($stmt->execute()) {

                $stmt->close();

                set_flash(
                    'success',
                    'Customer added successfully!'
                );

                redirect('customers_list.php');

            } else {

                $error = 'Insert failed: ' . $stmt->error;
                $stmt->close();

            }

        }

    }

}

// ------------------------------------------------------------
// 2. HEADER
// ------------------------------------------------------------

include '../includes/header.php';

?>

<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="flex items-center justify-between gap-3 mb-5">

    <div>
        <h1 class="text-2xl font-bold text-white">
            Add Customer
        </h1>

        <p class="text-xs text-slate-400 mt-0.5">
            Add new customer with location preview
        </p>
    </div>

    <a
        href="customers_list.php"
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
     ADD CUSTOMER FORM
========================================================= -->

<form
    method="POST"
    class="glass-dark rounded-2xl p-5 max-w-5xl"
>

    <div class="space-y-5">

        <!-- CUSTOMER NAME -->

        <div>

            <label class="text-xs text-slate-400 mb-1 block">
                Customer Name *
            </label>

            <input
                type="text"
                name="customer_name"
                required
                value="<?= e($_POST['customer_name'] ?? '') ?>"
                placeholder="e.g. Rahul Sharma"
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
                    value="<?= e($_POST['phone'] ?? '') ?>"
                    placeholder="e.g. 9876543210"
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
                    value="<?= e($_POST['email'] ?? '') ?>"
                    placeholder="e.g. rahul@example.com"
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
                value="<?= e($_POST['gst_no'] ?? '') ?>"
                placeholder="e.g. 22AAAAA0000A1Z5"
                class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-sm text-slate-200 outline-none focus:border-indigo-500/50"
            >

        </div>


        <!-- =================================================
             ADDRESS + GOOGLE MAP
        ================================================== -->

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

            <!-- ADDRESS INPUT -->

            <div>

                <label class="text-xs text-slate-400 mb-2 block">
                    <i class="fas fa-map-marker-alt mr-1 text-indigo-400"></i>
                    Customer Address
                </label>

                <textarea
                    name="address"
                    id="customerAddress"
                    rows="6"
                    placeholder="Enter complete customer address..."
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-3 text-sm text-slate-200 outline-none focus:border-indigo-500/50 resize-none"
                ><?= e($_POST['address'] ?? '') ?></textarea>

                <p class="text-[11px] text-slate-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Enter address to see its location on Google Maps.
                </p>

                <div
                    id="mapStatus"
                    class="text-xs text-slate-500 mt-3"
                >
                    <i class="fas fa-map mr-1"></i>
                    Default location: Nagpur, Maharashtra
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
                        id="customerMap"
                        src="https://maps.google.com/maps?q=Nagpur%20Maharashtra&t=&z=13&ie=UTF8&iwloc=&output=embed"
                        width="100%"
                        height="245"
                        style="border:0; display:block;"
                        loading="lazy"
                        allowfullscreen
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Customer Location Map"
                    ></iframe>

                </div>


                <a
                    id="openGoogleMap"
                    href="https://www.google.com/maps/search/?api=1&query=Nagpur%20Maharashtra"
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
                Save Customer
            </button>


            <a
                href="customers_list.php"
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

    const addressInput = document.getElementById('customerAddress');
    const mapFrame = document.getElementById('customerMap');
    const mapStatus = document.getElementById('mapStatus');
    const openGoogleMap = document.getElementById('openGoogleMap');

    let typingTimer = null;

    // --------------------------------------------------------
    // UPDATE GOOGLE MAP
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

        mapFrame.src = mapUrl;

        openGoogleMap.href =
            'https://www.google.com/maps/search/?api=1&query=' +
            encodedAddress;

        mapStatus.innerHTML = `
            <i class="fas fa-check-circle text-emerald-400 mr-1"></i>
            Map updated for entered address.
        `;

    }

    // --------------------------------------------------------
    // AUTOMATIC UPDATE ON TYPING
    // --------------------------------------------------------

    addressInput.addEventListener('input', function () {

        clearTimeout(typingTimer);

        mapStatus.innerHTML = `
            <i class="fas fa-spinner fa-spin mr-1"></i>
            Waiting for address...
        `;

        typingTimer = setTimeout(function () {

            updateMap();

        }, 1000);

    });

    // --------------------------------------------------------
    // INITIAL MAP FOR POSTED ADDRESS
    // --------------------------------------------------------

    if (addressInput.value.trim().length >= 5) {

        updateMap();

    }

});

</script>


<?php include '../includes/footer.php'; ?>
