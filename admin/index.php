<?php
session_start();

/* =========================================
   ADMIN LOGIN SETTINGS
========================================= */

$ADMIN_USER = 'admin';
$ADMIN_PASS = 'CHANGE_THIS_STRONG_PASSWORD';

$DATA_DIR = dirname(__DIR__) . '/data';
$BOTS_FILE = $DATA_DIR . '/bots.json';
$USERS_FILE = $DATA_DIR . '/users.json';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0750, true);
}

/* =========================================
   HELPER FUNCTIONS
========================================= */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function readJson($file) {
    if (!is_file($file)) {
        return [];
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return [];
    }

    $raw = stream_get_contents($fp);
    fclose($fp);

    $data = json_decode($raw ?: '{}', true);

    return is_array($data) ? $data : [];
}

function saveJson($file, $data) {
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp';

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $file);
}

function botTelegramId($bot) {
    return (string)($bot['telegram_bot_id'] ?? '');
}

function botRegistrationId($bot) {
    return (string)($bot['id'] ?? $bot['random_id'] ?? '');
}

function getUserFingerprint($user) {
    $fp = $user['fingerprint'] ?? [];

    if (!is_array($fp)) {
        $fp = [];
    }

    return $fp;
}

function getBotCounts($botId, $users) {
    $counts = [
        'opens' => 0,
        'verified' => 0,
        'blocked' => 0,
        'same' => 0
    ];

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }

        if ((string)($user['bot_id'] ?? '') !== (string)$botId) {
            continue;
        }

        $type = (string)($user['type'] ?? '');
        $status = (string)($user['status'] ?? '');

        if ($type === 'page_open') {
            $counts['opens']++;
        }

        if ($status === 'verified') {
            $counts['verified']++;
        }

        if (strpos($type, 'blocked') !== false) {
            $counts['blocked']++;
        }

        if (
            !empty($user['same_device']) ||
            $type === 'same_device_allowed'
        ) {
            $counts['same']++;
        }
    }

    return $counts;
}

/* =========================================
   LOGIN / LOGOUT
========================================= */

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();

    header('Location: ./');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';

if (isset($_POST['login'])) {

    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (
        hash_equals($ADMIN_USER, $username) &&
        hash_equals($ADMIN_PASS, $password)
    ) {
        session_regenerate_id(true);

        $_SESSION['admin_logged'] = true;

        header('Location: ./');
        exit;

    } else {
        $error = 'Wrong username or password!';
    }
}

if (empty($_SESSION['admin_logged'])):
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f5f7fc;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.login-card {
    width: 100%;
    max-width: 370px;
    background: white;
    padding: 30px;
    border-radius: 18px;
    box-shadow: 0 10px 35px rgba(0,0,0,.07);
}

.login-title {
    text-align: center;
    font-size: 21px;
    font-weight: 700;
    margin-bottom: 25px;
    color: #1e293b;
}

input {
    width: 100%;
    padding: 14px;
    margin-bottom: 14px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    outline: none;
}

input:focus {
    border-color: #2563eb;
}

.login-btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 10px;
    background: #2563eb;
    color: white;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.error {
    background: #fee2e2;
    color: #dc2626;
    padding: 10px;
    border-radius: 9px;
    margin-bottom: 15px;
    text-align: center;
    font-size: 13px;
}
</style>
</head>

<body>

<div class="login-card">

    <div class="login-title">
        🛡️ Admin Login
    </div>

    <?php if ($error): ?>
        <div class="error"><?=h($error)?></div>
    <?php endif; ?>

    <form method="POST">

        <input
            type="text"
            name="username"
            placeholder="Username"
            required
        >

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
        >

        <button class="login-btn" name="login" value="1">
            Login
        </button>

    </form>

</div>

</body>
</html>

<?php
exit;
endif;

/* =========================================
   LOAD DATABASE
========================================= */

$botsRaw = readJson($BOTS_FILE);
$users = readJson($USERS_FILE);

/* =========================================
   ADMIN ACTIONS
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    $csrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403);
        exit('Invalid request token');
    }

    $action = (string)$_POST['action'];

    if ($action === 'clear_users') {
        saveJson($USERS_FILE, []);
    }

    if ($action === 'clear_bots') {
        saveJson($BOTS_FILE, []);
    }

    if ($action === 'delete_bot') {

        $deleteId = (string)($_POST['bot_id'] ?? '');

        $botsRaw = array_values(array_filter(
            $botsRaw,
            function ($bot) use ($deleteId) {

                return botTelegramId($bot) !== $deleteId;
            }
        ));

        saveJson($BOTS_FILE, $botsRaw);
    }

    header('Location: ./');
    exit;
}

/* =========================================
   UNIQUE BOTS
========================================= */

$uniqueBots = [];

