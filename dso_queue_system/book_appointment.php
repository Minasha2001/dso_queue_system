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

// Load services
$stmt = $pdo->prepare("SELECT * FROM services WHERE status = 'active' ORDER BY service_name ASC");
$stmt->execute();
$services = $stmt->fetchAll();

// Pre-selected service
$selected_service_id = intval($_GET['service_id'] ?? 0);
$selected_service    = null;
$service_docs        = [];
$schedules           = [];

if ($selected_service_id) {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE service_id = ? AND status = 'active'");
    $stmt->execute([$selected_service_id]);
    $selected_service = $stmt->fetch();

    if ($selected_service) {
        $stmt = $pdo->prepare("SELECT * FROM service_documents WHERE service_id = ? ORDER BY document_name ASC");
        $stmt->execute([$selected_service_id]);
        $service_docs = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT sc.*, o.office_name
            FROM schedules sc
            JOIN offices o ON sc.office_id = o.office_id
            WHERE sc.service_id = ? AND sc.status = 'active'
            ORDER BY o.office_name ASC
        ");
        $stmt->execute([$selected_service_id]);
        $schedules = $stmt->fetchAll();
    }
}

// Handle booking
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schedule_id      = intval($_POST['schedule_id'] ?? 0);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $appointment_time = trim($_POST['appointment_time'] ?? '');
    $booking_type     = 'online';
    $notes            = trim($_POST['notes'] ?? '');
    $svc_id           = intval($_POST['service_id'] ?? 0);

    if (!$schedule_id || !$appointment_date || !$appointment_time) {
        $error = 'Please fill in all required fields.';
    } elseif (strtotime($appointment_date) < strtotime('today')) {
        $error = 'Appointment date cannot be in the past.';
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE user_id = ? AND appointment_date = ? AND appointment_time = ? AND status NOT IN ('cancelled')
        ");
        $stmt->execute([$user_id, $appointment_date, $appointment_time]);

        if ($stmt->fetchColumn() > 0) {
            $error = 'You already have an appointment at this date and time.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO appointments (user_id, schedule_id, appointment_date, appointment_time, booking_type, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            if ($stmt->execute([$user_id, $schedule_id, $appointment_date, $appointment_time, $booking_type, $notes])) {
                $new_appt_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM queue_tokens qt
                    JOIN appointments a ON qt.appointment_id = a.appointment_id
                    WHERE a.appointment_date = ? AND a.schedule_id = ?
                ");
                $stmt->execute([$appointment_date, $schedule_id]);
                $token_no = 'T' . str_pad($stmt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("INSERT INTO queue_tokens (appointment_id, token_no, queue_status) VALUES (?, ?, 'waiting')");
                $stmt->execute([$new_appt_id, $token_no]);
                $success = $token_no;
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

// Helpers
function getServiceIcon(string $name): array {
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
    return $map[strtolower(trim($name))] ?? ['fa-concierge-bell', '#6366f1', '#ede9fe'];
}

$fallback_docs = [
    'birth certificate'       => ['Hospital Birth Report', "Parents' NIC Copies", 'Marriage Certificate (if applicable)', 'Application Form'],
    'death certificate'       => ['Death Notification (BHT)', "Deceased's NIC Copy", 'Informant NIC Copy'],
    'residence certificate'   => ['NIC Copy', 'Utility Bill (recent)', 'Grama Niladhari Letter'],
    'nic services'            => ['Birth Certificate', 'Grama Niladhari Certificate', 'Passport Photo (2 copies)'],
    'marriage registration'   => ["Both Parties' NICs", 'Witnesses NICs (2)', 'Birth Certificates'],
    'business registration'   => ['NIC Copy', 'Business Plan / Name', 'Address Proof'],
    'land services'           => ['Title Deed', 'Survey Plan', "Owner's NIC Copy"],
    'samurdhi services'       => ['Income Proof', 'NIC Copy', 'Family Details Form'],
    'elderly assistance'      => ['NIC / Birth Certificate', 'Medical Reports', 'Guarantor Details'],
    'disability assistance'   => ['Medical Certificate', 'NIC Copy', 'Doctor Referral'],
];

$static_services = [
    ['service_id'=>1,'service_name'=>'Birth Certificate'],
    ['service_id'=>2,'service_name'=>'Death Certificate'],
    ['service_id'=>3,'service_name'=>'Residence Certificate'],
    ['service_id'=>4,'service_name'=>'NIC Services'],
    ['service_id'=>5,'service_name'=>'Marriage Registration'],
    ['service_id'=>6,'service_name'=>'Business Registration'],
    ['service_id'=>7,'service_name'=>'Land Services'],
    ['service_id'=>8,'service_name'=>'Samurdhi Services'],
    ['service_id'=>9,'service_name'=>'Elderly Assistance'],
    ['service_id'=>10,'service_name'=>'Disability Assistance'],
];
$display_services = !empty($services) ? $services : $static_services;

// If no service selected, default to first
if (!$selected_service && !empty($display_services)) {
    $first = $display_services[0];
    $selected_service_id = $first['service_id'];
    $selected_service    = $first;
}

$time_slots = ['08:30','09:00','09:30','10:00','10:30','11:00','11:30','13:00','13:30','14:00','14:30','15:00'];
$unread_notif = 0;

// Determine docs to show
$show_docs = [];
if (!empty($service_docs)) {
    foreach ($service_docs as $d) $show_docs[] = $d['document_name'];
} elseif ($selected_service) {
    $key = strtolower(trim($selected_service['service_name']));
    $show_docs = $fallback_docs[$key] ?? ['NIC Copy', 'Application Form'];
}

// Selected service style
$svc_icon = $svc_colour = $svc_bg = '';
if ($selected_service) {
    [$svc_icon, $svc_colour, $svc_bg] = getServiceIcon($selected_service['service_name']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - Divisional Secretariat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:    #1a6e3c;
            --primary-bg: #f0fdf4;
            --sidebar-w:  260px;
            --topbar-h:   70px;
            --radius:     14px;
            --shadow:     0 2px 12px rgba(0,0,0,.07);
            --text:       #1e293b;
            --muted:      #64748b;
            --border:     #e2e8f0;
            --bg:         #f8fafc;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; }

        /* Sidebar */
        .sidebar { width:var(--sidebar-w); background:#fff; border-right:1px solid var(--border); display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; z-index:100; }
        .sidebar-brand { padding:1.25rem 1.5rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:.75rem; }
        .sidebar-brand img { width:62px; height:62px; object-fit:contain; }
        .sidebar-brand strong { font-size:.85rem; color:var(--text); display:block; line-height:1.3; }
        .sidebar-brand span { font-size:.7rem; color:var(--muted); }
        .sidebar-nav { flex:1; overflow-y:auto; padding:.75rem 0; }
        .nav-label { font-size:.65rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); padding:.75rem 1.5rem .25rem; }
        .nav-item { list-style:none; }
        .nav-item a { display:flex; align-items:center; gap:.75rem; padding:.65rem 1.5rem; font-size:.875rem; font-weight:500; color:var(--muted); text-decoration:none; border-left:3px solid transparent; transition:all .2s; }
        .nav-item a:hover { background:var(--primary-bg); color:var(--primary); }
        .nav-item a.active { background:var(--primary-bg); color:var(--primary); border-left-color:var(--primary); font-weight:600; }
        .nav-item a i { width:18px; text-align:center; font-size:.9rem; }
        .sidebar-footer { padding:1rem 1.5rem; border-top:1px solid var(--border); }

        /* Topbar */
        .topbar { position:fixed; top:0; left:var(--sidebar-w); right:0; height:var(--topbar-h); background:#fff; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; padding:0 2rem; z-index:99; }
        .page-title { font-size:1rem; font-weight:700; }
        .breadcrumb { font-size:.75rem; color:var(--muted); margin:0; background:none; padding:0; }
        .topbar-right { display:flex; align-items:center; gap:1rem; }
        .notif-btn { position:relative; background:none; border:none; font-size:1.1rem; color:var(--muted); cursor:pointer; padding:.4rem; }
        .user-pill { display:flex; align-items:center; gap:.6rem; padding:.35rem .75rem; border-radius:999px; border:1px solid var(--border); text-decoration:none; transition:background .2s; }
        .user-pill:hover { background:var(--bg); }
        .user-avatar { width:32px; height:32px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; }
        .user-pill strong { font-size:.82rem; display:block; line-height:1.2; color:var(--text); }
        .user-pill span { font-size:.72rem; color:var(--muted); }

        /* Main */
        .main-content { margin-left:var(--sidebar-w); padding-top:var(--topbar-h); flex:1; }
        .content-wrap { padding:1.75rem 2rem; }

        /* Page header back link */
        .back-link { display:inline-flex; align-items:center; gap:.4rem; font-size:.82rem; color:var(--muted); text-decoration:none; margin-bottom:1.25rem; transition:color .2s; }
        .back-link:hover { color:var(--primary); }

        /* Two-column layout */
        .booking-wrap { display:grid; grid-template-columns:260px 1fr; gap:1.5rem; align-items:start; max-width:1200px; }

        /* ── LEFT PANEL ── */
        .left-col { display:flex; flex-direction:column; gap:1rem; }

        /* Service info card */
        .svc-info-card { background:#fff; border-radius:var(--radius); border:1px solid var(--border); padding:1.25rem; }
        .svc-info-icon { width:52px; height:52px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:.85rem; }
        .svc-info-card h6 { font-size:.92rem; font-weight:700; margin-bottom:.25rem; }
        .svc-info-card p { font-size:.78rem; color:var(--muted); margin-bottom:.75rem; }
        .svc-view-link { font-size:.78rem; color:var(--primary); font-weight:600; text-decoration:none; }
        .svc-view-link:hover { text-decoration:underline; }

        /* Docs card */
        .docs-card { background:#fff; border-radius:var(--radius); border:1px solid var(--border); padding:1.1rem 1.25rem; }
        .docs-card-title { font-size:.8rem; font-weight:700; margin-bottom:.75rem; color:var(--text); }
        .doc-row { display:flex; align-items:flex-start; gap:.5rem; margin-bottom:.45rem; font-size:.8rem; }
        .doc-row i { color:var(--primary); margin-top:2px; flex-shrink:0; }
        .doc-warning { background:#fef9c3; border-radius:8px; padding:.55rem .8rem; font-size:.75rem; color:#92400e; margin-top:.85rem; display:flex; gap:.45rem; align-items:flex-start; }
        .doc-warning i { flex-shrink:0; margin-top:1px; }

        /* ── RIGHT PANEL ── */
        .right-col { background:#fff; border-radius:var(--radius); border:1px solid var(--border); padding:2.5rem; display:flex; flex-direction:column; }
        .right-col h5 { font-size:1.35rem; font-weight:700; margin-bottom:1.25rem; }
        .right-col form { display:flex; flex-direction:column; flex:1; }
        .form-spacer { flex:1; min-height:1rem; }

        /* Office select */
        .field-label { font-size:.78rem; font-weight:600; color:var(--text); margin-bottom:.45rem; display:block; }
        .req { color:#ef4444; }
        .office-select { width:100%; padding:.65rem .9rem; border:1.5px solid var(--border); border-radius:10px; font-size:.84rem; color:var(--text); background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%2364748b' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right .9rem center; appearance:none; }
        .office-select:focus { outline:none; border-color:var(--primary); }

        /* Calendar + Slots row */
        .date-time-row { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-top:1.1rem; }

        /* Calendar */
        .cal-wrap { border:1.5px solid var(--border); border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); }
        .cal-head { display:flex; align-items:center; justify-content:space-between; padding:.7rem 1rem; background:linear-gradient(135deg,#1a6e3c,#22c55e); border-bottom:none; }
        .cal-head span { font-size:.85rem; font-weight:700; color:#fff; letter-spacing:.02em; }
        .cal-nav { background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); border-radius:7px; width:28px; height:28px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#fff; font-size:.7rem; transition:all .2s; }
        .cal-nav:hover { background:rgba(255,255,255,.4); border-color:rgba(255,255,255,.5); }
        .cal-dow-row { display:grid; grid-template-columns:repeat(7,1fr); background:#f0fdf4; border-bottom:1px solid #d1fae5; }
        .cal-dow { text-align:center; font-size:.62rem; font-weight:700; color:#16a34a; text-transform:uppercase; padding:.5rem 0; }
        .cal-days { display:grid; grid-template-columns:repeat(7,1fr); padding:.35rem; gap:.15rem; background:#fff; }
        .cal-day { text-align:center; padding:.45rem .1rem; font-size:.8rem; cursor:pointer; border:none; background:none; color:var(--text); position:relative; transition:all .15s; border-radius:7px; font-weight:500; }
        .cal-day:hover:not(.other):not(.past) { background:#dcfce7; color:#15803d; font-weight:600; }
        .cal-day.today { background:#f0fdf4; color:#16a34a; font-weight:700; border:1.5px solid #86efac; }
        .cal-day.today::after { content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%); width:4px; height:4px; background:#16a34a; border-radius:50%; }
        .cal-day.selected { background:linear-gradient(135deg,#1a6e3c,#22c55e) !important; color:#fff !important; border-radius:8px; font-weight:700; box-shadow:0 2px 8px rgba(26,110,60,.35); border:none !important; }
        .cal-day.other { color:#d1d5db; cursor:default; pointer-events:none; font-weight:400; }
        .cal-day.past { color:#e2e8f0; cursor:not-allowed; pointer-events:none; font-weight:400; }

        /* Time slots */
        .slot-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
        .slot-btn {
            padding:.55rem; border-radius:9px;
            border:1.5px solid var(--border); background:#fff;
            font-size:.8rem; font-weight:500; color:var(--text);
            cursor:pointer; text-align:center; transition:all .2s;
            position:relative; overflow:hidden;
        }
        .slot-btn::before {
            content:''; position:absolute; inset:0;
            background:linear-gradient(135deg,#1a6e3c,#22c55e);
            opacity:0; transition:opacity .2s;
        }
        .slot-btn span { position:relative; z-index:1; }
        .slot-btn:hover { border-color:#22c55e; color:#15803d; background:#f0fdf4; transform:translateY(-1px); box-shadow:0 3px 10px rgba(26,110,60,.15); }
        .slot-btn.selected { background:linear-gradient(135deg,#1a6e3c,#22c55e); color:#fff; border-color:transparent; font-weight:700; box-shadow:0 4px 12px rgba(26,110,60,.35); transform:translateY(-1px); }

        /* Notes */
        .notes-area { width:100%; padding:.65rem .9rem; border:1.5px solid var(--border); border-radius:10px; font-size:.83rem; color:var(--text); resize:none; min-height:180px; flex:1; font-family:'Inter',sans-serif; margin-top:1.1rem; }
        .notes-area:focus { outline:none; border-color:var(--primary); }

        /* Confirm */
        .btn-confirm { width:100%; padding:.85rem; background:var(--primary); color:#fff; border:none; border-radius:12px; font-size:.92rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.5rem; margin-top:1.25rem; transition:background .2s, transform .15s; }
        .btn-confirm:hover { background:#15582f; transform:translateY(-1px); }
        .btn-confirm:disabled { background:#94a3b8; cursor:not-allowed; transform:none; }

        /* Info strip */
        .info-strip { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-top:1.5rem; margin-bottom:1.25rem; padding:1.1rem; background:var(--bg); border-radius:12px; border:1px solid var(--border); }
        .info-strip-item { display:flex; align-items:flex-start; gap:.65rem; }
        .info-strip-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
        .info-strip-title { font-size:.78rem; font-weight:600; color:var(--text); margin-bottom:.1rem; }
        .info-strip-sub { font-size:.72rem; color:var(--muted); line-height:1.4; }

        /* Success modal */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; z-index:999; padding:1rem; }
        .modal-box { background:#fff; border-radius:20px; padding:2.5rem 2rem; text-align:center; max-width:400px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.2); animation:popIn .35s ease; }
        @keyframes popIn { from{transform:scale(.85);opacity:0} to{transform:scale(1);opacity:1} }
        .modal-icon { width:70px; height:70px; background:#dcfce7; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.1rem; font-size:1.7rem; color:var(--primary); }
        .token-num { display:inline-block; background:var(--primary); color:#fff; font-size:2rem; font-weight:800; padding:.5rem 1.75rem; border-radius:12px; letter-spacing:.05em; margin:.75rem 0; }
        .modal-box h4 { font-size:1.1rem; font-weight:700; margin-bottom:.35rem; }
        .modal-box p { font-size:.83rem; color:var(--muted); }
        .modal-actions { display:flex; gap:.6rem; justify-content:center; margin-top:1.25rem; flex-wrap:wrap; }
        .btn-modal-primary { background:var(--primary); color:#fff; border:none; border-radius:10px; padding:.6rem 1.3rem; font-size:.85rem; font-weight:600; cursor:pointer; text-decoration:none; }
        .btn-modal-primary:hover { background:#15582f; color:#fff; }
        .btn-modal-outline { background:#fff; color:var(--text); border:1.5px solid var(--border); border-radius:10px; padding:.58rem 1.1rem; font-size:.85rem; font-weight:500; text-decoration:none; }
        .btn-modal-outline:hover { background:var(--bg); color:var(--text); }

        @media(max-width:991px){
            .sidebar{transform:translateX(-100%)}
            .topbar,.main-content{left:0;margin-left:0}
            .topbar{left:0}
            .booking-wrap{grid-template-columns:1fr}
            .date-time-row{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="image/emblem.png" alt="Emblem" onerror="this.style.display='none'">
        <div>
            <strong>Divisional Secretariat</strong>
            <span>Queue & Appointment Management System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <p class="nav-label">Main Menu</p>
        <ul class="list-unstyled">
            <li class="nav-item"><a href="dashboard.php"><i class="fas fa-home"></i> Home</a></li>
            <li class="nav-item"><a href="book_appointment.php" class="active"><i class="far fa-calendar-plus"></i> Book Appointment</a></li>
            <li class="nav-item"><a href="my_appointments.php"><i class="far fa-calendar-check"></i> My Appointments</a></li>
            <li class="nav-item"><a href="queue_status.php"><i class="fas fa-users"></i> Queue Status</a></li>
            <li class="nav-item"><a href="services.php"><i class="fas fa-th-large"></i> Services</a></li>
            <li class="nav-item"><a href="announcements.php"><i class="far fa-bell"></i> Announcements</a></li>
        </ul>
        <p class="nav-label">Account</p>
        <ul class="list-unstyled">
            <li class="nav-item"><a href="my_profile.php"><i class="far fa-user-circle"></i> My Profile</a></li>
            <li class="nav-item"><a href="help.php"><i class="far fa-question-circle"></i> Help & Support</a></li>
            <li class="nav-item"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <p style="font-size:.72rem;color:var(--muted);text-align:center;margin:0;">&copy; <?php echo date('Y'); ?> Divisional Secretariat Office</p>
    </div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
    <div>
        <div class="page-title">Book Appointment</div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php" style="color:var(--primary);text-decoration:none;">Home</a></li>
                <li class="breadcrumb-item active">Book Appointment</li>
            </ol>
        </nav>
    </div>
    <div class="topbar-right">
        <button class="notif-btn" onclick="location.href='notifications.php'"><i class="far fa-bell"></i></button>
        <a href="my_profile.php" class="user-pill">
            <div class="user-avatar"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
            <div>
                <strong><?php echo htmlspecialchars($full_name); ?></strong>
                <span><?php echo ucfirst(htmlspecialchars($role)); ?></span>
            </div>
            <i class="fas fa-chevron-down" style="font-size:.65rem;color:var(--muted);margin-left:.25rem;"></i>
        </a>
    </div>
</header>

<!-- SUCCESS MODAL -->
<?php if ($success): ?>
<div class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon"><i class="fas fa-check"></i></div>
        <h4>Appointment Booked!</h4>
        <p>Your appointment is confirmed. Queue token:</p>
        <div class="token-num"><?php echo htmlspecialchars($success); ?></div>
        <p style="font-size:.76rem;color:#94a3b8;margin-top:.3rem;">Arrive 10 minutes early with all required documents.</p>
        <div class="modal-actions">
            <a href="my_appointments.php" class="btn-modal-primary"><i class="far fa-calendar-check me-1"></i> My Appointments</a>
            <a href="dashboard.php" class="btn-modal-outline">Back to Home</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MAIN -->
<main class="main-content">
<div class="content-wrap">

    <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Home</a>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-3" style="border-radius:10px;font-size:.84rem;">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="booking-wrap">

        <!-- LEFT -->
        <div class="left-col">

            <!-- Service info -->
            <?php if ($selected_service): ?>
            <div class="svc-info-card">
                <div class="svc-info-icon" style="background:<?php echo $svc_bg; ?>;color:<?php echo $svc_colour; ?>;">
                    <i class="fas <?php echo $svc_icon; ?>"></i>
                </div>
                <h6><?php echo htmlspecialchars($selected_service['service_name']); ?></h6>
                <p><?php echo htmlspecialchars($selected_service['description'] ?? 'Apply for ' . strtolower($selected_service['service_name'])); ?></p>
                <a href="services.php?id=<?php echo $selected_service_id; ?>" class="svc-view-link">
                    <i class="fas fa-external-link-alt me-1"></i>View Service Details
                </a>
            </div>
            <?php endif; ?>

            <!-- Required Documents -->
            <?php if (!empty($show_docs)): ?>
            <div class="docs-card">
                <div class="docs-card-title">Required Documents</div>
                <?php foreach ($show_docs as $doc): ?>
                <div class="doc-row">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($doc); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="doc-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Please bring original documents for verification.
                </div>
            </div>
            <?php endif; ?>

            <!-- Service chooser links -->
            <div style="background:#fff;border-radius:var(--radius);border:1px solid var(--border);padding:1rem;">
                <div style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.65rem;">Change Service</div>
                <?php foreach ($display_services as $svc):
                    [$ic,$cl,$bg] = getServiceIcon($svc['service_name']);
                    $act = ($svc['service_id'] == $selected_service_id) ? 'background:var(--primary-bg);border-color:var(--primary);' : '';
                ?>
                <a href="book_appointment.php?service_id=<?php echo $svc['service_id']; ?>"
                   style="display:flex;align-items:center;gap:.6rem;padding:.45rem .6rem;border-radius:8px;border:1.5px solid var(--border);margin-bottom:.4rem;text-decoration:none;transition:all .2s;<?php echo $act; ?>">
                    <div style="width:28px;height:28px;border-radius:6px;background:<?php echo $bg; ?>;color:<?php echo $cl; ?>;display:flex;align-items:center;justify-content:center;font-size:.72rem;flex-shrink:0;">
                        <i class="fas <?php echo $ic; ?>"></i>
                    </div>
                    <span style="font-size:.78rem;font-weight:500;color:var(--text);"><?php echo htmlspecialchars($svc['service_name']); ?></span>
                    <?php if ($svc['service_id'] == $selected_service_id): ?>
                    <i class="fas fa-check-circle" style="margin-left:auto;color:var(--primary);font-size:.72rem;"></i>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- RIGHT -->
        <div class="right-col">
            <h5>Select Appointment Details</h5>

            <form method="POST" action="" id="bookForm">
                <input type="hidden" name="service_id" value="<?php echo $selected_service_id; ?>">
                <input type="hidden" name="appointment_date" id="hdnDate">
                <input type="hidden" name="appointment_time" id="hdnTime">
                <input type="hidden" name="booking_type" value="online">

                <!-- Office -->
                <label class="field-label">Select Office <span class="req">*</span></label>
                <?php if (!empty($schedules)): ?>
                <select name="schedule_id" class="office-select" required>
                    <option value="">-- Select Office --</option>
                    <?php foreach ($schedules as $sch): ?>
                    <option value="<?php echo $sch['schedule_id']; ?>" <?php echo (isset($_POST['schedule_id']) && $_POST['schedule_id']==$sch['schedule_id'])?'selected':''; ?>>
                        <?php echo htmlspecialchars($sch['office_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <select name="schedule_id" class="office-select" required>
                    <option value="1">Divisional Secretariat Office - Matara</option>
                </select>
                <?php endif; ?>

                <!-- Date + Time -->
                <div class="date-time-row">
                    <!-- Calendar -->
                    <div>
                        <label class="field-label" style="margin-top:.9rem;">Select Date <span class="req">*</span></label>
                        <div class="cal-wrap">
                            <div class="cal-head">
                                <button type="button" class="cal-nav" id="prevM"><i class="fas fa-chevron-left"></i></button>
                                <span id="calTitle"></span>
                                <button type="button" class="cal-nav" id="nextM"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            <div class="cal-dow-row">
                                <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                                <div class="cal-dow"><?php echo $d; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="cal-days" id="calDays"></div>
                        </div>
                    </div>

                    <!-- Time slots -->
                    <div>
                        <label class="field-label" style="margin-top:.9rem;">Select Time Slot <span class="req">*</span></label>
                        <div class="slot-grid">
                            <?php foreach ($time_slots as $slot):
                                $disp = date('h:i A', strtotime($slot));
                                $was  = (isset($_POST['appointment_time']) && $_POST['appointment_time']===$slot);
                            ?>
                            <button type="button"
                                class="slot-btn <?php echo $was?'selected':''; ?>"
                                data-time="<?php echo $slot; ?>"
                                onclick="pickSlot(this)">
                                <span><?php echo $disp; ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <label class="field-label" style="margin-top:1.1rem;">Additional Notes <span style="font-weight:400;color:var(--muted);">(Optional)</span></label>
                <textarea name="notes" class="notes-area" placeholder="Enter any additional information..."><?php echo htmlspecialchars($_POST['notes']??''); ?></textarea>

                <!-- Info Strip -->
                <div class="info-strip">
                    <div class="info-strip-item">
                        <div class="info-strip-icon" style="background:#dcfce7;color:#16a34a;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="info-strip-title">Arrive Early</div>
                            <div class="info-strip-sub">Please arrive 10 mins before your slot</div>
                        </div>
                    </div>
                    <div class="info-strip-item">
                        <div class="info-strip-icon" style="background:#dbeafe;color:#2563eb;">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div>
                            <div class="info-strip-title">Bring Originals</div>
                            <div class="info-strip-sub">Original documents required for verification</div>
                        </div>
                    </div>
                    <div class="info-strip-item">
                        <div class="info-strip-icon" style="background:#fef3c7;color:#d97706;">
                            <i class="fas fa-undo-alt"></i>
                        </div>
                        <div>
                            <div class="info-strip-title">Free Cancellation</div>
                            <div class="info-strip-sub">Cancel up to 2 hours before appointment</div>
                        </div>
                    </div>
                    <div class="info-strip-item">
                        <div class="info-strip-icon" style="background:#f3e8ff;color:#9333ea;">
                            <i class="fas fa-hashtag"></i>
                        </div>
                        <div>
                            <div class="info-strip-title">Token Assigned</div>
                            <div class="info-strip-sub">Queue token auto-generated on confirmation</div>
                        </div>
                    </div>
                </div>

                <!-- Confirm -->
                <button type="submit" class="btn-confirm" id="confirmBtn" disabled>
                    <i class="fas fa-calendar-check"></i> Confirm Appointment
                </button>
                <p style="font-size:.74rem;color:var(--muted);text-align:center;margin-top:.65rem;">
                    <i class="fas fa-lock me-1"></i> Your booking is secure and encrypted.
                </p>
            </form>
        </div>
    </div>

    <div style="height:2rem;"></div>
</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let curDate = new Date();
let selDate = null;
let selTime = null;

<?php if (!empty($_POST['appointment_date'])): ?>
selDate = new Date('<?php echo $_POST['appointment_date']; ?>T00:00:00');
curDate = new Date(selDate);
<?php endif; ?>
<?php if (!empty($_POST['appointment_time'])): ?>
selTime = '<?php echo $_POST['appointment_time']; ?>';
document.getElementById('hdnTime').value = selTime;
<?php endif; ?>

function pad(n){return String(n).padStart(2,'0');}
function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}

function buildCal(){
    const y=curDate.getFullYear(), m=curDate.getMonth();
    const names=['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calTitle').textContent=names[m]+' '+y;

    const today=new Date(); today.setHours(0,0,0,0);
    const first=new Date(y,m,1);
    const last=new Date(y,m+1,0);
    const prevLast=new Date(y,m,0).getDate();

    let html='';
    for(let i=first.getDay()-1;i>=0;i--) html+=`<div class="cal-day other">${prevLast-i}</div>`;
    for(let d=1;d<=last.getDate();d++){
        const dt=new Date(y,m,d);
        const isSun=dt.getDay()===0;
        const isPast=dt<today;
        const isTod=dt.toDateString()===today.toDateString();
        const isSel=selDate&&dt.toDateString()===selDate.toDateString();
        let cls='cal-day';
        if(isPast||isSun) cls+=' past';
        if(isTod) cls+=' today';
        if(isSel) cls+=' selected';
        const ds=fmt(dt);
        html+=`<div class="${cls}" onclick="pickDate('${ds}',this)">${d}</div>`;
    }
    const rem=7-((first.getDay()+last.getDate())%7);
    if(rem<7) for(let i=1;i<=rem;i++) html+=`<div class="cal-day other">${i}</div>`;
    document.getElementById('calDays').innerHTML=html;
}

function pickDate(ds,el){
    document.querySelectorAll('.cal-day').forEach(e=>e.classList.remove('selected'));
    el.classList.add('selected');
    selDate=new Date(ds+'T00:00:00');
    document.getElementById('hdnDate').value=ds;
    checkReady();
}

function pickSlot(btn){
    document.querySelectorAll('.slot-btn').forEach(b=>b.classList.remove('selected'));
    btn.classList.add('selected');
    selTime=btn.dataset.time;
    document.getElementById('hdnTime').value=selTime;
    checkReady();
}

function checkReady(){
    document.getElementById('confirmBtn').disabled=!(selDate&&selTime);
}

document.getElementById('prevM').addEventListener('click',()=>{curDate.setMonth(curDate.getMonth()-1);buildCal();});
document.getElementById('nextM').addEventListener('click',()=>{curDate.setMonth(curDate.getMonth()+1);buildCal();});

document.getElementById('bookForm').addEventListener('submit',function(e){
    if(!selDate||!selTime){e.preventDefault();alert('Please select a date and time slot.');}
});

buildCal();
checkReady();
</script>
</body>
</html>
