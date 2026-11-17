
<?php
// ============================================================
// FILE: users/add_user.php
// DESCRIPTION: Create User + Dynamic Role + Custom Permissions
// ============================================================

require_once '../config/config.php';
require_admin();

require_once 'user_functions.php';

$page_title = 'Add New User';

// ------------------------------------------------------------
// 1. INITIAL DATA
// ------------------------------------------------------------

$errors = [];

$full_name = '';
$email = '';
$role_id = 0;
$permission_mode = 'role';
$is_active = 1;

$password = '';
$confirm_password = '';

$selected_permissions = [];


// ------------------------------------------------------------
// 2. FETCH ACTIVE PERMISSIONS
// ------------------------------------------------------------

$permissions = user_module_get_permissions($conn);

$valid_permission_ids = user_module_permission_ids($permissions);


// ------------------------------------------------------------
// 3. FETCH ACTIVE ROLES
// ------------------------------------------------------------

$roles = [];

$roles_sql = "
    SELECT
        id,
        role_key,
        role_name,
        description,
        is_system
    FROM roles
    WHERE is_active = 1
    ORDER BY
        is_system DESC,
        role_name ASC
";

$roles_result = $conn->query($roles_sql);

if ($roles_result) {

    while ($row = $roles_result->fetch_assoc()) {

        $roles[(int)$row['id']] = $row;

    }

}


// ------------------------------------------------------------
// 4. GROUP PERMISSIONS
// ------------------------------------------------------------

$permission_groups = [];

foreach ($permissions as $permission) {

    $group = $permission['permission_group'] ?: 'Other';

    $permission_groups[$group][] = $permission;

}


// ------------------------------------------------------------
// 5. FIND DASHBOARD PERMISSION
// ------------------------------------------------------------

$dashboard_permission_id = 0;

foreach ($permissions as $permission) {

    if ($permission['permission_key'] === 'dashboard') {

        $dashboard_permission_id = (int)$permission['id'];

        break;

    }

}


