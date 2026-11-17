
<?php
// ============================================================
// FILE: roles/roles_list.php
// ROLE MANAGEMENT - LIST
// ============================================================

require_once '../config/config.php';

require_admin();

$page_title = 'Role Management';

// ------------------------------------------------------------
// FETCH ROLES
// ------------------------------------------------------------

$sql = "
    SELECT
        r.id,
        r.role_key,
        r.role_name,
        r.description,
        r.is_system,
        r.is_active,
        r.created_at,
        COUNT(DISTINCT u.id) AS total_users,
        COUNT(DISTINCT rp.permission_id) AS total_permissions
    FROM roles r
    LEFT JOIN users u
        ON u.role_id = r.id
    LEFT JOIN role_permissions rp
        ON rp.role_id = r.id
    GROUP BY
        r.id,
        r.role_key,
        r.role_name,
        r.description,
        r.is_system,
        r.is_active,
        r.created_at
    ORDER BY
        r.is_system DESC,
        r.role_name ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die('Unable to fetch roles: ' . e(mysqli_error($conn)));
}

$roles = [];

while ($row = mysqli_fetch_assoc($result)) {
    $roles[] = $row;
}

// ------------------------------------------------------------
// SUMMARY
// ------------------------------------------------------------

$total_roles = count($roles);
$active_roles = 0;
$inactive_roles = 0;
$total_assigned_users = 0;

foreach ($roles as $role) {

    if ((int)$role['is_active'] === 1) {
        $active_roles++;
    } else {
        $inactive_roles++;
    }

    $total_assigned_users += (int)$role['total_users'];
}

include '../includes/header.php';
?>

<style>

/* ============================================================
   ROLE MANAGEMENT PAGE
============================================================ */

.role-page {
    padding: 26px;
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    color: #e2e8f0;
}

/* PAGE HEADER */

.role-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 26px;
    flex-wrap: wrap;
}

.role-heading {
    display: flex;
    align-items: center;
    gap: 14px;
}

.role-heading-icon {
    width: 50px;
    height: 50px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(99,102,241,0.16);
    border: 1px solid rgba(129,140,248,0.2);
    color: #a5b4fc;
    font-size: 21px;
    flex-shrink: 0;
}

.role-heading h1 {
    font-size: 23px;
    font-weight: 700;
    color: #f8fafc;
    margin: 0 0 5px;
}

.role-heading p {
    font-size: 13px;
    color: #94a3b8;
    margin: 0;
}

/* BUTTON */

