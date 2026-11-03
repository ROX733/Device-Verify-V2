<?php
session_start();
error_reporting(0);
ini_set('display_errors', '0');

$ADMIN_USER = 'admin';
$ADMIN_PASS = 'AdminHuBro';
$DATA_DIR = dirname(__DIR__) . '/data';
$BOTS_FILE = $DATA_DIR . '/bots.json';
$USERS_FILE = $DATA_DIR . '/users.json';
if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0750, true);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function readJsonFile($file) {
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function saveJsonFile($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) { if ($fp) fclose($fp); return false; }
    rewind($fp); $ok = ftruncate($fp, 0);
    if ($ok) { $n = fwrite($fp, $json); $ok = ($n !== false && $n === strlen($json)); fflush($fp); }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
function botId($b) { return (string)($b['telegram_bot_id'] ?? ''); }
function botName($b) { return '@' . ltrim((string)($b['bot_username'] ?? $b['bot_username_clean'] ?? 'Unknown'), '@'); }
function sameDevice($u) {
    return !empty($u['same_device']) || ($u['type'] ?? '') === 'same_device_allowed' || ($u['verification_type'] ?? '') === 'same_device';
}
function userLabel($u) {
    $status = strtolower((string)($u['status'] ?? ''));
    $type = strtolower((string)($u['type'] ?? ''));
    if (sameDevice($u)) return ['Same Device','same'];
    if ($status === 'blocked' || strpos($type, 'blocked') !== false) return ['Blocked','fail'];
    if ($status === 'verified') return ['Success','success'];
    if ($type === 'page_open') return ['Page Open','open'];
    return [$status ? ucfirst($status) : 'Unknown','unknown'];
}
function botCounts($id, $users) {
    $c = ['opens'=>0,'success'=>0,'same'=>0,'blocked'=>0];
    foreach ($users as $u) {
        if (!is_array($u) || (string)($u['bot_id'] ?? '') !== (string)$id) continue;
        $type = strtolower((string)($u['type'] ?? ''));
        $status = strtolower((string)($u['status'] ?? ''));
        if ($type === 'page_open') $c['opens']++;
        if (sameDevice($u)) $c['same']++;
        elseif ($status === 'verified') $c['success']++;
        if ($status === 'blocked' || strpos($type, 'blocked') !== false) $c['blocked']++;
    }
    return $c;
}

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (hash_equals($ADMIN_USER, $u) && hash_equals($ADMIN_PASS, $p)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: ./'); exit;
    }
    $loginError = 'Wrong username or password';
}

if (empty($_SESSION['admin_logged'])):
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#f4f6fb;font-family:Arial;color:#172033}.login{width:100%;max-width:380px;background:#fff;padding:30px;border-radius:18px;box-shadow:0 12px 40px #15213a12}h1{text-align:center;font-size:22px;margin:0 0 24px}.field{width:100%;padding:14px;margin:0 0 14px;border:1px solid #dce3ef;border-radius:10px;font-size:15px}button{width:100%;border:0;border-radius:10px;padding:14px;background:#2563eb;color:#fff;font-weight:700;font-size:15px}.error{background:#fee2e2;color:#b91c1c;padding:11px;border-radius:9px;margin-bottom:14px;text-align:center}</style></head>
<body><main class="login"><h1>🛡️ Admin Login</h1><?php if ($loginError): ?><div class="error"><?=h($loginError)?></div><?php endif; ?>
<form method="post"><input class="field" name="username" placeholder="Username" required autocomplete="username"><input class="field" type="password" name="password" placeholder="Password" required autocomplete="current-password"><button name="login" value="1">Login</button></form></main></body></html>
<?php exit; endif;

/* Admin actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request token'); }
    $b = readJsonFile($BOTS_FILE);
    $a = (string)$_POST['action'];
    if ($a === 'delete_bot') {
        $del = (string)($_POST['bot_id'] ?? '');
        $b = array_values(array_filter($b, function($row) use ($del) { return !is_array($row) || botId($row) !== $del; }));
        saveJsonFile($BOTS_FILE, $b);
    } elseif ($a === 'clear_bots') saveJsonFile($BOTS_FILE, []);
    elseif ($a === 'clear_users') saveJsonFile($USERS_FILE, []);
    header('Location: ./'); exit;
}

$botsRaw = readJsonFile($BOTS_FILE);
$users = readJsonFile($USERS_FILE);

/* Display only one row per Telegram bot ID, keeping the newest registration. */
$unique = [];
foreach ($botsRaw as $b) {
    if (!is_array($b)) continue;
    $id = botId($b);
    if ($id === '') continue;
    $oldTime = isset($unique[$id]) ? (strtotime((string)($unique[$id]['last_activity'] ?? $unique[$id]['created_at'] ?? '')) ?: 0) : -1;
    $newTime = strtotime((string)($b['last_activity'] ?? $b['created_at'] ?? '')) ?: 0;
    if (!isset($unique[$id]) || $newTime >= $oldTime) $unique[$id] = $b;
}
$bots = array_values($unique);

