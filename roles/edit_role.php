
<?php
// ============================================================
// FILE: roles/edit_role.php
// DESCRIPTION: Edit Role + Manage Role Permissions
// ============================================================

require_once '../config/config.php';
require_admin();

$page_title = 'Edit Role';

$errors = [];
$success = '';

$role_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($role_id <= 0) {
    set_flash('error', 'Invalid role selected.');
    redirect('roles_list.php');
}

// ------------------------------------------------------------
// 1. FETCH ROLE
// ------------------------------------------------------------

$stmt = mysqli_prepare($conn, "
    SELECT id, role_key, role_name, description, is_system, is_active
    FROM roles
    WHERE id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);

$role_result = mysqli_stmt_get_result($stmt);
$role = mysqli_fetch_assoc($role_result);

mysqli_stmt_close($stmt);

if (!$role) {
    set_flash('error', 'Role not found.');
    redirect('roles_list.php');
}

$is_admin_role = ($role['role_key'] === 'admin');

// ------------------------------------------------------------
// 2. FETCH ACTIVE PERMISSIONS
// ------------------------------------------------------------

$permissions_result = mysqli_query($conn, "
    SELECT id, permission_key, permission_name,
           permission_group, page_path, sort_order
    FROM permissions
    WHERE is_active = 1
    ORDER BY permission_group ASC, sort_order ASC, id ASC
");

if (!$permissions_result) {
    die('Unable to fetch permissions: ' . e(mysqli_error($conn)));
}

$permissions = [];

while ($row = mysqli_fetch_assoc($permissions_result)) {
    $permissions[] = $row;
}

// ------------------------------------------------------------
// 3. FETCH CURRENT ROLE PERMISSIONS
// ------------------------------------------------------------

$current_permissions = [];

$stmt = mysqli_prepare($conn, "
    SELECT permission_id
    FROM role_permissions
    WHERE role_id = ?
");

mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);

$current_result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($current_result)) {
    $current_permissions[] = (int)$row['permission_id'];
}

mysqli_stmt_close($stmt);

// Admin always has all active permissions.
// Keep its role permission records aligned as well.
if ($is_admin_role) {
    $current_permissions = array_map(
        'intval',
        array_column($permissions, 'id')
    );
}

// ------------------------------------------------------------
// 4. FORM DEFAULT VALUES
// ------------------------------------------------------------

$role_name = $role['role_name'];
$description = $role['description'] ?? '';
$selected_permissions = $current_permissions;

