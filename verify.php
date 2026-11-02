
<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$dataDir = __DIR__ . '/data';
$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
$logFile = $dataDir . '/webhook.log';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

if (!file_exists($usersFile)) {
    file_put_contents($usersFile, '[]', LOCK_EX);
}

function jsonOut($data, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function logEvent($message) {
    global $logFile;

    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function readJson($file) {
    if (!file_exists($file)) return [];

    $data = json_decode(file_get_contents($file), true);

    return is_array($data) ? $data : [];
}

function saveJson($file, $data) {
    return file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

function curlRequest($url, $post = false, $body = null) {
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ];

    if ($post) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER] = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'response' => $response,
        'http_code' => $httpCode,
        'error' => $error
    ];
}

/* Validate Telegram Web App initData */
function validateInitData($initData, $token, $expectedUser) {
    if (!$initData || !$token) return false;

    parse_str($initData, $fields);

    $receivedHash = $fields['hash'] ?? '';
    $authDate = (int)($fields['auth_date'] ?? 0);

    if (!$receivedHash || !$authDate) return false;

    if (time() - $authDate > 600 || $authDate > time() + 60) {
        return false;
    }

    unset($fields['hash']);
    ksort($fields);

    $parts = [];

    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    $checkString = implode("\n", $parts);

    $secretKey = hash_hmac(
        'sha256',
        $token,
        'WebAppData',
        true
    );

    $calculatedHash = hash_hmac(
        'sha256',
        $checkString,
        $secretKey
    );

    if (!hash_equals($calculatedHash, $receivedHash)) {
        return false;
    }

    $telegramUser = json_decode($fields['user'] ?? '{}', true);

    return isset($telegramUser['id']) &&
        (string)$telegramUser['id'] === (string)$expectedUser;
}

/* Send JSON callback to TeleBot Creator */
function sendToTBC($registration, $result) {
    $url = $registration['webhook_url'] ?? '';

    if (
        !$url ||
        !filter_var($url, FILTER_VALIDATE_URL) ||
        parse_url($url, PHP_URL_SCHEME) !== 'https'
    ) {
        logEvent('Invalid webhook URL');
        return false;
    }

    $callback = [
        'status' => in_array(
            $result['status'],
            ['success', 'info'],
            true
        ) ? 'pass' : 'error',

        'title' => $result['title'] ?? '',
        'message' => $result['message'] ?? '',
        'fp' => $result['visitorId'] ?? '',
        'bot_id' => $result['bot_id'] ?? '',
        'user_id' => $result['user_id'] ?? '',
        'hash' => $result['hash'] ?? ''
    ];

    $request = curlRequest(
        $url,
        true,
        json_encode($callback, JSON_UNESCAPED_SLASHES)
    );

    logEvent(
        'Webhook HTTP=' . $request['http_code'] .
        ' error=' . ($request['error'] ?: 'none')
    );

    return (
        $request['response'] !== false &&
        $request['http_code'] >= 200 &&
        $request['http_code'] < 300
    );
}

/* Return result and notify TBC */
function finish($registration, $result) {
    $delivered = sendToTBC($registration, $result);

    $result['callback_delivered'] = $delivered;

    jsonOut($result);
}

/* Read JSON request */
$raw = file_get_contents('php://input');
$in = json_decode($raw, true);

if (!is_array($in)) {
    jsonOut([
        'status' => 'error',
        'title' => 'Invalid Request',
        'message' => 'Invalid JSON request.'
    ], 400);
}

$botId = (string)($in['bot_id'] ?? '');
$userId = (string)($in['user_id'] ?? '');
$hash = (string)($in['bot_hash'] ?? $in['hash'] ?? '');

if (!$botId || !$userId || !$hash) {
    jsonOut([
        'status' => 'error',
        'title' => 'Invalid Request',
        'message' => 'Missing verification details.'
    ], 400);
}

/* Find registration in existing bots.json */
$bots = readJson($botsFile);
$registration = null;

foreach ($bots as $bot) {
    if (
        isset($bot['bot_hash']) &&
        hash_equals((string)$bot['bot_hash'], $hash)
    ) {
        $registration = $bot;
        break;
    }
}

if (!$registration) {
    logEvent('Registration not found: ' . $hash);

    jsonOut([
        'status' => 'error',
        'title' => 'Invalid Registration',
        'message' => 'Please reopen verification from your bot.'
    ], 403);
}

$token = $registration['bot_token'] ?? '';

if (!$token || empty($registration['webhook_url'])) {
    jsonOut([
        'status' => 'error',
        'title' => 'Registration Error',
        'message' => 'Bot registration is incomplete.'
    ], 403);
}

/* Confirm actual Telegram bot ID */
$me = curlRequest(
    'https://api.telegram.org/bot' . $token . '/getMe'
);

$meData = json_decode($me['response'] ?: '', true);

if (
    !($meData['ok'] ?? false) ||
    (string)($meData['result']['id'] ?? '') !== $botId
) {
    logEvent('Telegram bot identity mismatch');

    jsonOut([
        'status' => 'error',
        'title' => 'Invalid Bot',
        'message' => 'Bot identity validation failed.'
    ], 403);
}

/* Validate Telegram user session */
$initData = (string)($in['initData'] ?? '');

