
<?php
/*
 * SECURE DEVICE VERIFICATION
 * - Telegram WebApp initData HMAC validation
 * - Active bot registration validation
 * - Bot token verification with Telegram
 * - Atomic users.json read/write locking
 * - Same-device detection (allowed)
 * - Callback delivery to registered webhook
 */

error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST method required']);
    exit;
}

header('Access-Control-Allow-Origin: *');

$DATA_DIR = __DIR__ . '/data';
$BOTS_FILE = $DATA_DIR . '/bots.json';
$USERS_FILE = $DATA_DIR . '/users.json';
$LOG_FILE = $DATA_DIR . '/webhook.log';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0750, true);
}

if (!file_exists($USERS_FILE)) {
    @file_put_contents($USERS_FILE, '{}', LOCK_EX);
}

function output($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function writeLog($message) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function requestUrl($url, $postData = null, $timeout = 8) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => null];
    }

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP
    ];

    if ($postData !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        $options[CURLOPT_POSTFIELDS] = json_encode($postData);
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'ok' => ($body !== false && $code >= 200 && $code < 300),
        'code' => $code,
        'body' => $body,
        'error' => $error
    ];
}

function readJsonFile($file) {
    if (!file_exists($file)) {
        return [];
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/*
 * Telegram WebApp initData validation.
 */
function validateTelegram($initData, $token, $expectedUser) {
    if (!$initData || !$token || !$expectedUser) {
        return false;
    }

    $params = [];
    parse_str($initData, $params);

    if (empty($params['hash']) || empty($params['auth_date']) || empty($params['user'])) {
        return false;
    }

    $receivedHash = strtolower($params['hash']);
    unset($params['hash']);

    if (!preg_match('/^[a-f0-9]{64}$/', $receivedHash)) {
        return false;
    }

    $authDate = (int) $params['auth_date'];
    $now = time();

    if ($authDate <= 0 || $authDate > ($now + 60) || ($now - $authDate) > 600) {
        return false;
    }

    ksort($params);

    $dataCheckString = [];

    foreach ($params as $key => $value) {
        if (is_array($value)) {
            return false;
        }

        $dataCheckString[] = $key . '=' . $value;
    }

    $dataCheckString = implode("\n", $dataCheckString);

    $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
    $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    if (!hash_equals($calculatedHash, $receivedHash)) {
        return false;
    }

    $user = json_decode($params['user'], true);

    if (!is_array($user) || !isset($user['id'])) {
        return false;
    }

    return ((string) $user['id'] === (string) $expectedUser);
}

/*
 * Webhook callback.
 * Keep existing callback fields compatible with the bot.
 */
function sendCallback($registration, $payload) {
    $webhook = trim($registration['webhook_url'] ?? '');

    if (!$webhook || !filter_var($webhook, FILTER_VALIDATE_URL)) {
        writeLog('Callback rejected: invalid webhook URL');
        return false;
    }

    $parts = parse_url($webhook);

    if (
        !$parts ||
        strtolower($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host'])
    ) {
        writeLog('Callback rejected: HTTPS required');
        return false;
    }

    $result = requestUrl($webhook, $payload, 8);

    if (!$result['ok']) {
        writeLog('Callback failed. HTTP=' . $result['code'] . ' Error=' . ($result['error'] ?? ''));
        return false;
    }

    return true;
}

function finishVerification($registration, $response, $callbackData) {
    $delivered = sendCallback($registration, $callbackData);
    $response['callback_delivered'] = $delivered;

    if (!$delivered) {
        writeLog('Callback delivery failed for user ' . ($callbackData['user_id'] ?? 'unknown'));
    }

    output($response);
}

/*
 * Atomic read-modify-write for users.json.
 * Lock remains held during duplicate check and save.
 */
function withUsersLock($file, $callback) {
    $fp = @fopen($file, 'c+');

    if (!$fp) {
        return ['ok' => false];
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['ok' => false];
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $users = json_decode($raw ?: '{}', true);

    if (!is_array($users)) {
        $users = [];
    }

    $result = $callback($users);

    if (!empty($result['write'])) {
        $json = json_encode(
            $users,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false];
        }

        rewind($fp);
        if (!ftruncate($fp, 0)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false];
        }

        $written = fwrite($fp, $json);

        if ($written === false || $written !== strlen($json)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false];
        }

        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return ['ok' => true, 'result' => $result];
}

/* ---------------- REQUEST ---------------- */

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    output(['status' => 'error', 'message' => 'Invalid JSON request'], 400);
}

$botId = trim((string) ($input['botId'] ?? ''));
$userId = trim((string) ($input['userId'] ?? ''));
$hash = trim((string) ($input['bot_hash'] ?? $input['hash'] ?? ''));
$initData = (string) ($input['initData'] ?? '');

if (
    $botId === '' ||
    $userId === '' ||
    $hash === '' ||
    $initData === '' ||
    !ctype_digit($botId) ||
    !ctype_digit($userId)
) {
    output(['status' => 'error', 'message' => 'Missing or invalid verification details'], 400);
}

if (strlen($hash) > 256 || strlen($initData) > 10000) {
    output(['status' => 'error', 'message' => 'Request too large'], 400);
}

/* ---------------- REGISTRATION ---------------- */

$bots = readJsonFile($BOTS_FILE);
$registration = null;

foreach ($bots as $bot) {
    if (
        is_array($bot) &&
        hash_equals((string) ($bot['bot_hash'] ?? ''), $hash) &&
        (string) ($bot['telegram_bot_id'] ?? '') === $botId &&
        (string) ($bot['telegram_user_id'] ?? '') === $userId &&
        strtolower((string) ($bot['status'] ?? '')) === 'active'
    ) {
        $registration = $bot;
        break;
    }
}

if (!$registration) {
    output(['status' => 'error', 'message' => 'Invalid or inactive registration'], 403);
}

$token = trim((string) ($registration['bot_token'] ?? ''));

if ($token === '' || strpos($token, ':') === false) {
    output(['status' => 'error', 'message' => 'Bot registration token is invalid'], 403);
}

/* ---------------- TELEGRAM BOT CHECK ---------------- */

$meResult = requestUrl('https://api.telegram.org/bot' . $token . '/getMe', null, 6);

if (!$meResult['ok']) {
    output(['status' => 'error', 'message' => 'Unable to validate Telegram bot'], 503);
}

$me = json_decode($meResult['body'], true);

if (
    empty($me['ok']) ||
    (string) ($me['result']['id'] ?? '') !== $botId
) {
    output(['status' => 'error', 'message' => 'Bot identity validation failed'], 403);
}

/* ---------------- TELEGRAM WEBAPP VALIDATION ---------------- */

if (!validateTelegram($initData, $token, $userId)) {
    writeLog('Telegram initData validation failed for user ' . $userId);

    $callback = [
        'status' => 'error',
        'title' => 'Verification Failed',
        'message' => 'Telegram session validation failed',
        'fp' => '',
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash,
        'same_device' => false
    ];

    finishVerification($registration, [
        'status' => 'error',
        'message' => 'Telegram session validation failed'
    ], $callback);
}

/* ---------------- DEVICE DATA ---------------- */

$visitorId = substr((string) ($input['visitorId'] ?? ''), 0, 256);
$canvasHash = substr((string) ($input['canvasHash'] ?? ''), 0, 256);
$webglHash = substr((string) ($input['webglHash'] ?? ''), 0, 256);
$screenInfo = substr((string) ($input['screenInfo'] ?? ''), 0, 100);
$timezone = substr((string) ($input['timezone'] ?? ''), 0, 100);
$tzOffset = substr((string) ($input['tzOffset'] ?? ''), 0, 20);
$hardware = substr((string) ($input['hardware'] ?? ''), 0, 100);
$ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$username = substr((string) ($input['username'] ?? ''), 0, 100);

$fp = [
    'visitor_id' => $visitorId,
    'canvas_hash' => $canvasHash,
    'webgl_hash' => $webglHash,
    'screen_info' => $screenInfo,
    'timezone' => $timezone,
    'tz_offset' => $tzOffset,
    'hardware' => $hardware
];

/* ---------------- UNOFFICIAL APP CHECK ---------------- */

$uaLower = strtolower($ua);

if (strpos($uaLower, 'turbotel') !== false || strpos($uaLower, 'turbo') !== false) {
    $callback = [
        'status' => 'error',
        'title' => 'Unsupported App',
        'message' => 'Please open verification in the official Telegram app.',
        'fp' => $visitorId,
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash,
        'same_device' => false
    ];

    finishVerification($registration, [
        'status' => 'error',
        'message' => 'Unsupported Telegram app'
    ], $callback);
}

/* ---------------- NETWORK CHECK ---------------- */

/*
 * ip-api free endpoint uses HTTP.
 * Lookup failure is logged and does not falsely claim that VPN was checked.
 * This is a signal, not proof of a user's identity.
 */
$networkCheck = 'unavailable';

if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    $networkResult = requestUrl(
        'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,proxy,hosting',
        null,
        3
    );

    if ($networkResult['ok']) {
        $networkData = json_decode($networkResult['body'], true);

        if (($networkData['status'] ?? '') === 'success') {
            $networkCheck = 'checked';

            if (!empty($networkData['proxy']) || !empty($networkData['hosting'])) {
                $callback = [
                    'status' => 'error',
                    'title' => 'Network Check Failed',
                    'message' => 'Proxy or hosting network detected.',
                    'fp' => $visitorId,
                    'bot_id' => $botId,
                    'user_id' => $userId,
                    'hash' => $hash,
                    'same_device' => false
                ];

                finishVerification($registration, [
                    'status' => 'error',
                    'message' => 'Proxy or hosting network detected'
                ], $callback);
            }
        }
    }
}