$totalOpens = $totalSuccess = $totalSame = $totalBlocked = 0;
foreach ($users as $u) {
    if (!is_array($u)) continue;
    $type = strtolower((string)($u['type'] ?? ''));
    $status = strtolower((string)($u['status'] ?? ''));
    if ($type === 'page_open') $totalOpens++;
    if (sameDevice($u)) $totalSame++;
    elseif ($status === 'verified') $totalSuccess++;
    if ($status === 'blocked' || strpos($type, 'blocked') !== false) $totalBlocked++;
}
$viewId = (string)($_GET['view'] ?? '');
$displayUsers = [];
foreach ($users as $key => $u) {
    if (!is_array($u)) continue;
    if ($viewId !== '' && (string)($u['bot_id'] ?? '') !== $viewId) continue;
    $u['_key'] = $key; $displayUsers[] = $u;
}
$displayUsers = array_reverse($displayUsers);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Panel - Device Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fc;color:#1e293b;font-family:Inter,Arial,sans-serif}.header{position:sticky;top:0;z-index:5;background:#fff;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px #0f172a0c}.brand{font-size:17px;font-weight:800}.logout{background:#e7469a;color:#fff;text-decoration:none;padding:10px 16px;border-radius:9px;font-size:13px;font-weight:700}.container{max-width:1450px;margin:auto;padding:24px 18px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}.stat,.card{background:#fff;border-radius:16px;box-shadow:0 2px 8px #0f172a08}.stat{padding:20px}.stat-title{font-size:13px;color:#4775c4;font-weight:700;margin-bottom:8px}.stat-value{font-size:28px;font-weight:800}.sub{font-size:11px;color:#94a3b8;margin-top:5px}.card{padding:20px;margin-bottom:20px}.heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}.title{font-size:19px;font-weight:800}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:0;border-radius:8px;padding:9px 12px;font-size:12px;font-weight:700;cursor:pointer}.danger{background:#fee2e2;color:#b91c1c}.view{background:#dbeafe;color:#1d4ed8}.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;min-width:760px;font-size:12px}th{text-align:left;color:#64748b;font-size:10px;text-transform:uppercase;padding:13px 10px;border-bottom:1px solid #edf1f7;white-space:nowrap}td{padding:13px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}.badge{display:inline-block;padding:5px 9px;border-radius:30px;font-size:10px;font-weight:800;white-space:nowrap}.b-open{background:#dbeafe;color:#2563eb}.b-success{background:#dcfce7;color:#15803d}.b-same{background:#ede9fe;color:#7c3aed}.b-fail{background:#fee2e2;color:#b91c1c}.b-unknown{background:#f1f5f9;color:#64748b}.bot{color:#2563eb;font-weight:800}.muted{color:#94a3b8;font-size:11px}.details{min-width:170px;max-width:260px;overflow-wrap:anywhere;line-height:1.6;font-size:11px;color:#475569}.empty{text-align:center;padding:28px;color:#94a3b8}.filter{background:#eff6ff;color:#1d4ed8;padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:12px}@media(max-width:700px){.header{padding:14px}.brand{font-size:14px}.container{padding:16px 12px}.stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.stat{padding:15px}.stat-value{font-size:24px}.card{padding:15px}.title{font-size:16px}}
</style></head><body>
<header class="header"><div class="brand">🛡️ Admin Panel - Device Verification</div><a class="logout" href="?logout=1">Logout</a></header>
<main class="container">
<section class="stats">
<div class="stat"><div class="stat-title">Total Bots</div><div class="stat-value"><?=count($bots)?></div><div class="sub">Unique Telegram bot IDs</div></div>
<div class="stat"><div class="stat-title">Page Opens</div><div class="stat-value"><?=$totalOpens?></div><div class="sub">Recorded page-open events</div></div>
<div class="stat"><div class="stat-title">Verification Success</div><div class="stat-value"><?=$totalSuccess?></div><div class="sub">Successful verifications only</div></div>
<div class="stat"><div class="stat-title">Same Device</div><div class="stat-value"><?=$totalSame?></div><div class="sub">Counted separately from success</div></div>
</section>