// ------------------------------------------------------------
// 5. HANDLE FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role_name = trim($_POST['role_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

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

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------

    if ($role_name === '') {
        $errors[] = 'Role name is required.';
    } elseif (mb_strlen($role_name) > 100) {
        $errors[] = 'Role name cannot exceed 100 characters.';
    }

    if (mb_strlen($description) > 255) {
        $errors[] = 'Description cannot exceed 255 characters.';
    }

    // Admin role must retain all active permissions.
    if ($is_admin_role) {

        $selected_permissions = array_map(
            'intval',
            array_column($permissions, 'id')
        );

    } else {

        // Dashboard permission is mandatory.
        foreach ($permissions as $permission) {
            if ($permission['permission_key'] === 'dashboard') {
                $dashboard_id = (int)$permission['id'];

                if (!in_array($dashboard_id, $selected_permissions, true)) {
                    $selected_permissions[] = $dashboard_id;
                }

                break;
            }
        }

        // Dashboard must exist and be active.
        $dashboard_exists = false;

        foreach ($permissions as $permission) {
            if ($permission['permission_key'] === 'dashboard') {
                $dashboard_exists = true;
                break;
            }
        }

        if (!$dashboard_exists) {
            $errors[] = 'Active Dashboard permission is required.';
        }
    }

    // Validate selected permission IDs against active permissions.
    $active_permission_ids = array_map(
        'intval',
        array_column($permissions, 'id')
    );

    foreach ($selected_permissions as $permission_id) {
        if (!in_array($permission_id, $active_permission_ids, true)) {
            $errors[] = 'Invalid or inactive permission selected.';
            break;
        }
    }

    // --------------------------------------------------------
    // UPDATE DATABASE
    // --------------------------------------------------------

    if (empty($errors)) {

        try {

            mysqli_begin_transaction($conn);

            // Update role details.
            $update_stmt = mysqli_prepare($conn, "
                UPDATE roles
                SET role_name = ?,
                    description = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            mysqli_stmt_bind_param(
                $update_stmt,
                "ssi",
                $role_name,
                $description,
                $role_id
            );

            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception('Unable to update role details.');
            }

            mysqli_stmt_close($update_stmt);

            // Replace existing role permissions.
            $delete_stmt = mysqli_prepare($conn, "
                DELETE FROM role_permissions
                WHERE role_id = ?
            ");

            mysqli_stmt_bind_param($delete_stmt, "i", $role_id);

            if (!mysqli_stmt_execute($delete_stmt)) {
                throw new Exception('Unable to remove old permissions.');
            }

            mysqli_stmt_close($delete_stmt);

            // Insert selected permissions.
            if (!empty($selected_permissions)) {

                $insert_stmt = mysqli_prepare($conn, "
                    INSERT INTO role_permissions
                    (role_id, permission_id)
                    VALUES (?, ?)
                ");

                foreach ($selected_permissions as $permission_id) {

                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "ii",
                        $role_id,
                        $permission_id
                    );

                    if (!mysqli_stmt_execute($insert_stmt)) {
                        throw new Exception('Unable to save role permissions.');
                    }
                }

                mysqli_stmt_close($insert_stmt);
            }

            mysqli_commit($conn);

            set_flash('success', 'Role updated successfully.');

            redirect('roles_list.php');

        } catch (Throwable $e) {

            mysqli_rollback($conn);

            $errors[] = 'Something went wrong: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------
// 6. GROUP PERMISSIONS
// ------------------------------------------------------------

$permission_groups = [];

foreach ($permissions as $permission) {

    $group = $permission['permission_group'] ?: 'Other';

    if (!isset($permission_groups[$group])) {
        $permission_groups[$group] = [];
    }

    $permission_groups[$group][] = $permission;
}

include '../includes/header.php';
?>

<style>
.role-page {
    padding: 24px;
    color: #e5e7eb;
}

.role-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.role-heading {
    display: flex;
    align-items: center;
    gap: 13px;
}

.role-heading-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(16, 185, 129, .15);
    color: #34d399;
    font-size: 21px;
}

.role-heading h2 {
    margin: 0;
    font-size: 23px;
    font-weight: 700;
    color: #f8fafc;
}

.role-heading p {
    margin: 5px 0 0;
    color: #94a3b8;
    font-size: 13px;
}

.role-back-btn {
    padding: 10px 16px;
    border: 1px solid rgba(148,163,184,.2);
    background: rgba(255,255,255,.04);
    color: #cbd5e1;
    border-radius: 9px;
    text-decoration: none;
    font-size: 13px;
}

.role-back-btn:hover {
    background: rgba(255,255,255,.09);
    color: #fff;
}

.role-form-card {
    max-width: 1050px;
    margin: 0 auto;
    background: rgba(15,23,42,.78);
    border: 1px solid rgba(148,163,184,.15);
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 15px 40px rgba(0,0,0,.18);
}

.role-section {
    margin-bottom: 28px;
}

.role-section-title {
    font-size: 16px;
    font-weight: 700;
    color: #f1f5f9;
    margin-bottom: 17px;
    display: flex;
    align-items: center;
    gap: 9px;
}

.role-section-title i {
    color: #34d399;
}

.role-form-group {
    margin-bottom: 18px;
}

.role-form-group label {
    display: block;
    color: #cbd5e1;
    font-size: 13px;
    margin-bottom: 8px;
    font-weight: 600;
}

.role-form-group input,
.role-form-group textarea {
    width: 100%;
    padding: 12px 14px;
    background: rgba(2,6,23,.55);
    border: 1px solid rgba(148,163,184,.2);
    border-radius: 9px;
    color: #f8fafc;
    outline: none;
    font-size: 14px;
}

.role-form-group input:focus,
.role-form-group textarea:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,.1);
}

.role-form-group textarea {
    min-height: 85px;
    resize: vertical;
}

.role-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 12px;
}

.role-meta span {
    padding: 6px 10px;
    border-radius: 7px;
    background: rgba(148,163,184,.09);
    color: #94a3b8;
    font-size: 12px;
}

