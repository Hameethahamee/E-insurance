<?php
session_start();

// Connect to DB
$conn = new mysqli("localhost", "root", "", "insurance");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function tableExists($conn, $table) {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return ($r && $r->num_rows > 0);
}

// --- POLICY VIEWS BY TYPE (kept as you had) ---
$policyStats = [];
if (tableExists($conn, 'policy_views')) {
    $res = $conn->query("SELECT policy_type, COUNT(*) as total FROM policy_views GROUP BY policy_type");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $policyStats[] = $row;
        }
    }
}

// --- POLICIES DISTRIBUTION (kept as you had) ---
$policyDist = [];
if (tableExists($conn, 'insurance_policies')) {
    $res = $conn->query("SELECT type, COUNT(*) as total FROM insurance_policies GROUP BY type");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $policyDist[] = $row;
        }
    }
}

// --- DEFAULT COUNTS ---
$userCount = 0;
$viewCount = 0;
$policyCount = 0;

// --- Record unique insurance view (your logic) ---
if (tableExists($conn, 'insurance_views')) {
    if (isset($_SESSION['user_id'])) {
        $user_id = intval($_SESSION['user_id']);
        $check = $conn->query("SELECT id FROM insurance_views WHERE user_id = $user_id");
        if ($check && $check->num_rows == 0) {
            $conn->query("INSERT INTO insurance_views (user_id, viewed_at) VALUES ($user_id, NOW())");
        }
    } else {
        $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR']);
        $check = $conn->query("SELECT id FROM insurance_views WHERE guest_ip = '$ip'");
        if ($check && $check->num_rows == 0) {
            $conn->query("INSERT INTO insurance_views (guest_ip, viewed_at) VALUES ('$ip', NOW())");
        }
    }
}

// --- Count users ---
if (tableExists($conn, 'user')) {
    $res = $conn->query("SELECT COUNT(*) as count FROM user");
    if ($res && $row = $res->fetch_assoc()) {
        $userCount = $row['count'];
    }
}

// --- Count total policies ---
if (tableExists($conn, 'insurance_policies')) {
    $res = $conn->query("SELECT COUNT(*) as count FROM insurance_policies");
    if ($res && $row = $res->fetch_assoc()) {
        $policyCount = $row['count'];
    }
}

// --- Count unique insurance views ---
if (tableExists($conn, 'insurance_views')) {
    $res = $conn->query("SELECT COUNT(*) as count FROM insurance_views");
    if ($res && $row = $res->fetch_assoc()) {
        $viewCount = $row['count'];
    }
}

/*
  BUILD DATA FOR GALLERY CHARTS
  1) Policies Applied by Name (from policy_applications or user_policies)
  2) Monthly Registrations (last 6 months) from user.created_at
*/

// 1) Policies Applied by Name
$appliedLabels = [];
$appliedData = [];

if (tableExists($conn, 'policy_applications')) {
    // Join policy_applications with insurance_policies to get policy names
    if (tableExists($conn, 'insurance_policies')) {
        $sql = "SELECT ip.policy_name, COUNT(*) AS total
                FROM policy_applications pa
                JOIN insurance_policies ip ON pa.policy_id = ip.id
                GROUP BY ip.policy_name
                ORDER BY total DESC
                LIMIT 20";
    } else {
        // fallback if insurance_policies table not found
        $sql = "SELECT policy_id AS policy_name, COUNT(*) AS total
                FROM policy_applications
                GROUP BY policy_id
                ORDER BY total DESC
                LIMIT 20";
    }

    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $appliedLabels[] = $r['policy_name'];
            $appliedData[] = intval($r['total']);
        }
    }
} elseif (tableExists($conn, 'user_policies')) {
    // fallback to user_policies if policy_applications not present
    if (tableExists($conn, 'insurance_policies')) {
        $sql = "SELECT ip.policy_name, COUNT(*) AS total
                FROM user_policies up
                JOIN insurance_policies ip ON up.policy_id = ip.id
                GROUP BY ip.policy_name
                ORDER BY total DESC
                LIMIT 20";
    } else {
        $sql = "SELECT policy_id AS policy_name, COUNT(*) AS total
                FROM user_policies
                GROUP BY policy_id
                ORDER BY total DESC
                LIMIT 20";
    }

    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $appliedLabels[] = $r['policy_name'];
            $appliedData[] = intval($r['total']);
        }
    }
}