/* ---------------- ATOMIC DEVICE VERIFICATION ---------------- */

$locked = withUsersLock($USERS_FILE, function (&$users) use (
    $botId,
    $userId,
    $visitorId,
    $canvasHash,
    $webglHash,
    $screenInfo,
    $timezone,
    $tzOffset,
    $hardware,
    $ip,
    $ua,
    $username,
    $fp,
    $networkCheck
) {
    $currentKey = $botId . '_' . $userId;

    /*
     * Already verified: do not create another record.
     * Return callback again so a previous callback failure can recover.
     */
    if (
        isset($users[$currentKey]) &&
        is_array($users[$currentKey]) &&
        ($users[$currentKey]['status'] ?? '') === 'verified'
    ) {
        return [
            'write' => false,
            'kind' => 'already'
        ];
    }

    $signals = [
        'visitor_id' => $visitorId,
        'canvas_hash' => $canvasHash,
        'webgl_hash' => $webglHash,
        'screen_info' => $screenInfo,
        'timezone' => $timezone,
        'hardware' => $hardware
    ];

    $matches = [];

    foreach ($users as $key => $old) {
        if (!is_array($old)) {
            continue;
        }

        if ((string) ($old['bot_id'] ?? '') !== (string) $botId) {
            continue;
        }

        if ((string) ($old['user_id'] ?? '') === (string) $userId) {
            continue;
        }

        $oldFp = $old['fingerprint'] ?? [];
        if (!is_array($oldFp)) {
            continue;
        }

        $matchCount = 0;
        $reasons = [];

        foreach ($signals as $name => $value) {
            if ($value === '') {
                continue;
            }

            $oldValue = (string) ($oldFp[$name] ?? '');

            if ($oldValue !== '' && hash_equals($oldValue, (string) $value)) {
                $matchCount++;
                $reasons[] = $name;
            }
        }

        if (
            $timezone !== '' &&
            $tzOffset !== '' &&
            (string) ($oldFp['timezone'] ?? '') === $timezone &&
            (string) ($oldFp['tz_offset'] ?? '') === $tzOffset
        ) {
            if (!in_array('timezone_offset', $reasons, true)) {
                $matchCount++;
                $reasons[] = 'timezone_offset';
            }
        }

        if ($matchCount >= 2) {
            $matches[] = [
                'user_id' => $old['user_id'] ?? '',
                'match_count' => $matchCount,
                'reasons' => $reasons
            ];
            break;
        }
    }

    $sameDevice = !empty($matches);
    $matchedUser = $sameDevice ? $matches[0]['user_id'] : null;
    $matchCount = $sameDevice ? $matches[0]['match_count'] : 0;
    $reasons = $sameDevice ? $matches[0]['reasons'] : [];

    $users[$currentKey] = [
        'bot_id' => $botId,
        'user_id' => $userId,
        'username' => $username,
        'status' => 'verified',
        'type' => $sameDevice ? 'same_device_allowed' : 'verified',
        'same_device' => $sameDevice,
        'matched_user' => $matchedUser,
        'match_count' => $matchCount,
        'match_reasons' => $reasons,
        'fingerprint' => $fp,
        'ip' => $ip,
        'network_check' => $networkCheck,
        'user_agent' => $ua,
        'verified_at' => date('Y-m-d H:i:s'),
        'verified_timestamp' => time()
    ];

    return [
        'write' => true,
        'kind' => $sameDevice ? 'same_device' : 'success',
        'matched_user' => $matchedUser,
        'match_count' => $matchCount
    ];
});