.role-meta .system {
    color: #fbbf24;
    background: rgba(245,158,11,.1);
}

.permission-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 17px;
}

.permission-toolbar p {
    color: #94a3b8;
    font-size: 13px;
    margin: 0;
}

.permission-actions {
    display: flex;
    gap: 8px;
}

.permission-actions button {
    border: 1px solid rgba(148,163,184,.2);
    background: rgba(255,255,255,.04);
    color: #cbd5e1;
    padding: 7px 12px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 12px;
}

.permission-actions button:hover {
    background: rgba(16,185,129,.13);
    border-color: #10b981;
}

.permission-group {
    border: 1px solid rgba(148,163,184,.15);
    background: rgba(2,6,23,.25);
    border-radius: 12px;
    margin-bottom: 15px;
    overflow: hidden;
}

.permission-group-header {
    padding: 13px 16px;
    background: rgba(148,163,184,.06);
    border-bottom: 1px solid rgba(148,163,184,.12);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.permission-group-header h4 {
    margin: 0;
    color: #e2e8f0;
    font-size: 14px;
    font-weight: 700;
}

.permission-group-header span {
    color: #64748b;
    font-size: 11px;
}

.permission-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    padding: 15px;
}

.permission-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    background: rgba(148,163,184,.045);
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 8px;
    cursor: pointer;
    transition: .2s;
}

.permission-item:hover {
    border-color: rgba(16,185,129,.5);
    background: rgba(16,185,129,.06);
}

.permission-item input {
    width: 16px;
    height: 16px;
    accent-color: #10b981;
    cursor: pointer;
    flex-shrink: 0;
}

.permission-item span {
    color: #cbd5e1;
    font-size: 13px;
}

.permission-item input:disabled {
    cursor: not-allowed;
}

.permission-item.locked {
    opacity: .8;
    cursor: not-allowed;
}

.role-alert {
    padding: 13px 15px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
}

.role-alert.error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5;
}

.role-alert.info {
    background: rgba(59,130,246,.1);
    border: 1px solid rgba(59,130,246,.25);
    color: #93c5fd;
}

.role-alert ul {
    margin: 7px 0 0 18px;
}

.role-form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 20px;
    border-top: 1px solid rgba(148,163,184,.15);
    flex-wrap: wrap;
}