<section class="card"><div class="heading"><div class="title">All Bots (<?=count($bots)?>)</div><div class="actions">
<form method="post" onsubmit="return confirm('Clear all bot registrations? Back up bots.json first.')"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="clear_bots"><button class="btn danger">Clear Bots</button></form>
<form method="post" onsubmit="return confirm('Clear all user records? Back up users.json first.')"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="clear_users"><button class="btn danger">Clear Users</button></form>
</div></div><div class="table-wrap"><table><thead><tr><th>Bot Username</th><th>Telegram Bot ID</th><th>Page Opens</th><th>Success</th><th>Same Device</th><th>Blocked</th><th>Last Activity</th><th>Action</th></tr></thead><tbody>
<?php if (!$bots): ?><tr><td colspan="8" class="empty">No bots registered yet.</td></tr><?php endif; ?>
<?php foreach ($bots as $b): $id=botId($b); $c=botCounts($id,$users); $last=$b['last_activity']??$b['created_at']??'N/A'; ?>
<tr><td class="bot"><?=h(botName($b))?></td><td><?=h($id)?></td><td><span class="badge b-open"><?=$c['opens']?></span></td><td><span class="badge b-success"><?=$c['success']?></span></td><td><span class="badge b-same"><?=$c['same']?></span></td><td><span class="badge b-fail"><?=$c['blocked']?></span></td><td><?=h($last)?></td><td><div class="actions"><a class="btn view" style="text-decoration:none" href="?view=<?=urlencode($id)?>">View</a><form method="post" onsubmit="return confirm('Delete all registrations for this bot?')"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_bot"><input type="hidden" name="bot_id" value="<?=h($id)?>"><button class="btn danger">Delete</button></form></div></td></tr>
<?php endforeach; ?></tbody></table></div></section>

<section class="card"><div class="heading"><div class="title">All Users Details (<?=count($displayUsers)?>)</div><?php if($viewId!==''): ?><a class="btn view" style="text-decoration:none" href="./">Show All Users</a><?php endif; ?></div>
<?php if($viewId!==''): ?><div class="filter">Showing users for Telegram Bot ID: <?=h($viewId)?></div><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>#</th><th>User ID</th><th>Username</th><th>Bot ID</th><th>Verification</th><th>IP Address</th><th>Network</th><th>Device Details</th><th>Verified At</th></tr></thead><tbody>
<?php if(!$displayUsers): ?><tr><td colspan="9" class="empty">No user records found.</td></tr><?php endif; ?>
<?php foreach($displayUsers as $i=>$u): [$label,$kind]=userLabel($u); $fp=is_array($u['fingerprint']??null)?$u['fingerprint']:[]; $ip=(string)($u['ip']??''); $org=(string)($u['org']??$u['organization']??''); $network=(string)($u['network_check']??'not recorded'); $parts=[]; foreach(['screen_info'=>'Screen','timezone'=>'Timezone','hardware'=>'Hardware','visitor_id'=>'Visitor ID','canvas_hash'=>'Canvas','webgl_hash'=>'WebGL'] as $k=>$caption){if(!empty($fp[$k]))$parts[]='<b>'.$caption.':</b> '.h($fp[$k]);} $badge=$kind==='same'?'b-same':($kind==='success'?'b-success':($kind==='fail'?'b-fail':($kind==='open'?'b-open':'b-unknown'))); ?>
<tr><td><?=$i+1?></td><td><?=h($u['user_id']??'—')?></td><td><?=!empty($u['username'])?'@'.h(ltrim((string)$u['username'],'@')):'—'?></td><td><?=h($u['bot_id']??'—')?></td><td><span class="badge <?=$badge?>"><?=h($label)?></span></td><td class="details"><?=h($ip?:'Not recorded')?><?php if($org!==''): ?><br><span class="muted"><?=h($org)?></span><?php endif; ?></td><td><?=h($network)?></td><td class="details"><?=$parts?implode('<br>',$parts):'<span class="muted">No fingerprint details</span>'?></td><td><?=h($u['verified_at']??$u['created_at']??'—')?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted" style="margin-top:12px">IP, organization and page-open records are shown only when the backend has saved those fields/events. Older records cannot display data that was never stored.</p>
</section></main></body></html>
