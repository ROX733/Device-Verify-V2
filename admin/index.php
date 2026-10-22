<?php
// FINAL Admin - Page Open + Verified Sab Dikhega
session_start();
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'AdminHuBro';

if(isset($_GET['logout'])){ session_destroy(); header("Location: ./"); exit; }
if(isset($_POST['login'])){
    if(($_POST['username']??'')===$ADMIN_USER && ($_POST['password']??'')===$ADMIN_PASS){
        $_SESSION['admin_logged']=true; header("Location: ./"); exit;
    }else $error="Wrong username or password!";
}
if(!isset($_SESSION['admin_logged'])){
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Inter,sans-serif;background:#f8f9fe;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:100%;max-width:360px;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,0.08);padding:28px}.title{font-size:18px;font-weight:700;text-align:center;margin-bottom:20px}input{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;margin-bottom:12px;outline:none}.btn{width:100%;padding:11px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer}.error{background:#fef2f2;color:#ef4444;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;text-align:center}</style>
</head><body><div class="card"><div class="title">🔒 Admin Login</div><?php if(isset($error)):?><div class="error"><?=$error?></div><?php endif;?><form method="POST"><input type="text" name="username" placeholder="Username" required><input type="password" name="password" placeholder="Password" required><button class="btn" name="login" type="submit">Login</button></form></div></body></html>
<?php exit; }

$dataFile = __DIR__ . '/../data/bots.json';
$usersFile = __DIR__ . '/../data/users.json';
$bots = file_exists($dataFile)? (json_decode(file_get_contents($dataFile),true)?:[]) : [];
$users = file_exists($usersFile)? (json_decode(file_get_contents($usersFile),true)?:[]) : [];
$search = $_GET['search'] ?? '';
if($search){ $bots = array_filter($bots, fn($b)=>stripos($b['bot_username']??'', $search)!==false); }

$totalUniqueBots = count($bots);
$totalPageOpen = count(array_filter($users, fn($u)=>($u['type']??'')=='page_open' || ($u['is_page_open']??0)==1));
$totalVerified = count(array_filter($users, fn($u)=>($u['is_page_open']??0)!=1 && ($u['type']??'')!='page_open' && ($u['type']??'')!='bot_not_found'));
$totalVpn = count(array_filter($users, fn($u)=>($u['is_vpn']??0)==1));
$mostActive=null;$max=0;foreach($bots as $b){$c=$b['total_users']??0;if($c>$max){$max=$c;$mostActive=$b;}}

if(isset($_GET['delete'])){ $del=$_GET['delete']; $botsAll=json_decode(file_get_contents($dataFile),true)?:[]; $botsAll=array_filter($botsAll, fn($b)=>($b['id']??'')!=$del && ($b['random_id']??'')!=$del); file_put_contents($dataFile, json_encode(array_values($botsAll), JSON_PRETTY_PRINT)); header("Location: ./"); exit; }
if(isset($_GET['clear_users'])){ file_put_contents($usersFile, json_encode([])); header("Location: ./"); exit; }
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#f8f9fe;color:#1e293b}.header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.06);position:sticky;top:0;z-index:10}.btn-logout{background:#ec4899;color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer}.container{padding:20px;max-width:1200px;margin:0 auto}.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);padding:16px;margin-bottom:16px}.stats-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:20px}.stat-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}.stat-title{font-size:12px;font-weight:600;color:#2563eb;margin-bottom:6px}.stat-value{font-size:22px;font-weight:800}.stat-sub{font-size:11px;color:#94a3b8}table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;padding:10px;font-size:11px;color:#64748b;text-transform:uppercase;border-bottom:1px solid #f1f5f9}td{padding:10px;border-bottom:1px solid #f8fafc;white-space:nowrap}.badge{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}.badge-open{background:#dbeafe;color:#2563eb}.badge-verified{background:#dcfce7;color:#16a34a}.badge-fail{background:#fee2e2;color:#dc2626}.btn-view{background:#3b82f6;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:11px;cursor:pointer}.btn-delete{background:#ec4899;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:11px;cursor:pointer}</style>
</head><body>
<div class="header"><div>🛡️ Admin Panel</div><div><button class="btn-logout" onclick="location.href='?logout=1'">Logout</button></div></div>
<div class="container">
<div class="stats-grid">
<div class="stat-card"><div class="stat-title">Total Bots</div><div class="stat-value"><?=$totalUniqueBots?></div><div class="stat-sub">Registered</div></div>
<div class="stat-card"><div class="stat-title">Page Opens</div><div class="stat-value"><?=$totalPageOpen?></div><div class="stat-sub">Webpage khula - Tera demand</div></div>
<div class="stat-card"><div class="stat-title">Verified Users</div><div class="stat-value"><?=$totalVerified?></div><div class="stat-sub">Final verified</div></div>
<div class="stat-card"><div class="stat-title">VPN Users</div><div class="stat-value"><?=$totalVpn?></div><div class="stat-sub">Across all bots</div></div>
</div>

