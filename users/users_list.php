
<?php
// ============================================================
// FILE: users/users_list.php
// DESCRIPTION: Dynamic User Management Listing
// ============================================================

require_once '../config/config.php';
require_admin();

require_once 'user_functions.php';

$page_title = 'User Management';

// ------------------------------------------------------------
// 1. FILTERS
// ------------------------------------------------------------

$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

if (
    $role_filter !== '' &&
    (!ctype_digit($role_filter) || (int)$role_filter <= 0)
) {
    $role_filter = '';
}

if (!in_array($status_filter, ['', '1', '0'], true)) {
    $status_filter = '';
}

// ------------------------------------------------------------
// 2. FETCH DYNAMIC ROLES
// ------------------------------------------------------------

$roles = [];

$roles_sql = "
    SELECT id, role_key, role_name, is_active, is_system
    FROM roles
    ORDER BY is_system DESC, role_name ASC
";

$roles_result = $conn->query($roles_sql);

if ($roles_result) {
    while ($role = $roles_result->fetch_assoc()) {
        $roles[] = $role;
    }
}

$valid_role_ids = array_map('intval', array_column($roles, 'id'));

if (
    $role_filter !== '' &&
    !in_array((int)$role_filter, $valid_role_ids, true)
) {
    $role_filter = '';
}

// ------------------------------------------------------------
// 3. PAGINATION
// ------------------------------------------------------------

$limit = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// ------------------------------------------------------------
// 4. BUILD FILTER QUERY
// ------------------------------------------------------------

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(
        u.full_name LIKE ?
        OR u.email LIKE ?
        OR r.role_name LIKE ?
        OR r.role_key LIKE ?
    )";

    $search_value = '%' . $search . '%';

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= 'ssss';
}

if ($role_filter !== '') {
    $where[] = "u.role_id = ?";
    $params[] = (int)$role_filter;
    $types .= 'i';
}

if ($status_filter !== '') {
    $where[] = "u.is_active = ?";
    $params[] = (int)$status_filter;
    $types .= 'i';
}

$where_sql = '';

if (!empty($where)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where);
}

// ------------------------------------------------------------
// 5. FETCH STATISTICS
// ------------------------------------------------------------

$stats_sql = "
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(u.is_active = 1), 0) AS active_users,
        COALESCE(SUM(u.is_active = 0), 0) AS inactive_users,
        COALESCE(SUM(r.role_key = 'admin'), 0) AS admin_users
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
";

$stats_result = $conn->query($stats_sql);

$stats = $stats_result
    ? $stats_result->fetch_assoc()
    : [
        'total_users' => 0,
        'active_users' => 0,
        'inactive_users' => 0,
        'admin_users' => 0
    ];

// ------------------------------------------------------------
// 6. TOTAL FILTERED RECORDS
// ------------------------------------------------------------

$count_sql = "
    SELECT COUNT(*) AS total
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    $where_sql
";

$count_stmt = $conn->prepare($count_sql);

if (!$count_stmt) {
    die('Unable to prepare user count query.');
}

if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}

$count_stmt->execute();

$count_result = $count_stmt->get_result();

$total_records = (int)(
    $count_result->fetch_assoc()['total'] ?? 0
);

$count_stmt->close();

$total_pages = max(1, (int)ceil($total_records / $limit));

$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

// ------------------------------------------------------------
// 7. FETCH USERS WITH DYNAMIC ROLES
// ------------------------------------------------------------

$list_sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.role_id,
        u.permission_mode,
        u.is_active,
        u.created_at,
        r.role_key,
        r.role_name,
        r.is_active AS role_is_active,
        r.is_system
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    $where_sql
    ORDER BY u.id DESC
    LIMIT ? OFFSET ?
";

$list_stmt = $conn->prepare($list_sql);

if (!$list_stmt) {
    die('Unable to prepare user listing query.');
}

$list_params = $params;
$list_types = $types;

$list_params[] = $limit;
$list_params[] = $offset;

$list_types .= 'ii';

$list_stmt->bind_param($list_types, ...$list_params);

$list_stmt->execute();

$users = $list_stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$list_stmt->close();

// ------------------------------------------------------------
// 8. CURRENT USER
// ------------------------------------------------------------

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// ------------------------------------------------------------
// 9. HEADER
// ------------------------------------------------------------

include '../includes/header.php';
?>

