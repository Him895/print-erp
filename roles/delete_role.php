
<?php
// ============================================================
// FILE: roles/delete_role.php
// DESCRIPTION: Delete Custom Role
// ============================================================

require_once '../config/config.php';

// ------------------------------------------------------------
// 1. ADMIN ACCESS CHECK
// ------------------------------------------------------------

require_admin();

// ------------------------------------------------------------
// 2. POST REQUEST ONLY
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request method.');
    redirect('roles_list.php');
    exit;
}

// ------------------------------------------------------------
// 3. VALIDATE ROLE ID
// ------------------------------------------------------------

$role_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$role_id || $role_id <= 0) {
    set_flash('error', 'Invalid role selected.');
    redirect('roles_list.php');
    exit;
}

// ------------------------------------------------------------
// 4. FETCH ROLE
// ------------------------------------------------------------

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, role_key, role_name, is_system
     FROM roles
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$role = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$role) {
    set_flash('error', 'Role not found.');
    redirect('roles_list.php');
    exit;
}

// ------------------------------------------------------------
// 5. PROTECT ADMIN AND SYSTEM ROLES
// ------------------------------------------------------------

if ($role['role_key'] === 'admin' || (int)$role['is_system'] === 1) {
    set_flash('error', 'System roles cannot be deleted.');
    redirect('roles_list.php');
    exit;
}

// ------------------------------------------------------------
// 6. CHECK ASSIGNED USERS
// ------------------------------------------------------------

$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role_id = ?"
);

mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user_data = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

$assigned_users = (int)($user_data['total'] ?? 0);

if ($assigned_users > 0) {
    set_flash(
        'error',
        'This role cannot be deleted because users are assigned to it.'
    );

    redirect('roles_list.php');
    exit;
}

// ------------------------------------------------------------
// 7. DELETE ROLE USING TRANSACTION
// ------------------------------------------------------------

mysqli_begin_transaction($conn);

try {

    $delete_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM roles
         WHERE id = ?
         AND is_system = 0
         AND role_key != 'admin'"
    );

    mysqli_stmt_bind_param($delete_stmt, "i", $role_id);

    if (!mysqli_stmt_execute($delete_stmt)) {
        throw new Exception('Unable to delete role.');
    }

    $deleted_rows = mysqli_stmt_affected_rows($delete_stmt);

    mysqli_stmt_close($delete_stmt);

    if ($deleted_rows !== 1) {
        throw new Exception('Role was not deleted. Please try again.');
    }

    mysqli_commit($conn);

    set_flash(
        'success',
        'Role "' . $role['role_name'] . '" deleted successfully.'
    );

} catch (Throwable $e) {

    mysqli_rollback($conn);

    error_log('Role Delete Error: ' . $e->getMessage());

    set_flash(
        'error',
        'Unable to delete this role. Please check its dependencies and try again.'
    );
}

// ------------------------------------------------------------
// 8. REDIRECT
// ------------------------------------------------------------

redirect('roles_list.php');
exit;
