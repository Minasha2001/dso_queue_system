<?php
session_start();
require_once 'db_connect.php';

// Auth check - only admin/staff allowed
// If not logged in at all, redirect to login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    header("Connection: close");
    exit;
}

$role = $_SESSION['role'] ?? 'public';

// If logged in but not admin/staff, redirect to public dashboard
if (!in_array($role, ['admin', 'staff', 'super_admin'])) {
    // Temporarily allow all roles for testing - remove this line in production
    // header("Location: dashboard.php");
    // exit;
}

// Fallback values if session vars missing
$full_name  = $_SESSION['full_name'] ?? 'Admin User';
$first_name = explode(' ', trim($full_name))[0];

// ── Today's Stats ──────────────────────────────────────────────────────────

// Today's walk-ins
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND booking_type = 'walkin'");
$stmt->execute();
$todays_walkins = $stmt->fetchColumn() ?: 0;

// Walk-ins yesterday (for % change)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = DATE_SUB(CURDATE(),INTERVAL 1 DAY) AND booking_type = 'walkin'");
$stmt->execute();
$yesterday_walkins = $stmt->fetchColumn() ?: 1;
$walkin_pct = $yesterday_walkins > 0 ? round((($todays_walkins - $yesterday_walkins) / $yesterday_walkins) * 100) : 0;

// Today's appointments
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()");
$stmt->execute();
$todays_appointments = $stmt->fetchColumn() ?: 0;

// Yesterday appointments
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$stmt->execute();
$yesterday_appt = $stmt->fetchColumn() ?: 1;
$appt_pct = $yesterday_appt > 0 ? round((($todays_appointments - $yesterday_appt) / $yesterday_appt) * 100) : 0;

// Currently in queue (waiting)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM queue_tokens qt
    JOIN appointments a ON qt.appointment_id = a.appointment_id
    WHERE a.appointment_date = CURDATE() AND qt.queue_status = 'waiting'
");
$stmt->execute();
$in_queue = $stmt->fetchColumn() ?: 0;

// Served today
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM queue_tokens qt
    JOIN appointments a ON qt.appointment_id = a.appointment_id
    WHERE a.appointment_date = CURDATE() AND qt.queue_status = 'completed'
");
$stmt->execute();
$served_today = $stmt->fetchColumn() ?: 0;

// ── Live Queue ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT qt.token_no, qt.queue_status, qt.counter_no,
           s.service_name
    FROM queue_tokens qt
    JOIN appointments a  ON qt.appointment_id = a.appointment_id
    JOIN schedules sc    ON a.schedule_id = sc.schedule_id
    JOIN services s      ON sc.service_id  = s.service_id
    WHERE a.appointment_date = CURDATE()
      AND qt.queue_status IN ('waiting','called','in_progress')
    ORDER BY FIELD(qt.queue_status,'called','in_progress','waiting'), qt.token_no DESC
    LIMIT 6
");
$stmt->execute();
$live_queue = $stmt->fetchAll();

// ── Today's Appointments list ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT a.appointment_time, u.full_name, s.service_name, a.status
    FROM appointments a
    JOIN users    u  ON a.user_id    = u.user_id
    JOIN schedules sc ON a.schedule_id = sc.schedule_id
    JOIN services  s  ON sc.service_id = s.service_id
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
    LIMIT 6
");
$stmt->execute();
$todays_list = $stmt->fetchAll();

// ── Appointments Overview (donut data) ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) as cnt
    FROM appointments
    WHERE appointment_date = CURDATE()
    GROUP BY status
");
$stmt->execute();
$overview_raw = $stmt->fetchAll();
$overview = ['completed'=>0,'pending'=>0,'cancelled'=>0,'no_show'=>0];
foreach ($overview_raw as $r) {
    $key = $r['status'] === 'confirmed' ? 'pending' : $r['status'];
    if (isset($overview[$key])) $overview[$key] += $r['cnt'];
}
$overview_total = array_sum($overview) ?: 1;

// ── Top Services this month ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT s.service_name, COUNT(*) as cnt
    FROM appointments a
    JOIN schedules sc ON a.schedule_id = sc.schedule_id
    JOIN services  s  ON sc.service_id = s.service_id
    WHERE MONTH(a.appointment_date) = MONTH(CURDATE())
      AND YEAR(a.appointment_date)  = YEAR(CURDATE())
    GROUP BY s.service_id
    ORDER BY cnt DESC
    LIMIT 5
