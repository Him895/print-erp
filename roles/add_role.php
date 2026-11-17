
<?php
// ============================================================
// FILE: roles/add_role.php
// DESCRIPTION: Create Dynamic Role + Assign Permissions
// ============================================================

require_once '../config/config.php';

require_admin();

$page_title = 'Add New Role';

// ------------------------------------------------------------
// FETCH ACTIVE PERMISSIONS
// ------------------------------------------------------------

$permission_sql = "
    SELECT
        id,
        permission_key,
        permission_name,
        permission_group,
        page_path,
        sort_order
    FROM permissions
    WHERE is_active = 1
    ORDER BY permission_group ASC, sort_order ASC, permission_name ASC
";

$permission_result = mysqli_query($conn, $permission_sql);

if (!$permission_result) {
    die('Unable to fetch permissions: ' . e(mysqli_error($conn)));
}

$permissions = [];
$permission_groups = [];

while ($row = mysqli_fetch_assoc($permission_result)) {

    $permissions[] = $row;

    $group = $row['permission_group'] ?: 'Other';

    $permission_groups[$group][] = $row;
}

// ------------------------------------------------------------
// FORM DEFAULTS
// ------------------------------------------------------------

$role_name = '';
$description = '';
$selected_permissions = [];

$errors = [];

// ------------------------------------------------------------
// GENERATE ROLE KEY
// ------------------------------------------------------------

function create_role_key($name) {

    $key = strtolower(trim($name));

    $key = preg_replace('/[^a-z0-9]+/', '_', $key);

    $key = trim($key, '_');

    return $key;
}

// ------------------------------------------------------------
// HANDLE FORM SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role_name = trim($_POST['role_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $selected_permissions = $_POST['permissions'] ?? [];

    // --------------------------------------------
    // VALIDATE ROLE NAME
    // --------------------------------------------

    if ($role_name === '') {

        $errors[] = 'Role name is required.';

    } elseif (mb_strlen($role_name) > 100) {

        $errors[] = 'Role name cannot exceed 100 characters.';

    }

    // --------------------------------------------
    // VALIDATE DESCRIPTION
    // --------------------------------------------

    if (mb_strlen($description) > 255) {

        $errors[] = 'Description cannot exceed 255 characters.';

    }

    // --------------------------------------------
    // GENERATE ROLE KEY
    // --------------------------------------------

    $role_key = create_role_key($role_name);

    if ($role_name !== '' && $role_key === '') {

        $errors[] = 'Role name must contain at least one English letter or number.';

    }

    // --------------------------------------------
    // CHECK DUPLICATE ROLE KEY
    // --------------------------------------------

    if ($role_key !== '') {

        $check_stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM roles WHERE role_key = ? LIMIT 1"
        );

        mysqli_stmt_bind_param($check_stmt, 's', $role_key);

        mysqli_stmt_execute($check_stmt);

        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $errors[] = 'This role already exists. Please use a different role name.';
        }

        mysqli_stmt_close($check_stmt);
    }

    // --------------------------------------------
    // VALIDATE SELECTED PERMISSIONS
    // --------------------------------------------

    if (!is_array($selected_permissions)) {
        $selected_permissions = [];
    }

    $selected_permissions = array_values(
        array_unique(
            array_filter(
                array_map('intval', $selected_permissions),
                function ($id) {
                    return $id > 0;
                }
            )
        )
    );

    // Dashboard permission is mandatory when active.
    $dashboard_stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM permissions
         WHERE permission_key = 'dashboard'
         AND is_active = 1
         LIMIT 1"
    );

    mysqli_stmt_execute($dashboard_stmt);

    $dashboard_result = mysqli_stmt_get_result($dashboard_stmt);

    if ($dashboard_row = mysqli_fetch_assoc($dashboard_result)) {

        $dashboard_id = (int)$dashboard_row['id'];

        if (!in_array($dashboard_id, $selected_permissions, true)) {
            $selected_permissions[] = $dashboard_id;
        }
    }

    mysqli_stmt_close($dashboard_stmt);

    // Check that every submitted permission is active and valid.
    $valid_permission_ids = [];

    if (!empty($selected_permissions)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($selected_permissions), '?')
        );

        $types = str_repeat('i', count($selected_permissions));

        $validation_sql = "
            SELECT id
            FROM permissions
            WHERE is_active = 1
            AND id IN ($placeholders)
        ";

        $validation_stmt = mysqli_prepare($conn, $validation_sql);

        mysqli_stmt_bind_param(
            $validation_stmt,
            $types,
            ...$selected_permissions
        );

        mysqli_stmt_execute($validation_stmt);

        $validation_result = mysqli_stmt_get_result($validation_stmt);

        while ($permission_row = mysqli_fetch_assoc($validation_result)) {
            $valid_permission_ids[] = (int)$permission_row['id'];
        }

        mysqli_stmt_close($validation_stmt);
    }

    if (count($valid_permission_ids) !== count($selected_permissions)) {
        $errors[] = 'One or more selected permissions are invalid or inactive.';
    }

    // --------------------------------------------
    // SAVE ROLE AND PERMISSIONS
    // --------------------------------------------

    if (empty($errors)) {

        mysqli_begin_transaction($conn);

        try {

            // Insert role
            $insert_sql = "
                INSERT INTO roles
                (
                    role_key,
                    role_name,
                    description,
                    is_system,
                    is_active
                )
                VALUES (?, ?, ?, 0, 1)
            ";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);

            mysqli_stmt_bind_param(
                $insert_stmt,
                'sss',
                $role_key,
                $role_name,
                $description
            );

            if (!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception('Unable to create role.');
            }

            $new_role_id = mysqli_insert_id($conn);

            mysqli_stmt_close($insert_stmt);

            // Insert role permissions
            if (!empty($valid_permission_ids)) {

                $permission_insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO role_permissions
                    (role_id, permission_id)
                    VALUES (?, ?)"
                );

                foreach ($valid_permission_ids as $permission_id) {

                    mysqli_stmt_bind_param(
                        $permission_insert,
                        'ii',
                        $new_role_id,
                        $permission_id
                    );

                    if (!mysqli_stmt_execute($permission_insert)) {
                        throw new Exception('Unable to assign role permissions.');
                    }
                }

                mysqli_stmt_close($permission_insert);
            }

            mysqli_commit($conn);

            set_flash(
                'success',
                'New role created successfully.'
            );

            header('Location: roles_list.php');
            exit;

        } catch (Throwable $e) {

            mysqli_rollback($conn);

            $errors[] = 'Unable to create role: ' . $e->getMessage();
        }
    }
}

