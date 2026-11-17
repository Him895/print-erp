
<?php
// ============================================================
// FILE: users/user_functions.php
// DESCRIPTION: Common User Management Functions
// ============================================================


// ------------------------------------------------------------
// 1. CSRF TOKEN GENERATE
// ------------------------------------------------------------

function user_module_csrf_token() {

    if (empty($_SESSION['user_module_csrf'])) {

        $_SESSION['user_module_csrf'] = bin2hex(random_bytes(32));

    }

    return $_SESSION['user_module_csrf'];
}


// ------------------------------------------------------------
// 2. CSRF TOKEN VERIFY
// ------------------------------------------------------------

function user_module_verify_csrf() {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['user_module_csrf'])
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['user_module_csrf'],
        $token
    );
}


// ------------------------------------------------------------
// 3. AVAILABLE USER ROLES
// ------------------------------------------------------------

function user_module_roles() {

    return [
        'admin'    => 'Admin',
        'manager'  => 'Manager',
        'sales'    => 'Sales',
        'service'  => 'Service',
        'accounts' => 'Accounts'
    ];
}


// ------------------------------------------------------------
// 4. FETCH ACTIVE PERMISSIONS
// ------------------------------------------------------------

function user_module_get_permissions($conn) {

    $sql = "
        SELECT
            id,
            permission_key,
            permission_name,
            permission_group,
            page_path,
            sort_order
        FROM permissions
        WHERE is_active = 1
        ORDER BY sort_order ASC, permission_name ASC
    ";

    $result = $conn->query($sql);

    $permissions = [];

    if ($result) {

        while ($row = $result->fetch_assoc()) {

            $permissions[] = $row;

        }

    }

    return $permissions;
}


// ------------------------------------------------------------
// 5. SAVE USER PERMISSIONS
// ------------------------------------------------------------

function user_module_save_permissions(
    $conn,
    $user_id,
    $permission_ids
) {

    $user_id = (int) $user_id;

    // Remove old permissions
    $delete = $conn->prepare("
        DELETE FROM user_permissions
        WHERE user_id = ?
    ");

    if (!$delete) {
        throw new Exception('Permission delete query failed.');
    }

    $delete->bind_param('i', $user_id);

    if (!$delete->execute()) {

        $delete->close();

        throw new Exception('Unable to remove old permissions.');

    }

    $delete->close();


    // No permission selected
    if (empty($permission_ids)) {
        return true;
    }


    // Insert new permissions
    $insert = $conn->prepare("
        INSERT INTO user_permissions
            (user_id, permission_id)
        VALUES (?, ?)
    ");

    if (!$insert) {
        throw new Exception('Permission insert query failed.');
    }


    foreach ($permission_ids as $permission_id) {

        $permission_id = (int) $permission_id;

        if ($permission_id <= 0) {
            continue;
        }

        $insert->bind_param(
            'ii',
            $user_id,
            $permission_id
        );

        if (!$insert->execute()) {

            $insert->close();

            throw new Exception('Unable to save user permissions.');

        }

    }

    $insert->close();

    return true;
}


// ------------------------------------------------------------
// 6. GET ALL PERMISSION IDS
// ------------------------------------------------------------

function user_module_permission_ids($permissions) {

    $ids = [];

    foreach ($permissions as $permission) {

        $ids[] = (int) $permission['id'];

    }

    return $ids;
}


// ------------------------------------------------------------
// 7. CHECK DASHBOARD PERMISSION
// ------------------------------------------------------------

function user_module_dashboard_selected(
    $permissions,
    $selected_ids
) {

    foreach ($permissions as $permission) {

        if (
            $permission['permission_key'] === 'dashboard' &&
            in_array(
                (int) $permission['id'],
                $selected_ids,
                true
            )
        ) {

            return true;

        }

    }

    return false;
}


// ------------------------------------------------------------
// 8. CHECK LAST ACTIVE ADMIN
// ------------------------------------------------------------

function user_module_is_last_active_admin(
    $conn,
    $user_id
) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM users
        WHERE role = 'admin'
          AND is_active = 1
          AND id != ?
    ");

    if (!$stmt) {
        throw new Exception('Admin verification query failed.');
    }

    $stmt->bind_param('i', $user_id);

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return ((int) ($row['total'] ?? 0)) === 0;
}


// ------------------------------------------------------------
// 9. REDIRECT TO USERS PAGE
// ------------------------------------------------------------

function user_module_redirect(
    $page = 'users_list.php'
) {

    redirect(BASE_URL . 'users/' . $page);

}


// ============================================================
// END OF USER MANAGEMENT FUNCTIONS
// ============================================================
