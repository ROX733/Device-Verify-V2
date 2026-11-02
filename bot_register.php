
<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

/* Create data directory */
$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        respond(['ok' => false, 'msg' => 'Cannot create data directory'], 500);
    }
}

$botsFile = $dataDir . '/bots.json';

/* Read request values */
$botHash = trim($_POST['botHash'] ?? $_GET['botHash'] ?? '');
$botUsername = trim($_POST['bot'] ?? $_GET['bot'] ?? '');
$botToken = trim($_POST['bot_token'] ?? $_GET['bot_token'] ?? '');
$webhookUrl = trim($_POST['webhook_url'] ?? $_GET['webhook_url'] ?? '');
$userId = trim($_POST['user_id'] ?? $_GET['user_id'] ?? '');

/*
 * bot_id is intentionally NOT used for validation.
 * The actual ID is obtained securely from Telegram getMe.
 */

/* Validate hash */
if (!preg_match('/^[a-zA-Z0-9]{20,100}$/', $botHash)) {
    respond(['ok' => false, 'msg' => 'Invalid or missing botHash'], 400);
}

/* Validate token */
if ($botToken === '') {
    respond(['ok' => false, 'msg' => 'Bot token missing'], 400);
}

/* Validate user ID */
if ($userId === '' || !ctype_digit($userId)) {
    respond(['ok' => false, 'msg' => 'Invalid or missing Telegram user ID'], 400);
}

/* Validate webhook */
if (
    !filter_var($webhookUrl, FILTER_VALIDATE_URL) ||
    strtolower((string)parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https'
) {
    respond(['ok' => false, 'msg' => 'Invalid HTTPS webhook URL'], 400);
}

/* Verify token directly with Telegram */
function telegramGetMe($token) {
    $url = 'https://api.telegram.org/bot' . $token . '/getMe';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'DeviceVerification/2.0'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return [
            'ok' => false,
            'error' => $error ?: 'Telegram API HTTP error'
        ];
    }

    $result = json_decode($response, true);

    if (!is_array($result) || empty($result['ok'])) {
        return [
            'ok' => false,
            'error' => 'Telegram rejected this bot token'
        ];
    }

    return $result;
}

$telegram = telegramGetMe($botToken);

if (empty($telegram['ok'])) {
    respond([
        'ok' => false,
        'msg' => 'Telegram token verification failed',
        'details' => $telegram['error'] ?? 'Unknown Telegram error'
    ], 401);
}

$telegramBot = $telegram['result'] ?? [];

$actualBotId = (string)($telegramBot['id'] ?? '');
$actualUsername = ltrim((string)($telegramBot['username'] ?? ''), '@');

if ($actualBotId === '' || $actualUsername === '') {
    respond(['ok' => false, 'msg' => 'Telegram did not return bot details'], 400);
}

/* Check username, but do not compare supplied bot_id */
if (
    $botUsername !== '' &&
    strtolower(ltrim($botUsername, '@')) !== strtolower($actualUsername)
) {
    respond([
        'ok' => false,
        'msg' => 'Bot username does not match token',
        'actual_bot' => '@' . $actualUsername
    ], 400);
}

/*
 * Atomic read + update + write.
 * Exclusive lock is held for the entire operation.
 */
$fp = fopen($botsFile, 'c+');

if (!$fp) {
    respond(['ok' => false, 'msg' => 'Cannot open bots database'], 500);
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Cannot lock bots database'], 500);
}

rewind($fp);
$content = stream_get_contents($fp);

if ($content === false || trim($content) === '') {
    $bots = [];
} else {
    $bots = json_decode($content, true);

    if (!is_array($bots)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(['ok' => false, 'msg' => 'Bots database contains invalid JSON'], 500);
    }
}

/* Find registration for this bot + this Telegram user */
$foundIndex = -1;

foreach ($bots as $index => $bot) {
    if (
        (string)($bot['telegram_bot_id'] ?? '') === $actualBotId &&
        (string)($bot['telegram_user_id'] ?? '') === $userId
    ) {
        $foundIndex = $index;
        break;
    }
}

$now = date('Y-m-d H:i:s');

if ($foundIndex >= 0) {
    /* Update only this user's registration */
    $registrationId = $bots[$foundIndex]['id'] ?? bin2hex(random_bytes(8));

    $bots[$foundIndex]['id'] = $registrationId;
    $bots[$foundIndex]['random_id'] = $registrationId;
    $bots[$foundIndex]['bot_hash'] = $botHash;
    $bots[$foundIndex]['bot_username'] = '@' . $actualUsername;
    $bots[$foundIndex]['bot_username_clean'] = $actualUsername;
    $bots[$foundIndex]['bot_token'] = $botToken;
    $bots[$foundIndex]['webhook_url'] = $webhookUrl;
    $bots[$foundIndex]['telegram_bot_id'] = $actualBotId;
    $bots[$foundIndex]['telegram_user_id'] = $userId;
    $bots[$foundIndex]['status'] = 'active';
    $bots[$foundIndex]['last_activity'] = $now;

} else {
    $registrationId = bin2hex(random_bytes(8));

    $bots[] = [
        'id' => $registrationId,
        'random_id' => $registrationId,
        'bot_hash' => $botHash,
        'bot_username' => '@' . $actualUsername,
        'bot_username_clean' => $actualUsername,
        'bot_token' => $botToken,
        'webhook_url' => $webhookUrl,
        'telegram_bot_id' => $actualBotId,
        'telegram_user_id' => $userId,
        'status' => 'active',
        'created_at' => $now,
        'last_activity' => $now
    ];
}

/* Save while lock is held */
$json = json_encode(
    $bots,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    flock($fp, LOCK_UN);
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Could not encode bots database'], 500);
}

rewind($fp);
ftruncate($fp, 0);

$written = fwrite($fp, $json);
fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

if ($written === false) {
    respond(['ok' => false, 'msg' => 'Could not save bot registration'], 500);
}

/* Return Telegram's actual bot ID */
respond([
    'ok' => true,
    'msg' => 'Bot registration saved successfully',
    'id' => $registrationId,
    'bot_id' => $actualBotId,
    'user_id' => $userId,
    'bot' => '@' . $actualUsername
]);
