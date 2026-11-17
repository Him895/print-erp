
<?php
// ============================================================
// FILE: config/config.php
// DESCRIPTION: Database + Session + Helpers + Dynamic Roles
// ============================================================


// ---------- ERROR REPORTING ----------
error_reporting(E_ALL);
ini_set('display_errors', 1);


// ---------- TIMEZONE ----------
date_default_timezone_set('Asia/Kolkata');


// ---------- DATABASE CREDENTIALS ----------
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'print_erp');


// ---------- SITE CONSTANTS ----------
define('SITE_NAME', 'PrintERP');
define('SITE_VERSION', 'v1.0');
define('BASE_URL', 'http://localhost/print-erp/');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/designs/');
define('UPLOAD_URL', BASE_URL . 'assets/uploads/designs/');


// ---------- DATABASE CONNECTION ----------
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


// ---------- START SESSION ----------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ============================================================
// GENERAL HELPER FUNCTIONS
// ============================================================


/**
 * HTML escape
 */
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}


/**
 * Redirect
 */
function redirect($url) {
    header("Location: $url");
    exit();
}


/**
 * Get logged-in user's latest database access details.
 */
function get_current_user_access() {

    global $conn;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.role_id,
            u.permission_mode,
            u.is_active AS user_active,
            r.role_key,
            r.role_name,
            r.is_active AS role_active
        FROM users u
        LEFT JOIN roles r
            ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    $user = null;

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
    }

    $stmt->close();

    return $user;
}


/**
 * Login check.
 *
 * Also verifies:
 * 1. User exists.
 * 2. User is active.
 * 3. Assigned role exists.
 * 4. Assigned role is active.
 *
 * Role information is synchronized from database.
 */
function require_login() {

    if (empty($_SESSION['user_id'])) {
        redirect(BASE_URL . 'login.php');
    }

    $user = get_current_user_access();

    if (
        !$user ||
        (int)$user['user_active'] !== 1 ||
        $user['role_id'] === null ||
        (int)$user['role_active'] !== 1
    ) {

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        redirect(BASE_URL . 'login.php');
    }

    // Synchronize latest role from database.
    $_SESSION['role'] = $user['role_key'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['permission_mode'] = $user['permission_mode'];
}


/**
 * Role check.
 *
 * Example:
 * require_role(['admin', 'manager']);
 */
function require_role($roles = []) {

    require_login();

    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        redirect(BASE_URL . 'dashboard.php');
    }
}


/**
 * Flash message set.
 */
