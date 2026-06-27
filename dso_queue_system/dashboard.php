<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$first_name = explode(' ', trim($full_name))[0];

// ── Stats ──────────────────────────────────────────────────────────────────

// Today's total appointments
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()");
$stmt->execute();
$todays_appointments = $stmt->fetchColumn();

// Current queue token number being served today
$stmt = $pdo->prepare("
    SELECT qt.token_no 
    FROM queue_tokens qt
    JOIN appointments a ON qt.appointment_id = a.appointment_id
    WHERE a.appointment_date = CURDATE() 
    AND qt.queue_status = 'called'
    ORDER BY qt.called_time DESC
    LIMIT 1
");
$stmt->execute();
$current_queue = $stmt->fetchColumn() ?: '—';

// Active staff count (used as "counters")
$stmt = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE status = 'active'");
$stmt->execute();
$active_staff = $stmt->fetchColumn();

// Total active services
$stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE status = 'active'");
$stmt->execute();
$services_available = $stmt->fetchColumn();

// ── Available Services ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM services WHERE status = 'active' ORDER BY service_name ASC LIMIT 10");
$stmt->execute();
$services = $stmt->fetchAll();

function getServiceStyle(string $name): array {
    $map = [
        'birth certificate'       => ['fa-file-alt',      '#23703f', '#dcfce7'],
        'death certificate'       => ['fa-cross',          '#dc2626', '#fee2e2'],
        'residence certificate'   => ['fa-home',           '#d97706', '#fef3c7'],
        'nic services'            => ['fa-id-card',        '#2563eb', '#dbeafe'],
        'marriage registration'   => ['fa-heart',          '#9333ea', '#f3e8ff'],
        'business registration'   => ['fa-building',       '#ea580c', '#ffedd5'],
        'land services'           => ['fa-map-marker-alt', '#0d9488', '#ccfbf1'],
        'samurdhi services'       => ['fa-hands-helping',  '#dc2626', '#fee2e2'],
        'elderly assistance'      => ['fa-walking',        '#2563eb', '#dbeafe'],
        'disability assistance'   => ['fa-wheelchair',     '#16a34a', '#dcfce7'],
    ];
    $key = strtolower(trim($name));
    return $map[$key] ?? ['fa-concierge-bell', '#6366f1', '#ede9fe'];
}

// ── Notification badge count (last 7 days) ─────────────────────────────────
$unread_notif = 0;

// ── My Upcoming Appointments ───────────────────────────────────────────────
$my_appointments = [];
if ($role === 'public' || $role === 'citizen') {
    $stmt = $pdo->prepare("
        SELECT a.*, s.service_name,
               qt.token_no, qt.queue_status
        FROM appointments a
        JOIN services s ON a.schedule_id = (
            SELECT schedule_id FROM schedules WHERE service_id = s.service_id LIMIT 1
        )
        LEFT JOIN queue_tokens qt ON qt.appointment_id = a.appointment_id
        WHERE a.user_id = ? AND a.appointment_date >= CURDATE()
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $my_appointments = $stmt->fetchAll();

    // Simpler fallback if join fails
    if (empty($my_appointments)) {
        $stmt = $pdo->prepare("
            SELECT a.*, qt.token_no, qt.queue_status
            FROM appointments a
            LEFT JOIN queue_tokens qt ON qt.appointment_id = a.appointment_id
            WHERE a.user_id = ? AND a.appointment_date >= CURDATE()
            ORDER BY a.appointment_date ASC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $my_appointments = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Divisional Secretariat Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:       #1a6e3c;
            --primary-light: #22c55e;
            --primary-bg:    #f0fdf4;
            --sidebar-w:     260px;
            --topbar-h:      70px;
            --radius:        14px;
            --shadow:        0 2px 12px rgba(0,0,0,.07);
            --text:          #1e293b;
            --muted:         #64748b;
            --border:        #e2e8f0;
            --bg:            #f8fafc;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-w);
            background: #fff;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
        }
        .sidebar-brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .sidebar-brand img { width: 62px; height: 62px; object-fit: contain; }
        .sidebar-brand strong { font-size: .85rem; color: var(--text); display: block; line-height: 1.3; }
        .sidebar-brand span  { font-size: .7rem; color: var(--muted); }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: .75rem 0; }
        .nav-label {
            font-size: .65rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .08em; color: var(--muted);
            padding: .75rem 1.5rem .25rem;
        }
        .nav-item { list-style: none; }
        .nav-item a {
            display: flex; align-items: center; gap: .75rem;
            padding: .65rem 1.5rem;
            font-size: .875rem; font-weight: 500; color: var(--muted);
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all .2s;
        }
        .nav-item a:hover { background: var(--primary-bg); color: var(--primary); }
        .nav-item a.active {
            background: var(--primary-bg); color: var(--primary);
            border-left-color: var(--primary); font-weight: 600;
        }
        .nav-item a i { width: 18px; text-align: center; font-size: .9rem; }

        .sidebar-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); }

        /* Topbar */
        .topbar {
            position: fixed; top: 0;
            left: var(--sidebar-w); right: 0;
            height: var(--topbar-h);
            background: #fff; border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 0 2rem; z-index: 99;
        }
        .page-title { font-size: 1rem; font-weight: 700; }
        .breadcrumb { font-size: .75rem; color: var(--muted); margin: 0; background: none; padding: 0; }
        .breadcrumb-item.active { color: var(--muted); }

        .topbar-right { display: flex; align-items: center; gap: 1rem; }
        .notif-btn {
            position: relative; background: none; border: none;
            font-size: 1.1rem; color: var(--muted); cursor: pointer; padding: .4rem;
        }
        .notif-badge {
            position: absolute; top: 0; right: 0;
            background: #ef4444; color: #fff;
            font-size: .6rem; width: 16px; height: 16px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
        }
        .user-pill {
            display: flex; align-items: center; gap: .6rem;
            padding: .35rem .75rem; border-radius: 999px;
            border: 1px solid var(--border); cursor: pointer;
            transition: background .2s; text-decoration: none;
        }
        .user-pill:hover { background: var(--bg); }
        .user-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .85rem; flex-shrink: 0;
        }
        .user-pill strong { font-size: .82rem; display: block; line-height: 1.2; color: var(--text); }
        .user-pill span   { font-size: .72rem; color: var(--muted); }

        /* Main */
        .main-content { margin-left: var(--sidebar-w); padding-top: var(--topbar-h); flex: 1; min-width: 0; }
        .content-wrap { padding: 1.75rem 2rem; }

        /* Hero */
        .hero-banner {
    background: url('image/home.png') top/100% no-repeat;
    border-radius: var(--radius);
    padding: 2rem 2.5rem;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.75rem;
    overflow: hidden;
    position: relative;
}
.hero-text-box {
    display: inline-block;
    background: rgba(255,255,255,0.92);
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.25rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.12);
}
.hero-text-box h2 {
    font-size: 1.7rem;
    font-weight: 700;
    margin-bottom: .3rem;
    color: #022202; 
}
.hero-text-box p {
    font-size: .9rem;
    color: #232725;
    margin-bottom: 0;
}
.hero-actions {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
}
.btn-hero-primary {
    background: var(--primary);
    color: #fff !important;
    border: none;
    border-radius: 10px;
    padding: .6rem 1.25rem;
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    transition: transform .2s, box-shadow .2s;
}
.btn-hero-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,.2);
    background: #15582f;
    color: #fff !important;
}
.btn-hero-outline {
    background: #fff;
    color: #000 !important;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: .6rem 1.25rem;
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    transition: background .2s;
}
.btn-hero-outline:hover {
    background: #f5f5f5;
    color: #000 !important;
}
/* Check Queue Status button — white background */
.btn-hero-outline {
    background: #fff;
    color: var(--primary);
    border: 1px solid #fff;
    border-radius: 10px;
    padding: .6rem 1.25rem;
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    transition: background .2s;
}
.btn-hero-outline:hover {
    background: #f0fdf4;
    color: var(--primary);
}
        
        .btn-hero-outline:hover { background: rgba(255,255,255,.25); color: #fff; }
        .hero-img { position: relative; z-index: 1; }
        .hero-img img { height: 150px; border-radius: 10px; object-fit: cover; opacity: .85; }
        .hero-badge {
            position: absolute; bottom: -8px; right: -8px;
            background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
            color: #fff; border-radius: 10px; padding: .5rem .75rem; font-size: .72rem;
        }
        .hero-badge strong { display: block; font-size: .8rem; }

        /* Stat Cards */
        .stat-card {
            background: #fff; border-radius: var(--radius);
            padding: 1.25rem 1.5rem; box-shadow: var(--shadow);
            display: flex; align-items: center; gap: 1rem; height: 100%;
        }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1; margin-bottom: .2rem; }
        .stat-label { font-size: .78rem; color: var(--muted); font-weight: 500; margin-bottom: .35rem; }
        .stat-link  { font-size: .75rem; color: var(--primary); text-decoration: none; font-weight: 600; }
        .stat-link:hover { text-decoration: underline; }

        /* Section Head */
        .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.1rem; }
        .section-head h5 { font-size: 1rem; font-weight: 700; }
        .section-head a  { font-size: .8rem; color: var(--primary); text-decoration: none; font-weight: 600; }

        /* Service Grid */
        /* Service Grid */