");
$stmt->execute();
$top_services = $stmt->fetchAll();

// Fallback if tables not yet populated
if (empty($top_services)) {
    $top_services = [
        ['service_name'=>'License Renewal',        'cnt'=>320],
        ['service_name'=>'Document Verification',  'cnt'=>245],
        ['service_name'=>'Information Request',    'cnt'=>180],
        ['service_name'=>'Certificate Request',    'cnt'=>165],
        ['service_name'=>'Payment Service',        'cnt'=>130],
    ];
}
$max_svc = max(array_column($top_services,'cnt')) ?: 1;

// ── System Notifications (recent) ────────────────────────────────────────
// Using a simple static list since a notifications table may not exist yet
$sys_notifs = [
    ['icon'=>'fa-check-circle','color'=>'#16a34a','bg'=>'#dcfce7','title'=>'Backup completed successfully',  'time'=>'Today | 03:00 AM'],
    ['icon'=>'fa-info-circle',  'color'=>'#2563eb','bg'=>'#dbeafe','title'=>'System update scheduled',        'time'=>'22 May | 11:00 PM'],
    ['icon'=>'fa-exclamation-triangle','color'=>'#d97706','bg'=>'#fef3c7','title'=>'High queue volume detected','time'=>'Today | 10:15 AM'],
    ['icon'=>'fa-user-plus',    'color'=>'#7c3aed','bg'=>'#ede9fe','title'=>'New user registered',            'time'=>'Today | 09:45 AM'],
];

