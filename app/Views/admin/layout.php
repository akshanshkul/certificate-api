<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= $title ?? 'Admin Panel' ?> | Certificate System
    </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8b5cf6;
            --primary-light: #a78bfa;
            --secondary: #ec4899;
            --accent: #10b981;
            --info: #3b82f6;
            --warning: #f59e0b;
            --danger: #ef4444;

            --bg-body: #030712;
            --bg-sidebar: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);

            --text-main: #f8fafc;
            --text-dim: #94a3b8;
            --border: rgba(255, 255, 255, 0.1);

            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-body);
            background-image:
                radial-gradient(at 0% 0%, rgba(139, 92, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(236, 72, 153, 0.1) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(59, 130, 246, 0.05) 0px, transparent 50%);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            padding: 2rem 1.2rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 100;
            box-shadow: 20px 0 50px rgba(0, 0, 0, 0.5);
        }

        .logo {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 3.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            flex-grow: 1;
        }

        .nav-link {
            padding: 0.9rem 1.2rem;
            border-radius: 16px;
            color: var(--text-dim);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid transparent;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            transform: translateX(5px);
            border-color: var(--border);
        }

        .nav-link.active {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.2), transparent);
            border-left: 4px solid var(--primary);
        }

        .nav-link i {
            font-size: 1.1rem;
            background: linear-gradient(135deg, var(--text-dim), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-link.active i {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logout-btn {
            margin-top: auto;
            color: #fca5a5;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border-radius: 16px;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 3rem;
            position: relative;
        }

        .header {
            margin-bottom: 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 28px;
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            position: relative;
            overflow: hidden;
            transition: all 0.4s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            opacity: 0.6;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .stat-card .label {
            color: var(--text-dim);
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-card .value {
            font-size: 2.6rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        /* Tables & Lists */
        .card {
            background: var(--bg-card);
            border-radius: 32px;
            border: 1px solid var(--border);
            padding: 2.5rem;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.8rem;
            margin-top: 1rem;
        }

        th {
            text-align: left;
            color: var(--text-dim);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 1rem 1.5rem;
        }

        td {
            padding: 1.2rem 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            color: var(--text-dim);
            font-size: 0.95rem;
        }

        tr td:first-child {
            border-radius: 16px 0 0 16px;
        }

        tr td:last-child {
            border-radius: 0 16px 16px 0;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-main);
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .badge-primary {
            background: rgba(139, 92, 246, 0.15);
            color: var(--primary-light);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .btn {
            padding: 0.8rem 1.8rem;
            border-radius: 18px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .alert {
            padding: 1.2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideDown 0.5s ease;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        code {
            background: rgba(0, 0, 0, 0.3);
            padding: 4px 8px;
            border-radius: 8px;
            color: var(--primary-light);
            font-family: monospace;
            border: 1px solid var(--border);
        }
    </style>
</head>

<body>

    <aside class="sidebar">
        <div class="logo">
            <i class="fas fa-certificate"></i>
            AdminPanel
        </div>

        <nav class="nav-links">
            <a href="<?= base_url('admin/dashboard') ?>"
                class="nav-link <?= uri_string() == 'admin/dashboard' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="<?= base_url('admin/clients') ?>"
                class="nav-link <?= str_contains(uri_string(), 'admin/clients') ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Clients
            </a>
            <a href="<?= base_url('admin/certificates') ?>"
                class="nav-link <?= str_contains(uri_string(), 'admin/certificates') && uri_string() != 'admin/certificates/new' ? 'active' : '' ?>">
                <i class="fas fa-award"></i> Certificates
            </a>
            <a href="<?= base_url('admin/certificates/new') ?>"
                class="nav-link <?= uri_string() == 'admin/certificates/new' ? 'active' : '' ?>">
                <i class="fas fa-plus-circle"></i> Create Certificate
            </a>
            <a href="#" class="nav-link">
                <i class="fas fa-cog"></i> Settings
            </a>
        </nav>

        <a href="<?= base_url('admin/logout') ?>" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>

    <main class="main-content">
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= session()->getFlashdata('success') ?>
            </div>
        <?php endif; ?>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?= session()->getFlashdata('error') ?>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>

</body>

</html>