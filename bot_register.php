<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
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
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    respond(['ok'=>false, 'msg'=>'GET or POST required'], 405);
}

/* --------------------------------
   READ REQUEST
-------------------------------- */

$input = $_POST;

if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $jsonInput = json_decode($raw, true);

    if (is_array($jsonInput)) {
        $input = $jsonInput;
    }
}

function getValue($key, $input) {
    if (isset($input[$key])) {
        return trim((string)$input[$key]);
    }

    if (isset($_GET[$key])) {
        return trim((string)$_GET[$key]);
    }

    return '';
}

$botHash = getValue('botHash', $input);
$botUsername = getValue('bot', $input);
$botToken = getValue('bot_token', $input);
$webhookUrl = getValue('webhook_url', $input);
$userId = getValue('user_id', $input);

/* --------------------------------
   DATA DIRECTORY
-------------------------------- */

$dataDir = __DIR__ . '/data';

if (
    !is_dir($dataDir) &&
    !@mkdir($dataDir, 0750, true) &&
    !is_dir($dataDir)
) {
    respond(['ok'=>false, 'msg'=>'Cannot create data directory']);
}

$botsFile = $dataDir . '/bots.json';

/* --------------------------------
   VALIDATION
-------------------------------- */

if (!preg_match('/^[a-zA-Z0-9]{20,100}$/', $botHash)) {
    respond(['ok'=>false, 'msg'=>'Invalid or missing botHash']);
}

if ($userId === '' || !ctype_digit($userId)) {
    respond(['ok'=>false, 'msg'=>'Invalid Telegram user ID']);
}

if (!preg_match('/^[0-9]{6,15}:[A-Za-z0-9_-]{20,}$/', $botToken)) {
    respond([
        'ok'=>false,
        'msg'=>'Bot token format is invalid. Check Bot.info().token.'
    ]);
}

if (
    !filter_var($webhookUrl, FILTER_VALIDATE_URL) ||
    strtolower((string)parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https' ||
    empty(parse_url($webhookUrl, PHP_URL_HOST))
) {
    respond(['ok'=>false, 'msg'=>'Invalid HTTPS webhook URL']);
}

/* --------------------------------
   TELEGRAM GETME
-------------------------------- */

function telegramGetMe($token) {

    if (!function_exists('curl_init')) {
        return [
            'ok'=>false,
            'error'=>'PHP cURL extension is not enabled'
        ];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/getMe';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_USERAGENT=>'DeviceVerification/3.0'
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok'=>false,
            'error'=>'Telegram connection failed: ' . $curlError
        ];
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'ok'=>false,
            'error'=>'Telegram returned invalid JSON. HTTP ' . $httpCode
        ];
    }

    if ($httpCode !== 200 || empty($data['ok'])) {
        return [
            'ok'=>false,
            'error'=>'Telegram API HTTP ' . $httpCode . ': ' .
                ($data['description'] ?? 'Unknown error')
        ];
    }

    return $data;
}

$telegram = telegramGetMe($botToken);

if (empty($telegram['ok'])) {

    error_log(
        'Device verification getMe failed: ' .
        ($telegram['error'] ?? 'Unknown')
    );

    respond([
        'ok'=>false,
        'msg'=>'Telegram bot token verification failed',
        'details'=>$telegram['error'] ?? 'Unknown Telegram error'
    ]);
}

$telegramBot = $telegram['result'] ?? [];

$actualBotId = (string)($telegramBot['id'] ?? '');
$actualUsername = ltrim(
    (string)($telegramBot['username'] ?? ''),
    '@'
);

if ($actualBotId === '' || $actualUsername === '') {
    respond([
        'ok'=>false,
        'msg'=>'Telegram did not return valid bot details'
    ]);
}

if (
    $botUsername !== '' &&
    strtolower(ltrim($botUsername, '@')) !== strtolower($actualUsername)
) {
    respond([
        'ok'=>false,
        'msg'=>'Bot username does not match supplied token',
        'actual_bot'=>'@' . $actualUsername
    ]);
}

/* --------------------------------
   OPEN DATABASE WITH LOCK
-------------------------------- */

$fp = @fopen($botsFile, 'c+');

if (!$fp) {
    respond(['ok'=>false, 'msg'=>'Cannot open bots database']);
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(['ok'=>false, 'msg'=>'Cannot lock bots database']);
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

        respond([
            'ok'=>false,
            'msg'=>'bots.json contains invalid JSON'
        ]);
    }
}

/* --------------------------------
   FIND BOT GLOBALLY
   One Telegram bot = one record
-------------------------------- */

