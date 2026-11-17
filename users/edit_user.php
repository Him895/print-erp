
<?php
// ============================================================
// FILE: users/edit_user.php
// DESCRIPTION: Dynamic Edit User + Role Based / Custom Permissions
// ============================================================

require_once '../config/config.php';
require_admin();

require_once 'user_functions.php';

$page_title = 'Edit User';

// ------------------------------------------------------------
// 1. GET USER ID
// ------------------------------------------------------------

$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    user_module_redirect('users_list.php');
}

// ------------------------------------------------------------
// 2. FETCH USER WITH ROLE DETAILS
// ------------------------------------------------------------

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.password,
        u.role,
        u.role_id,
        u.permission_mode,
        u.is_active,
        u.created_at,
        r.role_key,
        r.role_name,
        r.is_active AS role_is_active
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Unable to prepare user query.');
}

$stmt->bind_param('i', $user_id);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$user) {
    set_flash('error', 'User not found.');
    user_module_redirect('users_list.php');
}

// ------------------------------------------------------------
// 3. FETCH ACTIVE PERMISSIONS
// ------------------------------------------------------------

$permissions = user_module_get_permissions($conn);
$valid_permission_ids = user_module_permission_ids($permissions);

$permission_groups = [];

foreach ($permissions as $permission) {
    $group = $permission['permission_group'] ?: 'Other';
    $permission_groups[$group][] = $permission;
}

// Find mandatory dashboard permission ID
$dashboard_permission_id = 0;

foreach ($permissions as $permission) {
    if ($permission['permission_key'] === 'dashboard') {
        $dashboard_permission_id = (int)$permission['id'];
        break;
    }
}

if ($dashboard_permission_id <= 0) {
    die('Dashboard permission is missing. Please configure it first.');
}

// ------------------------------------------------------------
// 4. FETCH ROLES FROM DATABASE
// ------------------------------------------------------------

$roles = [];

