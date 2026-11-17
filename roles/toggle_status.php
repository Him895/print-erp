
<?php
// ============================================================
// FILE: roles/toggle_status.php
// DESCRIPTION: Activate / Deactivate Roles
// ============================================================

require_once '../config/config.php';

require_admin();

// ------------------------------------------------------------
// 1. ONLY POST REQUEST ALLOWED
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request method.');
    redirect('roles_list.php');
}

// ------------------------------------------------------------
// 2. VALIDATE ROLE ID
// ------------------------------------------------------------

$role_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($role_id <= 0) {
    set_flash('error', 'Invalid role selected.');
    redirect('roles_list.php');
}

// ------------------------------------------------------------
// 3. FETCH ROLE
// ------------------------------------------------------------

$stmt = mysqli_prepare($conn, "
    SELECT id, role_key, role_name, is_active
    FROM roles
    WHERE id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$role = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$role) {
    set_flash('error', 'Role not found.');
    redirect('roles_list.php');
}

// ------------------------------------------------------------
// 4. PROTECT ADMIN ROLE
// ------------------------------------------------------------

if ($role['role_key'] === 'admin') {

    set_flash(
        'error',
        'Admin role cannot be deactivated.'
    );

    redirect('roles_list.php');
}

// ------------------------------------------------------------
// 5. CHECK ASSIGNED USERS BEFORE DEACTIVATION
// ------------------------------------------------------------

$current_status = (int)$role['is_active'];

// Only check assigned users when deactivating.
if ($current_status === 1) {

    $count_stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS total_users
        FROM users
        WHERE role_id = ?
    ");

    mysqli_stmt_bind_param($count_stmt, "i", $role_id);
    mysqli_stmt_execute($count_stmt);

    $count_result = mysqli_stmt_get_result($count_stmt);
    $count_data = mysqli_fetch_assoc($count_result);

    mysqli_stmt_close($count_stmt);

    $assigned_users = (int)($count_data['total_users'] ?? 0);

    if ($assigned_users > 0) {

        set_flash(
            'error',
            "Cannot deactivate this role. {$assigned_users} user(s) are currently assigned to it. Reassign those users first."
        );

        redirect('roles_list.php');
    }
}

// ------------------------------------------------------------
// 6. TOGGLE STATUS
// ------------------------------------------------------------

$new_status = ($current_status === 1) ? 0 : 1;

$update_stmt = mysqli_prepare($conn, "
    UPDATE roles
    SET is_active = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

mysqli_stmt_bind_param(
    $update_stmt,
    "ii",
    $new_status,
    $role_id
);

if (mysqli_stmt_execute($update_stmt)) {

    mysqli_stmt_close($update_stmt);

    if ($new_status === 1) {

        set_flash(
            'success',
            'Role activated successfully.'
        );

    } else {

        set_flash(
            'success',
            'Role deactivated successfully.'
        );
    }

} else {

    mysqli_stmt_close($update_stmt);

    set_flash(
        'error',
        'Unable to update role status.'
    );
}

redirect('roles_list.php');
