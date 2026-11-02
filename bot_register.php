
<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$botsFile = $dataDir . '/bots.json';

if (!file_exists($botsFile)) {
    file_put_contents($botsFile, '[]', LOCK_EX);
}

/* JSON response */
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

/* Read JSON */
function readBots($file) {
    $fp = fopen($file, 'c+');

    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_SH);

    $content = stream_get_contents($fp);
    $data = json_decode($content, true);

    flock($fp, LOCK_UN);
    fclose($fp);

    return is_array($data) ? $data : [];
}

/* Save JSON with file lock */
function saveBots($file, $bots) {
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
            $bots,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    );

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written !== false;
}

/* Telegram API request */
function telegramGetMe($token) {

    $url = 'https://api.telegram.org/bot' .
        $token . '/getMe';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
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
            'error' => $error ?: 'Telegram API request failed'
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data) || !($data['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Invalid Telegram bot token'
        ];
    }

    return $data;
}

/* Get request parameters */
$botHash = trim(
    $_POST['botHash'] ??
    $_GET['botHash'] ??
    ''
);

$botUsername = trim(
    $_POST['bot'] ??
    $_GET['bot'] ??
    ''
);

$botToken = trim(
    $_POST['bot_token'] ??
    $_GET['bot_token'] ??
    ''
);

$webhookUrl = trim(
    $_POST['webhook_url'] ??
    $_GET['webhook_url'] ??
    ''
);

$userId = trim(
    $_POST['user_id'] ??
    $_GET['user_id'] ??
    ''
);

$botId = trim(
    $_POST['bot_id'] ??
    $_GET['bot_id'] ??
    ''
);

/* Required validation */
if ($botHash === '') {
    respond([
        'ok' => false,
        'msg' => 'botHash missing'
    ], 400);
}

if (!preg_match('/^[a-zA-Z0-9]{20,100}$/', $botHash)) {
    respond([
        'ok' => false,
        'msg' => 'Invalid botHash format'
    ], 400);
}

if ($botToken === '') {
    respond([
        'ok' => false,
        'msg' => 'bot_token missing'
    ], 400);
}

if ($webhookUrl === '') {
    respond([
        'ok' => false,
        'msg' => 'webhook_url missing'
    ], 400);
}

if (
    !filter_var($webhookUrl, FILTER_VALIDATE_URL) ||
    parse_url($webhookUrl, PHP_URL_SCHEME) !== 'https'
) {
    respond([
        'ok' => false,
        'msg' => 'Invalid HTTPS webhook URL'
    ], 400);
}

/* Verify actual bot token */
$telegram = telegramGetMe($botToken);

if (!($telegram['ok'] ?? false)) {
    respond([
        'ok' => false,
        'msg' => 'Telegram bot token verification failed'
    ], 401);
}

$telegramBot = $telegram['result'];

$actualBotId = (string)$telegramBot['id'];
$actualUsername = $telegramBot['username'] ?? '';

if ($actualUsername === '') {
    respond([
        'ok' => false,
        'msg' => 'Bot username not available'
    ], 400);
}

$actualUsername = ltrim($actualUsername, '@');

/* Verify supplied username */
if (
    $botUsername !== '' &&
    strtolower(ltrim($botUsername, '@')) !== strtolower($actualUsername)
) {
    respond([
        'ok' => false,
        'msg' => 'Bot username does not match token'
    ], 400);
}

/* Verify supplied numeric bot ID if present */
if ($botId !== '' && $botId !== $actualBotId) {
    respond([
        'ok' => false,
        'msg' => 'Bot ID does not match token'
    ], 400);
}

/* Read existing registrations */
$bots = readBots($botsFile);

if (!is_array($bots)) {
    respond([
        'ok' => false,
        'msg' => 'Unable to read bots database'
    ], 500);
}

/*
 * One record per bot + Telegram user.
 * A user's new hash replaces only that user's old registration.
 */

$foundIndex = -1;

foreach ($bots as $index => $bot) {

    $savedBotId = (string)($bot['telegram_bot_id'] ?? '');
    $savedUserId = (string)($bot['telegram_user_id'] ?? '');

    if (
        $savedBotId === $actualBotId &&
        $savedUserId === $userId &&
        $userId !== ''
    ) {
        $foundIndex = $index;
        break;
    }
}

/* Preserve existing internal registration ID */
if ($foundIndex >= 0) {

    $registrationId = $bots[$foundIndex]['id'];

    $bots[$foundIndex]['bot_hash'] = $botHash;
    $bots[$foundIndex]['bot_username'] = '@' . $actualUsername;
    $bots[$foundIndex]['bot_username_clean'] = $actualUsername;
    $bots[$foundIndex]['bot_token'] = $botToken;
    $bots[$foundIndex]['webhook_url'] = $webhookUrl;
    $bots[$foundIndex]['telegram_bot_id'] = $actualBotId;
    $bots[$foundIndex]['telegram_user_id'] = $userId;
    $bots[$foundIndex]['last_activity'] = date('Y-m-d H:i:s');

    $bots[$foundIndex]['status'] = 'active';

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

        'created_at' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s')
    ];
}

/* Save registrations */
if (!saveBots($botsFile, $bots)) {
    respond([
        'ok' => false,
        'msg' => 'Unable to save bot registration'
    ], 500);
}

/* Success */
respond([
    'ok' => true,
    'msg' => 'Bot registration saved successfully',
    'id' => $registrationId,
    'bot_id' => $actualBotId,
    'user_id' => $userId,
    'bot' => '@' . $actualUsername
]);

?>