include '../includes/header.php';
?>

<style>

/* ============================================================
   ADD ROLE PAGE
============================================================ */

.add-role-page {
    width: 100%;
    max-width: 1250px;
    margin: 0 auto;
    padding: 26px;
    color: #e2e8f0;
}

.role-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.role-page-title {
    display: flex;
    align-items: center;
    gap: 13px;
}

.role-page-title-icon {
    width: 49px;
    height: 49px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(99,102,241,.16);
    color: #a5b4fc;
    border: 1px solid rgba(129,140,248,.2);
    font-size: 20px;
}

.role-page-title h1 {
    margin: 0 0 5px;
    font-size: 22px;
    color: #f8fafc;
    font-weight: 700;
}

.role-page-title p {
    margin: 0;
    font-size: 12px;
    color: #94a3b8;
}

.role-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid rgba(148,163,184,.15);
    border-radius: 9px;
    color: #cbd5e1;
    background: rgba(30,41,59,.6);
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.role-back-btn:hover {
    color: #fff;
    background: rgba(51,65,85,.7);
}

/* FORM CARD */

.role-form-card {
    background: rgba(15,23,42,.82);
    border: 1px solid rgba(148,163,184,.11);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 20px;
}

.role-card-header {
    padding: 19px 22px;
    border-bottom: 1px solid rgba(148,163,184,.10);
}

.role-card-header h2 {
    font-size: 15px;
    font-weight: 700;
    color: #f8fafc;
    margin: 0 0 5px;
}

.role-card-header p {
    font-size: 11px;
    color: #94a3b8;
    margin: 0;
}

.role-card-body {
    padding: 22px;
}

/* INPUTS */

.role-form-group {
    margin-bottom: 19px;
}

.role-form-group:last-child {
    margin-bottom: 0;
}

.role-form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #cbd5e1;
    margin-bottom: 8px;
}

.role-form-group label span {
    color: #f87171;
}

.role-form-control {
    width: 100%;
    padding: 11px 13px;
    background: rgba(30,41,59,.75);
    border: 1px solid rgba(148,163,184,.17);
    border-radius: 9px;
    color: #f1f5f9;
    font-size: 13px;
    outline: none;
    transition: border .2s, box-shadow .2s;
}

.role-form-control:focus {
    border-color: #818cf8;
    box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}

.role-form-control::placeholder {
    color: #64748b;
}