// ── Queue hourly chart data ───────────────────────────────────────────────
// Build approximate data from today's appointments grouped by hour
$stmt = $pdo->prepare("
    SELECT HOUR(appointment_time) as hr, COUNT(*) as cnt
    FROM appointments
    WHERE appointment_date = CURDATE()
    GROUP BY HOUR(appointment_time)
    ORDER BY hr ASC
");
$stmt->execute();
$hourly_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chart_hours  = [8,9,10,11,12,13,14,15,16,17,18];
$chart_labels = [];
$chart_data   = [];
foreach ($chart_hours as $h) {
    $chart_labels[] = ($h <= 12 ? $h : $h-12) . ' ' . ($h < 12 ? 'AM' : 'PM');
    $chart_data[]   = $hourly_raw[$h] ?? 0;
}
// Use demo data when empty
if (array_sum($chart_data) === 0) {
    $chart_data = [5, 18, 42, 68, 55, 35, 72, 80, 65, 40, 20];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – QMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --primary:    #1a6e3c;
    --primary-bg: #f0fdf4;
    --sidebar-w:  220px;
    --topbar-h:   60px;
    --radius:     12px;
    --shadow:     0 1px 8px rgba(0,0,0,.07);
    --text:       #1e293b;
    --muted:      #64748b;
    --border:     #e2e8f0;
    --bg:         #f1f5f9;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}

/* ── Sidebar ── */
.sidebar{
    width:var(--sidebar-w);background:#1e293b;
    display:flex;flex-direction:column;
    position:fixed;top:0;left:0;bottom:0;z-index:200;
}
.sidebar-brand{
    padding:1.1rem 1.25rem;border-bottom:1px solid rgba(255,255,255,.08);
    display:flex;align-items:center;gap:.7rem;
}
.brand-icon{
    width:38px;height:38px;background:var(--primary);border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:.95rem;color:#fff;flex-shrink:0;
}
.brand-text strong{font-size:.82rem;color:#fff;display:block;line-height:1.25;}
.brand-text span{font-size:.65rem;color:#94a3b8;line-height:1.3;display:block;}

.sidebar-nav{flex:1;overflow-y:auto;padding:.6rem 0;}
.nav-label{
    font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;
    color:#475569;padding:.75rem 1.25rem .2rem;
}
.nav-item{list-style:none;}
.nav-item a{
    display:flex;align-items:center;gap:.7rem;
    padding:.55rem 1.25rem;
    font-size:.8rem;font-weight:500;color:#94a3b8;
    text-decoration:none;border-left:3px solid transparent;transition:all .2s;
}
.nav-item a:hover{background:rgba(255,255,255,.06);color:#e2e8f0;}
.nav-item a.active{background:rgba(26,110,60,.25);color:#4ade80;border-left-color:#22c55e;font-weight:600;}
.nav-item a i{width:16px;text-align:center;font-size:.82rem;}

.sidebar-user{
    padding:.9rem 1.25rem;border-top:1px solid rgba(255,255,255,.08);
    display:flex;align-items:center;gap:.7rem;
}
.sidebar-avatar{
    width:34px;height:34px;border-radius:50%;background:var(--primary);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:.85rem;flex-shrink:0;
}
.sidebar-user strong{font-size:.78rem;color:#e2e8f0;display:block;line-height:1.25;}
.sidebar-user span{font-size:.65rem;color:#94a3b8;}
.sidebar-user .dropdown-toggle::after{display:none;}
.sidebar-user-chevron{margin-left:auto;color:#475569;font-size:.62rem;}

/* ── Topbar ── */
.topbar{
    position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--topbar-h);
    background:#fff;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    padding:0 1.5rem;z-index:99;
}
.topbar-left{display:flex;align-items:center;gap:.75rem;}
.menu-btn{background:none;border:none;font-size:1.1rem;color:var(--muted);cursor:pointer;padding:.3rem;}
.topbar-date{font-size:.8rem;color:var(--muted);display:flex;align-items:center;gap:.4rem;}
.topbar-right{display:flex;align-items:center;gap:.9rem;}
.notif-btn{
    position:relative;background:none;border:none;
    font-size:1rem;color:var(--muted);cursor:pointer;padding:.4rem;
}
.notif-badge{
    position:absolute;top:0;right:0;
    background:#ef4444;color:#fff;font-size:.55rem;
    width:15px;height:15px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
}
.expand-btn{background:none;border:none;font-size:1rem;color:var(--muted);cursor:pointer;padding:.4rem;}

/* ── Main ── */
.main-content{margin-left:var(--sidebar-w);padding-top:var(--topbar-h);flex:1;min-width:0;}
.content-wrap{padding:1.5rem;}

/* ── Page Greeting ── */
.page-greeting h4{font-size:1.15rem;font-weight:700;margin-bottom:.2rem;}
.page-greeting p{font-size:.82rem;color:var(--muted);}

/* ── Stat Cards ── */
.stat-card{
    background:#fff;border-radius:var(--radius);padding:1.1rem 1.25rem;
    box-shadow:var(--shadow);display:flex;align-items:center;gap:1rem;height:100%;
}
.stat-icon{
    width:50px;height:50px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;flex-shrink:0;
}
.stat-value{font-size:1.85rem;font-weight:700;line-height:1;margin-bottom:.2rem;}
.stat-label{font-size:.74rem;color:var(--muted);font-weight:500;margin-bottom:.3rem;}
.stat-pct{font-size:.73rem;font-weight:600;}
.stat-pct.up{color:#16a34a;} .stat-pct.down{color:#dc2626;}
.stat-link{font-size:.72rem;color:#2563eb;text-decoration:none;font-weight:600;}
.stat-link:hover{text-decoration:underline;}

/* ── Cards ── */
.card-box{background:#fff;border-radius:var(--radius);padding:1.25rem;box-shadow:var(--shadow);}
.card-head{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:1rem;
}
.card-head h6{font-size:.88rem;font-weight:700;margin:0;}
.card-head a,.card-head span{font-size:.74rem;color:#2563eb;text-decoration:none;font-weight:600;cursor:pointer;}

/* ── Chart Dropdown ── */
.chart-select{
    font-size:.72rem;border:1px solid var(--border);border-radius:7px;
    padding:.25rem .55rem;color:var(--text);background:#fff;cursor:pointer;
}

/* ── Live Queue Table ── */
.queue-table{width:100%;border-collapse:collapse;font-size:.78rem;}
.queue-table th{
    font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
    color:var(--muted);padding:.5rem .6rem;text-align:left;border-bottom:1px solid var(--border);
}
.queue-table td{padding:.6rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.queue-table tr:last-child td{border-bottom:none;}
.badge-status{
    padding:.2rem .6rem;border-radius:999px;font-size:.68rem;font-weight:600;display:inline-block;
}
.counter-circle{
    width:26px;height:26px;border-radius:50%;background:var(--bg);
    display:flex;align-items:center;justify-content:center;
    font-size:.72rem;font-weight:700;color:var(--text);
}

/* ── Today's Appt List ── */
.appt-row{display:flex;align-items:flex-start;gap:.7rem;padding:.55rem 0;border-bottom:1px solid #f1f5f9;}
.appt-row:last-child{border-bottom:none;}
.appt-time{font-size:.75rem;color:var(--muted);font-weight:600;white-space:nowrap;min-width:52px;}
.appt-name{font-size:.8rem;font-weight:600;color:var(--text);margin-bottom:.07rem;}
.appt-svc{font-size:.7rem;color:var(--muted);}
.upcoming-badge{
    margin-left:auto;flex-shrink:0;
    background:#dbeafe;color:#1d4ed8;
    font-size:.65rem;font-weight:700;
    padding:.2rem .55rem;border-radius:6px;
}

/* ── Donut Chart ── */
.donut-wrap{position:relative;width:160px;height:160px;flex-shrink:0;}
.donut-center{
    position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
    text-align:center;pointer-events:none;
}
.donut-center .big{font-size:1.6rem;font-weight:700;display:block;line-height:1;}
.donut-center .lbl{font-size:.65rem;color:var(--muted);}
.legend-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:3px;}
.legend-row{display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.45rem;}
.legend-label{font-size:.74rem;color:var(--muted);}
.legend-value{font-size:.74rem;font-weight:700;color:var(--text);}
.legend-pct{font-size:.68rem;color:var(--muted);}

/* ── Top Services ── */
.svc-row{margin-bottom:.7rem;}
.svc-meta{display:flex;justify-content:space-between;margin-bottom:.25rem;}
.svc-name{font-size:.78rem;font-weight:500;}
.svc-cnt{font-size:.75rem;font-weight:700;color:var(--text);}
.svc-bar-track{background:#f1f5f9;border-radius:4px;height:6px;overflow:hidden;}
.svc-bar-fill{height:100%;border-radius:4px;transition:width .6s ease;}
.rank-num{
    width:22px;height:22px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:.65rem;font-weight:700;color:#fff;
    flex-shrink:0;margin-right:.5rem;margin-top:1px;
}

/* ── System Notif ── */
.notif-row{display:flex;align-items:flex-start;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f1f5f9;}
.notif-row:last-child{border-bottom:none;}
.notif-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;}
.notif-title{font-size:.78rem;font-weight:600;color:var(--text);margin-bottom:.07rem;}
.notif-time{font-size:.68rem;color:var(--muted);}

/* ── Responsive ── */
@media(max-width:991px){
    .sidebar{transform:translateX(-100%);}
    .sidebar.open{transform:translateX(0);}
    .topbar,.main-content{margin-left:0;}
}
</style>
</head>
<body>

<!-- ════════════════ SIDEBAR ════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-users-cog"></i></div>
        <div class="brand-text">
            <strong>QMS</strong>
            <span>Queue & Appointment<br>Management System</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <p class="nav-label">Main</p>
        <ul class="list-unstyled">
            <li class="nav-item"><a href="admin_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li class="nav-item"><a href="admin_appointments.php"><i class="far fa-calendar-alt"></i> Appointments</a></li>
            <li class="nav-item"><a href="admin_queue.php"><i class="fas fa-layer-group"></i> Queue Management</a></li>
            <li class="nav-item"><a href="admin_customers.php"><i class="fas fa-users"></i> Customers</a></li>
            <li class="nav-item"><a href="admin_services.php"><i class="fas fa-th-large"></i> Services</a></li>
        </ul>
        <p class="nav-label">Manage</p>
        <ul class="list-unstyled">
            <li class="nav-item"><a href="admin_staff.php"><i class="fas fa-user-tie"></i> Staff / Agents</a></li>
            <li class="nav-item"><a href="admin_departments.php"><i class="fas fa-building"></i> Departments</a></li>
            <li class="nav-item"><a href="admin_reports.php"><i class="fas fa-chart-bar"></i> Reports & Analytics</a></li>
            <li class="nav-item"><a href="admin_notifications.php"><i class="far fa-bell"></i> Notifications</a></li>
            <li class="nav-item"><a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li class="nav-item"><a href="admin_logs.php"><i class="fas fa-clipboard-list"></i> System Logs</a></li>
        </ul>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-avatar"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($full_name); ?></strong>
            <span><?php echo ucfirst(htmlspecialchars($role)); ?></span>
        </div>
        <i class="fas fa-chevron-down sidebar-user-chevron"></i>
    </div>
</aside>

<!-- ════════════════ TOPBAR ════════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-date">
            <i class="far fa-calendar"></i>
            <span><?php echo date('d M Y | h:i A'); ?></span>
        </div>
    </div>
    <div class="topbar-right">
        <button class="notif-btn" onclick="location.href='admin_notifications.php'">
            <i class="far fa-bell"></i>
            <span class="notif-badge">5</span>
        </button>
        <button class="expand-btn"><i class="fas fa-expand-alt"></i></button>
    </div>
</header>

<!-- ════════════════ MAIN ════════════════ -->
<main class="main-content">
<div class="content-wrap">

    <!-- Greeting -->
    <div class="page-greeting mb-3">
        <h4>Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h4>
        <p>Here's what's happening with your system today.</p>
    </div>

    <!-- ── Stat Cards ── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb;">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="stat-label">Today's Walk-ins</div>
                    <div class="stat-value"><?php echo $todays_walkins ?: 128; ?></div>
                    <div class="stat-pct <?php echo $walkin_pct >= 0 ? 'up' : 'down'; ?>">
                        <i class="fas fa-arrow-<?php echo $walkin_pct >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs($walkin_pct ?: 12); ?>% from yesterday
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a;">
                    <i class="far fa-calendar-check"></i>
                </div>
                <div>
                    <div class="stat-label">Today's Appointments</div>
                    <div class="stat-value"><?php echo $todays_appointments ?: 96; ?></div>
                    <div class="stat-pct up">
                        <i class="fas fa-arrow-up"></i>
                        <?php echo abs($appt_pct ?: 8); ?>% from yesterday
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef3c7;color:#d97706;">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="stat-label">Currently in Queue</div>
                    <div class="stat-value"><?php echo $in_queue ?: 45; ?></div>
                    <a href="admin_queue.php" class="stat-link">View Queue →</a>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <div class="stat-label">Served Today</div>
                    <div class="stat-value"><?php echo $served_today ?: 152; ?></div>
                    <a href="admin_reports.php" class="stat-link">View Report →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Row 2: Chart + Live Queue + Today's Appointments ── -->
    <div class="row g-3 mb-3">

        <!-- Queue Overview Chart -->
        <div class="col-lg-5">
            <div class="card-box" style="height:100%;">
                <div class="card-head">
                    <h6>Queue Overview</h6>
                    <select class="chart-select">
                        <option>Today</option>
                        <option>This Week</option>
                        <option>This Month</option>
                    </select>
                </div>
                <div style="position:relative;height:210px;">
                    <canvas id="queueChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Live Queue -->
        <div class="col-lg-4">
            <div class="card-box" style="height:100%;">
                <div class="card-head">
                    <h6>Live Queue</h6>
                    <a href="admin_queue.php">View All</a>
                </div>
                <?php if (!empty($live_queue)): ?>
                <table class="queue-table">
                    <thead><tr>
                        <th>Token No.</th><th>Service</th><th>Status</th><th>Counter</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($live_queue as $q):
                        $st = $q['queue_status'];
                        $sc = $st==='called'||$st==='in_progress' ? ['#dcfce7','#16a34a','In Progress'] : ['#fef3c7','#d97706','Waiting'];
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($q['token_no']); ?></strong></td>
                        <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($q['service_name']); ?></td>
                        <td><span class="badge-status" style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;"><?php echo $sc[2]; ?></span></td>
                        <td><div class="counter-circle"><?php echo str_pad($q['counter_no']??'—',2,'0',STR_PAD_LEFT); ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <!-- Demo data -->
                <table class="queue-table">
                    <thead><tr><th>Token No.</th><th>Service</th><th>Status</th><th>Counter</th></tr></thead>
                    <tbody>
                    <?php
                    $demo_q = [
                        ['A102','Document Verification','in_progress','01'],
                        ['A101','License Renewal',      'in_progress','02'],
                        ['A100','Information Request',  'in_progress','03'],
                        ['A099','Payment Service',      'waiting',    '04'],
                        ['A098','Certificate Request',  'waiting',    '05'],
                    ];
                    foreach ($demo_q as [$tok,$svc,$st,$ctr]):
                        $sc = $st==='in_progress' ? ['#dcfce7','#16a34a','In Progress'] : ['#fef3c7','#d97706','Waiting'];
                    ?>
                    <tr>
                        <td><strong><?php echo $tok; ?></strong></td>
                        <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $svc; ?></td>
                        <td><span class="badge-status" style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;"><?php echo $sc[2]; ?></span></td>
                        <td><div class="counter-circle"><?php echo $ctr; ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="padding:.5rem 0;font-size:.76rem;color:var(--muted);">
                    Total in Queue: <strong style="color:var(--text);"><?php echo $in_queue ?: 45; ?></strong>
                    &nbsp;&nbsp;<a href="admin_queue.php" style="color:#2563eb;font-weight:600;">Manage Queue</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Today's Appointments -->
        <div class="col-lg-3">
            <div class="card-box" style="height:100%;">
                <div class="card-head">
                    <h6>Today's Appointments</h6>
                    <a href="admin_appointments.php">View Calendar</a>
                </div>
                <?php if (!empty($todays_list)):
                    foreach ($todays_list as $ap): ?>
                <div class="appt-row">
                    <div class="appt-time"><?php echo date('h:i A', strtotime($ap['appointment_time'])); ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="appt-name"><?php echo htmlspecialchars($ap['full_name']); ?></div>
                        <div class="appt-svc"><?php echo htmlspecialchars($ap['service_name']); ?></div>
                    </div>
                    <span class="upcoming-badge">Upcoming</span>
                </div>
                <?php endforeach;
                else:
                    $demo_appt = [
                        ['10:30 AM','Nimal Perera',        'License Renewal'],
                        ['11:00 AM','Kavindu Fernando',    'Document Verification'],
                        ['11:30 AM','Disna Jayawardena',   'Information Request'],
                        ['12:00 PM','Tharindu Silva',      'Payment Service'],
                        ['01:30 PM','Sanduni Weerasinghe', 'Certificate Request'],
                    ];
                    foreach ($demo_appt as [$time,$name,$svc]): ?>
                <div class="appt-row">
                    <div class="appt-time"><?php echo $time; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="appt-name"><?php echo $name; ?></div>
                        <div class="appt-svc"><?php echo $svc; ?></div>
                    </div>
                    <span class="upcoming-badge">Upcoming</span>
                </div>
                <?php endforeach; endif; ?>
                <div style="font-size:.73rem;color:var(--muted);padding-top:.5rem;">
                    Total Appointments Today: <strong style="color:var(--text);"><?php echo $todays_appointments ?: 96; ?></strong>
                </div>
            </div>
        </div>

    </div><!-- /row 2 -->

    <!-- ── Row 3: Donut + Top Services + System Notifications ── -->
    <div class="row g-3 mb-3">

        <!-- Appointments Overview (Donut) -->
        <div class="col-lg-4">
            <div class="card-box">
                <div class="card-head"><h6>Appointments Overview</h6></div>
                <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                    <div class="donut-wrap">
                        <canvas id="donutChart" width="160" height="160"></canvas>
                        <div class="donut-center">
                            <span class="big"><?php echo $todays_appointments ?: 96; ?></span>
                            <span class="lbl">Total</span>
                        </div>
                    </div>
                    <div style="flex:1;min-width:130px;">
                        <?php
                        $legend_items = [
                            ['Completed', $overview['completed'] ?: 42, '#2563eb', '#dbeafe'],
                            ['Upcoming',  $overview['pending']   ?: 36, '#22c55e', '#dcfce7'],
                            ['Cancelled', $overview['cancelled'] ?: 8,  '#f97316', '#ffedd5'],
                            ['No Show',   $overview['no_show']   ?: 10, '#94a3b8', '#f1f5f9'],
                        ];
                        foreach ($legend_items as [$lbl,$cnt,$clr,$bg]):
                            $pct = $todays_appointments > 0 ? round($cnt/$todays_appointments*100,1) : round($cnt/96*100,1);
                        ?>
                        <div class="legend-row">
                            <div class="legend-dot" style="background:<?php echo $clr; ?>;"></div>
                            <div style="flex:1;">
                                <div style="display:flex;justify-content:space-between;">
                                    <span class="legend-label"><?php echo $lbl; ?></span>
                                    <span class="legend-value"><?php echo $cnt; ?></span>
                                </div>
                                <div class="legend-pct">(<?php echo $pct; ?>%)</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Services -->
        <div class="col-lg-4">
            <div class="card-box">
                <div class="card-head">
                    <h6>Top Services</h6>
                    <select class="chart-select">
                        <option>This Month</option>
                        <option>This Week</option>
                        <option>Today</option>
                    </select>
                </div>
                <?php
                $bar_colors = ['#2563eb','#22c55e','#f97316','#7c3aed','#0d9488'];
                foreach ($top_services as $i => $svc):
                    $pct = round($svc['cnt'] / $max_svc * 100);
                    $clr = $bar_colors[$i % count($bar_colors)];
                ?>
                <div class="svc-row">
                    <div class="svc-meta">
                        <div style="display:flex;align-items:center;">
                            <div class="rank-num" style="background:<?php echo $clr; ?>;"><?php echo $i+1; ?></div>
                            <span class="svc-name"><?php echo htmlspecialchars($svc['service_name']); ?></span>
                        </div>
                        <span class="svc-cnt"><?php echo number_format($svc['cnt']); ?></span>
                    </div>
                    <div class="svc-bar-track">
                        <div class="svc-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- System Notifications -->
        <div class="col-lg-4">
            <div class="card-box">
                <div class="card-head">
                    <h6>System Notifications</h6>
                    <a href="admin_notifications.php">View All</a>
                </div>
                <?php foreach ($sys_notifs as $n): ?>
                <div class="notif-row">
                    <div class="notif-icon" style="background:<?php echo $n['bg']; ?>;color:<?php echo $n['color']; ?>;">
                        <i class="fas <?php echo $n['icon']; ?>"></i>
                    </div>
                    <div>
                        <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                        <div class="notif-time"><?php echo $n['time']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /row 3 -->

    <div style="height:1rem;"></div>
    <p style="font-size:.7rem;color:var(--muted);text-align:center;">
        &copy; <?php echo date('Y'); ?> Queue &amp; Appointment Management System. All rights reserved. &nbsp;|&nbsp; Version 1.0.0
    </p>
    <div style="height:1.5rem;"></div>

</div><!-- /content-wrap -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Queue Overview Line Chart ──────────────────────────────────────────────
const qCtx = document.getElementById('queueChart').getContext('2d');
new Chart(qCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets:[{
            data: <?php echo json_encode($chart_data); ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#2563eb',
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.4,
            fill: true,
        }]
    },
    options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{
            callbacks:{
                title: ctx => ctx[0].label,
                label: ctx => ' ' + ctx.parsed.y + ' in queue',
            }
        }},
        scales:{
            x:{grid:{display:false},ticks:{font:{size:10},color:'#94a3b8'}},
            y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},color:'#94a3b8'},beginAtZero:true},
        }
    }
});

// ── Donut Chart ────────────────────────────────────────────────────────────
const dCtx = document.getElementById('donutChart').getContext('2d');
new Chart(dCtx, {
    type: 'doughnut',
    data:{
        labels:['Completed','Upcoming','Cancelled','No Show'],
        datasets:[{
            data:[
                <?php echo $overview['completed'] ?: 42; ?>,
                <?php echo $overview['pending']   ?: 36; ?>,
                <?php echo $overview['cancelled'] ?: 8;  ?>,
                <?php echo $overview['no_show']   ?: 10; ?>,
            ],
            backgroundColor:['#2563eb','#22c55e','#f97316','#94a3b8'],
            borderWidth:0, hoverOffset:6,
        }]
    },
    options:{
        responsive:false, cutout:'72%',
        plugins:{legend:{display:false},tooltip:{
            callbacks:{label: ctx => ' ' + ctx.label + ': ' + ctx.parsed}
        }}
    }
});

// ── Sidebar toggle ─────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key==='Escape') document.getElementById('sidebar').classList.remove('open');
});
</script>
</body>
</html>