function set_flash($type, $msg) {

    $_SESSION['flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}


/**
 * Flash message get.
 */
function get_flash() {

    if (isset($_SESSION['flash'])) {

        $f = $_SESSION['flash'];

        unset($_SESSION['flash']);

        return $f;
    }

    return null;
}


/**
 * Auto-generate next number.
 */
function generate_no($conn, $table, $column, $prefix) {

    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';

    $stmt = $conn->prepare("
        SELECT $column
        FROM $table
        WHERE $column LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->bind_param('s', $like);
    $stmt->execute();

    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {

        $last = (int) substr(
            $row[$column],
            strrpos($row[$column], '-') + 1
        );

        $next = $last + 1;

    } else {

        $next = 1;
    }

    $stmt->close();

    return $prefix . '-' . $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}


/**
 * File upload.
 */
function upload_design($file) {

    if (empty($file['name'])) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return false;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'svg', 'ai', 'cdr'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        return false;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    $newName = 'design_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newName)) {
        return $newName;
    }

    return false;
}


/**
 * Currency format.
 */
function money($n) {
    return '₹' . number_format((float)$n, 2);
}


/**
 * Date format.
 */
function fmt_date($d) {

    if (!$d) {
        return '-';
    }

    return date('d M Y', strtotime($d));
}


// ============================================================
// DYNAMIC ROLE & PERMISSION MANAGEMENT
// ============================================================


/**
 * Check whether logged-in user is Admin.
 *
 * Admin always gets full access.
 */
function is_admin() {

    $user = get_current_user_access();

    return $user
        && (int)$user['user_active'] === 1
        && $user['role_id'] !== null
        && (int)$user['role_active'] === 1
        && $user['role_key'] === 'admin';
}


/**
 * Admin-only page protection.
 *
 * Example:
 * require_admin();
 */
function require_admin() {

    require_login();

    if (!is_admin()) {

        http_response_code(403);

        exit('
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Access Denied</title>
            </head>
            <body>
                <h2>403 - Access Denied</h2>
                <p>Only Admin can access this page.</p>
                <a href="' . BASE_URL . 'dashboard.php">
                    Go to Dashboard
                </a>
            </body>
            </html>
        ');
    }
}


/**
 * Get all permissions assigned to logged-in user.
 *
 * Admin:
 * All active permissions.
 *
 * Role mode:
 * Permissions from role_permissions.
 *
 * Custom mode:
 * Permissions from user_permissions.
 *
 * Example:
 * [
 *     "dashboard",
 *     "sales",
 *     "clients"
 * ]
 */
function get_user_permissions() {

    global $conn;

    require_login();

    static $permission_cache = null;

    if ($permission_cache !== null) {
        return $permission_cache;
    }

    $permission_cache = [];

    $user = get_current_user_access();

    if (!$user) {
        return $permission_cache;
    }


    // --------------------------------------------------------
    // ADMIN: ALL ACTIVE PERMISSIONS
    // --------------------------------------------------------

    if (is_admin()) {

        $result = $conn->query("
            SELECT permission_key
            FROM permissions
            WHERE is_active = 1
            ORDER BY sort_order ASC
        ");

        if ($result) {

            while ($row = $result->fetch_assoc()) {

                $permission_cache[] = $row['permission_key'];
            }
        }

        return $permission_cache;
    }


    $user_id = (int)$user['id'];
    $role_id = (int)$user['role_id'];


    // --------------------------------------------------------
    // ROLE-BASED PERMISSIONS
    // --------------------------------------------------------

    if ($user['permission_mode'] === 'role') {

        $stmt = $conn->prepare("
            SELECT p.permission_key
            FROM role_permissions rp
            INNER JOIN permissions p
                ON p.id = rp.permission_id
            WHERE rp.role_id = ?
              AND p.is_active = 1
            ORDER BY p.sort_order ASC
        ");

        if (!$stmt) {
            return $permission_cache;
        }

        $stmt->bind_param('i', $role_id);

    } else {


        // ----------------------------------------------------
        // INDIVIDUAL CUSTOM PERMISSIONS
        // ----------------------------------------------------

        $stmt = $conn->prepare("
            SELECT p.permission_key
            FROM user_permissions up
            INNER JOIN permissions p
                ON p.id = up.permission_id
            WHERE up.user_id = ?
              AND p.is_active = 1
            ORDER BY p.sort_order ASC
        ");

        if (!$stmt) {
            return $permission_cache;
        }

        $stmt->bind_param('i', $user_id);
    }


    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $permission_cache[] = $row['permission_key'];
    }

    $stmt->close();

    return $permission_cache;
}


/**
 * Check whether logged-in user has a permission.
 *
 * Examples:
 * has_permission('sales')
 * has_permission('reports.profit')
 */
function has_permission($permission_key) {

    require_login();

    // Admin automatically gets all permissions.
    if (is_admin()) {
        return true;
    }

    $permissions = get_user_permissions();

    return in_array($permission_key, $permissions, true);
}


/**
 * Protect individual pages using permissions.
 *
 * Examples:
 * require_permission('sales');
 * require_permission('reports.profit');
 */
function require_permission($permission_key) {

    require_login();

    if (has_permission($permission_key)) {
        return true;
    }

    http_response_code(403);

    exit('
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied - PrintERP</title>

            <style>
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #0f172a;
                    color: #fff;
                    font-family: Arial, sans-serif;
                }

                .error-box {
                    text-align: center;
                    background: #1e293b;
                    padding: 40px;
                    border-radius: 16px;
                    max-width: 400px;
                    width: 90%;
                }

                .error-box h1 {
                    font-size: 52px;
                    margin: 0 0 15px;
                    color: #f87171;
                }

                .error-box p {
                    color: #cbd5e1;
                    line-height: 1.6;
                }

                .error-box a {
                    display: inline-block;
                    margin-top: 15px;
                    padding: 12px 22px;
                    background: #2563eb;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 8px;
                }
            </style>
        </head>

        <body>

            <div class="error-box">

                <h1>403</h1>

                <h2>Access Denied</h2>

                <p>
                    You do not have permission to access this page.
                    Please contact your administrator.
                </p>

                <a href="' . BASE_URL . 'dashboard.php">
                    Back to Dashboard
                </a>

            </div>

        </body>
        </html>
    ');
}


// ---------- CONFIG COMPLETE ----------
?>
