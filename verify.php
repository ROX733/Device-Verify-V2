
<?php

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

/* --------------------------------
   JSON RESPONSE
-------------------------------- */

function output($data, $code = 200) {
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* --------------------------------
   LOGGING
-------------------------------- */

function writeLog($message) {
    global $logFile;

    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/* --------------------------------
   JSON DATABASE
-------------------------------- */

function readDatabase($file) {

    if (!file_exists($file)) {
        return [];
    }

    $fp = fopen($file, 'c+');

    if (!$fp) {
        return [];
    }

    flock($fp, LOCK_SH);

    $content = stream_get_contents($fp);
    $data = json_decode($content, true);

    flock($fp, LOCK_UN);
    fclose($fp);

    return is_array($data) ? $data : [];
}

function saveDatabase($file, $data) {

    $fp = fopen($file, 'c+');

    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_EX);

    ftruncate($fp, 0);
    rewind($fp);

    $written = fwrite(
        $fp,
        json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    );

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written !== false;
}

/* --------------------------------
   HTTP REQUEST
-------------------------------- */

function requestUrl($url, $postData = null) {

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ];

    if ($postData !== null) {

        $options[CURLOPT_POST] = true;

        $options[CURLOPT_POSTFIELDS] = json_encode(
            $postData,
            JSON_UNESCAPED_SLASHES
        );

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

/* --------------------------------
   TELEGRAM WEB APP VALIDATION
-------------------------------- */

function validateTelegram($initData, $token, $expectedUser) {

    if (!$initData || !$token) {
        return false;
    }

    parse_str($initData, $fields);

    $receivedHash = $fields['hash'] ?? '';
    $authDate = (int)($fields['auth_date'] ?? 0);

    if (!$receivedHash || !$authDate) {
        return false;
    }

    if (
        $authDate > time() + 60 ||
        time() - $authDate > 600
    ) {
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

    $telegramUser = json_decode(
        $fields['user'] ?? '{}',
        true
    );

    if (!is_array($telegramUser)) {
        return false;
    }

    return (
        isset($telegramUser['id']) &&
        (string)$telegramUser['id'] === (string)$expectedUser
    );
}

/* --------------------------------
   TBC WEBHOOK CALLBACK
-------------------------------- */

function sendCallback($registration, $result) {

    $url = $registration['webhook_url'] ?? '';

    if (
        !$url ||
        !filter_var($url, FILTER_VALIDATE_URL) ||
        parse_url($url, PHP_URL_SCHEME) !== 'https'
    ) {
        writeLog('Invalid callback URL');
        return false;
    }

    $sameDevice = !empty($result['same_device']);

    $callback = [
        'status' => (
            $result['status'] === 'success' ||
            $result['status'] === 'info' ||
            $sameDevice
        ) ? 'pass' : 'error',

        'title' => $result['title'] ?? '',
        'message' => $result['message'] ?? '',

        'fp' => $result['visitorId'] ?? '',

        'bot_id' => $result['bot_id'] ?? '',
        'user_id' => $result['user_id'] ?? '',
        'hash' => $result['hash'] ?? '',

        'same_device' => $sameDevice
    ];

    $request = requestUrl($url, $callback);

    writeLog(
        'Callback user=' . ($result['user_id'] ?? '') .
        ' HTTP=' . $request['http_code'] .
        ' error=' . ($request['error'] ?: 'none')
    );

    return (
        $request['response'] !== false &&
        $request['http_code'] >= 200 &&
        $request['http_code'] < 300
    );
}

/* --------------------------------
   FINAL RESULT
-------------------------------- */

function finishVerification($registration, $result) {

    $result['callback_delivered'] = sendCallback(
        $registration,
        $result
    );

    output($result);
}

/* --------------------------------
   READ REQUEST
-------------------------------- */

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    output([
        'status' => 'error',
        'title' => 'Invalid Request',
        'message' => 'Invalid JSON request.'
    ], 400);
}

$botId = trim((string)($data['bot_id'] ?? ''));
$userId = trim((string)($data['user_id'] ?? ''));

$hash = trim((string)(
    $data['bot_hash'] ?? $data['hash'] ?? ''
));

$initData = (string)($data['initData'] ?? '');

if (!$botId || !$userId || !$hash) {

    output([
        'status' => 'error',
        'title' => 'Missing Details',
        'message' => 'Required verification details are missing.'
    ], 400);
}

/* --------------------------------
   FIND EXACT REGISTRATION
-------------------------------- */

$bots = readDatabase($botsFile);

$registration = null;

foreach ($bots as $bot) {

    $savedHash = (string)($bot['bot_hash'] ?? '');
    $savedBotId = (string)($bot['telegram_bot_id'] ?? '');
    $savedUserId = (string)($bot['telegram_user_id'] ?? '');

    if (
        $savedHash !== '' &&
        $savedBotId !== '' &&
        $savedUserId !== '' &&
        hash_equals($savedHash, $hash) &&
        $savedBotId === $botId &&
        $savedUserId === $userId &&
        ($bot['status'] ?? 'active') === 'active'
    ) {
        $registration = $bot;
        break;
    }
}

if (!$registration) {

    writeLog('Registration mismatch user=' . $userId);

    output([
        'status' => 'error',
        'title' => 'Invalid Registration',
        'message' => 'Verification link expired. Reopen verification from your bot.'
    ], 403);
}

/* --------------------------------
   BOT TOKEN
-------------------------------- */

$token = $registration['bot_token'] ?? '';

if (!$token) {
    output([
        'status' => 'error',
        'title' => 'Bot Configuration Error',
        'message' => 'Bot token is missing.'
    ], 500);
}

/* --------------------------------
   CONFIRM BOT ID WITH TELEGRAM
-------------------------------- */

$me = requestUrl(
    'https://api.telegram.org/bot' . $token . '/getMe'
);

$meData = json_decode(
    $me['response'] ?: '',
    true
);

if (
    !($meData['ok'] ?? false) ||
    (string)($meData['result']['id'] ?? '') !== $botId
) {
    output([
        'status' => 'error',
        'title' => 'Invalid Bot',
        'message' => 'Telegram bot identity verification failed.'
    ], 403);
}

/* --------------------------------
   VERIFY TELEGRAM SESSION
-------------------------------- */

if (!validateTelegram($initData, $token, $userId)) {

    finishVerification($registration, [
        'status' => 'error',
        'title' => 'Session Validation Failed',
        'message' => 'Invalid or expired Telegram session.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* --------------------------------
   DEVICE INFORMATION
-------------------------------- */

$visitorId = (string)($data['visitorId'] ?? '');
$canvas = (string)($data['canvasHash'] ?? '');
$webgl = (string)($data['webglHash'] ?? '');

$screenInfo = (string)($data['screenInfo'] ?? '');
$timezone = (string)($data['timezone'] ?? '');
$tzOffset = (string)($data['tzOffset'] ?? '');

$hardware = (string)($data['hardware'] ?? '');
$ua = (string)($data['ua'] ?? '');

$username = (string)($data['username'] ?? '');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

/* --------------------------------
   UNOFFICIAL APP CHECK
-------------------------------- */

$lowerUA = strtolower($ua);

if (
    strpos($lowerUA, 'turbotel') !== false ||
    strpos($lowerUA, 'turbo') !== false
) {

    finishVerification($registration, [
        'status' => 'error',
        'title' => 'Unofficial Telegram App Detected',
        'message' => 'Please use the official Telegram app.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* --------------------------------
   VPN / PROXY CHECK
-------------------------------- */

$vpnDetected = false;

if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {

    $ipUrl = 'http://ip-api.com/json/' .
        rawurlencode($ip) .
        '?fields=status,proxy,hosting';

    $ipResponse = requestUrl($ipUrl);

    $ipData = json_decode(
        $ipResponse['response'] ?: '',
        true
    );

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

    finishVerification($registration, [
        'status' => 'error',
        'title' => 'VPN Detected',
        'message' => 'VPN/Proxy detected. Disable VPN and try again.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* --------------------------------
   READ USERS DATABASE
-------------------------------- */

$users = readDatabase($usersFile);

/* --------------------------------
   ALREADY VERIFIED USER
-------------------------------- */

foreach ($users as $existing) {

    if (
        (string)($existing['bot_id'] ?? '') === $botId &&
        (string)($existing['user_id'] ?? '') === $userId &&
        ($existing['status'] ?? '') === 'verified'
    ) {

        finishVerification($registration, [
            'status' => 'info',
            'title' => 'Already Verified',
            'message' => 'Already Verified.',
            'visitorId' => $visitorId,
            'bot_id' => $botId,
            'user_id' => $userId,
            'hash' => $hash
        ]);
    }
}

/* --------------------------------
   SAME DEVICE DETECTION
-------------------------------- */

$matchCount = 0;
$matchReasons = [];
$matchedUser = '';

foreach ($users as $existing) {

    if (
        (string)($existing['bot_id'] ?? '') !== $botId ||
        ($existing['status'] ?? '') !== 'verified' ||
        (string)($existing['user_id'] ?? '') === $userId
    ) {
        continue;
    }

    $currentCount = 0;
    $currentReasons = [];

    if (
        $visitorId &&
        !empty($existing['visitorId']) &&
        hash_equals(
            (string)$existing['visitorId'],
            $visitorId
        ) &&
        strlen($visitorId) > 10
    ) {
        $currentCount++;
        $currentReasons[] = 'visitorId';
    }

    if (
        $canvas &&
        !empty($existing['canvas']) &&
        hash_equals(
            (string)$existing['canvas'],
            $canvas
        ) &&
        strlen($canvas) > 20
    ) {
        $currentCount++;
        $currentReasons[] = 'canvas';
    }

    if (
        $webgl &&
        $webgl !== 'no-webgl' &&
        !empty($existing['webgl']) &&
        $webgl === $existing['webgl']
    ) {
        $currentCount++;
        $currentReasons[] = 'webgl';
    }

    if (
        $screenInfo &&
        !empty($existing['screenInfo']) &&
        $screenInfo === $existing['screenInfo']
    ) {
        $currentCount++;
        $currentReasons[] = 'screen';
    }

    if (
        $timezone &&
        !empty($existing['timezone']) &&
        $timezone === $existing['timezone'] &&
        $tzOffset === (string)($existing['tzOffset'] ?? '')
    ) {
        $currentCount++;
        $currentReasons[] = 'timezone';
    }

    if (
        $hardware &&
        !empty($existing['hardware']) &&
        $hardware === $existing['hardware'] &&
        $hardware !== '0x0'
    ) {
        $currentCount++;
        $currentReasons[] = 'hardware';
    }

    if ($currentCount >= 2) {

        $matchCount = $currentCount;
        $matchReasons = $currentReasons;
        $matchedUser = (string)$existing['user_id'];

        break;
    }
}

/* --------------------------------
   SAVE VERIFIED USER
-------------------------------- */

$newUser = [
    'bot_id' => $botId,
    'user_id' => $userId,
    'username' => $username,

    'status' => 'verified',

    'type' => $matchCount >= 2
        ? 'same_device_allowed'
        : 'verified',

    'same_device' => $matchCount >= 2,

    'matched_with' => $matchedUser,
    'match_count' => $matchCount,
    'reasons' => $matchReasons,

    'visitorId' => $visitorId,
    'canvas' => $canvas,
    'webgl' => $webgl,

    'screenInfo' => $screenInfo,
    'timezone' => $timezone,
    'tzOffset' => $tzOffset,
    'hardware' => $hardware,

    'ua' => $ua,
    'ip' => $ip,

    'time' => date('Y-m-d H:i:s')
];

$users[] = $newUser;

if (!saveDatabase($usersFile, $users)) {

    finishVerification($registration, [
        'status' => 'error',
        'title' => 'Database Error',
        'message' => 'Unable to save verification. Please retry.',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* --------------------------------
   SAME DEVICE - ALLOW
-------------------------------- */

if ($matchCount >= 2) {

    finishVerification($registration, [
        'status' => 'success',
        'title' => 'Used Device Detected',
        'message' => 'Same device detected. You are still allowed to continue.',
        'same_device' => true,

        'visitorId' => $visitorId,

        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash
    ]);
}

/* --------------------------------
   NORMAL SUCCESS
-------------------------------- */

finishVerification($registration, [
    'status' => 'success',
    'title' => 'Device Verified Successfully',
    'message' => 'Your device is now verified.',

    'same_device' => false,

    'visitorId' => $visitorId,

    'bot_id' => $botId,
    'user_id' => $userId,
    'hash' => $hash
]);

?>