if (empty($locked['ok'])) {
    writeLog('Unable to lock users database for user ' . $userId);
    output(['status' => 'error', 'message' => 'Verification storage temporarily unavailable'], 503);
}

$result = $locked['result'] ?? [];
$kind = $result['kind'] ?? 'error';

/* ---------------- RESPONSE + CALLBACK ---------------- */

if ($kind === 'already') {
    $response = [
        'status' => 'info',
        'title' => 'Already Verified',
        'message' => 'Your device has already been verified.'
    ];

    $callback = [
        'status' => 'pass',
        'title' => 'Already Verified',
        'message' => 'Your device has already been verified.',
        'fp' => $visitorId,
        'bot_id' => $botId,
        'user_id' => $userId,
        'hash' => $hash,
        'same_device' => !empty($users[$botId . '_' . $userId]['same_device'])
    ];

    finishVerification($registration, $response, $callback);
}

if ($kind === 'same_device') {
    $title = 'Used Device Detected';
    $message = 'Same device detected. You are still allowed to continue.';
    $sameDevice = true;
} else {
    $title = 'Device Verified';
    $message = 'Your device has been successfully verified.';
    $sameDevice = false;
}

$callback = [
    'status' => 'pass',
    'title' => $title,
    'message' => $message,
    'fp' => $visitorId,
    'bot_id' => $botId,
    'user_id' => $userId,
    'hash' => $hash,
    'same_device' => $sameDevice
];

finishVerification($registration, [
    'status' => 'success',
    'title' => $title,
    'message' => $message,
    'same_device' => $sameDevice
], $callback);