.role-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 11px 17px;
    border-radius: 11px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.role-btn-primary {
    color: #fff;
    background: linear-gradient(135deg, #6366f1, #7c3aed);
    box-shadow: 0 5px 18px rgba(99,102,241,0.18);
}

.role-btn-primary:hover {
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(99,102,241,0.28);
}

/* SUMMARY GRID */

.role-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.role-stat-card {
    position: relative;
    overflow: hidden;
    background: rgba(15,23,42,0.78);
    border: 1px solid rgba(148,163,184,0.10);
    border-radius: 15px;
    padding: 19px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.2s ease;
}

.role-stat-card:hover {
    transform: translateY(-3px);
    border-color: rgba(129,140,248,0.3);
    background: rgba(20,30,53,0.95);
}

.role-stat-icon {
    width: 47px;
    height: 47px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    flex-shrink: 0;
}

.role-stat-icon.purple {
    background: rgba(139,92,246,0.16);
    color: #c4b5fd;
}

.role-stat-icon.green {
    background: rgba(16,185,129,0.15);
    color: #6ee7b7;
}

.role-stat-icon.red {
    background: rgba(239,68,68,0.14);
    color: #fca5a5;
}

.role-stat-icon.blue {
    background: rgba(59,130,246,0.15);
    color: #93c5fd;
}

.role-stat-info h3 {
    color: #f8fafc;
    font-size: 25px;
    font-weight: 700;
    line-height: 1.2;
    margin: 0 0 5px;
}

.role-stat-info p {
    color: #94a3b8;
    font-size: 12px;
    margin: 0;
}

/* TABLE CARD */

.role-table-card {
    background: rgba(15,23,42,0.78);
    border: 1px solid rgba(148,163,184,0.10);
    border-radius: 16px;
    overflow: hidden;
}

.role-table-header {
    padding: 20px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid rgba(148,163,184,0.10);
    flex-wrap: wrap;
}

.role-table-header h2 {
    font-size: 16px;
    font-weight: 700;
    color: #f8fafc;
    margin: 0 0 5px;
}

.role-table-header p {
    color: #94a3b8;
    font-size: 12px;
    margin: 0;
}

.role-count {
    padding: 6px 11px;
    background: rgba(99,102,241,0.13);
    border: 1px solid rgba(129,140,248,0.18);
    color: #a5b4fc;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
}

/* TABLE */

.role-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.role-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.role-table thead {
    background: rgba(30,41,59,0.55);
}

.role-table th {
    padding: 14px 16px;
    text-align: left;
    color: #94a3b8;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    font-weight: 700;
    white-space: nowrap;
}

.role-table td {
    padding: 15px 16px;
    border-top: 1px solid rgba(148,163,184,0.08);
    color: #cbd5e1;
    font-size: 12px;
    vertical-align: middle;
}

.role-table tbody tr {
    transition: background 0.2s ease;
}

.role-table tbody tr:hover {
    background: rgba(51,65,85,0.22);
}

.role-number {
    color: #64748b;
    font-size: 11px;
}

.role-name {
    color: #f1f5f9;
    font-weight: 600;
    font-size: 13px;
    display: block;
    margin-bottom: 5px;
}

.role-description {
    color: #64748b;
    font-size: 11px;
    line-height: 1.5;
    display: block;
    max-width: 220px;
}

.role-key {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    background: rgba(51,65,85,0.45);
    border: 1px solid rgba(148,163,184,0.10);
    color: #a5b4fc;
    font-family: monospace;
    font-size: 11px;
}

.role-number-badge {
    display: inline-flex;
    min-width: 29px;
    height: 26px;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    background: rgba(59,130,246,0.13);
    color: #93c5fd;
    border-radius: 7px;
    font-weight: 700;
    font-size: 11px;
}

.role-type,
.role-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 7px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.role-type.system {
    background: rgba(139,92,246,0.13);
    color: #c4b5fd;
}

.role-type.custom {
    background: rgba(56,189,248,0.12);
    color: #7dd3fc;
}

.role-status.active {
    background: rgba(16,185,129,0.12);
    color: #6ee7b7;
}

.role-status.inactive {
    background: rgba(239,68,68,0.12);
    color: #fca5a5;
}

.role-status i,
.role-type i {
    font-size: 7px;
}

/* ACTION BUTTONS */

.role-action-group {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}

.role-edit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 8px 11px;
    background: rgba(99,102,241,0.13);
    border: 1px solid rgba(129,140,248,0.18);
    color: #a5b4fc;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
    white-space: nowrap;
    cursor: pointer;
}

.role-edit-btn:hover {
    background: rgba(99,102,241,0.25);
    color: #fff;
}

.role-toggle-btn {
    background: rgba(16,185,129,0.10);
    border: 1px solid rgba(16,185,129,0.2);
    color: #6ee7b7;
}

.role-toggle-btn:hover {
    background: rgba(16,185,129,0.22);
    color: #fff;
}

.role-toggle-btn.deactivate {
    background: rgba(245,158,11,0.10);
    border-color: rgba(245,158,11,0.2);
    color: #fcd34d;
}

.role-toggle-btn.deactivate:hover {
    background: rgba(245,158,11,0.22);
    color: #fff;
}

.role-delete-btn {
    background: rgba(239,68,68,0.10);
    border: 1px solid rgba(239,68,68,0.2);
    color: #fca5a5;
}

.role-delete-btn:hover {
    background: rgba(239,68,68,0.23);
    color: #fff;
}