textarea.role-form-control {
    resize: vertical;
    min-height: 85px;
}

.role-help-text {
    display: block;
    color: #64748b;
    font-size: 10px;
    margin-top: 6px;
}

/* ERROR */

.role-errors {
    padding: 14px 17px;
    border-radius: 10px;
    background: rgba(239,68,68,.10);
    border: 1px solid rgba(239,68,68,.25);
    margin-bottom: 20px;
}

.role-errors strong {
    display: block;
    color: #fca5a5;
    font-size: 12px;
    margin-bottom: 7px;
}

.role-errors ul {
    margin: 0;
    padding-left: 19px;
}

.role-errors li {
    color: #fecaca;
    font-size: 11px;
    margin: 4px 0;
}

/* PERMISSION SECTION */

.permission-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.permission-toolbar p {
    margin: 0;
    color: #94a3b8;
    font-size: 11px;
}

.permission-actions {
    display: flex;
    gap: 8px;
}

.permission-action-btn {
    border: 1px solid rgba(129,140,248,.22);
    background: rgba(99,102,241,.12);
    color: #a5b4fc;
    padding: 7px 11px;
    border-radius: 7px;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
}

.permission-action-btn:hover {
    background: rgba(99,102,241,.22);
    color: #fff;
}

.permission-group {
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 14px;
}

.permission-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 13px 16px;
    background: rgba(30,41,59,.65);
    border-bottom: 1px solid rgba(148,163,184,.10);
}

.permission-group-header h3 {
    font-size: 12px;
    font-weight: 700;
    color: #e2e8f0;
    margin: 0;
}

.permission-group-header span {
    font-size: 10px;
    color: #94a3b8;
}

.permission-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    padding: 15px;
}

.permission-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 12px;
    border: 1px solid rgba(148,163,184,.10);
    border-radius: 9px;
    background: rgba(15,23,42,.45);
    cursor: pointer;
    transition: all .2s;
}

.permission-item:hover {
    background: rgba(51,65,85,.35);
    border-color: rgba(129,140,248,.3);
}

.permission-item input {
    width: 15px;
    height: 15px;
    margin-top: 2px;
    accent-color: #818cf8;
    flex-shrink: 0;
}

.permission-item strong {
    display: block;
    color: #e2e8f0;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 4px;
}

.permission-item small {
    display: block;
    color: #64748b;
    font-size: 9px;
    word-break: break-word;
}

.permission-item.is-required {
    border-color: rgba(16,185,129,.25);
}

.permission-required {
    color: #6ee7b7;
    font-size: 9px;
    margin-left: 5px;
}

/* FOOTER */

.role-form-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 17px 22px;
    border-top: 1px solid rgba(148,163,184,.10);
    background: rgba(30,41,59,.28);
}

.role-cancel-btn,
.role-save-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 17px;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.role-cancel-btn {
    color: #cbd5e1;
    background: rgba(51,65,85,.55);
    border: 1px solid rgba(148,163,184,.15);
}