$roles_result = $conn->query("
    SELECT
        id,
        role_key,
        role_name,
        description,
        is_system,
        is_active
    FROM roles
    ORDER BY
        CASE WHEN role_key = 'admin' THEN 0 ELSE 1 END,
        role_name ASC
");

if (!$roles_result) {
    die('Unable to fetch roles.');
}

while ($role_row = $roles_result->fetch_assoc()) {
    $roles[$role_row['role_key']] = $role_row;
}

// Current role key from roles table, fallback to legacy users.role
$current_role_key = $user['role_key'] ?: $user['role'];

if (!isset($roles[$current_role_key])) {
    $current_role_key = $user['role'];
}

// ------------------------------------------------------------
// 5. INITIAL FORM DATA
// ------------------------------------------------------------

$errors = [];

$full_name = $user['full_name'];
$email = $user['email'];

$role = $current_role_key;

$permission_mode = $user['permission_mode'] ?: 'role';

if (!in_array($permission_mode, ['role', 'custom'], true)) {
    $permission_mode = 'role';
}

$is_active = (int)$user['is_active'];

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

$is_self = ($user_id === $current_user_id);

$is_target_admin = ($current_role_key === 'admin');

$selected_permissions = [];

// ------------------------------------------------------------
// 6. FETCH EXISTING CUSTOM PERMISSIONS
// ------------------------------------------------------------

$current_permissions_stmt = $conn->prepare("
    SELECT permission_id
    FROM user_permissions
    WHERE user_id = ?
");

if (!$current_permissions_stmt) {
    die('Unable to prepare permission query.');
}

$current_permissions_stmt->bind_param('i', $user_id);
$current_permissions_stmt->execute();

$current_permissions_result = $current_permissions_stmt->get_result();

while ($permission_row = $current_permissions_result->fetch_assoc()) {
    $selected_permissions[] = (int)$permission_row['permission_id'];
}

$current_permissions_stmt->close();

// Role-based users do not use user_permissions.
// Only show their saved individual selections in Custom mode.
if ($permission_mode !== 'custom') {
    $selected_permissions = [];
}

// ------------------------------------------------------------
// 7. FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!user_module_verify_csrf()) {
        $errors[] = 'Invalid or expired form request. Please refresh and try again.';
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($is_self) {

        // Self account restrictions
        $role = $current_role_key;
        $permission_mode = $user['permission_mode'] ?: 'role';
        $is_active = (int)$user['is_active'];

        $password = '';
        $confirm_password = '';

    } else {

        $role = trim($_POST['role'] ?? '');

        $permission_mode = trim($_POST['permission_mode'] ?? 'role');

        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

    }

    // --------------------------------------------------------
    // 8. BASIC VALIDATION
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

    if (!in_array($permission_mode, ['role', 'custom'], true)) {
        $errors[] = 'Please select a valid permission mode.';
    }

    // --------------------------------------------------------
    // 9. ROLE VALIDATION
    // --------------------------------------------------------

    $selected_role = null;

    if (!$is_self) {

        if (!isset($roles[$role])) {

            $errors[] = 'Please select a valid role.';

        } else {

            $selected_role = $roles[$role];

            if ((int)$selected_role['is_active'] !== 1) {
                $errors[] = 'This role is inactive. Please select an active role.';
            }

        }

    } else {

        $selected_role = $roles[$role] ?? null;

    }

    // Admin always uses Role Based mode
    if ($role === 'admin') {
        $permission_mode = 'role';
    }

    // --------------------------------------------------------
    // 10. PASSWORD VALIDATION
    // --------------------------------------------------------

    if (!$is_self && $password !== '') {

        if (strlen($password) < 8) {
            $errors[] = 'New password must contain at least 8 characters.';
        }

        if ($password !== $confirm_password) {
            $errors[] = 'Password and confirm password do not match.';
        }

    }

    // --------------------------------------------------------
    // 11. DUPLICATE EMAIL CHECK
    // --------------------------------------------------------

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $check_email = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        if (!$check_email) {

            $errors[] = 'Unable to validate email address.';

        } else {

            $check_email->bind_param('si', $email, $user_id);
            $check_email->execute();

            $existing_user = $check_email->get_result()->fetch_assoc();

            $check_email->close();

            if ($existing_user) {
                $errors[] = 'This email address is already used by another user.';
            }

        }

    }

    // --------------------------------------------------------
    // 12. PREVENT LAST ADMIN DEMOTION / DEACTIVATION
    // --------------------------------------------------------

    if (
        !$is_self &&
        $is_target_admin &&
        (int)$user['is_active'] === 1 &&
        ($role !== 'admin' || $is_active !== 1)
    ) {

        try {

            if (user_module_is_last_active_admin($conn, $user_id)) {
                $errors[] = 'You cannot demote or deactivate the last active Admin.';
            }

        } catch (Throwable $e) {

            error_log('Edit User Admin Check: ' . $e->getMessage());

            $errors[] = 'Unable to verify active Admin accounts.';

        }

    }

    // --------------------------------------------------------
    // 13. PERMISSION VALIDATION
    // --------------------------------------------------------

    if ($is_self) {

        // Self permissions cannot be changed here.
        $selected_permissions = $valid_permission_ids;

    } elseif ($role === 'admin') {

        // Admin automatically receives all active permissions.
        $selected_permissions = $valid_permission_ids;
        $permission_mode = 'role';

    } elseif ($permission_mode === 'custom') {

        $posted_permissions = $_POST['permissions'] ?? [];

        if (!is_array($posted_permissions)) {
            $posted_permissions = [];
        }

        $selected_permissions = array_values(
            array_unique(
                array_map('intval', $posted_permissions)
            )
        );

        // Only active permission IDs are accepted.
        $selected_permissions = array_values(
            array_intersect(
                $selected_permissions,
                $valid_permission_ids
            )
        );

        // Dashboard is always mandatory.
        if (!in_array($dashboard_permission_id, $selected_permissions, true)) {
            $selected_permissions[] = $dashboard_permission_id;
        }

        $selected_permissions = array_values(
            array_unique($selected_permissions)
        );

    } else {

        // Role Based mode: verify role has Dashboard permission.
        $selected_permissions = [];

        if ($selected_role) {

            $dashboard_check = $conn->prepare("
                SELECT rp.permission_id
                FROM role_permissions rp
                INNER JOIN permissions p
                    ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                  AND p.permission_key = 'dashboard'
                  AND p.is_active = 1
                LIMIT 1
            ");

            if (!$dashboard_check) {

                $errors[] = 'Unable to verify role permissions.';

            } else {

                $selected_role_id = (int)$selected_role['id'];

                $dashboard_check->bind_param('i', $selected_role_id);
                $dashboard_check->execute();

                $has_dashboard = $dashboard_check->get_result()->fetch_assoc();

                $dashboard_check->close();

                if (!$has_dashboard) {
                    $errors[] = 'Selected role does not have Dashboard permission. Please update its role permissions first.';
                }

            }

        }

    }

    // --------------------------------------------------------
    // 14. UPDATE USER
    // --------------------------------------------------------

    if (empty($errors)) {

        try {

            $conn->begin_transaction();

            // Lock user row
            $lock_stmt = $conn->prepare("
                SELECT id, role, role_id, is_active
                FROM users
                WHERE id = ?
                FOR UPDATE
            ");

            if (!$lock_stmt) {
                throw new Exception('Unable to lock user record.');
            }

            $lock_stmt->bind_param('i', $user_id);
            $lock_stmt->execute();

            $locked_user = $lock_stmt->get_result()->fetch_assoc();

            $lock_stmt->close();

            if (!$locked_user) {
                throw new Exception('User no longer exists.');
            }

            // Recheck last Admin inside transaction
            if (
                !$is_self &&
                $is_target_admin &&
                (int)$locked_user['is_active'] === 1 &&
                ($role !== 'admin' || $is_active !== 1)
            ) {

                if (user_module_is_last_active_admin($conn, $user_id)) {
                    throw new Exception(
                        'You cannot demote or deactivate the last active Admin.'
                    );
                }

            }

            if ($is_self) {

                // Self account: only name and email
                $update_stmt = $conn->prepare("
                    UPDATE users
                    SET
                        full_name = ?,
                        email = ?
                    WHERE id = ?
                ");

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare profile update query.');
                }

                $update_stmt->bind_param(
                    'ssi',
                    $full_name,
                    $email,
                    $user_id
                );

            } else {

                if (!$selected_role) {
                    throw new Exception('Selected role is invalid.');
                }

                $role_id = (int)$selected_role['id'];
                $role_key = $selected_role['role_key'];

                // Admin always uses role mode.
                if ($role_key === 'admin') {
                    $permission_mode = 'role';
                }

                if ($password !== '') {

                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $update_stmt = $conn->prepare("
                        UPDATE users
                        SET
                            full_name = ?,
                            email = ?,
                            password = ?,
                            role = ?,
                            role_id = ?,
                            permission_mode = ?,
                            is_active = ?
                        WHERE id = ?
                    ");

                    if (!$update_stmt) {
                        throw new Exception('Unable to prepare user update query.');
                    }

                    $update_stmt->bind_param(
                        'ssssissi',
                        $full_name,
                        $email,
                        $hashed_password,
                        $role_key,
                        $role_id,
                        $permission_mode,
                        $is_active,
                        $user_id
                    );

                } else {

                    $update_stmt = $conn->prepare("
                        UPDATE users
                        SET
                            full_name = ?,
                            email = ?,
                            role = ?,
                            role_id = ?,
                            permission_mode = ?,
                            is_active = ?
                        WHERE id = ?
                    ");

                    if (!$update_stmt) {
                        throw new Exception('Unable to prepare user update query.');
                    }

                    $update_stmt->bind_param(
                        'sssissi',
                        $full_name,
                        $email,
                        $role_key,
                        $role_id,
                        $permission_mode,
                        $is_active,
                        $user_id
                    );

                }

            }

            if (!$update_stmt->execute()) {
                $update_stmt->close();
                throw new Exception('Unable to update user details.');
            }

            $update_stmt->close();

            // ------------------------------------------------
            // 15. SAVE CUSTOM / ROLE BASED PERMISSIONS
            // ------------------------------------------------

            if (!$is_self) {

                if ($role === 'admin') {

                    // Admin gets all permissions automatically.
                    // Remove any old custom permission records.
                    user_module_save_permissions(
                        $conn,
                        $user_id,
                        []
                    );

                } elseif ($permission_mode === 'custom') {

                    // Custom mode saves selected permissions.
                    user_module_save_permissions(
                        $conn,
                        $user_id,
                        $selected_permissions
                    );

                } else {

                    // Role mode uses role_permissions only.
                    // Clear old custom permissions.
                    user_module_save_permissions(
                        $conn,
                        $user_id,
                        []
                    );

                }

            }

            // Commit
            $conn->commit();

            set_flash(
                'success',
                'User details and permissions updated successfully.'
            );

            user_module_redirect('users_list.php');

        } catch (Throwable $e) {

            $conn->rollback();

            error_log('Edit User Error: ' . $e->getMessage());

            if (strpos($e->getMessage(), 'last active Admin') !== false) {

                $errors[] = $e->getMessage();

            } else {

                $errors[] = 'Unable to update user. Please check the details and try again.';

            }

        }

    }

}

// ------------------------------------------------------------
// 16. HEADER
// ------------------------------------------------------------

include '../includes/header.php';

?>

<div class="space-y-6">

    <!-- PAGE HEADER -->

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

        <div>
            <h1 class="text-2xl font-bold text-white">Edit User</h1>
            <p class="text-sm text-gray-400 mt-1">
                Update account details, roles and page permissions.
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

    <!-- USER INFORMATION -->

    <div class="rounded-2xl border border-white/10 bg-white/5 p-5">

        <div class="flex items-center gap-3">

            <div class="w-12 h-12 rounded-full bg-emerald-500/15 text-emerald-400 flex items-center justify-center text-xl font-bold">
                <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
            </div>

            <div>
                <h2 class="text-white font-semibold">
                    <?= e($user['full_name']) ?>
                </h2>

                <p class="text-gray-400 text-sm">
                    User ID: #<?= (int)$user['id'] ?>

                    <?php if ($is_self): ?>
                        <span class="text-emerald-400 ml-1">(Your Account)</span>
                    <?php endif; ?>
                </p>
            </div>

        </div>

    </div>

    <!-- ERRORS -->

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

    <!-- EDIT FORM -->

    <form method="POST" class="space-y-6">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(user_module_csrf_token()) ?>"
        >

        <!-- BASIC INFORMATION -->

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5 sm:p-6">

            <div class="flex items-center gap-3 mb-6">

                <div class="w-10 h-10 rounded-xl bg-blue-500/15 text-blue-400 flex items-center justify-center">
                    <i class="fas fa-user-edit text-lg"></i>
                </div>

                <div>
                    <h2 class="text-lg font-semibold text-white">
                        Basic Information
                    </h2>

                    <p class="text-xs text-gray-400">
                        Update user account details.
                    </p>
                </div>

            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                <!-- NAME -->

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
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                    >
                </div>

                <!-- PASSWORD -->

                <?php if (!$is_self): ?>

                    <div>
                        <label class="block text-sm text-gray-300 mb-2">
                            New Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Leave blank to keep current password"
                            class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                        >

                        <p class="text-xs text-gray-500 mt-2">
                            Leave blank if you do not want to change the password.
                        </p>
                    </div>

                    <!-- CONFIRM PASSWORD -->

                    <div>
                        <label class="block text-sm text-gray-300 mb-2">
                            Confirm New Password
                        </label>

                        <input
                            type="password"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Confirm new password"
                            class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500"
                        >
                    </div>

                <?php endif; ?>

                <!-- ROLE -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">
                        User Role
                    </label>

                    <?php if ($is_self): ?>

                        <input
                            type="hidden"
                            name="role"
                            value="<?= e($role) ?>"
                        >

                    <?php endif; ?>

                    <select
                        name="role"
                        id="userRole"
                        <?= $is_self ? 'disabled' : '' ?>
                        class="w-full rounded-xl bg-gray-900/70 border border-white/10 text-white px-4 py-3 focus:outline-none focus:border-emerald-500 <?= $is_self ? 'opacity-60 cursor-not-allowed' : '' ?>"
                    >

                        <?php foreach ($roles as $key => $role_data): ?>

                            <?php
                            $role_is_active = (int)$role_data['is_active'] === 1;
                            $is_current_role = ($role === $key);
                            ?>

                            <?php if ($role_is_active || $is_current_role): ?>

                                <option
                                    value="<?= e($key) ?>"
                                    <?= $role === $key ? 'selected' : '' ?>
                                    <?= !$role_is_active ? 'disabled' : '' ?>
                                >
                                    <?= e($role_data['role_name']) ?>
                                    <?= !$role_is_active ? ' (Inactive)' : '' ?>
                                </option>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </select>

                    <?php if ($is_self): ?>
                        <p class="text-xs text-amber-400 mt-2">
                            You cannot change your own Admin role.
                        </p>
                    <?php endif; ?>

                </div>

                <!-- ACCOUNT STATUS -->

                <div>

                    <label class="block text-sm text-gray-300 mb-2">
                        Account Status
                    </label>

                    <?php if ($is_self): ?>
                        <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>

                    <label class="flex items-center gap-3 rounded-xl bg-gray-900/70 border border-white/10 px-4 py-3 <?= $is_self ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' ?>">

                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?= $is_active === 1 ? 'checked' : '' ?>
                            <?= $is_self ? 'disabled' : '' ?>
                            class="w-4 h-4 accent-emerald-500"
                        >

                        <span class="text-white text-sm">
                            Active Account
                        </span>

                    </label>

                    <?php if ($is_self): ?>
                        <p class="text-xs text-amber-400 mt-2">
                            You cannot deactivate your own account.
                        </p>
                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- PERMISSION MODE -->

        <?php if (!$is_self): ?>

            <div
                id="permissionModeSection"
                class="rounded-2xl border border-white/10 bg-white/5 p-5 sm:p-6"
            >

                <div class="flex items-center gap-3 mb-5">

                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 text-purple-400 flex items-center justify-center">
                        <i class="fas fa-shield-alt text-lg"></i>
                    </div>

                    <div>
                        <h2 class="text-lg font-semibold text-white">
                            Permission Settings
                        </h2>

                        <p class="text-xs text-gray-400">
                            Choose how this user's permissions should work.
                        </p>
                    </div>

                </div>

                <div class="max-w-xl">

                    <label class="block text-sm text-gray-300 mb-2">
                        Permission Mode
                    </label>

                    <select
                        name="permission_mode"
                        id="permissionMode"
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
                        Role Based uses permissions assigned to the selected role. Custom Permissions uses the individual permissions selected below.
                    </p>

                </div>

                <!-- ROLE BASED MESSAGE -->

                <div
                    id="roleBasedMessage"
                    class="mt-5 rounded-xl border border-blue-500/20 bg-blue-500/10 p-4"
                >

                    <div class="flex gap-3">

                        <i class="fas fa-info-circle text-blue-400 mt-0.5"></i>

                        <div>
                            <p class="text-blue-300 font-semibold">
                                Role Based Access
                            </p>

                            <p class="text-sm text-blue-200/80 mt-1">
                                This user will automatically receive the permissions assigned to the selected role. Individual permissions are not used in this mode.
                            </p>
                        </div>

                    </div>

                </div>

                <!-- CUSTOM PERMISSIONS -->

                <div id="customPermissionSection" class="mt-6">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">

                        <div>
                            <h3 class="text-base font-semibold text-white">
                                Individual Page Permissions
                            </h3>

                            <p class="text-xs text-gray-400 mt-1">
                                Select exactly which pages this user can access.
                            </p>
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

                    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-4 mb-5">

                        <div class="flex gap-3">

                            <i class="fas fa-info-circle text-amber-400 mt-0.5"></i>

                            <div class="text-sm text-amber-200">

                                <p class="font-semibold">
                                    Permission Rules
                                </p>

                                <ul class="list-disc pl-5 mt-2 space-y-1 text-amber-100/80">

                                    <li>Dashboard permission is mandatory.</li>
                                    <li>Custom permissions replace the previous individual permission selection.</li>
                                    <li>Inactive permissions cannot be assigned.</li>
                                    <li>Admin always receives all active permissions.</li>

                                </ul>

                            </div>

                        </div>

                    </div>

                    <?php if (empty($permissions)): ?>

                        <p class="text-center text-gray-400 py-8">
                            No active permissions found.
                        </p>

                    <?php else: ?>

                        <div class="space-y-5">

                            <?php foreach ($permission_groups as $group_name => $group_permissions): ?>

                                <div class="rounded-xl border border-white/10 overflow-hidden">

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

                                            $is_checked = (
                                                in_array(
                                                    $permission_id,
                                                    $selected_permissions,
                                                    true
                                                ) || $is_dashboard
                                            );
                                            ?>

                                            <label class="flex items-start gap-3 p-3 rounded-lg bg-gray-900/50 border border-white/5 hover:border-emerald-500/30 cursor-pointer transition">

                                                <input
                                                    type="checkbox"
                                                    name="permissions[]"
                                                    value="<?= $permission_id ?>"
                                                    data-dashboard="<?= $is_dashboard ? '1' : '0' ?>"
                                                    <?= $is_checked ? 'checked' : '' ?>
                                                    <?= $is_dashboard ? 'disabled' : '' ?>
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

                <!-- ADMIN MESSAGE -->

                <div
                    id="adminPermissionMessage"
                    class="mt-5 rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-4"
                >

                    <div class="flex gap-3">

                        <i class="fas fa-shield-alt text-emerald-400 mt-0.5"></i>

                        <div>
                            <p class="text-emerald-300 font-semibold">
                                Admin Full Access
                            </p>

                            <p class="text-sm text-emerald-200/80 mt-1">
                                Admin automatically receives all active permissions. Custom permission selection is disabled for Admin.
                            </p>
                        </div>

                    </div>

                </div>

            </div>

        <?php else: ?>

            <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-4">

                <div class="flex gap-3">

                    <i class="fas fa-shield-alt text-emerald-400 mt-0.5"></i>

                    <div>
                        <p class="text-emerald-300 font-semibold">
                            Admin Account
                        </p>

                        <p class="text-sm text-emerald-200/80 mt-1">
                            Admin automatically has access to all active permissions. Your own role, status and permissions cannot be changed here.
                        </p>
                    </div>

                </div>

            </div>

        <?php endif; ?>

        <!-- ACTION BUTTONS -->

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">

            <a
                href="users_list.php"
                class="px-6 py-3 rounded-xl bg-white/10 hover:bg-white/15 text-white text-center font-semibold transition"
            >
                Cancel
            </a>

            <button
                type="submit"
                class="px-6 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold transition"
            >
                <i class="fas fa-save mr-2"></i>
                Update User
            </button>

        </div>

    </form>

</div>

<!-- PERMISSION SCRIPT -->

<?php if (!$is_self): ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const roleSelect = document.getElementById('userRole');
    const modeSelect = document.getElementById('permissionMode');

    const customSection = document.getElementById('customPermissionSection');
    const roleMessage = document.getElementById('roleBasedMessage');
    const adminMessage = document.getElementById('adminPermissionMessage');

    const checkboxes = document.querySelectorAll('.permission-checkbox');

    const selectAllButton = document.getElementById('selectAllPermissions');
    const clearAllButton = document.getElementById('clearAllPermissions');

    function updatePermissionUI() {

        const isAdmin = roleSelect.value === 'admin';
        const isCustom = modeSelect.value === 'custom' && !isAdmin;

        // Admin always uses Role Based mode.
        if (isAdmin) {
            modeSelect.value = 'role';
            modeSelect.disabled = true;
        } else {
            modeSelect.disabled = false;
        }

        customSection.style.display = isCustom ? 'block' : 'none';
        roleMessage.style.display = (!isCustom && !isAdmin) ? 'block' : 'none';
        adminMessage.style.display = isAdmin ? 'block' : 'none';

        checkboxes.forEach(function (checkbox) {

            const isDashboard = checkbox.dataset.dashboard === '1';

            if (isDashboard) {
                checkbox.checked = true;
                checkbox.disabled = true;
                return;
            }

            checkbox.disabled = !isCustom;

        });

        selectAllButton.disabled = !isCustom;
        clearAllButton.disabled = !isCustom;

        selectAllButton.classList.toggle('opacity-40', !isCustom);
        clearAllButton.classList.toggle('opacity-40', !isCustom);

    }

    roleSelect.addEventListener('change', updatePermissionUI);
    modeSelect.addEventListener('change', updatePermissionUI);

    selectAllButton.addEventListener('click', function () {

        if (modeSelect.value !== 'custom' || roleSelect.value === 'admin') {
            return;
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.checked = true;
        });

    });

    clearAllButton.addEventListener('click', function () {

        if (modeSelect.value !== 'custom' || roleSelect.value === 'admin') {
            return;
        }

        checkboxes.forEach(function (checkbox) {

            if (checkbox.dataset.dashboard !== '1') {
                checkbox.checked = false;
            }

        });

    });

    updatePermissionUI();

});
</script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
