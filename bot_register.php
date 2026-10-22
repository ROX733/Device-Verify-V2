<?php
// FILE 2: bot_register.php - FINAL: ID stable + Token validate
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD'] == 'OPTIONS'){ exit; }

$dataDir = __DIR__ . '/data';
if(!is_dir($dataDir)){ mkdir($dataDir, 0755, true); }

$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
if(!file_exists($botsFile)){ file_put_contents($botsFile, json_encode([])); }
if(!file_exists($usersFile)){ file_put_contents($usersFile, json_encode([])); }

$bots = json_decode(file_get_contents($botsFile), true);
if(!is_array($bots)) $bots = [];

$botHash = $_GET['botHash'] ?? $_POST['botHash'] ?? $_GET['id'] ?? '';
$botUsername = $_GET['bot'] ?? $_POST['bot'] ?? '';
$webhookUrl = $_GET['webhook_url'] ?? $_POST['webhook_url'] ?? $_GET['webhook'] ?? '';
$botToken = $_GET['bot_token'] ?? $_POST['bot_token'] ?? '';

$botUsernameClean = ltrim($botUsername, '@');

if(!$botHash){ echo json_encode(['status'=>'error','message'=>'botHash / id missing']); exit; }
if(!$botUsernameClean){ echo json_encode(['status'=>'error','message'=>'bot username missing']); exit; }
if(!$botToken){ echo json_encode(['status'=>'error','message'=>'bot_token missing']); exit; }

// === TOKEN VALIDATE - Fake token block ===
$check = @file_get_contents("https://api.telegram.org/bot{$botToken}/getMe");
$checkData = json_decode($check, true);
if(!($checkData['ok']??false)){ 
    echo json_encode(['status'=>'error','message'=>'Invalid bot_token - getMe failed']); 
    exit; 
}
$botUsernameClean = $checkData['result']['username'] ?? $botUsernameClean;

// Check if bot already exists - SAME BOT = SAME ID
$foundIndex = -1;
foreach($bots as $idx => $b){
    if(isset($b['bot_username_clean']) && $b['bot_username_clean'] === $botUsernameClean){
        $foundIndex = $idx; break;
    }
    if(isset($b['bot_username']) && ltrim($b['bot_username'],'@') === $botUsernameClean){
        $foundIndex = $idx; break;
    }
    if(isset($b['id']) && $b['id'] === $botHash){
        $foundIndex = $idx; break;
    }
}

if($foundIndex >= 0){
    // Purana bot - ID same rakho
    $responseId = $bots[$foundIndex]['id'] ?? $bots[$foundIndex]['random_id'];
    $bots[$foundIndex]['bot_hash'] = $botHash;
    $bots[$foundIndex]['bot_token'] = $botToken;
    $bots[$foundIndex]['webhook_url'] = $webhookUrl;
    $bots[$foundIndex]['bot_username'] = '@'.$botUsernameClean;
    $bots[$foundIndex]['bot_username_clean'] = $botUsernameClean;
    $bots[$foundIndex]['last_update'] = date('Y-m-d H:i:s');
    $bots[$foundIndex]['last_activity'] = date('Y-m-d H:i:s');
}else{
    // Naya bot
    $responseId = substr(md5($botUsernameClean . time() . rand(1000,9999)), 0, 12);
    $newBot = [
        'id' => $responseId,
        'random_id' => $responseId,
        'bot_hash' => $botHash,
        'bot_username' => '@'.$botUsernameClean,
        'bot_username_clean' => $botUsernameClean,
        'bot_token' => $botToken,
        'webhook_url' => $webhookUrl,
        'created_at' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s'),
        'last_update' => date('Y-m-d H:i:s'),
        'total_users' => 0,
        'vpn_users' => 0,
        'multiple_accounts' => 0,
    ];
    $bots[] = $newBot;
}

file_put_contents($botsFile, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode([
    'status' => 'success',
    'id' => $responseId,
    'random_id' => $responseId,
    'bot' => '@'.$botUsernameClean,
    'message' => 'Bot registered successfully - ID stable',
    'verify_url' => "./index.html?id=".$responseId
]);
?>