.role-save-btn {
    color: #fff;
    background: linear-gradient(135deg,#6366f1,#7c3aed);
    border: 0;
}

.role-save-btn:hover {
    filter: brightness(1.12);
}

@media(max-width:700px) {

    .add-role-page {
        padding: 17px 13px;
    }

    .role-card-body {
        padding: 16px;
    }

    .permission-grid {
        grid-template-columns: 1fr;
    }

    .role-form-footer {
        flex-direction: column-reverse;
    }

    .role-cancel-btn,
    .role-save-btn {
        width: 100%;
    }

}

</style>


<div class="add-role-page">

    <!-- PAGE HEADER -->

    <div class="role-page-header">

        <div class="role-page-title">

            <div class="role-page-title-icon">
                <i class="fas fa-user-plus"></i>
            </div>

            <div>
                <h1>Add New Role</h1>
                <p>Create a custom role and configure its access permissions.</p>
            </div>

        </div>

        <a href="roles_list.php" class="role-back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Roles
        </a>

    </div>


    <?php if (!empty($errors)): ?>

        <div class="role-errors">

            <strong>
                <i class="fas fa-exclamation-circle"></i>
                Please correct the following:
            </strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>

        </div>

    <?php endif; ?>


    <form method="POST" action="" id="addRoleForm">

        <!-- ROLE DETAILS -->

        <div class="role-form-card">

            <div class="role-card-header">
                <h2>
                    <i class="fas fa-info-circle" style="color:#818cf8;margin-right:7px;"></i>
                    Role Information
                </h2>
                <p>Enter the basic details of the new role.</p>
            </div>


            <div class="role-card-body">

                <div class="role-form-group">

                    <label>
                        Role Name <span>*</span>
                    </label>

                    <input
                        type="text"
                        name="role_name"
                        class="role-form-control"
                        placeholder="Example: Designer, Operator, Supervisor"
                        maxlength="100"
                        value="<?= e($role_name) ?>"
                        required
                    >

                    <small class="role-help-text">
                        A unique role key will be generated automatically from this name.
                    </small>

                </div>


                <div class="role-form-group">

                    <label>
                        Description
                    </label>

                    <textarea
                        name="description"
                        class="role-form-control"
                        maxlength="255"
                        placeholder="Describe what this role is responsible for..."
                    ><?= e($description) ?></textarea>

                    <small class="role-help-text">
                        Optional. Maximum 255 characters.
                    </small>

                </div>

            </div>

        </div>


        <!-- PERMISSIONS -->

        <div class="role-form-card">

            <div class="role-card-header">

                <h2>
                    <i class="fas fa-key" style="color:#a78bfa;margin-right:7px;"></i>
                    Assign Permissions
                </h2>

                <p>Select which modules and pages this role can access.</p>

            </div>


            <div class="role-card-body">

                <div class="permission-toolbar">

                    <p>
                        <i class="fas fa-info-circle" style="color:#818cf8;"></i>
                        Dashboard access is mandatory for every role.
                    </p>

                    <div class="permission-actions">

                        <button
                            type="button"
                            class="permission-action-btn"
                            onclick="selectAllPermissions()"
                        >
                            Select All
                        </button>

                        <button
                            type="button"
                            class="permission-action-btn"
                            onclick="clearPermissions()"
                        >
                            Clear All
                        </button>

                    </div>

                </div>


                <?php if (empty($permission_groups)): ?>

                    <div class="role-errors">
                        No active permissions found. Please add permissions first.
                    </div>

                <?php else: ?>

                    <?php foreach ($permission_groups as $group_name => $group_permissions): ?>

                        <div class="permission-group">

                            <div class="permission-group-header">

                                <h3>
                                    <i class="fas fa-folder" style="color:#818cf8;margin-right:7px;"></i>
                                    <?= e($group_name) ?>
                                </h3>

                                <span>
                                    <?= count($group_permissions) ?> Permissions
                                </span>

                            </div>


                            <div class="permission-grid">

                                <?php foreach ($group_permissions as $permission): ?>

                                    <?php
                                    $permission_id = (int)$permission['id'];

                                    $is_dashboard = (
                                        $permission['permission_key'] === 'dashboard'
                                    );

                                    $is_checked = (
                                        $is_dashboard ||
                                        in_array(
                                            $permission_id,
                                            $selected_permissions,
                                            true
                                        )
                                    );
                                    ?>

                                    <label class="permission-item <?= $is_dashboard ? 'is-required' : '' ?>">

                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="<?= $permission_id ?>"
                                            <?= $is_checked ? 'checked' : '' ?>
                                            <?= $is_dashboard ? 'disabled' : '' ?>
                                            <?= $is_dashboard ? 'data-dashboard="1"' : '' ?>
                                        >

                                        <span>

                                            <strong>
                                                <?= e($permission['permission_name']) ?>

                                                <?php if ($is_dashboard): ?>
                                                    <span class="permission-required">
                                                        REQUIRED
                                                    </span>
                                                <?php endif; ?>
                                            </strong>

                                            <small>
                                                <?= e($permission['permission_key']) ?>
                                            </small>

                                        </span>

                                    </label>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>


            <div class="role-form-footer">

                <a href="roles_list.php" class="role-cancel-btn">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>

                <button
                    type="submit"
                    class="role-save-btn"
                    <?= empty($permissions) ? 'disabled' : '' ?>
                >
                    <i class="fas fa-save"></i>
                    Create Role
                </button>

            </div>

        </div>

    </form>

</div>


<script>

// ============================================================
// PERMISSION SELECTION
// ============================================================

function selectAllPermissions() {

    document.querySelectorAll(
        '#addRoleForm input[name="permissions[]"]:not([disabled])'
    ).forEach(function (checkbox) {

        checkbox.checked = true;

    });

}


function clearPermissions() {

    document.querySelectorAll(
        '#addRoleForm input[name="permissions[]"]:not([disabled])'
    ).forEach(function (checkbox) {

        checkbox.checked = false;

    });

}

</script>


<?php include '../includes/footer.php'; ?>