.role-cancel-btn,
.role-save-btn {
    padding: 11px 20px;
    border-radius: 9px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.role-cancel-btn {
    background: rgba(148,163,184,.1);
    color: #cbd5e1;
}

.role-save-btn {
    background: linear-gradient(135deg,#059669,#10b981);
    color: #fff;
}

.role-save-btn:hover {
    filter: brightness(1.1);
}

@media(max-width: 850px) {
    .permission-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 550px) {
    .role-page {
        padding: 14px;
    }

    .role-form-card {
        padding: 17px;
    }

    .permission-grid {
        grid-template-columns: 1fr;
    }

    .role-heading h2 {
        font-size: 19px;
    }
}
</style>

<div class="role-page">

    <div class="role-header">

        <div class="role-heading">
            <div class="role-heading-icon">
                <i class="fas fa-user-edit"></i>
            </div>

            <div>
                <h2>Edit Role</h2>
                <p>Update role details and manage permissions</p>
            </div>
        </div>

        <a href="roles_list.php" class="role-back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Roles
        </a>

    </div>

    <div class="role-form-card">

        <?php if (!empty($errors)): ?>
            <div class="role-alert error">
                <strong>
                    <i class="fas fa-exclamation-circle"></i>
                    Please fix the following:
                </strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($is_admin_role): ?>
            <div class="role-alert info">
                <i class="fas fa-shield-alt"></i>
                Admin role is protected. Its full access permissions cannot be removed.
            </div>
        <?php endif; ?>

        <form method="POST" action="edit_role.php?id=<?= (int)$role_id ?>">

            <!-- ROLE DETAILS -->

            <div class="role-section">

                <div class="role-section-title">
                    <i class="fas fa-info-circle"></i>
                    Role Information
                </div>

                <div class="role-form-group">
                    <label for="role_name">Role Name *</label>

                    <input
                        type="text"
                        id="role_name"
                        name="role_name"
                        maxlength="100"
                        value="<?= e($role_name) ?>"
                        placeholder="Enter role name"
                        required
                    >
                </div>

                <div class="role-form-group">
                    <label for="description">Description</label>

                    <textarea
                        id="description"
                        name="description"
                        maxlength="255"
                        placeholder="Describe this role..."
                    ><?= e($description) ?></textarea>
                </div>

                <div class="role-meta">

                    <span>
                        <i class="fas fa-key"></i>
                        Key: <?= e($role['role_key']) ?>
                    </span>

                    <?php if ((int)$role['is_system'] === 1): ?>
                        <span class="system">
                            <i class="fas fa-lock"></i>
                            System Role
                        </span>
                    <?php else: ?>
                        <span>
                            <i class="fas fa-user-tag"></i>
                            Custom Role
                        </span>
                    <?php endif; ?>

                    <span>
                        <i class="fas fa-circle"></i>
                        <?= (int)$role['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                    </span>

                </div>

            </div>

            <!-- PERMISSIONS -->

            <div class="role-section">

                <div class="role-section-title">
                    <i class="fas fa-shield-alt"></i>
                    Role Permissions
                </div>

                <div class="permission-toolbar">

                    <p>
                        Select the modules this role can access.
                    </p>

                    <?php if (!$is_admin_role): ?>
                        <div class="permission-actions">
                            <button type="button" onclick="selectAllPermissions()">
                                <i class="fas fa-check-double"></i>
                                Select All
                            </button>

                            <button type="button" onclick="clearPermissions()">
                                <i class="fas fa-times"></i>
                                Clear All
                            </button>
                        </div>
                    <?php endif; ?>

                </div>

                <?php if (empty($permissions)): ?>

                    <div class="role-alert error">
                        No active permissions found. Please add permissions first.
                    </div>

                <?php else: ?>

                    <?php foreach ($permission_groups as $group_name => $group_permissions): ?>

                        <div class="permission-group">

                            <div class="permission-group-header">
                                <h4>
                                    <i class="fas fa-folder-open"></i>
                                    <?= e($group_name) ?>
                                </h4>

                                <span>
                                    <?= count($group_permissions) ?> Permissions
                                </span>
                            </div>

                            <div class="permission-grid">

                                <?php foreach ($group_permissions as $permission): ?>

                                    <?php
                                    $permission_id = (int)$permission['id'];

                                    $is_checked = in_array(
                                        $permission_id,
                                        $selected_permissions,
                                        true
                                    );

                                    $is_dashboard = (
                                        $permission['permission_key'] === 'dashboard'
                                    );

                                    $is_locked = ($is_admin_role || $is_dashboard);
                                    ?>

                                    <label class="permission-item <?= $is_locked ? 'locked' : '' ?>">

                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="<?= $permission_id ?>"
                                            <?= $is_checked ? 'checked' : '' ?>
                                            <?= $is_locked ? 'disabled' : '' ?>
                                            <?= $is_dashboard ? 'data-dashboard="1"' : '' ?>
                                        >

                                        <span>
                                            <?= e($permission['permission_name']) ?>

                                            <?php if ($is_dashboard): ?>
                                                <small style="color:#34d399;">
                                                    (Required)
                                                </small>
                                            <?php endif; ?>
                                        </span>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

                <?php if ($is_admin_role): ?>
                    <p style="color:#94a3b8;font-size:12px;margin-top:10px;">
                        Admin automatically receives all active permissions,
                        including permissions added in the future.
                    </p>
                <?php endif; ?>

            </div>

            <!-- ACTIONS -->

            <div class="role-form-footer">

                <a href="roles_list.php" class="role-cancel-btn">
                    Cancel
                </a>

                <button type="submit" class="role-save-btn">
                    <i class="fas fa-save"></i>
                    Update Role
                </button>

            </div>

        </form>

    </div>

</div>

<script>
function selectAllPermissions() {
    document.querySelectorAll(
        'input[name="permissions[]"]:not(:disabled)'
    ).forEach(function (checkbox) {
        checkbox.checked = true;
    });
}

function clearPermissions() {
    document.querySelectorAll(
        'input[name="permissions[]"]:not(:disabled)'
    ).forEach(function (checkbox) {
        checkbox.checked = false;
    });
}
</script>

<?php include '../includes/footer.php'; ?>
