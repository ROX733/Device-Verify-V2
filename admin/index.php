<?php
// FINAL Admin with Login - Replace old admin/index.php with this
session_start();

$ADMIN_USER = 'admin';
$ADMIN_PASS = 'AdminHuBro'; // <-- Yaha apna password change kar le

// Logout
if(isset($_GET['logout'])){
    session_destroy();
    header("Location: ./"); exit;
}

// Login Check
if(isset($_POST['login'])){
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if($u === $ADMIN_USER && $p === $ADMIN_PASS){
        $_SESSION['admin_logged'] = true;
        header("Location: ./"); exit;
    }else{
        $error = "Wrong username or password!";
    }
}

// If not logged in - Show Login Page
if(!isset($_SESSION['admin_logged'])){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#f8f9fe;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:100%;max-width:360px;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,0.08);padding:28px}
.title{font-size:18px;font-weight:700;text-align:center;margin-bottom:20px;display:flex;align-items:center;justify-content:center;gap:8px}
input{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;margin-bottom:12px;outline:none}
input:focus{border-color:#2563eb}
.btn{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer}
.error{background:#fef2f2;color:#ef4444;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;text-align:center}
</style>
</head>
<body>
<div class="card">
<div class="title">🔒 Admin Login</div>
<?php if(isset($error)): ?><div class="error"><?=$error?></div><?php endif; ?>
<form method="POST">
<input type="text" name="username" placeholder="Username (admin)" required>
<input type="password" name="password" placeholder="Password" required>
<button class="btn" name="login" type="submit">Login</button>
</form>
<p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:12px">Default: admin / admin123</p>
</div>
</body>
</html>
<?php
exit;
}

// ---- Yaha se niche wahi purana admin panel code hai, but ab login ke baad hi khulega ----
$dataFile = __DIR__ . '/../data/bots.json';
$usersFile = __DIR__ . '/../data/users.json';
if(!file_exists($dataFile)){ $bots = []; } else { $bots = json_decode(file_get_contents($dataFile), true) ?: []; }
if(!file_exists($usersFile)){ $users = []; } else { $users = json_decode(file_get_contents($usersFile), true) ?: []; }
$search = $_GET['search'] ?? '';
if($search){ $bots = array_filter($bots, fn($b)=>stripos($b['bot_username']??'', $search)!==false); }
$totalUniqueBots = count(json_decode(file_get_contents($dataFile), true) ?: []);
$mostActive = null; $maxUsers = 0;
foreach((json_decode(file_get_contents($dataFile), true) ?: []) as $b){ $cnt=$b['total_users']??0; if($cnt>$maxUsers){$maxUsers=$cnt;$mostActive=$b;}}
$totalVpn=0; foreach((json_decode(file_get_contents($usersFile), true) ?: []) as $u){ if($u['is_vpn']??0) $totalVpn++; }
$fpCount=[]; $multiple=0; foreach((json_decode(file_get_contents($usersFile), true) ?: []) as $u){ $fp=$u['fingerprint']??''; if(!$fp) continue; if(isset($fpCount[$fp])) $multiple++; else $fpCount[$fp]=1; }
$totalMultiple=$multiple;
if(isset($_GET['delete'])){ $delId=$_GET['delete']; $allBots=json_decode(file_get_contents($dataFile), true)?:[]; $allBots=array_filter($allBots, fn($b)=>($b['id']??'')!=$delId && ($b['random_id']??'')!=$delId); file_put_contents($dataFile, json_encode(array_values($allBots), JSON_PRETTY_PRINT)); header("Location: ./"); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#f8f9fe;color:#1e293b;padding:0}
.header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.06);position:sticky;top:0;z-index:10}
.header-left{display:flex;align-items:center;gap:8px;font-weight:700;font-size:16px}
.header-right{display:flex;align-items:center;gap:12px}.badge-admin{display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b}
.btn-logout{background:#ec4899;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer}
.container{padding:20px;max-width:1200px;margin:0 auto}.title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px;margin-bottom:16px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);padding:16px;margin-bottom:16px}
.search-box{display:flex;gap:10px;align-items:center}.search-box input{flex:1;max-width:320px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none}
.btn-search{background:#2563eb;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px}.stat-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
.stat-title{font-size:13px;font-weight:600;color:#2563eb;margin-bottom:8px}.stat-value{font-size:26px;font-weight:800;color:#0f172a}.stat-sub{font-size:11px;color:#94a3b8;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;padding:12px 10px;font-size:11px;color:#64748b;text-transform:uppercase;border-bottom:1px solid #f1f5f9;white-space:nowrap}
td{padding:12px 10px;border-bottom:1px solid #f8fafc;white-space:nowrap}.bot-name{color:#2563eb;font-weight:600}
.btn-view{background:#3b82f6;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px}.btn-delete{background:#ec4899;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:11px;cursor:pointer}
</style>
</head>
<body>
<div class="header">
<div class="header-left">🛡️ Admin Panel</div>
<div class="header-right"><div class="badge-admin">👤 Admin</div><button class="btn-logout" onclick="location.href='?logout=1'">⎋ Logout</button></div>
</div>
<div class="container">
<div class="title">📊 Bot Statistics</div>
<div class="card">
<div style="font-size:13px;font-weight:600;margin-bottom:10px">🔍 Search Bots</div>
<form method="GET" class="search-box">
<input type="text" name="search" placeholder="Search by bot username..." value="<?=htmlspecialchars($search)?>">
<button class="btn-search" type="submit">Search</button>
</form>
</div>
<div class="stats-grid">
<div class="stat-card"><div class="stat-title">Total Unique Bots</div><div class="stat-value"><?=$totalUniqueBots?></div><div class="stat-sub">Tracked in system</div></div>
<div class="stat-card"><div class="stat-title">Most Active Bot</div><div class="stat-value" style="font-size:18px"><?=$mostActive?htmlspecialchars($mostActive['bot_username']??''):'—'?></div><div class="stat-sub"><?=$maxUsers?> users</div></div>
<div class="stat-card"><div class="stat-title">VPN Users</div><div class="stat-value"><?=$totalVpn?></div><div class="stat-sub">Across all bots</div></div>
<div class="stat-card"><div class="stat-title">Multiple Accounts</div><div class="stat-value"><?=$totalMultiple?></div><div class="stat-sub">Potential duplicates</div></div>
</div>
<div class="card">
<div style="font-size:13px;font-weight:700;margin-bottom:12px">Bot Details</div>
<table>
<tr><th>Bot Username</th><th>Users</th><th>VPN</th><th>Last Activity</th><th>Actions</th></tr>
<?php foreach($bots as $b): $botUsers=array_filter($users, fn($u)=>($u['bot_id']??'')==($b['id']??'')||($u['bot_id']??'')==($b['random_id']??'')); $vpnCount=count(array_filter($botUsers, fn($u)=>($u['is_vpn']??0)==1)); ?>
<tr>
<td class="bot-name"><?=htmlspecialchars($b['bot_username']??'')?></td>
<td><?=count($botUsers)?></td>
<td><?=$vpnCount?></td>
<td style="font-size:11px"><?=htmlspecialchars($b['last_activity']??'')?></td>
<td><button class="btn-view" onclick="alert('ID: <?=htmlspecialchars($b['id']??'')?>\nUsers: <?=count($botUsers)?>')">View</button><button class="btn-delete" onclick="if(confirm('Delete?')) location.href='?delete=<?=urlencode($b['id']??'')?>'">Delete</button></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>
</body>
</html>