foreach ($botsRaw as $bot) {

    if (!is_array($bot)) {
        continue;
    }

    $telegramId = botTelegramId($bot);

    $key = $telegramId !== ''
        ? 'telegram_' . $telegramId
        : 'registration_' . botRegistrationId($bot);

    if ($key === 'registration_') {
        continue;
    }

    if (!isset($uniqueBots[$key])) {
        $uniqueBots[$key] = $bot;
        continue;
    }

    $oldTime = strtotime(
        $uniqueBots[$key]['last_activity'] ??
        $uniqueBots[$key]['created_at'] ?? ''
    ) ?: 0;

    $newTime = strtotime(
        $bot['last_activity'] ??
        $bot['created_at'] ?? ''
    ) ?: 0;

    if ($newTime >= $oldTime) {
        $uniqueBots[$key] = $bot;
    }
}

$bots = array_values($uniqueBots);

/* =========================================
   STATISTICS
========================================= */

$totalVerified = 0;
$totalBlocked = 0;
$totalPageOpen = 0;
$totalSameDevice = 0;

foreach ($users as $user) {

    if (!is_array($user)) {
        continue;
    }

    $type = (string)($user['type'] ?? '');
    $status = (string)($user['status'] ?? '');

    if ($status === 'verified') {
        $totalVerified++;
    }

    if (strpos($type, 'blocked') !== false) {
        $totalBlocked++;
    }

    if ($type === 'page_open') {
        $totalPageOpen++;
    }

    if (
        !empty($user['same_device']) ||
        $type === 'same_device_allowed'
    ) {
        $totalSameDevice++;
    }
}

/* =========================================
   BOT FILTER
========================================= */

$viewId = (string)($_GET['view'] ?? '');

$displayUsers = $users;

if ($viewId !== '') {

    $displayUsers = array_filter(
        $users,
        function ($user) use ($viewId) {

            if (!is_array($user)) {
                return false;
            }

            return (string)($user['bot_id'] ?? '') === $viewId;
        }
    );
}

$displayUsers = array_reverse($displayUsers);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Admin Panel - Device Verification</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Inter, Arial, sans-serif;
    background: #f6f7fc;
    color: #1e293b;
}

.header {
    position: sticky;
    top: 0;
    z-index: 10;
    background: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 8px #0f172a0c;
}

.brand {
    font-size: 18px;
    font-weight: 600;
}