.role-protected {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #64748b;
    font-size: 10px;
    white-space: nowrap;
}

/* EMPTY STATE */

.role-empty {
    padding: 55px 20px !important;
    text-align: center !important;
}

.role-empty i {
    display: block;
    font-size: 34px;
    color: #475569;
    margin-bottom: 13px;
}

.role-empty strong {
    display: block;
    color: #cbd5e1;
    font-size: 14px;
    margin-bottom: 6px;
}

.role-empty span {
    color: #64748b;
    font-size: 12px;
}

/* BOTTOM NOTE */

.role-footer-note {
    padding: 13px 20px;
    border-top: 1px solid rgba(148,163,184,0.08);
    color: #64748b;
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.role-footer-note i {
    color: #818cf8;
}

/* RESPONSIVE */

@media (max-width: 1100px) {

    .role-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

}

@media (max-width: 640px) {

    .role-page {
        padding: 17px 13px;
    }

    .role-header {
        align-items: flex-start;
    }

    .role-heading h1 {
        font-size: 19px;
    }

    .role-btn {
        width: 100%;
    }

    .role-stats {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .role-stat-card {
        padding: 13px 10px;
        gap: 9px;
    }

    .role-stat-icon {
        width: 37px;
        height: 37px;
        font-size: 15px;
    }

    .role-stat-info h3 {
        font-size: 20px;
    }

    .role-stat-info p {
        font-size: 10px;
    }

    .role-table-header {
        padding: 16px;
    }

}

</style>

<!-- ============================================================
     PAGE CONTENT
============================================================ -->

<div class="role-page">

    <!-- PAGE HEADER -->

    <div class="role-header">

        <div class="role-heading">

            <div class="role-heading-icon">
                <i class="fas fa-user-shield"></i>
            </div>

            <div>
                <h1>Role Management</h1>
                <p>Manage system roles and their permissions.</p>
            </div>

        </div>

        <a href="add_role.php" class="role-btn role-btn-primary">
            <i class="fas fa-plus"></i>
            Add New Role
        </a>

    </div>

    <!-- SUMMARY CARDS -->

    <div class="role-stats">

        <div class="role-stat-card">

            <div class="role-stat-icon purple">
                <i class="fas fa-user-shield"></i>
            </div>

            <div class="role-stat-info">
                <h3><?= $total_roles ?></h3>
                <p>Total Roles</p>
            </div>

        </div>

        <div class="role-stat-card">

            <div class="role-stat-icon green">
                <i class="fas fa-check-circle"></i>
            </div>

            <div class="role-stat-info">
                <h3><?= $active_roles ?></h3>
                <p>Active Roles</p>
            </div>

        </div>

        <div class="role-stat-card">

            <div class="role-stat-icon red">
                <i class="fas fa-ban"></i>
            </div>

            <div class="role-stat-info">
                <h3><?= $inactive_roles ?></h3>
                <p>Inactive Roles</p>
            </div>

        </div>

        <div class="role-stat-card">

            <div class="role-stat-icon blue">
                <i class="fas fa-users"></i>
            </div>

            <div class="role-stat-info">
                <h3><?= $total_assigned_users ?></h3>
                <p>Assigned Users</p>
            </div>

        </div>

    </div>

    <!-- ROLES TABLE -->

    <div class="role-table-card">

        <div class="role-table-header">

            <div>
                <h2>
                    <i class="fas fa-list-ul" style="color:#818cf8;margin-right:7px;"></i>
                    All Roles
                </h2>

                <p>View and manage available system roles.</p>
            </div>

            <span class="role-count">
                <?= $total_roles ?> ROLES
            </span>

        </div>

        <div class="role-table-wrapper">

            <table class="role-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Role Details</th>
                        <th>Role Key</th>
                        <th>Permissions</th>
                        <th>Users</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($roles)): ?>

                    <tr>
                        <td colspan="8" class="role-empty">

                            <i class="fas fa-user-shield"></i>

                            <strong>No Roles Found</strong>

                            <span>Create your first role to get started.</span>

                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($roles as $index => $role): ?>

                        <?php
                        $is_admin_role = ($role['role_key'] === 'admin');
                        $is_system_role = ((int)$role['is_system'] === 1);
                        $has_users = ((int)$role['total_users'] > 0);
                        ?>

                        <tr>

                            <td>
                                <span class="role-number">
                                    <?= $index + 1 ?>
                                </span>
                            </td>

                            <td>

                                <span class="role-name">
                                    <?= e($role['role_name']) ?>
                                </span>

                                <?php if (!empty($role['description'])): ?>

                                    <span class="role-description">
                                        <?= e($role['description']) ?>
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <span class="role-key">
                                    <?= e($role['role_key']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="role-number-badge">
                                    <?= (int)$role['total_permissions'] ?>
                                </span>
                            </td>

                            <td>
                                <i class="fas fa-users" style="color:#64748b;margin-right:5px;"></i>
                                <?= (int)$role['total_users'] ?>
                            </td>

                            <td>

                                <?php if ($is_system_role): ?>

                                    <span class="role-type system">
                                        <i class="fas fa-circle"></i>
                                        System
                                    </span>

                                <?php else: ?>

                                    <span class="role-type custom">
                                        <i class="fas fa-circle"></i>
                                        Custom
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ((int)$role['is_active'] === 1): ?>

                                    <span class="role-status active">
                                        <i class="fas fa-circle"></i>
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="role-status inactive">
                                        <i class="fas fa-circle"></i>
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ACTIONS -->

                            <td>

                                <div class="role-action-group">

                                    <!-- EDIT -->

                                    <a
                                        href="edit_role.php?id=<?= (int)$role['id'] ?>"
                                        class="role-edit-btn"
                                        title="Edit Role"
                                    >
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </a>

                                    <!-- ACTIVATE / DEACTIVATE -->

                                    <?php if (!$is_admin_role): ?>

                                        <form
                                            method="POST"
                                            action="toggle_status.php"
                                            style="display:inline;"
                                            onsubmit="return confirm('Are you sure you want to <?= (int)$role['is_active'] === 1 ? 'deactivate' : 'activate' ?> this role?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$role['id'] ?>"
                                            >

                                            <?php if ((int)$role['is_active'] === 1): ?>

                                                <button
                                                    type="submit"
                                                    class="role-edit-btn role-toggle-btn deactivate"
                                                    title="Deactivate Role"
                                                >
                                                    <i class="fas fa-ban"></i>
                                                    Deactivate
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="submit"
                                                    class="role-edit-btn role-toggle-btn"
                                                    title="Activate Role"
                                                >
                                                    <i class="fas fa-check-circle"></i>
                                                    Activate
                                                </button>

                                            <?php endif; ?>

                                        </form>

                                    <?php else: ?>

                                        <span class="role-protected" title="Admin role cannot be deactivated">
                                            <i class="fas fa-lock"></i>
                                            Protected
                                        </span>

                                    <?php endif; ?>

                                    <!-- DELETE -->

                                    <?php if (!$is_system_role && !$is_admin_role && !$has_users): ?>

                                        <form
                                            method="POST"
                                            action="delete_role.php"
                                            style="display:inline;"
                                            onsubmit="return confirm('WARNING: This will permanently delete this role. Are you sure?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$role['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="role-edit-btn role-delete-btn"
                                                title="Delete Role"
                                            >
                                                <i class="fas fa-trash-alt"></i>
                                                Delete
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span
                                            class="role-protected"
                                            title="<?= $has_users ? 'Users are assigned to this role' : 'System roles cannot be deleted' ?>"
                                        >
                                            <i class="fas fa-lock"></i>
                                            Protected
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="role-footer-note">

            <i class="fas fa-info-circle"></i>

            System roles and Admin are protected. Roles with assigned users cannot be deleted or deactivated.

        </div>

    </div>

</div>

<?php include '../includes/footer.php'; ?>
