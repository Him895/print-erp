<?php
// ============================================================
// FILE: includes/header.php
// ============================================================
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
require_login();

$h_user = $_SESSION['full_name'] ?? 'User';
$h_role = $_SESSION['role'] ?? 'sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($page_title ?? 'Dashboard') ?> · <?= SITE_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(145deg, #0b1120 0%, #1a2639 100%);
            min-height: 100vh;
            margin: 0;
        }
        .glass-dark {
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(18px) saturate(190%);
            -webkit-backdrop-filter: blur(18px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.7);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 1rem;
        }
        .logout-btn {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
            transition: all 0.3s ease;
        }
        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.25);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            transform: scale(1.05);
        }
        .icon-glow {
            animation: soft-glow 3.5s infinite ease-in-out;
        }
        @keyframes soft-glow {
            0%, 100% { filter: drop-shadow(0 0 6px rgba(129,140,248,0.30)); }
            50%      { filter: drop-shadow(0 0 20px rgba(129,140,248,0.60)); }
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.4); border-radius: 20px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.6); }

        /* Sidebar link styles */
        .side-link {
            display: flex; align-items: center; gap: 0.7rem;
            padding: 0.55rem 0.9rem;
            border-radius: 0.65rem;
            color: #cbd5e1;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .side-link:hover {
            background: rgba(255,255,255,0.06);
            color: #fff;
        }
        .side-link.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(139,92,246,0.15));
            color: #fff;
            border: 1px solid rgba(99,102,241,0.3);
        }
        .side-link .side-icon {
            width: 1.9rem; height: 1.9rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<div class="flex min-h-screen">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
        <!-- ===== TOP HEADER ===== -->
        <header class="glass-dark border-b border-white/5 px-4 md:px-6 py-3 flex items-center justify-between sticky top-0 z-20">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="md:hidden text-slate-300 hover:text-white">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <div>
                    <h1 class="text-base md:text-lg font-semibold text-white leading-tight"><?= e($page_title ?? 'Dashboard') ?></h1>
                    <p class="text-[11px] text-slate-400 hidden sm:block">
                        <?= date('l, d M Y · h:i A') ?>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 md:gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-sm font-medium text-white leading-tight"><?= e($h_user) ?></div>
                    <div class="text-[10px] text-indigo-300 bg-indigo-500/15 px-2 py-0.5 rounded-full inline-block mt-0.5 uppercase tracking-wide">
                        <?= e($h_role) ?>
                    </div>
                </div>
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500/40 to-purple-500/40 flex items-center justify-center border border-white/20 text-indigo-200 text-sm font-semibold">
                    <?= strtoupper(substr($h_user, 0, 1)) ?>
                </div>
                <a href="<?= BASE_URL ?>logout.php" class="logout-btn px-3 py-1.5 rounded-lg text-xs flex items-center gap-1.5">
                    <i class="fas fa-sign-out-alt"></i> <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </header>

        <!-- ===== PAGE CONTENT ===== -->
        <main class="flex-1 p-4 md:p-6">
            <?php if ($f = get_flash()): ?>
                <div class="mb-4 rounded-xl p-3 text-sm border <?= $f['type'] === 'success' ? 'bg-emerald-500/15 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/15 border-rose-500/30 text-rose-300' ?>">
                    <i class="fas <?= $f['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                    <?= e($f['msg']) ?>
                </div>
            <?php endif; ?>