.logout {
    background: #e7469a;
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

.container {
    max-width: 1400px;
    margin: auto;
    padding: 25px 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border-radius: 18px;
    padding: 22px 20px;
    box-shadow: 0 2px 7px #0f172a08;
    min-width: 0;
}

.stat-title {
    font-size: 14px;
    font-weight: 700;
    color: #4775c4;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
}

.stat-sub {
    font-size: 13px;
    color: #94a3b8;
    margin-top: 4px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 2px 7px #0f172a08;
}

.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.card-title {
    font-size: 20px;
    font-weight: 800;
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    border: none;
    padding: 9px 13px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.btn-danger {
    background: #fee2e2;
    color: #dc2626;
}

.btn-view {
    background: #dbeafe;
    color: #2563eb;
}

.btn-delete {
    background: #fee2e2;
    color: #dc2626;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 650px;
}

th {
    text-align: left;
    padding: 14px 12px;
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    border-bottom: 1px solid #f1f5f9;
    white-space: nowrap;
}

td {
    padding: 15px 12px;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
}

.bot-name {
    color: #3672c9;
    font-weight: 700;
    text-decoration: none;
}

.badge {
    padding: 5px 10px;
    border-radius: 30px;
    font-size: 10px;
    font-weight: 800;
    display: inline-block;
    white-space: nowrap;
}

.badge-open {
    background: #dbeafe;
    color: #2563eb;
}

.badge-verified {
    background: #dcfce7;
    color: #16a34a;
}

.badge-fail {
    background: #fee2e2;
    color: #dc2626;
}

.badge-same {
    background: #ede9fe;
    color: #7c3aed;
}

.badge-unknown {
    background: #f1f5f9;
    color: #64748b;
}

.user-ip {
    min-width: 150px;
    white-space: normal;
    word-break: break-word;
}

.device-info {
    min-width: 180px;
    white-space: normal;
    word-break: break-word;
    font-size: 11px;
    color: #475569;
    line-height: 1.7;
}

.muted {
    color: #94a3b8;
    font-size: 11px;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #94a3b8;
}

.filter-info {
    background: #eff6ff;
    color: #2563eb;
    padding: 10px 13px;
    border-radius: 9px;
    margin-bottom: 15px;
    font-size: 12px;
}

@media(max-width:700px) {

    .header {
        padding: 15px;
    }

    .brand {
        font-size: 15px;
    }

    .container {
        padding: 18px 15px;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .stat-card {
        padding: 18px 15px;
    }

    .stat-value {
        font-size: 25px;
    }

    .card {
        padding: 17px;
        border-radius: 17px;
    }

    .card-title {
        font-size: 17px;
    }
}

@media(max-width:350px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }

    .stat-card {
        padding: 13px;
    }

    .stat-title {
        font-size: 12px;
    }
}

</style>

</head>

<body>

<div class="header">

    <div class="brand">
        🛡️ Admin Panel - Device Verification
    </div>

    <button class="logout" onclick="location.href='?logout=1'">
        Logout
    </button>

</div>

<div class="container">

    <!-- STATISTICS -->

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-title">Total Bots</div>
            <div class="stat-value"><?=count($bots)?></div>
            <div class="stat-sub">Unique registered bots</div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Page Opens</div>
            <div class="stat-value"><?=$totalPageOpen?></div>
            <div class="stat-sub">Recorded page opens</div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Verified Users</div>
            <div class="stat-value"><?=$totalVerified?></div>
            <div class="stat-sub">Final verified</div>
        </div>

        <div class="stat-card">
            <div class="stat-title">VPN / Blocked</div>
            <div class="stat-value"><?=$totalBlocked?></div>
            <div class="stat-sub">Blocked records</div>
        </div>

    </div>

    <!-- ALL BOTS -->

    <div class="card">

        <div class="card-heading">

            <div class="card-title">
                All Bots (<?=count($bots)?>)
            </div>

            <div class="actions">

                <form method="POST"
                      onsubmit="return confirm('Clear all bot registrations?')">

                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                    <input type="hidden" name="action" value="clear_bots">

                    <button class="btn btn-danger" type="submit">
                        Clear Bots
                    </button>

                </form>

                <form method="POST"
                      onsubmit="return confirm('Clear all user records?')">

                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                    <input type="hidden" name="action" value="clear_users">

                    <button class="btn btn-danger" type="submit">
                        Clear Users
                    </button>

                </form>

            </div>

        </div>

        <div class="table-wrap">

            <table>

                <tr>
                    <th>Bot Username</th>
                    <th>Telegram Bot ID</th>
                    <th>Page Opens</th>
                    <th>Verified</th>
                    <th>Same Device</th>
                    <th>Blocked</th>
                    <th>Last Activity</th>
                    <th>Action</th>
                </tr>

                <?php if (empty($bots)): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No bots registered yet.
                        </td>
                    </tr>

                <?php endif; ?>

                <?php foreach ($bots as $bot): ?>

                    <?php
                    $bid = botTelegramId($bot);
                    $registrationId = botRegistrationId($bot);

                    $counts = getBotCounts($bid, $users);

                    $lastActivity =
                        $bot['last_activity'] ??
                        $bot['created_at'] ??
                        'N/A';
                    ?>

                    <tr>

                        <td>
                            <span class="bot-name">
                                <?=h('@' . ltrim($bot['bot_username'] ?? 'Unknown', '@'))?>
                            </span>
                        </td>

                        <td>
                            <?=h($bid ?: $registrationId)?>
                        </td>

                        <td>
                            <span class="badge badge-open">
                                <?=$counts['opens']?>
                            </span>
                        </td>

                        <td>
                            <span class="badge badge-verified">
                                <?=$counts['verified']?>
                            </span>
                        </td>

                        <td>
                            <span class="badge badge-same">
                                <?=$counts['same']?>
                            </span>
                        </td>

                        <td>
                            <span class="badge badge-fail">
                                <?=$counts['blocked']?>
                            </span>
                        </td>

                        <td>
                            <?=h($lastActivity)?>
                        </td>

                        <td>

                            <?php if ($bid !== ''): ?>

                                <button
                                    class="btn btn-view"
                                    onclick="location.href='?view=<?=urlencode($bid)?>'">
                                    View
                                </button>

                                <form method="POST"
                                      style="display:inline"
                                      onsubmit="return confirm('Delete all registrations of this bot?')">

                                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                                    <input type="hidden" name="action" value="delete_bot">
                                    <input type="hidden" name="bot_id" value="<?=h($bid)?>">

                                    <button class="btn btn-delete" type="submit">
                                        Delete
                                    </button>

                                </form>

                            <?php else: ?>

                                <span class="muted">No Telegram ID</span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </table>

        </div>

    </div>

    <!-- ALL USERS -->

    <div class="card">

        <div class="card-heading">

            <div class="card-title">
                All Users Details (<?=count($displayUsers)?>)
            </div>

            <?php if ($viewId !== ''): ?>

                <button class="btn btn-view" onclick="location.href='./'">
                    Show All Users
                </button>

            <?php endif; ?>

        </div>

        <?php if ($viewId !== ''): ?>

            <div class="filter-info">
                Showing users for Telegram Bot ID: <?=h($viewId)?>
            </div>

        <?php endif; ?>

        <div class="table-wrap">

            <table>

                <tr>
                    <th>#</th>
                    <th>User ID</th>
                    <th>Username</th>
  