// ------------------------------------------------------------
// 6. FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --------------------------------------------------------
    // CSRF VALIDATION
    // --------------------------------------------------------

    if (!user_module_verify_csrf()) {

        $errors[] = 'Invalid or expired form request. Please refresh and try again.';

    }


    // --------------------------------------------------------
    // READ FORM VALUES
    // --------------------------------------------------------

    $full_name = trim($_POST['full_name'] ?? '');

    $email = strtolower(trim($_POST['email'] ?? ''));

    $role_id = (int)($_POST['role_id'] ?? 0);

    $permission_mode = trim($_POST['permission_mode'] ?? 'role');

    $password = $_POST['password'] ?? '';

    $confirm_password = $_POST['confirm_password'] ?? '';

    $is_active = isset($_POST['is_active']) ? 1 : 0;


    // --------------------------------------------------------
    // 7. BASIC VALIDATION
    // --------------------------------------------------------

    if ($full_name === '') {

        $errors[] = 'Full name is required.';

    } elseif (mb_strlen($full_name) > 100) {

        $errors[] = 'Full name cannot exceed 100 characters.';

    }


    if ($email === '') {

        $errors[] = 'Email address is required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors[] = 'Please enter a valid email address.';

    } elseif (strlen($email) > 120) {

        $errors[] = 'Email cannot exceed 120 characters.';

    }


    // --------------------------------------------------------
    // 8. ROLE VALIDATION
    // --------------------------------------------------------

    if (
        $role_id <= 0 ||
        !isset($roles[$role_id])
    ) {

        $errors[] = 'Please select a valid active user role.';

    }


    if (!in_array($permission_mode, ['role', 'custom'], true)) {

        $errors[] = 'Please select a valid permission mode.';

    }


    // Get selected role details

    $selected_role = $roles[$role_id] ?? null;

    $selected_role_key = $selected_role['role_key'] ?? '';

    $selected_role_name = $selected_role['role_name'] ?? '';


    // Admin cannot use custom permission mode

    if (
        $selected_role_key === 'admin' &&
        $permission_mode === 'custom'
    ) {

        $errors[] = 'Admin role always receives full permissions. Custom permission mode is not allowed for Admin.';

    }


    // --------------------------------------------------------
    // 9. PASSWORD VALIDATION
    // --------------------------------------------------------

    if (strlen($password) < 8) {

        $errors[] = 'Password must contain at least 8 characters.';

    }


    if ($password !== $confirm_password) {

        $errors[] = 'Password and confirm password do not match.';

    }


    // --------------------------------------------------------
    // 10. EMAIL DUPLICATE CHECK
    // --------------------------------------------------------

    if (
        $email !== '' &&
        filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        $check_email = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        if (!$check_email) {

            $errors[] = 'Unable to validate email address.';

        } else {

            $check_email->bind_param('s', $email);

            $check_email->execute();

            $existing_user = $check_email
                ->get_result()
                ->fetch_assoc();

            $check_email->close();

            if ($existing_user) {

                $errors[] = 'This email address is already registered.';

            }

        }

    }


    // --------------------------------------------------------
    // 11. READ AND VALIDATE PERMISSIONS
    // --------------------------------------------------------

    $posted_permissions = $_POST['permissions'] ?? [];

    if (!is_array($posted_permissions)) {

        $posted_permissions = [];

    }


    $selected_permissions = array_values(
        array_unique(
            array_filter(
                array_map('intval', $posted_permissions),
                function ($id) {
                    return $id > 0;
                }
            )
        )
    );


    // Keep only valid active permission IDs

    $selected_permissions = array_values(
        array_intersect(
            $selected_permissions,
            $valid_permission_ids
        )
    );


    // --------------------------------------------------------
    // ROLE BASED PERMISSION VALIDATION
    // --------------------------------------------------------

    if (
        $selected_role_key !== 'admin' &&
        $permission_mode === 'role' &&
        $selected_role
    ) {

        // Selected role must contain Dashboard permission

        if ($dashboard_permission_id <= 0) {

            $errors[] = 'Dashboard permission is missing or inactive.';

        } else {

            $check_dashboard = $conn->prepare("
                SELECT rp.permission_id

                FROM role_permissions rp

                INNER JOIN permissions p
                    ON p.id = rp.permission_id

                WHERE rp.role_id = ?
                AND rp.permission_id = ?
                AND p.is_active = 1

                LIMIT 1
            ");

            if (!$check_dashboard) {

                $errors[] = 'Unable to validate role permissions.';

            } else {

                $check_dashboard->bind_param(
                    'ii',
                    $role_id,
                    $dashboard_permission_id
                );

                $check_dashboard->execute();

                $dashboard_result = $check_dashboard
                    ->get_result()
                    ->fetch_assoc();

                $check_dashboard->close();

                if (!$dashboard_result) {

                    $errors[] = 'Selected role does not have Dashboard permission. Please update the role permissions first.';

                }

            }

        }

    }


    // --------------------------------------------------------
    // CUSTOM PERMISSION VALIDATION
    // --------------------------------------------------------

    if (
        $selected_role_key !== 'admin' &&
        $permission_mode === 'custom'
    ) {

        if ($dashboard_permission_id <= 0) {

            $errors[] = 'Dashboard permission is missing or inactive.';

        } else {

            // Dashboard is mandatory - add server side

            if (
                !in_array(
                    $dashboard_permission_id,
                    $selected_permissions,
                    true
                )
            ) {

                $selected_permissions[] = $dashboard_permission_id;

            }

        }

    }


    // --------------------------------------------------------
    // ADMIN PERMISSION HANDLING
    // --------------------------------------------------------

    if ($selected_role_key === 'admin') {

        $permission_mode = 'role';

        // Admin receives all permissions through config.php

        $selected_permissions = [];

    }


    // --------------------------------------------------------
    // 12. CREATE USER
    // --------------------------------------------------------

    if (empty($errors)) {

        try {

            $conn->begin_transaction();


            // ------------------------------------------------
            // HASH PASSWORD
            // ------------------------------------------------

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if (!$hashed_password) {

                throw new Exception('Password hashing failed.');

            }


            // ------------------------------------------------
            // INSERT USER
            // ------------------------------------------------

            $insert_user = $conn->prepare("
                INSERT INTO users
                (
                    full_name,
                    email,
                    password,
                    role,
                    role_id,
                    permission_mode,
                    is_active
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$insert_user) {

                throw new Exception('Unable to prepare user creation query.');

            }


            $insert_user->bind_param(
                'ssssisi',
                $full_name,
                $email,
                $hashed_password,
                $selected_role_key,
                $role_id,
                $permission_mode,
                $is_active
            );


            if (!$insert_user->execute()) {

                $insert_user->close();

                throw new Exception('Unable to create user account.');

            }


            $new_user_id = (int)$conn->insert_id;

            $insert_user->close();


            // ------------------------------------------------
            // SAVE CUSTOM PERMISSIONS
            // ------------------------------------------------

            if ($permission_mode === 'custom') {

                user_module_save_permissions(
                    $conn,
                    $new_user_id,
                    $selected_permissions
                );

            } else {

                // Role Based / Admin:
                // No individual user permission entries needed

                user_module_save_permissions(
                    $conn,
                    $new_user_id,
                    []
                );

            }


            // ------------------------------------------------
            // COMMIT
            // ------------------------------------------------

            $conn->commit();


            // ------------------------------------------------
            // SUCCESS
            // ------------------------------------------------

            set_flash(
                'success',
                'User "' . $full_name . '" created successfully with ' . $selected_role_name . ' role.'
            );

            user_module_redirect('users_list.php');

            exit;


        } catch (Throwable $e) {

            $conn->rollback();

            error_log(
                'Add User Error: ' . $e->getMessage()
            );

            $errors[] = 'Unable to create user. Please check the details and try again.';

        }

    }

}


// ------------------------------------------------------------
// 13. HEADER
// ------------------------------------------------------------

include '../includes/header.php';

?>

<div class="space-y-6">


    <!-- PAGE HEADER -->

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

        <div>

            <h1 class="text-2xl font-bold text-white">
                Add New User
            </h1>

            <p class="text-sm text-gray-400 mt-1">
                Create a user account and assign dynamic roles and permissions.
            </p>

        </div>


        <a
            href="users_list.php"
            class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/15 text-white transition"
        >

            <i class="fas fa-arrow-left"></i>

            Back to Users

        </a>

    </div>


    <!-- ERROR MESSAGES -->

    <?php if (!empty($errors)): ?>

        <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-4">

            <div class="flex items-center gap-2 text-red-400 font-semibold mb-2">

                <i class="fas fa-exclamation-circle"></i>

                Please fix the following errors:

            </div>

            <ul class="list-disc pl-6 space-y-1 text-sm text-red-300">

                <?php foreach ($errors as $error): ?>

                    <li><?= e($error) ?></li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- USER FORM -->

    <form method="POST" class="space-y-6">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(user_module_csrf_token()) ?>"
        >


        <!-- BASIC DETAILS -->

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5 sm:p-6">

            <div class="flex items-center gap-3 mb-6">

                <div class="w-10 h-10 rounded-xl bg-blue-500/15 text-blue-400 flex items-center justify-center">

                    <i class="fas fa-user text-lg"></i>

                </div>

                <div>

                    <h2 class="text-lg font-semibold text-white">
                        Basic Information
                    </h2>

                    <p class="text-xs text-gray-400">
                        Enter user account details.
                    </p>

                </div>

            </div>


            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">


                <!-- FULL NAME -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        Full Name <span class="text-red-400">*</span>

                    </label>

                    <input
                        type="text"
                        name="full_name"
                        value="<?= e($full_name) ?>"
                        maxlength="100"
                        required
                        placeholder="Enter full name"
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                </div>


                <!-- EMAIL -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        Email Address <span class="text-red-400">*</span>

                    </label>

                    <input
                        type="email"
                        name="email"
                        value="<?= e($email) ?>"
                        maxlength="120"
                        required
                        placeholder="Enter email address"
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                </div>


                <!-- PASSWORD -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        Password <span class="text-red-400">*</span>

                    </label>

                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        placeholder="Minimum 8 characters"
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                    <p class="text-xs text-gray-500 mt-2">
                        Password must contain at least 8 characters.
                    </p>

                </div>


                <!-- CONFIRM PASSWORD -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        Confirm Password <span class="text-red-400">*</span>

                    </label>

                    <input
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        placeholder="Confirm password"
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                </div>


                <!-- ROLE -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        User Role <span class="text-red-400">*</span>

                    </label>

                    <select
                        name="role_id"
                        id="userRole"
                        required
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                        <option value="">
                            Select Role
                        </option>

                        <?php foreach ($roles as $id => $role): ?>

                            <option
                                value="<?= (int)$id ?>"
                                data-role-key="<?= e($role['role_key']) ?>"
                                <?= $role_id === (int)$id ? 'selected' : '' ?>
                            >

                                <?= e($role['role_name']) ?>

                                <?= (int)$role['is_system'] === 1 ? ' (System)' : '' ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <?php if (empty($roles)): ?>

                        <p class="text-xs text-red-400 mt-2">
                            No active roles available. Please create or activate a role first.
                        </p>

                    <?php endif; ?>

                </div>


                <!-- PERMISSION MODE -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">

                        Permission Mode <span class="text-red-400">*</span>

                    </label>

                    <select
                        name="permission_mode"
                        id="permissionMode"
                        required
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >

                        <option
                            value="role"
                            <?= $permission_mode === 'role' ? 'selected' : '' ?>
                        >
                            Role Based
                        </option>

                        <option
                            value="custom"
                            <?= $permission_mode === 'custom' ? 'selected' : '' ?>
                        >
                            Custom Permissions
                        </option>

                    </select>

                    <p class="text-xs text-gray-500 mt-2">
                        Role Based uses role permissions. Custom uses individually selected permissions.
                    </p>

                </div>


                <!-- ACCOUNT STATUS -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">
                        Account Status
                    </label>

                    <label class="flex items-center gap-3 rounded-xl bg-gray-900/70 border border-white/10 px-4 py-3 cursor-pointer">

                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?= $is_active ? 'checked' : '' ?>
                            class="w-4 h-4 accent-emerald-500"
                        >

                        <span class="text-white text-sm">
                            Active Account
                        </span>

                    </label>

                </div>

            </div>

        </div>


        <!-- PAGE PERMISSIONS -->

        <div
            id="permissionSection"
            class="rounded-2xl border border-white/10 bg-white/5 p-5 sm:p-6"
        >

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">

                <div class="flex items-center gap-3">

                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 text-purple-400 flex items-center justify-center">

                        <i class="fas fa-shield-alt text-lg"></i>

                    </div>

                    <div>

                        <h2 class="text-lg font-semibold text-white">
                            Page Permissions
                        </h2>

                        <p class="text-xs text-gray-400">
                            Select which pages this user can access.
                        </p>

                    </div>

                </div>


                <div class="flex gap-2">

                    <button
                        type="button"
                        id="selectAllPermissions"
                        class="px-3 py-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 text-xs font-semibold"
                    >
                        Select All
                    </button>

                    <button
                        type="button"
                        id="clearAllPermissions"
                        class="px-3 py-2 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-300 text-xs font-semibold"
                    >
                        Clear All
                    </button>

                </div>

            </div>


            <!-- PERMISSION INFO -->

            <div
                id="rolePermissionInfo"
                class="rounded-xl border border-blue-500/20 bg-blue-500/10 p-4 mb-5"
            >

                <div class="flex gap-3">

                    <i class="fas fa-info-circle text-blue-400 mt-0.5"></i>

                    <div class="text-sm text-blue-200">

                        <p class="font-semibold">
                            Role Based Permissions
                        </p>

                        <p class="text-blue-100/80 mt-1">
                            This user will automatically receive permissions assigned to the selected role. Manage those permissions from Role Management.
                        </p>

                    </div>

                </div>

            </div>


            <div
                id="customPermissionInfo"
                class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 mb-5"
            >

                <div class="flex gap-3">

                    <i class="fas fa-info-circle text-amber-400 mt-0.5"></i>

                    <div class="text-sm text-amber-200">

                        <p class="font-semibold">
                            Custom Permission Rules
                        </p>

                        <ul class="list-disc pl-5 mt-2 space-y-1 text-amber-100/80">

                            <li>Only individually selected permissions will be assigned.</li>

                            <li>Dashboard permission is mandatory.</li>

                            <li>Role permissions will not automatically be added.</li>

                        </ul>

                    </div>

                </div>

            </div>


            <?php if (empty($permissions)): ?>

                <div class="text-center py-8 text-gray-400">

                    <i class="fas fa-exclamation-triangle text-2xl mb-3"></i>

                    <p>No active permissions found.</p>

                    <p class="text-xs mt-1">
                        Please check the permissions table.
                    </p>

                </div>

            <?php else: ?>


                <div class="space-y-5" id="permissionContainer">

                    <?php foreach ($permission_groups as $group_name => $group_permissions): ?>

                        <div class="rounded-xl border border-white/10 overflow-hidden permission-group">

                            <div class="bg-white/5 px-4 py-3 flex items-center justify-between">

                                <h3 class="text-sm font-semibold text-white">

                                    <?= e(ucwords(str_replace(['_', '-'], ' ', $group_name))) ?>

                                </h3>

                                <span class="text-xs text-gray-400">

                                    <?= count($group_permissions) ?> permissions

                                </span>

                            </div>


                            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">

                                <?php foreach ($group_permissions as $permission): ?>

                                    <?php

                                    $permission_id = (int)$permission['id'];

                                    $is_dashboard = (
                                        $permission['permission_key'] === 'dashboard'
                                    );

                                    ?>

                                    <label class="permission-option flex items-start gap-3 p-3 rounded-lg bg-gray-900/50 border border-white/5 hover:border-emerald-500/30 cursor-pointer transition">

                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="<?= $permission_id ?>"
                                            data-dashboard="<?= $is_dashboard ? '1' : '0' ?>"
                                            <?= in_array($permission_id, $selected_permissions, true) ? 'checked' : '' ?>
                                            <?= $is_dashboard ? 'checked disabled' : '' ?>
                                            class="permission-checkbox w-4 h-4 mt-0.5 accent-emerald-500"
                                        >

                                        <span>

                                            <span class="block text-sm text-gray-200 font-medium">

                                                <?= e($permission['permission_name']) ?>

                                                <?php if ($is_dashboard): ?>

                                                    <span class="text-xs text-emerald-400 ml-1">
                                                        Required
                                                    </span>

                                                <?php endif; ?>

                                            </span>

                                            <span class="block text-xs text-gray-500 mt-1">

                                                <?= e($permission['page_path']) ?>

                                            </span>

                                        </span>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>


        <!-- FORM ACTIONS -->

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">

            <a
                href="users_list.php"
                class="px-6 py-3 rounded-xl bg-white/10 hover:bg-white/15 text-white text-center font-semibold transition"
            >
                Cancel
            </a>


            <button
                type="submit"
                <?= empty($roles) ? 'disabled' : '' ?>
                class="px-6 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold transition"
            >

                <i class="fas fa-user-plus mr-2"></i>

                Create User

            </button>

        </div>

    </form>

</div>


<!-- PERMISSION UI SCRIPT -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const roleSelect = document.getElementById('userRole');

    const permissionMode = document.getElementById('permissionMode');

    const permissionSection = document.getElementById('permissionSection');

    const rolePermissionInfo = document.getElementById('rolePermissionInfo');

    const customPermissionInfo = document.getElementById('customPermissionInfo');

    const checkboxes = document.querySelectorAll('.permission-checkbox');

    const selectAllButton = document.getElementById('selectAllPermissions');

    const clearAllButton = document.getElementById('clearAllPermissions');


    function updatePermissionUI() {

        const selectedOption = roleSelect.options[roleSelect.selectedIndex];

        const roleKey = selectedOption
            ? selectedOption.dataset.roleKey
            : '';

        const isAdmin = roleKey === 'admin';

        const isCustom = permissionMode.value === 'custom' && !isAdmin;


        // Admin always uses role-based permissions

        if (isAdmin) {

            permissionMode.value = 'role';

            permissionMode.disabled = true;

        } else {

            permissionMode.disabled = false;

        }


        // Permission section visibility

        permissionSection.style.display = isCustom ? 'block' : 'none';

        rolePermissionInfo.style.display = isCustom ? 'none' : 'block';

        customPermissionInfo.style.display = isCustom ? 'block' : 'none';


        // Checkbox behavior

        checkboxes.forEach(function (checkbox) {

            const isDashboard = checkbox.dataset.dashboard === '1';

            if (isDashboard) {

                checkbox.checked = true;

                checkbox.disabled = true;

                return;

            }

            checkbox.disabled = !isCustom;

        });


        // Buttons

        selectAllButton.disabled = !isCustom;

        clearAllButton.disabled = !isCustom;

        selectAllButton.classList.toggle('opacity-40', !isCustom);

        clearAllButton.classList.toggle('opacity-40', !isCustom);

    }


    // Role change

    roleSelect.addEventListener('change', updatePermissionUI);


    // Permission mode change

    permissionMode.addEventListener('change', updatePermissionUI);


    // Select all

    selectAllButton.addEventListener('click', function () {

        checkboxes.forEach(function (checkbox) {

            checkbox.checked = true;

        });

    });


    // Clear all except Dashboard

    clearAllButton.addEventListener('click', function () {

        checkboxes.forEach(function (checkbox) {

            if (checkbox.dataset.dashboard !== '1') {

                checkbox.checked = false;

            }

        });

    });


    // Initial UI state

    updatePermissionUI();

});

</script>


<?php include '../includes/footer.php'; ?>