$foundIndex = -1;
$duplicateIndexes = [];

foreach ($bots as $index => $bot) {

    if (!is_array($bot)) {
        continue;
    }

    if (
        (string)($bot['telegram_bot_id'] ?? '') !== $actualBotId
    ) {
        continue;
    }

    $existingOwner = (string)($bot['telegram_user_id'] ?? '');

    /*
     * Do not allow another Telegram account
     * to take over an already registered bot.
     */

    if ($existingOwner !== $userId) {

        flock($fp, LOCK_UN);
        fclose($fp);

        respond([
            'ok'=>false,
            'msg'=>'This bot is already registered with another owner.',
            'bot'=>'@' . $actualUsername,
            'bot_id'=>$actualBotId
        ], 403);
    }

    if ($foundIndex === -1) {
        $foundIndex = $index;
    } else {
        $duplicateIndexes[] = $index;
    }
}

/* --------------------------------
   REMOVE OLD DUPLICATES
-------------------------------- */

if (!empty($duplicateIndexes)) {

    foreach ($duplicateIndexes as $index) {
        unset($bots[$index]);
    }

    $bots = array_values($bots);
}

/* --------------------------------
   CREATE OR UPDATE REGISTRATION
-------------------------------- */

$now = date('Y-m-d H:i:s');

try {

    if ($foundIndex >= 0) {

        /*
         * Find retained record again after
         * removing duplicate indexes.
         */

        $foundIndex = -1;

        foreach ($bots as $index => $bot) {

            if (
                is_array($bot) &&
                (string)($bot['telegram_bot_id'] ?? '') === $actualBotId &&
                (string)($bot['telegram_user_id'] ?? '') === $userId
            ) {
                $foundIndex = $index;
                break;
            }
        }
    }

    if ($foundIndex >= 0) {

        $registrationId =
            $bots[$foundIndex]['id'] ??
            bin2hex(random_bytes(8));

        $createdAt =
            $bots[$foundIndex]['created_at'] ?? $now;

        $bots[$foundIndex] = array_merge(
            $bots[$foundIndex],
            [
                'id'=>$registrationId,
                'random_id'=>$registrationId,
                'bot_hash'=>$botHash,
                'bot_username'=>'@' . $actualUsername,
                'bot_username_clean'=>$actualUsername,
                'bot_token'=>$botToken,
                'webhook_url'=>$webhookUrl,
                'telegram_bot_id'=>$actualBotId,
                'telegram_user_id'=>$userId,
                'status'=>'active',
                'created_at'=>$createdAt,
                'last_activity'=>$now
            ]
        );

        $action = 'updated';

    } else {

        $registrationId = bin2hex(random_bytes(8));

        $bots[] = [
            'id'=>$registrationId,
            'random_id'=>$registrationId,
            'bot_hash'=>$botHash,
            'bot_username'=>'@' . $actualUsername,
            'bot_username_clean'=>$actualUsername,
            'bot_token'=>$botToken,
            'webhook_url'=>$webhookUrl,
            'telegram_bot_id'=>$actualBotId,
            'telegram_user_id'=>$userId,
            'status'=>'active',
            'created_at'=>$now,
            'last_activity'=>$now
        ];

        $action = 'created';
    }

} catch (Throwable $e) {

    flock($fp, LOCK_UN);
    fclose($fp);

    respond([
        'ok'=>false,
        'msg'=>'Could not prepare registration record'
    ]);
}

/* --------------------------------
   SAVE DATABASE
-------------------------------- */

$json = json_encode(
    $bots,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

if ($json === false) {

    flock($fp, LOCK_UN);
    fclose($fp);

    respond([
        'ok'=>false,
        'msg'=>'Could not encode bots database'
    ]);
}

rewind($fp);

if (!ftruncate($fp, 0)) {

    flock($fp, LOCK_UN);
    fclose($fp);

    respond([
        'ok'=>false,
        'msg'=>'Could not clear bots database'
    ]);
}

$written = fwrite($fp, $json);

fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

if ($written === false || $written !== strlen($json)) {
    respond([
        'ok'=>false,
        'msg'=>'Could not completely save bot registration'
    ]);
}

/* --------------------------------
   SUCCESS
-------------------------------- */

respond([
    'ok'=>true,
    'msg'=>'Bot registration saved successfully',
    'action'=>$action,
    'id'=>$registrationId,
    'bot_id'=>$actualBotId,
    'user_id'=>$userId,
    'bot'=>'@' . $actualUsername,
    'total_unique_bots'=>count($bots)
]);