if (!validateInitData($initData, $token, $userId)) {
    logEvent('Invalid Telegram initData for user ' . $userId);

    finish($registration, [
        'status' => 'error',
        'title' => 'Session Validation Failed',
        'message' => 'Invalid or expired Telegram session.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* Read fingerprint */
$vid = (string)($in['visitorId'] ?? '');
$info = (string)($in['information'] ?? '');
$canvas = (string)($in['canvasHash'] ?? '');
$webgl = (string)($in['webglHash'] ?? '');
$screenInfo = (string)($in['screenInfo'] ?? '');
$tz = (string)($in['timezone'] ?? '');
$tzOff = (string)($in['tzOffset'] ?? '');
$hw = (string)($in['hardware'] ?? '');
$ua = (string)($in['ua'] ?? '');
$username = (string)($in['username'] ?? '');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

/* Unofficial app check */
$low = strtolower($ua);

if (
    strpos($low, 'turbotel') !== false ||
    strpos($low, 'turbo') !== false
) {
    finish($registration, [
        'status' => 'error',
        'title' => 'Unofficial Telegram App Detected',
        'message' => 'Please use the official Telegram app.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* VPN / Proxy check */
$vpnDetected = false;

if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {
    $ipUrl = 'http://ip-api.com/json/' .
        rawurlencode($ip) .
        '?fields=proxy,hosting,status';

    $ipResponse = curlRequest($ipUrl);
    $ipData = json_decode($ipResponse['response'] ?: '', true);

    if (
        ($ipData['status'] ?? '') === 'success' &&
        (
            ($ipData['proxy'] ?? false) === true ||
            ($ipData['hosting'] ?? false) === true
        )
    ) {
        $vpnDetected = true;
    }
}

if ($vpnDetected) {
    finish($registration, [
        'status' => 'error',
        'title' => 'VPN Detected',
        'message' => 'VPN/Proxy Detected. Disable VPN and try again.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* Load users */
$users = readJson($usersFile);

/* Check same user */
foreach ($users as $x) {
    if (
        (string)($x['bot_id'] ?? '') === $botId &&
        (string)($x['user_id'] ?? '') === $userId &&
        ($x['status'] ?? '') === 'verified'
    ) {
        finish($registration, [
            'status' => 'info',
            'title' => 'Already Verified',
            'message' => 'Already Verified.',
            'visitorId' => $vid,
            'bot_id' => $botId,
            'user_id' => $userId,
            'hash' => $hash
        ]);
    }
}

/* Same-device detection */
$matchCount = 0;
$reasons = [];
$matchedUser = '';

foreach ($users as $x) {
    if (
        (string)($x['bot_id'] ?? '') !== $botId ||
        ($x['status'] ?? '') !== 'verified' ||
        (string)($x['user_id'] ?? '') === $userId
    ) {
        continue;
    }

    /* Reset matches for each previous account */
    $currentMatches = 0;
    $currentReasons = [];

    $oldVid = (string)($x['visitorId'] ?? '');

    if (
        $vid && $oldVid &&
        hash_equals($oldVid, $vid) &&
        strlen($vid) > 10
    ) {
        $currentMatches++;
        $currentReasons[] = 'visitorId';
    }

    if (
        $canvas &&
        !empty($x['canvas']) &&
        hash_equals((string)$x['canvas'], $canvas) &&
        strlen($canvas) > 20
    ) {
        $currentMatches++;
        $currentReasons[] = 'canvas';
    }

    if (
        $webgl &&
        !empty($x['webgl']) &&
        $webgl !== 'no-webgl' &&
        $webgl === $x['webgl']
    ) {
        $currentMatches++;
        $currentReasons[] = 'webgl';
    }

    if (
        $screenInfo &&
        !empty($x['screenInfo']) &&
        $screenInfo === $x['screenInfo']
    ) {
        $currentMatches++;
        $currentReasons[] = 'screen';
    }

    if (
        $tz &&
        !empty($x['timezone']) &&
        $tz === $x['timezone'] &&
        $tzOff === (string)($x['tzOffset'] ?? '')
    ) {
        $currentMatches++;
        $currentReasons[] = 'timezone';
    }

    if (
        $hw &&
        !empty($x['hardware']) &&
        $hw === $x['hardware'] &&
        $hw !== '0x0'
    ) {
        $currentMatches++;
        $currentReasons[] = 'hardware';
    }

    if ($currentMatches >= 2) {
        $matchCount = $currentMatches;
        $reasons = $currentReasons;
        $matchedUser = (string)($x['user_id'] ?? '');
        break;
    }
}

/* Save user - same device is allowed */
$newUser = [
    'bot_id' => $botId,
    'user_id' => $userId,
    'username' => $username,
    'type' => $matchCount >= 2 ? 'same_device_allowed' : 'verified',
    'status' => 'verified',
    'same_device' => $matchCount >= 2,
    'matched_with' => $matchedUser,
    'match_count' => $matchCount,
    'reasons' => $reasons,
    'visitorId' => $vid,
    'information' => $info,
    'canvas' => $canvas,
    'webgl' => $webgl,
    'screenInfo' => $screenInfo,
    'timezone' => $tz,
    'tzOffset' => $tzOff,
    'hardware' => $hw,
    'ua' => $ua,
    'ip' => $ip,
    'hash' => $hash,
    'time' => date('Y-m-d H:i:s')
];

$users[] = $newUser;

if (!saveJson($usersFile, $users)) {
    finish($registration, [
        'status' => 'error',
        'title' => 'Database Error',
        'message' => 'Verification could not be saved. Please retry.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* Same device: allowed, but flagged */
if ($matchCount >= 2) {
    finish($registration, [
        'status' => 'error',
        'title' => 'Used Device Detected',
        'message' => 'Same device detected. You are still allowed to continue.',
        'visitorId' => $vid,
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* Normal success */
finish($registration, [
    'status' => 'success',
    'title' => 'Device Verified Successfully',
    'message' => 'Your device is now verified.',
    'visitorId' => $vid,
    'bot_id' => $botId,
    'user_id' => $userId,
    'hash' => $hash
]);

?>