<div class="card">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><div style="font-weight:700">Bot Details</div><button onclick="if(confirm('Clear all users?')) location.href='?clear_users=1'" style="background:#fee2e2;color:#dc2626;border:none;padding:5px 10px;border-radius:6px;font-size:11px;cursor:pointer">Clear Users</button></div>
<table><tr><th>Bot</th><th>ID</th><th>Page Opens</th><th>Verified</th><th>Last Activity</th><th>Action</th></tr>
<?php foreach($bots as $b): 
$bid=$b['id']??$b['random_id']??'';
$botUsers=array_filter($users, fn($u)=>($u['bot_id']??'')==$bid || ($u['bot_id']??'')==($b['random_id']??''));
$pageOpens=count(array_filter($botUsers, fn($u)=>($u['type']??'')=='page_open' || ($u['is_page_open']??0)==1));
$verified=count(array_filter($botUsers, fn($u)=>($u['is_page_open']??0)!=1 && ($u['type']??'')!='page_open'));
?>
<tr><td style="color:#2563eb;font-weight:600"><?=htmlspecialchars($b['bot_username']??'')?></td><td style="font-size:11px"><?=htmlspecialchars($bid)?></td><td><span class="badge badge-open"><?=$pageOpens?></span></td><td><span class="badge badge-verified"><?=$verified?></span></td><td style="font-size:11px"><?=htmlspecialchars($b['last_activity']??$b['created_at']??'')?></td><td><button class="btn-view" onclick="location.href='?view=<?=urlencode($bid)?>'">View</button><button class="btn-delete" onclick="if(confirm('Delete?')) location.href='?delete=<?=urlencode($bid)?>'">Delete</button></td></tr>
<?php endforeach; ?></table>
</div>

<?php if(isset($_GET['view'])): $vid=$_GET['view']; $vusers=array_filter($users, fn($u)=>($u['bot_id']??'')==$vid); ?>
<div class="card">
<div style="font-weight:700;margin-bottom:12px">Users for ID: <?=htmlspecialchars($vid)?> (<?=count($vusers)?>)</div>
<table><tr><th>User ID</th><th>Type</th><th>IP</th><th>UA</th><th>Time</th></tr>
<?php foreach(array_reverse($vusers) as $u): ?>
<tr><td><?=htmlspecialchars($u['user_id']??'')?></td><td><?php $t=$u['type']??($u['is_page_open']??0?'page_open':'verified'); if($t=='page_open') echo '<span class="badge badge-open">PAGE OPEN</span>'; elseif($t=='bot_not_found') echo '<span class="badge badge-fail">BOT NOT FOUND</span>'; else echo '<span class="badge badge-verified">VERIFIED</span>'; ?></td><td style="font-size:11px"><?=htmlspecialchars($u['ip']??'')?></td><td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars(substr($u['user_agent']??$u['ua']??'',0,80))?></td><td style="font-size:11px"><?=htmlspecialchars($u['verified_at']??$u['time']??'')?></td></tr>
<?php endforeach; ?></table>
</div>
<?php endif; ?>

</div></body></html>
