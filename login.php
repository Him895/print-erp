
<?php
// ============================================================
// FILE: login.php
// DESCRIPTION: Secure Login with Dynamic Role System
// ============================================================

require_once 'config/config.php';

// Already logged-in user
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {

        $error = 'Email aur password dono bharo.';

    } else {

        // Fetch user with dynamic role details
        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.password,
                u.role_id,
                u.permission_mode,
                u.is_active,

                r.role_key,
                r.role_name,
                r.is_active AS role_active

            FROM users u

            LEFT JOIN roles r
                ON r.id = u.role_id

            WHERE u.email = ?
            LIMIT 1
        ");

        if (!$stmt) {

            $error = 'Login process mein technical error hai.';

        } else {

            $stmt->bind_param('s', $email);
            $stmt->execute();

            $res = $stmt->get_result();
            $user = $res->fetch_assoc();

            $stmt->close();

            if (!$user) {

                $error = 'Email registered nahi hai.';

            } elseif ((int)$user['is_active'] !== 1) {

                $error = 'Account inactive hai. Admin se contact karo.';

            } elseif (empty($user['role_id']) || empty($user['role_key'])) {

                $error = 'Account ke saath koi valid role assigned nahi hai. Admin se contact karo.';

            } elseif ((int)$user['role_active'] !== 1) {

                $error = 'Assigned role inactive hai. Admin se contact karo.';

            } elseif (!password_verify($pass, $user['password'])) {

                $error = 'Password galat hai.';

            } else {

                // Regenerate session ID after successful authentication
                session_regenerate_id(true);

                // Store authenticated user details
                $_SESSION['user_id'] = (int)$user['id'];

                $_SESSION['full_name'] = $user['full_name'];

                $_SESSION['email'] = $user['email'];

                // Dynamic role details
                $_SESSION['role'] = $user['role_key'];

                $_SESSION['role_id'] = (int)$user['role_id'];

                $_SESSION['permission_mode'] = $user['permission_mode'];

                // Login successful
                redirect(BASE_URL . 'dashboard.php');
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login · PrintERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #05070d;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
            color: #e2e8f0;
            perspective: 1400px;
        }

        /* ═══════════════════════════════════════
           FULL PAGE BACKGROUND (card ke peeche)
           ═══════════════════════════════════════ */
        .bg-mesh {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(ellipse 60% 50% at 12% 18%, rgba(99,102,241,0.20), transparent 55%),
                radial-gradient(ellipse 50% 60% at 88% 15%, rgba(168,85,247,0.18), transparent 55%),
                radial-gradient(ellipse 70% 50% at 50% 100%, rgba(56,189,248,0.10), transparent 55%),
                radial-gradient(ellipse 40% 40% at 75% 80%, rgba(236,72,153,0.10), transparent 60%),
                linear-gradient(180deg, #05070d 0%, #080c16 60%, #05070d 100%);
        }
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image: radial-gradient(rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: radial-gradient(ellipse at center, black 15%, transparent 70%);
            -webkit-mask-image: radial-gradient(ellipse at center, black 15%, transparent 70%);
            pointer-events: none;
        }
        .bg-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            pointer-events: none;
            opacity: 0.5;
        }
        .bg-orb-1 {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(99,102,241,0.6), transparent 70%);
            top: -140px; left: -100px;
            animation: drift1 22s ease-in-out infinite;
        }
        .bg-orb-2 {
            width: 340px; height: 340px;
            background: radial-gradient(circle, rgba(168,85,247,0.55), transparent 70%);
            bottom: -120px; right: -80px;
            animation: drift2 26s ease-in-out infinite;
        }
        .bg-orb-3 {
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(56,189,248,0.4), transparent 70%);
            top: 45%; right: 5%;
            animation: drift3 20s ease-in-out infinite;
        }
        @keyframes drift1 {
            0%,100% { transform: translate(0,0) scale(1); }
            50%     { transform: translate(90px, 70px) scale(1.12); }
        }
        @keyframes drift2 {
            0%,100% { transform: translate(0,0) scale(1); }
            50%     { transform: translate(-80px, -60px) scale(1.15); }
        }
        @keyframes drift3 {
            0%,100% { transform: translate(0,0) scale(1); }
            50%     { transform: translate(-50px, 80px) scale(1.1); }
        }

        /* ═══════════════════════════════════════
           CARD STAGE (3D tilt wrapper)
           ═══════════════════════════════════════ */
        .card-stage {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 960px;
            transform-style: preserve-3d;
            transition: transform 0.15s ease-out;
        }

        /* Rotating conic gradient border */
        .card-stage::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 1.6rem;
            padding: 1px;
            background: conic-gradient(
                from var(--angle, 0deg),
                rgba(99,102,241,0.0) 0%,
                rgba(99,102,241,0.55) 15%,
                rgba(168,85,247,0.55) 30%,
                rgba(236,72,153,0.45) 45%,
                rgba(56,189,248,0.45) 60%,
                rgba(99,102,241,0.0) 80%,
                rgba(99,102,241,0.0) 100%
            );
            -webkit-mask:
                linear-gradient(#000 0 0) content-box,
                linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            animation: spinBorder 7s linear infinite;
            pointer-events: none;
            opacity: 0.9;
        }
        @property --angle {
            syntax: '<angle>';
            initial-value: 0deg;
            inherits: false;
        }
        @keyframes spinBorder {
            to { --angle: 360deg; }
        }
        @supports not (background: conic-gradient(from var(--angle), red, blue)) {
            .card-stage::before {
                background: linear-gradient(135deg, rgba(99,102,241,0.55), rgba(168,85,247,0.55), rgba(236,72,153,0.45));
                animation: none;
            }
        }

        /* ═══════════════════════════════════════
           GLASS CARD — contains split layout
           ═══════════════════════════════════════ */
        .glass-card {
            position: relative;
            width: 100%;
            background: rgba(10, 16, 28, 0.72);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border-radius: 1.5rem;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow:
                0 50px 100px -30px rgba(0,0,0,0.9),
                0 0 0 1px rgba(255,255,255,0.03) inset,
                0 1px 0 rgba(255,255,255,0.08) inset;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr;   /* mobile: form only */
            min-height: 540px;
        }
        @media (min-width: 900px) {
            .glass-card { grid-template-columns: 1.05fr 1fr; }
        }

        /* Spotlight follow */
        .glass-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(
                600px circle at var(--mx, 50%) var(--my, 50%),
                rgba(129,140,248,0.10),
                transparent 55%
            );
            opacity: 0;
            transition: opacity 0.4s;
            pointer-events: none;
            z-index: 0;
        }
        .glass-card:hover::before { opacity: 1; }

        /* ═══════════════════════════════════════
           LEFT PANEL — BRAND (inside card)
           ═══════════════════════════════════════ */
        .brand-panel {
            display: none;
            position: relative;
            padding: 2.5rem 2.4rem;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            z-index: 1;
            border-right: 1px solid rgba(255,255,255,0.06);
            background:
                radial-gradient(ellipse 100% 70% at 0% 0%, rgba(99,102,241,0.14), transparent 60%),
                radial-gradient(ellipse 90% 60% at 100% 100%, rgba(168,85,247,0.12), transparent 60%),
                rgba(8, 12, 22, 0.35);
        }
        @media (min-width: 900px) {
            .brand-panel { display: flex; }
        }

        /* Grid pattern inside left panel */
        .brand-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            mask-image: radial-gradient(ellipse at 30% 30%, black 20%, transparent 80%);
            -webkit-mask-image: radial-gradient(ellipse at 30% 30%, black 20%, transparent 80%);
            pointer-events: none;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
            animation: fadeUp 0.6s cubic-bezier(0.22,1,0.36,1) both;
        }
        .brand-logo-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px -8px rgba(99,102,241,0.6);
            position: relative;
        }
        .brand-logo-icon::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(255,255,255,0.3), transparent);
            opacity: 0.5;
        }
        .brand-logo-icon i { color: #fff; font-size: 1.1rem; position: relative; z-index: 1; }

        .brand-hero {
            max-width: 340px;
            position: relative;
            z-index: 1;
            animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.08s both;
        }
        .brand-hero .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            background: rgba(99,102,241,0.1);
            border: 1px solid rgba(99,102,241,0.25);
            color: #a5b4fc;
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 1.1rem;
        }
        .brand-hero .eyebrow .pulse-dot {
            width: 5px; height: 5px;
            border-radius: 50%;
            background: #818cf8;
            box-shadow: 0 0 0 0 rgba(129,140,248,0.7);
            animation: pulseDot 2s infinite;
        }
        @keyframes pulseDot {
            0%   { box-shadow: 0 0 0 0 rgba(129,140,248,0.7); }
            70%  { box-shadow: 0 0 0 7px rgba(129,140,248,0); }
            100% { box-shadow: 0 0 0 0 rgba(129,140,248,0); }
        }

        .brand-hero h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.9rem, 2.6vw, 2.4rem);
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -0.03em;
            color: #fff;
            margin-bottom: 0.9rem;
        }
        .brand-hero h1 .gradient-text {
            background: linear-gradient(135deg, #818cf8 0%, #c084fc 50%, #f472b6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .brand-hero p {
            font-size: 0.88rem;
            line-height: 1.6;
            color: #94a3b8;
        }

        /* Stats row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.7rem;
            margin-top: 1.5rem;
            position: relative;
            z-index: 1;
            animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.2s both;
        }
        .stat-card {
            padding: 0.75rem 0.65rem;
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.07);
            backdrop-filter: blur(8px);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(129,140,248,0.3);
            transform: translateY(-2px);
        }
        .stat-card .num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            line-height: 1;
        }
        .stat-card .lbl {
            font-size: 0.6rem;
            color: #64748b;
            margin-top: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        /* Quote */
        .brand-footer {
            position: relative;
            z-index: 1;
            animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.3s both;
        }
        .quote-box {
            padding: 1rem 1.1rem;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(255,255,255,0.04), rgba(255,255,255,0.01));
            border: 1px solid rgba(255,255,255,0.07);
            backdrop-filter: blur(8px);
            position: relative;
        }
        .quote-box::before {
            content: '"';
            position: absolute;
            top: 0.35rem; right: 0.9rem;
            font-size: 2.6rem;
            font-family: 'Space Grotesk', serif;
            color: rgba(129,140,248,0.2);
            line-height: 1;
        }
        .quote-box p {
            font-size: 0.78rem;
            color: #cbd5e1;
            line-height: 1.55;
            font-style: italic;
        }
        .quote-box .author {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-top: 0.75rem;
        }
        .quote-box .author .avatar {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 700;
            color: #fff;
        }
        .quote-box .author .name {
            font-size: 0.72rem;
            font-weight: 600;
            color: #e2e8f0;
        }
        .quote-box .author .role {
            font-size: 0.62rem;
            color: #64748b;
        }

        /* ═══════════════════════════════════════
           RIGHT PANEL — FORM (inside card)
           ═══════════════════════════════════════ */
        .form-panel {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 2rem;
            z-index: 1;
            background: rgba(6, 10, 18, 0.35);
        }
        @media (min-width: 900px) {
            .form-panel { padding: 2.5rem 2.6rem; }
        }

        .form-wrap {
            width: 100%;
            max-width: 340px;
            animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.15s both;
        }

        /* Mobile logo (sirf mobile pe) */
        .mobile-logo {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1.5rem;
            justify-content: center;
        }
        @media (min-width: 900px) { .mobile-logo { display: none; } }

        .form-header { margin-bottom: 1.5rem; }
        .form-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.55rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.02em;
            margin-bottom: 0.4rem;
        }
        .form-header p {
            font-size: 0.8rem;
            color: #64748b;
        }
        .form-header p a {
            color: #818cf8;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .form-header p a:hover { color: #a5b4fc; }

        /* Inputs */
        .field { position: relative; margin-bottom: 1rem; }
        .field-label {
            display: block;
            font-size: 0.68rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 0.45rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute;
            left: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.82rem;
            color: #475569;
            transition: color 0.25s;
            pointer-events: none;
        }
        .input-glass {
            width: 100%;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 11px;
            padding: 0.8rem 1rem 0.8rem 2.55rem;
            color: #e2e8f0;
            font-size: 0.88rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.25s ease;
            outline: none;
        }
        .input-glass::placeholder { color: #334155; }
        .input-glass:hover { border-color: rgba(255,255,255,0.14); background: rgba(255,255,255,0.04); }
        .input-glass:focus {
            background: rgba(99,102,241,0.06);
            border-color: rgba(129,140,248,0.55);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1), 0 0 24px -8px rgba(99,102,241,0.5);
        }
        .input-wrap:focus-within .icon { color: #818cf8; }

        .toggle-pass {
            position: absolute;
            right: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #475569;
            cursor: pointer;
            font-size: 0.82rem;
            background: none;
            border: none;
            padding: 0.3rem;
            transition: color 0.2s;
        }
        .toggle-pass:hover { color: #a5b4fc; }

        /* Row */
        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.3rem;
            margin-top: 0.15rem;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.74rem;
            color: #94a3b8;
            cursor: pointer;
            user-select: none;
        }
        .checkbox-label input {
            width: 14px; height: 14px;
            accent-color: #6366f1;
            cursor: pointer;
        }
        .forgot-link {
            font-size: 0.74rem;
            color: #818cf8;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: #a5b4fc; }

        /* Submit */
        .btn-submit {
            position: relative;
            width: 100%;
            padding: 0.9rem;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 55%, #a855f7 100%);
            background-size: 200% 200%;
            color: #fff;
            font-weight: 600;
            font-size: 0.86rem;
            cursor: pointer;
            overflow: hidden;
            transition: transform 0.25s cubic-bezier(0.22,1,0.36,1),
                        box-shadow 0.25s, background-position 0.4s;
            box-shadow:
                0 12px 30px -10px rgba(99,102,241,0.6),
                0 0 0 1px rgba(255,255,255,0.1) inset;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,0.35) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.7s ease;
        }
        .btn-submit:hover {
            background-position: 100% 50%;
            box-shadow:
                0 20px 40px -10px rgba(139,92,246,0.75),
                0 0 0 1px rgba(255,255,255,0.15) inset;
        }
        .btn-submit:hover::before { transform: translateX(100%); }
        .btn-submit:active { transform: scale(0.985); }
        .btn-submit span { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-submit i { font-size: 0.72rem; transition: transform 0.3s; }
        .btn-submit:hover i { transform: translateX(3px); }

        /* Divider + Trust */
        .divider {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin: 1.35rem 0 0.9rem;
            color: #334155;
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 600;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
        }
        .trust-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.9rem;
            flex-wrap: wrap;
        }
        .trust-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.63rem;
            color: #475569;
            font-weight: 500;
            transition: color 0.2s;
        }
        .trust-item:hover { color: #94a3b8; }
        .trust-item i { color: #10b981; font-size: 0.68rem; }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
            padding: 0.7rem 0.9rem;
            border-radius: 11px;
            font-size: 0.78rem;
            margin-bottom: 1rem;
            animation: shakeIn 0.45s ease;
            line-height: 1.45;
        }
        @keyframes shakeIn {
            0%   { transform: translateX(-8px); opacity: 0; }
            40%  { transform: translateX(5px); }
            70%  { transform: translateX(-3px); }
            100% { transform: translateX(0); opacity: 1; }
        }
        .alert-error {
            background: rgba(244,63,94,0.1);
            border: 1px solid rgba(244,63,94,0.25);
            color: #fda4af;
        }
        .alert-success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.25);
            color: #6ee7b7;
        }
        .alert i { margin-top: 2px; }

        /* Animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Mobile */
        @media (max-width: 899px) {
            .glass-card { min-height: auto; }
            .form-panel { padding: 2.25rem 1.5rem; }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

    <!-- Full page background (card ke peeche) -->
    <div class="bg-mesh"></div>
    <div class="bg-grid"></div>
    <div class="bg-orb bg-orb-1"></div>
    <div class="bg-orb bg-orb-2"></div>
    <div class="bg-orb bg-orb-3"></div>

    <!-- Floating Split Card (screen ke middle mein) -->
    <div class="card-stage" id="cardStage">
        <div class="glass-card" id="glassCard">

            <!-- ═══════════════════════════════════
                 LEFT: BRAND PANEL (inside card)
                 ═══════════════════════════════════ -->
            <div class="brand-panel">

                <!-- Logo -->
                <div class="brand-logo">
                    <div class="brand-logo-icon">
                        <i class="fas fa-tshirt"></i>
                    </div>
                    <div>
                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;color:#fff;letter-spacing:-0.02em;">PrintERP</div>
                        <div style="font-size:0.6rem;color:#64748b;letter-spacing:0.1em;text-transform:uppercase;">Production Suite</div>
                    </div>
                </div>

                <!-- Hero -->
                <div class="brand-hero">
                    <div class="eyebrow">
                        <span class="pulse-dot"></span>
                        v<?= SITE_VERSION ?> · Now Live
                    </div>
                    <h1>
                        Print smarter.<br>
                        <span class="gradient-text">Ship faster.</span>
                    </h1>
                    <p>
                        The all-in-one ERP for modern print shops. Manage orders, inventory,
                        and production — from one calm, powerful dashboard.
                    </p>

                    <!-- Stats -->
                    <div class="stats-row">
                        <div class="stat-card">
                            <div class="num">12k+</div>
                            <div class="lbl">Orders</div>
                        </div>
                        <div class="stat-card">
                            <div class="num">99.9%</div>
                            <div class="lbl">Uptime</div>
                        </div>
                        <div class="stat-card">
                            <div class="num">4.9★</div>
                            <div class="lbl">Rating</div>
                        </div>
                    </div>
                </div>

                <!-- Quote -->
                <div class="brand-footer">
                    <div class="quote-box">
                        <p>PrintERP cut our order processing time in half. The dashboard is the calmest part of our day.</p>
                        <div class="author">
                            <div class="avatar">R</div>
                            <div>
                                <div class="name">Rahul Mehta</div>
                                <div class="role">Owner · InkWorks Studio</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════
                 RIGHT: LOGIN FORM (inside card)
                 ═══════════════════════════════════ -->
            <div class="form-panel">
                <div class="form-wrap">

                    <!-- Mobile logo -->
                    <div class="mobile-logo">
                        <div class="brand-logo-icon" style="width:38px;height:38px;">
                            <i class="fas fa-tshirt"></i>
                        </div>
                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.05rem;font-weight:700;color:#fff;">PrintERP</div>
                    </div>

                    <!-- Header -->
                    <div class="form-header">
                        <h2>Welcome back</h2>
                        <p>Don't have an account? <a href="#">Contact admin</a></p>
                    </div>

                    <!-- Error -->
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= e($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Flash -->
                    <?php if ($f = get_flash()): ?>
                        <div class="alert <?= $f['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
                            <i class="fas fa-<?= $f['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                            <span><?= e($f['msg']) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Form -->
                    <form method="POST">

                        <div class="field">
                            <label class="field-label">Email Address</label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope icon"></i>
                                <input type="email" name="email" required class="input-glass"
                                       placeholder="you@example.com" autofocus autocomplete="email" />
                            </div>
                        </div>

                        <div class="field">
                            <label class="field-label">Password</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock icon"></i>
                                <input type="password" name="password" id="password" required
                                       class="input-glass" style="padding-right:2.5rem;"
                                       placeholder="••••••••" autocomplete="current-password" />
                                <button type="button" class="toggle-pass" onclick="togglePassword()" aria-label="Toggle password">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row-between">
                            <label class="checkbox-label">
                                <input type="checkbox" name="remember" />
                                <span>Remember me</span>
                            </label>
                            <a href="#" class="forgot-link">Forgot password?</a>
                        </div>

                        <button type="submit" class="btn-submit">
                            <span>Sign In <i class="fas fa-arrow-right"></i></span>
                        </button>
                    </form>

                    <!-- Divider -->
                    <div class="divider">Secured Access</div>

                    <!-- Trust -->
                    <div class="trust-row">
                        <div class="trust-item"><i class="fas fa-shield-alt"></i> SSL</div>
                        <div class="trust-item"><i class="fas fa-lock"></i> 2FA</div>
                        <div class="trust-item"><i class="fas fa-check-circle"></i> GDPR</div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <script>
        // ─── Password toggle ───
        function togglePassword() {
            const p = document.getElementById('password');
            const i = document.getElementById('eyeIcon');
            if (p.type === 'password') {
                p.type = 'text';
                i.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                p.type = 'password';
                i.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // ─── 3D tilt + spotlight ───
        const stage = document.getElementById('cardStage');
        const card  = document.getElementById('glassCard');
        const MAX_TILT = 4;

        if (stage && card && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            stage.addEventListener('mousemove', (e) => {
                const rect = stage.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                const rx = ((y / rect.height) - 0.5) * -MAX_TILT;
                const ry = ((x / rect.width)  - 0.5) *  MAX_TILT;
                stage.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg)`;

                card.style.setProperty('--mx', x + 'px');
                card.style.setProperty('--my', y + 'px');
            });

            stage.addEventListener('mouseleave', () => {
                stage.style.transform = 'rotateX(0) rotateY(0)';
            });
        }
    </script>
</body>
</html>