.service-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);   /* peliyak boxes 5k fixed */
    gap: 1rem;
}
.service-card {
    background: #fff; border-radius: var(--radius);
    padding: 1.1rem 1rem; text-align: center;
    box-shadow: var(--shadow); transition: transform .2s, box-shadow .2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;                /* card heka okkoma same height */
}
.service-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.service-icon {
    width: 52px; height: 52px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin: 0 auto .75rem;
    flex-shrink: 0;
}
.service-card h6 {
    font-size: .82rem; font-weight: 600; color: var(--text);
    margin-bottom: .6rem;
    display: flex;
    align-items: center;
    flex-grow: 1;                /* name eke line ganan wenas unath button eka same level ekema yanawa */
}

        /* Book Now button — filled solid, same width across all cards */
        .btn-book {
            display: block;
            width: 90%;
            margin: 0 auto;
            padding: .38rem 0;
            border-radius: 8px;
            font-size: .75rem;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            border: none;
            color: #fff !important;
            transition: opacity .2s, transform .15s;
            cursor: pointer;
        }
        .btn-book:hover {
            opacity: .85;
            transform: translateY(-1px);
            color: #fff !important;
        }

        /* Card Box */
        .card-box {
            background: #fff; border-radius: var(--radius);
            padding: 1.25rem 1.5rem; box-shadow: var(--shadow);
        }

        /* Appointments Table */
        .appt-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
        .appt-table th {
            background: var(--bg); color: var(--muted); font-weight: 600;
            font-size: .74rem; text-transform: uppercase; letter-spacing: .04em;
            padding: .6rem .9rem; text-align: left;
        }
        .appt-table td { padding: .75rem .9rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .appt-table tr:last-child td { border-bottom: none; }
        .badge-pill {
            padding: .25rem .7rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600; display: inline-block;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .topbar, .main-content { left: 0; margin-left: 0; }
            .topbar { left: 0; }
            .bottom-grid { grid-template-columns: 1fr; }
            .hero-img { display: none; }
            .service-grid { grid-template-columns: repeat(3, 1fr); }   
        }
        @media (max-width: 575px) {
            .content-wrap { padding: 1rem; }
            .service-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="image/emblem.png" alt="Emblem"
             onerror="this.style.display='none'">
        <div>
            <strong>Divisional Secretariat</strong>
            <span>Queue & Appointment Management System</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <p class="nav-label">Main Menu</p>
        <ul class="list-unstyled">
            <li class="nav-item">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-home"></i> Home
                </a>
            </li>
            <li class="nav-item">
                <a href="book_appointment.php">
                    <i class="far fa-calendar-plus"></i> Book Appointment
                </a>
            </li>
            <li class="nav-item">
                <a href="my_appointments.php">
                    <i class="far fa-calendar-check"></i> My Appointments
                </a>
            </li>
            <li class="nav-item">
                <a href="queue_status.php">
                    <i class="fas fa-users"></i> Queue Status
                </a>
            </li>
            <li class="nav-item">
                <a href="services.php">
                    <i class="fas fa-th-large"></i> Services
                </a>
            </li>
            <li class="nav-item">
                <a href="announcements.php">
                    <i class="far fa-bell"></i> Announcements
                </a>
            </li>
        </ul>

        <p class="nav-label">Account</p>
        <ul class="list-unstyled">
            <li class="nav-item">
                <a href="my_profile.php">
                    <i class="far fa-user-circle"></i> My Profile
                </a>
            </li>
            <li class="nav-item">
                <a href="help.php">
                    <i class="far fa-question-circle"></i> Help & Support
                </a>
            </li>
            <li class="nav-item">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <p style="font-size:.72rem;color:var(--muted);text-align:center;margin:0;">
            &copy; <?php echo date('Y'); ?> Divisional Secretariat Office
        </p>
    </div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
    <div></div>
    <div class="topbar-right">
        <!-- Notifications -->
        <button class="notif-btn" title="Notifications" onclick="location.href='notifications.php'">
            <i class="far fa-bell"></i>
            <?php if ($unread_notif > 0): ?>
            <span class="notif-badge"><?php echo $unread_notif; ?></span>
            <?php endif; ?>
        </button>
        <!-- User -->
        <a href="my_profile.php" class="user-pill">
            <div class="user-avatar">
                <?php echo strtoupper(substr($full_name, 0, 1)); ?>
            </div>
            <div>
                <strong><?php echo htmlspecialchars($full_name); ?></strong>
                <span><?php echo ucfirst(htmlspecialchars($role)); ?></span>
            </div>
            <i class="fas fa-chevron-down" style="font-size:.65rem;color:var(--muted);margin-left:.25rem;"></i>
        </a>
    </div>
</header>

<!-- MAIN CONTENT -->
<main class="main-content">
<div class="content-wrap">

    <!-- Hero Banner -->
    <div class="hero-banner">
        <div class="hero-text">
            <div class="hero-text-box">
                <h2>Welcome, <?php echo htmlspecialchars($first_name); ?>! 👋</h2>
                <p>Book appointments, check queue status<br>and access government services easily.</p>
            </div>
            <div class="hero-actions">
                <a href="book_appointment.php" class="btn-hero-primary">
                    <i class="far fa-calendar-plus"></i> Book an Appointment
                </a>
                <a href="queue_status.php" class="btn-hero-outline">
                    <i class="fas fa-users"></i> Check Queue Status
                </a>
            </div>
        </div>
        <div class="hero-img">
            <img src="image/office.jpg" alt="Divisional Secretariat Office"
                 onerror="this.parentElement.style.display='none'">
            <div class="hero-badge">
                <i class="fas fa-landmark"></i>
                <strong>Divisional Secretariat Office</strong>
                Your Partner in Public Service
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a;">
                    <i class="far fa-calendar-alt"></i>
                </div>
                <div>
                    <div class="stat-label">Today's Appointments</div>
                    <div class="stat-value"><?php echo $todays_appointments; ?></div>
                    <a href="my_appointments.php" class="stat-link">View Details →</a>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb;">
                    <i class="fas fa-hashtag"></i>
                </div>
                <div>
                    <div class="stat-label">Current Queue Token</div>
                    <div class="stat-value"><?php echo $current_queue; ?></div>
                    <a href="queue_status.php" class="stat-link">View Live Queue →</a>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef3c7;color:#d97706;">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <div class="stat-label">Active Staff</div>
                    <div class="stat-value"><?php echo $active_staff; ?></div>
                    <a href="queue_status.php" class="stat-link">View Counters →</a>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;">
                    <i class="fas fa-list-alt"></i>
                </div>
                <div>
                    <div class="stat-label">Services Available</div>
                    <div class="stat-value"><?php echo $services_available; ?></div>
                    <a href="services.php" class="stat-link">View All Services →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Available Services -->
    <div class="section-head">
        <h5><i class="fas fa-th-large me-2" style="color:var(--primary);"></i>Available Services</h5>
        
    </div>
    <div class="service-grid mb-4">
        <?php if (!empty($services)):
            foreach ($services as $svc):
                [$icon, $colour, $bg] = getServiceStyle($svc['service_name']); ?>
        <div class="service-card">
            <div class="service-icon" style="background:<?php echo $bg; ?>;color:<?php echo $colour; ?>;">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <h6><?php echo htmlspecialchars($svc['service_name']); ?></h6>
            <a href="book_appointment.php?service_id=<?php echo $svc['service_id']; ?>"
               class="btn-book"
               style="background:<?php echo $colour; ?>;">
                Book Now
            </a>
        </div>
        <?php endforeach;
        else:
            $static_services = [
                ['Birth Certificate',     'fa-file-alt',       '#c93838','#f0f0f0'],
                ['Death Certificate',     'fa-cross',           '#99970a','#f0f0f0'],
                ['Residence Certificate', 'fa-home',            '#3a348f','#f0f0f0'],
                ['NIC Services',          'fa-id-card',         '#06633f','#f0f0f0'],
                ['Marriage Registration', 'fa-heart',           '#b83209','#f0f0f0'],
                ['Business Registration', 'fa-building',        '#643f2b','#f0f0f0'],
                ['Land Services',         'fa-map-marker-alt',  '#15504b','#f0f0f0'],
                ['Samurdhi Services',     'fa-hands-helping',   '#811c1c','#f0f0f0'],
                ['Elderly Assistance',    'fa-walking',         '#1f386d','#f0f0f0'],
                ['Disability Assistance', 'fa-wheelchair',      '#5f083b','#f0f0f0'],
            ];
            foreach ($static_services as [$name,$icon,$colour,$bg]): ?>
        <div class="service-card">
            <div class="service-icon" style="background:<?php echo $bg; ?>;color:<?php echo $colour; ?>;">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <h6><?php echo $name; ?></h6>
            <a href="book_appointment.php" class="btn-book"
               style="background:<?php echo $colour; ?>;">
                Book Now
            </a>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- My Upcoming Appointments -->
    <?php if (!empty($my_appointments)): ?>
    <div class="card-box mb-4">
        <div class="section-head" style="margin-bottom:.9rem;">
            <h5><i class="far fa-calendar-check me-2" style="color:var(--primary);"></i>My Upcoming Appointments</h5>
            <a href="my_appointments.php">View All →</a>
        </div>
        <table class="appt-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Token No</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_appointments as $i => $appt):
                    $status_styles = [
                        'pending'   => ['#d97706','#fef3c7'],
                        'confirmed' => ['#16a34a','#dcfce7'],
                        'cancelled' => ['#dc2626','#fee2e2'],
                        'completed' => ['#6366f1','#ede9fe'],
                    ];
                    [$sc,$sb] = $status_styles[$appt['status']] ?? ['#64748b','#f1f5f9'];
                ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo date('d M Y', strtotime($appt['appointment_date'])); ?></td>
                    <td><?php echo date('h:i A', strtotime($appt['appointment_time'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($appt['booking_type'] ?? 'Walk-in')); ?></td>
                    <td>
                        <?php if (!empty($appt['token_no'])): ?>
                            <span class="badge-pill"
                                  style="background:#dbeafe;color:#1d4ed8;font-weight:700;">
                                <?php echo htmlspecialchars($appt['token_no']); ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge-pill"
                              style="background:<?php echo $sb; ?>;color:<?php echo $sc; ?>;">
                            <?php echo ucfirst($appt['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- bottom spacing -->
    <div style="height:1.5rem;"></div>

</div><!-- end content-wrap -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') sidebar.classList.remove('open');
    });
</script>
</body>
</html>
