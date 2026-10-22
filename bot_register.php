<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD'] == 'OPTIONS'){ exit; }

$dataDir = __DIR__ . '/data';
if(!is_dir($dataDir)){ mkdir($dataDir, 0777, true); }
$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
if(!file_exists($botsFile)){ file_put_contents($botsFile, '[]'); }
if(!file_exists($usersFile)){ file_put_contents($usersFile, '[]'); }

function curl_get($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

$bots = json_decode(file_get_contents($botsFile), true) ?: [];

$botHash = $_GET['botHash'] ?? $_POST['botHash'] ?? $_GET['id'] ?? '';
$botUsername = $_GET['bot'] ?? $_POST['bot'] ?? '';
$botToken = $_GET['bot_token'] ?? $_POST['bot_token'] ?? $_GET['token'] ?? '';
$webhookUrl = $_GET['webhook_url'] ?? $_POST['webhook_url'] ?? $_GET['webhook'] ?? '';

$botUsernameClean = ltrim($botUsername, '@');

if(!$botHash) { echo json_encode(['ok'=>False, 'msg'=>'botHash missing']); exit; }
if(!$botToken) { echo json_encode(['ok'=>False, 'msg'=>'bot_token missing']); exit; }

$checkJson = curl_get("https://api.telegram.org/bot{$botToken}/getMe");
$checkData = json_decode($checkJson, true);
$finalUsername = $botUsernameClean;
if($checkData && ($checkData['ok']??false)){
    $finalUsername = $checkData['result']['username'] ?? $botUsernameClean;
}

// Same bot = Same ID
$foundIndex = -1;
foreach($bots as $idx => $b){
    $clean = $b['bot_username_clean'] ?? ltrim($b['bot_username']??'', '@');
    if($clean === $finalUsername) { $foundIndex = $idx; break; }
}

if($foundIndex >= 0){
    $responseId = $bots[$foundIndex]['id'];
    $bots[$foundIndex]['bot_token'] = $botToken;
    $bots[$foundIndex]['bot_hash'] = $botHash;
    $bots[$foundIndex]['webhook_url'] = $webhookUrl;
    $bots[$foundIndex]['bot_username'] = '@'.$finalUsername;
    $bots[$foundIndex]['bot_username_clean'] = $finalUsername;
    $bots[$foundIndex]['last_activity'] = date('Y-m-d H:i:s');
}else{
    $responseId = substr(md5($finalUsername.time().rand(1000,9999)), 0, 12);
    $bots[] = [
        'id' => $responseId,
        'random_id' => $responseId,
        'bot_hash' => $botHash,
        'bot_username' => '@'.$finalUsername,
        'bot_username_clean' => $finalUsername,
        'bot_token' => $botToken,
        'webhook_url' => $webhookUrl,
        'created_at' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s'),
    ];
}

file_put_contents($botsFile, json_encode($bots, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);

// === TERA WALA FINAL RESPONSE ===
echo json_encode([
    'ok' => True,
    'msg' => 'Bot token and webhook URL saved successfully',
    'id' => $responseId,
    'bot' => '@'.$finalUsername
]);
?>