<div class="space-y-4">

    <!-- PAGE HEADER -->

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">

        <div>
            <h1 class="text-xl font-bold text-white">
                User Management
            </h1>

            <p class="text-gray-400 text-xs mt-1">
                Manage users, dynamic roles and account permissions.
            </p>
        </div>

        <a
            href="add_user.php"
            class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold transition"
        >
            <i class="fas fa-user-plus"></i>
            Add New User
        </a>

    </div>


    <!-- STATISTICS -->

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">

        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="flex items-center justify-between">
                <span class="text-gray-400 text-xs">Total Users</span>
                <i class="fas fa-users text-blue-400 text-lg"></i>
            </div>

            <h2 class="text-2xl font-bold text-white mt-2">
                <?= (int)$stats['total_users'] ?>
            </h2>
        </div>

        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="flex items-center justify-between">
                <span class="text-gray-400 text-xs">Active Users</span>
                <i class="fas fa-user-check text-emerald-400 text-lg"></i>
            </div>

            <h2 class="text-2xl font-bold text-white mt-2">
                <?= (int)$stats['active_users'] ?>
            </h2>
        </div>

        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="flex items-center justify-between">
                <span class="text-gray-400 text-xs">Inactive Users</span>
                <i class="fas fa-user-slash text-red-400 text-lg"></i>
            </div>

            <h2 class="text-2xl font-bold text-white mt-2">
                <?= (int)$stats['inactive_users'] ?>
            </h2>
        </div>

        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="flex items-center justify-between">
                <span class="text-gray-400 text-xs">Admin Users</span>
                <i class="fas fa-user-shield text-purple-400 text-lg"></i>
            </div>

            <h2 class="text-2xl font-bold text-white mt-2">
                <?= (int)$stats['admin_users'] ?>
            </h2>
        </div>

    </div>


    <!-- SEARCH AND FILTERS -->

    <div class="rounded-xl border border-white/10 bg-white/5 p-4">

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">

            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-300 mb-1.5">
                    Search User
                </label>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search name, email or role..."
                    class="w-full rounded-lg bg-gray-900/70 border border-white/10 text-white text-sm px-3 py-2.5 focus:outline-none focus:border-emerald-500"
                >
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-300 mb-1.5">
                    Role
                </label>

                <select
                    name="role"
                    class="w-full rounded-lg bg-gray-900/70 border border-white/10 text-white text-sm px-3 py-2.5 focus:outline-none focus:border-emerald-500"
                >
                    <option value="">All Roles</option>

                    <?php foreach ($roles as $role): ?>
                        <option
                            value="<?= (int)$role['id'] ?>"
                            <?= $role_filter === (string)$role['id'] ? 'selected' : '' ?>
                        >
                            <?= e($role['role_name']) ?>
                            <?= (int)$role['is_active'] === 0 ? ' (Inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-300 mb-1.5">
                    Account Status
                </label>

                <select
                    name="status"
                    class="w-full rounded-lg bg-gray-900/70 border border-white/10 text-white text-sm px-3 py-2.5 focus:outline-none focus:border-emerald-500"
                >
                    <option value="">All Status</option>

                    <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                </select>
            </div>

            <div class="md:col-span-4 flex flex-wrap gap-2">

                <button
                    type="submit"
                    class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold transition"
                >
                    <i class="fas fa-search mr-1"></i>
                    Apply Filters
                </button>

                <a
                    href="users_list.php"
                    class="px-4 py-2 rounded-lg bg-white/10 hover:bg-white/15 text-white text-sm font-semibold transition"
                >
                    Reset
                </a>

            </div>

        </form>

    </div>


    <!-- USERS TABLE -->

    <div class="rounded-xl border border-white/10 bg-white/5 overflow-hidden">

        <div class="px-4 py-3 border-b border-white/10 flex flex-wrap items-center justify-between gap-2">

            <h2 class="text-base font-semibold text-white">
                All Users
            </h2>

            <span class="text-xs text-gray-400">
                <?= (int)$total_records ?> records found
            </span>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-sm text-left min-w-[950px]">

                <thead class="bg-white/5 text-gray-400 uppercase text-[11px]">

                    <tr>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Permission Mode</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-white/10">

                    <?php if (empty($users)): ?>

                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-gray-400">
                                <i class="fas fa-users text-2xl mb-2 block"></i>
                                No users found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($users as $user): ?>

                            <tr class="hover:bg-white/5 transition">

                                <!-- USER -->

                                <td class="px-4 py-3">

                                    <div class="flex items-center gap-2.5">

                                        <div class="w-9 h-9 rounded-full bg-emerald-500/15 text-emerald-400 flex items-center justify-center font-bold text-sm shrink-0">
                                            <?= e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?>
                                        </div>

                                        <div class="min-w-0">

                                            <div class="font-semibold text-white text-sm">
                                                <?= e($user['full_name']) ?>

                                                <?php if ((int)$user['id'] === $current_user_id): ?>
                                                    <span class="text-[11px] text-emerald-400 ml-1">
                                                        (You)
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="text-gray-400 text-xs mt-0.5">
                                                <?= e($user['email']) ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- DYNAMIC ROLE -->

                                <td class="px-4 py-3">

                                    <?php if (!empty($user['role_name'])): ?>

                                        <div class="flex flex-col items-start gap-1">

                                            <span class="inline-flex px-2.5 py-1 rounded-full bg-blue-500/10 text-blue-300 text-xs font-semibold">
                                                <?= e($user['role_name']) ?>
                                            </span>

                                            <?php if ((int)$user['role_is_active'] === 0): ?>
                                                <span class="text-[11px] text-red-400">
                                                    Role Inactive
                                                </span>
                                            <?php endif; ?>

                                        </div>

                                    <?php else: ?>

                                        <span class="inline-flex px-2.5 py-1 rounded-full bg-red-500/10 text-red-300 text-xs font-semibold">
                                            Role Not Assigned
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- PERMISSION MODE -->

                                <td class="px-4 py-3">

                                    <?php if (($user['permission_mode'] ?? 'role') === 'custom'): ?>

                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-purple-500/10 text-purple-300 text-xs font-semibold">
                                            <i class="fas fa-sliders-h"></i>
                                            Custom
                                        </span>

                                    <?php else: ?>

                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-cyan-500/10 text-cyan-300 text-xs font-semibold">
                                            <i class="fas fa-shield-alt"></i>
                                            Role Based
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ACCOUNT STATUS -->

                                <td class="px-4 py-3">

                                    <?php if ((int)$user['is_active'] === 1): ?>

                                        <span class="inline-flex items-center gap-1.5 text-emerald-400 text-xs font-semibold">
                                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="inline-flex items-center gap-1.5 text-red-400 text-xs font-semibold">
                                            <span class="w-2 h-2 rounded-full bg-red-400"></span>
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- CREATED DATE -->

                                <td class="px-4 py-3 text-gray-400 text-xs">
                                    <?= !empty($user['created_at']) ? e(fmt_date($user['created_at'])) : '-' ?>
                                </td>


                                <!-- ACTIONS -->

                                <td class="px-4 py-3">

                                    <div class="flex justify-end items-center gap-1.5">

                                        <a
                                            href="edit_user.php?id=<?= (int)$user['id'] ?>"
                                            class="px-2.5 py-2 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-300 transition"
                                            title="Edit User"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <?php if ((int)$user['id'] !== $current_user_id): ?>

                                            <form
                                                method="POST"
                                                action="toggle_status.php"
                                                onsubmit="return confirm('Are you sure you want to <?= (int)$user['is_active'] === 1 ? 'deactivate' : 'activate' ?> this user?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e(user_module_csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int)$user['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="px-2.5 py-2 rounded-lg <?= (int)$user['is_active'] === 1
                                                        ? 'bg-red-500/10 hover:bg-red-500/20 text-red-300'
                                                        : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300' ?> transition"
                                                    title="<?= (int)$user['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>"
                                                >
                                                    <i class="fas <?= (int)$user['is_active'] === 1 ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <?php if ($total_pages > 1): ?>

            <div class="px-4 py-3 border-t border-white/10 flex flex-wrap items-center justify-between gap-3">

                <p class="text-xs text-gray-400">
                    Page <?= (int)$page ?> of <?= (int)$total_pages ?>
                </p>

                <div class="flex gap-2">

                    <?php
                    $query_params = $_GET;

                    $query_params['page'] = max(1, $page - 1);
                    $previous_url = '?' . http_build_query($query_params);

                    $query_params['page'] = min($total_pages, $page + 1);
                    $next_url = '?' . http_build_query($query_params);
                    ?>

                    <a
                        href="<?= e($previous_url) ?>"
                        class="px-3 py-2 rounded-lg bg-white/10 text-white text-xs <?= $page <= 1 ? 'opacity-40 pointer-events-none' : 'hover:bg-white/20' ?>"
                    >
                        <i class="fas fa-chevron-left mr-1"></i>
                        Previous
                    </a>

                    <a
                        href="<?= e($next_url) ?>"
                        class="px-3 py-2 rounded-lg bg-white/10 text-white text-xs <?= $page >= $total_pages ? 'opacity-40 pointer-events-none' : 'hover:bg-white/20' ?>"
                    >
                        Next
                        <i class="fas fa-chevron-right ml-1"></i>
                    </a>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php include '../includes/footer.php'; ?>
