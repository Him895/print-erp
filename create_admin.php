<?php
// ============================================================
// FILE: create_admin.php
// DESCRIPTION: One-time admin user creation (delete after use)
// ============================================================
require_once 'config/config.php';

$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $role  = $_POST['role'] ?? 'admin';

    if ($name === '' || $email === '' || strlen($pass) < 6) {
        $error = 'Saari fields bharo. Password minimum 6 characters.';
    } else {
        // Check if email exists
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'Ye email pehle se registered hai.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $name, $email, $hash, $role);
            if ($stmt->execute()) {
                $done = true;
            } else {
                $error = 'Insert failed: ' . $conn->error;
            }
            $stmt->close();
        }
        $chk->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create Admin · PrintERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(145deg, #0b1120 0%, #1a2639 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            margin: 0;
        }
        .glass-dark {
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(18px) saturate(190%);
            -webkit-backdrop-filter: blur(18px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.7);
        }
        .input-glass {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 0.75rem;
            padding: 0.7rem 1rem;
            color: #e2e8f0;
            font-size: 0.9rem;
            transition: all 0.25s ease;
            outline: none;
        }
        .input-glass:focus {
            background: rgba(255,255,255,0.08);
            border-color: rgba(99,102,241,0.5);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        .input-glass::placeholder { color: #64748b; }
        select.input-glass option { background: #1e293b; color: #e2e8f0; }
        .btn-gradient {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -10px rgba(99,102,241,0.6);
        }
    </style>
</head>
<body>
    <div class="glass-dark rounded-2xl p-8 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/40 to-purple-500/40 flex items-center justify-center mb-3 border border-white/20">
                <i class="fas fa-user-shield text-2xl text-indigo-200"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">Create Admin</h1>
            <p class="text-slate-400 text-sm mt-1">One-time setup · delete after use</p>
        </div>

        <?php if ($done): ?>
            <div class="bg-emerald-500/15 border border-emerald-500/30 rounded-xl p-4 mb-4 text-emerald-300 text-sm">
                <i class="fas fa-check-circle mr-2"></i>
                Admin created successfully! 
                <a href="login.php" class="underline font-semibold">Login now →</a>
                <br><span class="text-xs text-emerald-400/80">⚠️ Delete this file (create_admin.php) for security.</span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-rose-500/15 border border-rose-500/30 rounded-xl p-3 mb-4 text-rose-300 text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$done): ?>
        <form method="POST" class="space-y-3">
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Full Name</label>
                <input type="text" name="full_name" required class="input-glass" placeholder="Admin Name" />
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Email</label>
                <input type="email" name="email" required class="input-glass" placeholder="admin@printerp.com" />
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Password</label>
                <input type="password" name="password" required minlength="6" class="input-glass" placeholder="Min 6 characters" />
            </div>
            <div>
                <label class="text-xs text-slate-400 mb-1 block">Role</label>
                <select name="role" class="input-glass">
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="sales">Sales</option>
                    <option value="service">Service</option>
                    <option value="accounts">Accounts</option>
                </select>
            </div>
            <button type="submit" class="btn-gradient w-full py-3 rounded-xl font-semibold text-sm">
                <i class="fas fa-plus-circle mr-2"></i>Create Admin
            </button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>