// If no data, create placeholder so chart renders
if (empty($appliedLabels)) {
    $appliedLabels = ['No data'];
    $appliedData = [0];
}


// 2) Monthly Registrations (last 6 months)
$monthlyLabels = [];
$monthlyData = [];

// build last 6 months labels (YYYY-MM key for mapping)
$months = [];
$dt = new DateTime('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $m = (clone $dt)->modify("-$i month");
    $key = $m->format('Y-m');         // 2025-09
    $label = $m->format('M Y');       // Sep 2025
    $months[$key] = $label;
}

// Query user registrations if table exists and created_at likely present
$regCounts = [];
if (tableExists($conn, 'user')) {
    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total
            FROM user
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
            GROUP BY ym
            ORDER BY ym";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $regCounts[$r['ym']] = intval($r['total']);
        }
    }
}

// Fill monthlyLabels & monthlyData using months map
foreach ($months as $key => $label) {
    $monthlyLabels[] = $label;
    $monthlyData[] = isset($regCounts[$key]) ? $regCounts[$key] : 0;
}

// If everything empty, fallback to zeros
$allZeros = true;
foreach ($monthlyData as $v) { if ($v > 0) { $allZeros = false; break; } }
if ($allZeros) {
    // keep labels but zeros are fine
}

// free connection (optional)
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-Insurance Home</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        /* ====== KEEPING YOUR ORIGINAL STYLES INTACT ====== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, input, select, textarea, button { font-family: 'Times New Roman', Times, serif; }

        .top-bar {
            width: 100%; padding: 15px 30px; background: #00a3e0;
            display: flex; justify-content: space-between; align-items: center;
            position: fixed; top: 0; z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); border-radius: 0 0 25px 25px;
        }
        .logo {
            position: absolute; left: 50%; transform: translateX(-50%);
            font-size: 24px; font-weight: bold; color: white;
        }
        .menu { margin-left: auto; display: flex; gap: 15px; }
        .menu a {
            color: #fff; font-weight: 500; text-decoration: none;
            padding: 8px 16px; border-radius: 25px;
            background: rgba(255, 255, 255, 0.1); transition: 0.3s;
        }
        .menu a:hover { background: #fff; color: #00a3e0; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        .module-section { display: none; padding-top: 100px; animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from {opacity: 0;} to {opacity: 1;} }

        .hero-section {
            min-height: 100vh;
            background: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSWtpS3TZZFM0-0K3GiFTNXVDHDQko_GUwM2A&s') no-repeat center center/cover;
            position: relative; margin-top: 80px;
            display: flex; align-items: center; justify-content: center;
        }
        .hero-section::before {
            content: ''; position: absolute; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 0;
        }
        .hero-content {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 80px 60px; max-width: 1200px; margin: 0 auto;
            position: relative; z-index: 1; color: white; flex-wrap: wrap;
        }
        .home-description { width: 60%; font-size: 18px; line-height: 1.6; }
        .home-description ul { margin-top: 10px; padding-left: 20px; }
        .home-image { width:40%; }
        .home-image img {
            width: 140%; border-radius: 55px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }

        .main-title { text-align: center; font-size: 36px; font-weight: 700; color: #333; margin-bottom: 20px; }
        .container {
            background: rgba(255, 255, 255, 0.15); padding: 40px 30px;
            width: 90%; max-width: 1100px; margin: 40px auto; border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3); backdrop-filter: blur(6px);
            text-align: center; color: #fff;
        }
        .container a, .container p {
            display: block; margin: 15px auto; padding: 14px 30px;
            font-size: 22px; font-weight: 500; text-decoration: none;
            background-color: #26A69A; color: white; border-radius: 10px;
            transition: background-color 0.3s ease, transform 0.2s ease; width: 80%;
        }
        .container a:hover { background-color: #00796B; transform: scale(1.05); }

        /* ====== Gallery-specific layout for charts (minimal & safe) ====== */
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 28px; align-items: stretch; }
        .chart-card { background: #fff; color: #333; padding: 18px; border-radius: 10px; text-align: left; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
        .chart-card h3 { margin: 0 0 10px 0; font-size: 18px; color: #222; }
        .chart-card canvas { width: 100% !important; height: 320px !important; display: block; }

        /* small responsive tweak */
        @media (max-width: 900px) {
            .charts-grid { grid-template-columns: 1fr; }
            .hero-content { padding: 40px 20px; }
        }
		/* Background images for different modules */

.module-section { background-size: cover; /* stretch image to cover full section */ background-repeat: no-repeat; /* no repeat */ background-position: center; /* center the image */ background-attachment: fixed; /* optional: gives a parallax effect */ min-height: 100vh; /* take full height of screen */ display: flex; justify-content: center; align-items: center; } #home { background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSWtpS3TZZFM0-0K3GiFTNXVDHDQko_GUwM2A&s'); } #admin { background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRk5mdjdqUPtPLQ8wkMKy6vuovbeJnW01hSKw&s'); } #user { background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQuSlFoRauBCq5_FuLxbp8mg6xxeYg6MFcavA&s'); } #gallery { background-image: url('data:image/jpeg;base64,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'); }

    </style>
</head>
<body onload="showModule('home')">

<!-- NAVIGATION -->
<div class="top-bar">
    <div class="logo">
        E-Insurance
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="user_dashboard.php" title="Your Profile">👤</a>
        <?php endif; ?>
    </div>
    <div class="menu">
        <a href="#" onclick="showModule('home')">🏠 Home</a>
        <a href="#" onclick="showModule('admin')">🛡️ Admin</a>
        <a href="#" onclick="showModule('user')">👤 User</a>
        <a href="#" onclick="showModule('gallery')">📊 Gallery</a>
    </div>
</div>

<!-- HOME MODULE -->
<div id="home" class="module-section hero-section">
    <div class="hero-content">
        <div class="home-description">
            <p><strong>E-Insurance</strong> — your trusted digital partner for smart and secure insurance services.</p>
            <p>Whether you're looking for protection for your family, vehicle, home, health, or business, our platform helps you compare, choose, and manage insurance policies tailored to your lifestyle.</p>
            <p><strong>Why E-Insurance?</strong></p>
            <ul>
                <li>✔️ Quick online registration and profile management</li>
                <li>📊 Real-time access to your active policies and coverage details</li>
                <li>🔔 Automated reminders for premium due dates and renewals</li>
                <li>📁 Easy filing and tracking of claims and support tickets</li>
                <li>🔒 Secure access with modern encryption and privacy standards</li>
            </ul>
        </div>
        <div class="home-image">
            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSWtpS3TZZFM0-0K3GiFTNXVDHDQko_GUwM2A&s" alt="E-Insurance">
        </div>
    </div>
</div>

<!-- ADMIN MODULE -->
<div id="admin" class="module-section">
    <div class="container">
        <a href="admin_login.php">🛡️ Admin Login</a>
    </div>
</div>

<!-- USER MODULE -->
<div id="user" class="module-section">
    <div class="container">
        <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="user_register.php">👤 User Register</a>
            <a href="user_login.php">🔐 User Login</a>
        <?php else: ?>
            <p>You are already logged in!</p>
        <?php endif; ?>
    </div>
</div>

<!-- GALLERY MODULE (UPDATED with charts) -->
<div id="gallery" class="module-section">
    <div class="container">
        <h2 class="main-title">📊 Platform Insights</h2>

        <p style="color:#fff; font-size:18px; text-align:left;">👥 Total Registered Users: <strong style="color:#fff; margin-left:8px;"><?= $userCount ?></strong></p>
        <p style="color:#fff; font-size:18px; text-align:left;">📑 Total Policies: <strong style="color:#fff; margin-left:8px;"><?= $policyCount ?></strong></p>
       

        <div class="charts-grid" id="chartsGrid">
            <div class="chart-card">
                <h3>Policies Applied by Policy (Top)</h3>
                <canvas id="appliedPolicyChart"></canvas>
            </div>

            <div class="chart-card">
                <h3>Monthly Registrations (last 6 months)</h3>
                <canvas id="monthlyRegChart"></canvas>
            </div>
        </div>

    </div>
</div>

<!-- Chart.js (v3) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
    // JSON data injected safely from PHP
    const APPLIED_LABELS = <?= json_encode($appliedLabels, JSON_UNESCAPED_UNICODE) ?>;
    const APPLIED_DATA   = <?= json_encode($appliedData) ?>;

    const MONTHLY_LABELS = <?= json_encode($monthlyLabels) ?>;
    const MONTHLY_DATA   = <?= json_encode($monthlyData) ?>;

    let appliedChart = null;
    let monthlyChart = null;
    let chartsRendered = false;

    // create a subtle gradient for monthly area chart
    function makeGradient(ctx, height) {
        const g = ctx.createLinearGradient(0, 0, 0, height);
        g.addColorStop(0, 'rgba(34,197,94,0.25)');
        g.addColorStop(1, 'rgba(34,197,94,0.03)');
        return g;
    }

    function renderCharts() {
        if (chartsRendered) return;
        chartsRendered = true;

        // Applied policies - donut chart
        const appliedCtx = document.getElementById('appliedPolicyChart').getContext('2d');

        // auto-generate colors array
        const colours = [
            '#007bff','#28a745','#ffc107','#dc3545','#17a2b8','#6f42c1','#fd7e14','#20c997','#ff6b6b','#4dabf7'
        ];
        const appliedColors = [];
        for (let i=0;i<APPLIED_LABELS.length;i++){
            appliedColors.push(colours[i % colours.length]);
        }

        appliedChart = new Chart(appliedCtx, {
            type: 'doughnut',
            data: {
                labels: APPLIED_LABELS,
                datasets: [{
                    data: APPLIED_DATA,
                    backgroundColor: appliedColors,
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth:12 } },
                    tooltip: { callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let val = context.raw || 0;
                            return label + ': ' + val;
                        }
                    } }
                }
            }
        });

        // Monthly registrations - area/line chart
        const monthlyCtx = document.getElementById('monthlyRegChart').getContext('2d');
        const gradient = makeGradient(monthlyCtx, 320);

        monthlyChart = new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: MONTHLY_LABELS,
                datasets: [{
                    label: 'Registrations',
                    data: MONTHLY_DATA,
                    fill: true,
                    backgroundColor: gradient,
                    borderColor: '#22c55e',
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#22c55e',
                    tension: 0.3,
                    borderWidth: 3,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                },
                plugins: {
                    legend: { display: true, position: 'top' }
                }
            }
        });
    }

    // showModule preserves your existing behavior: only create charts when Gallery shown
    function showModule(id) {
        document.querySelectorAll('.module-section').forEach(s => s.style.display = 'none');
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';

        // when gallery is displayed, render charts once (fixes invisible-canvas problem)
        if (id === 'gallery') {
            // small timeout to ensure element is visible and layout done
            setTimeout(renderCharts, 80);
        }
    }

    // If the page initially opens to gallery, render charts too
    (function() {
        // default loaded set to 'home' by onload attribute
        // but if you want to open gallery by default, you can call showModule('gallery') here.
    })();
</script>
</body>
</html>
