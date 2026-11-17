
<?php
// ============================================================
// FILE: users/toggle_status.php
// DESCRIPTION: Activate / Deactivate User
// ============================================================

require_once '../config/config.php';
require_admin();

require_once 'user_functions.php';

// ------------------------------------------------------------
// 1. ONLY POST REQUEST ALLOWED
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('405 - Method Not Allowed');
}

// ------------------------------------------------------------
// 2. CSRF VALIDATION
// ------------------------------------------------------------

if (!user_module_verify_csrf()) {
    set_flash(
        'error',
        'Invalid or expired request. Please try again.'
    );

    user_module_redirect('users_list.php');
}

// ------------------------------------------------------------
// 3. GET USER ID
// ------------------------------------------------------------

$user_id = (int) ($_POST['user_id'] ?? 0);
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    set_flash('error', 'Invalid user selected.');
    user_module_redirect('users_list.php');
}

// ------------------------------------------------------------
// 4. PREVENT SELF DEACTIVATION
// ------------------------------------------------------------

if ($user_id === $current_user_id) {
    set_flash(
        'error',
        'You cannot deactivate your own account.'
    );

    user_module_redirect('users_list.php');
}

// ------------------------------------------------------------
// 5. DATABASE TRANSACTION
// ------------------------------------------------------------

try {

    $conn->begin_transaction();

    // Lock target user record
    $stmt = $conn->prepare("
        SELECT
            id,
            full_name,
            role,
            role_id,
            is_active
        FROM users
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception(
            'Unable to prepare user status query.'
        );
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $target_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // User not found
    if (!$target_user) {
        throw new Exception('User not found.');
    }

    // --------------------------------------------------------
    // 6. DETERMINE NEW STATUS
    // --------------------------------------------------------

    $old_status = (int) $target_user['is_active'];
    $new_status = ($old_status === 1) ? 0 : 1;

    // --------------------------------------------------------
    // 7. VERIFY DYNAMIC ROLE
    // --------------------------------------------------------

    $role_key = '';

    if (!empty($target_user['role_id'])) {

        $role_stmt = $conn->prepare("
            SELECT role_key
            FROM roles
            WHERE id = ?
            LIMIT 1
        ");

        if (!$role_stmt) {
            throw new Exception(
                'Unable to verify user role.'
            );
        }

        $role_stmt->bind_param(
            'i',
            $target_user['role_id']
        );

        $role_stmt->execute();

        $role_data = $role_stmt
            ->get_result()
            ->fetch_assoc();

        $role_stmt->close();

        if ($role_data) {
            $role_key = $role_data['role_key'];
        }

    }

    // Legacy fallback for older Admin records
    if ($role_key === '') {
        $role_key = $target_user['role'];
    }

    // --------------------------------------------------------
    // 8. LAST ACTIVE ADMIN PROTECTION
    // --------------------------------------------------------

    if (
        $role_key === 'admin' &&
        $old_status === 1 &&
        $new_status === 0
    ) {

        if (
            user_module_is_last_active_admin(
                $conn,
                $user_id
            )
        ) {
            throw new Exception(
                'You cannot deactivate the last active Admin.'
            );
        }

    }

    // --------------------------------------------------------
    // 9. UPDATE USER STATUS
    // --------------------------------------------------------

    $update_stmt = $conn->prepare("
        UPDATE users
        SET is_active = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$update_stmt) {
        throw new Exception(
            'Unable to prepare status update query.'
        );
    }

    $update_stmt->bind_param(
        'ii',
        $new_status,
        $user_id
    );

    if (!$update_stmt->execute()) {
        $update_stmt->close();

        throw new Exception(
            'Unable to update user status.'
        );
    }

    $update_stmt->close();

    // --------------------------------------------------------
    // 10. COMMIT TRANSACTION
    // --------------------------------------------------------

    $conn->commit();

    if ($new_status === 1) {

        set_flash(
            'success',
            $target_user['full_name'] .
            ' has been activated successfully.'
        );

    } else {

        set_flash(
            'success',
            $target_user['full_name'] .
            ' has been deactivated successfully.'
        );

    }

} catch (Throwable $e) {

    $conn->rollback();

    error_log(
        'Toggle User Status Error: ' .
        $e->getMessage()
    );

    if (
        strpos(
            $e->getMessage(),
            'last active Admin'
        ) !== false
    ) {

        set_flash('error', $e->getMessage());

    } elseif (
        $e->getMessage() === 'User not found.'
    ) {

        set_flash('error', 'User not found.');

    } else {

        set_flash(
            'error',
            'Unable to update user status. Please try again.'
        );

    }

}

// ------------------------------------------------------------
// 11. REDIRECT
// ------------------------------------------------------------

user_module_redirect('users_list.php');

// ============================================================
// END OF FILE
// ============================================================
