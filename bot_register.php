<?php
// FILE 2: bot_register.php - Self Hosted Bot Registration (pro.bots.lt ka replacement)
// Location: https://tera-domain.com/bot_register.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD'] == 'OPTIONS'){ exit; }

$dataDir = __DIR__ . '/data';
if(!is_dir($dataDir)){ mkdir($dataDir, 0755, true); }

$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';

// Create empty files if not exists
if(!file_exists($botsFile)){ file_put_contents($botsFile, json_encode([])); }
if(!file_exists($usersFile)){ file_put_contents($usersFile, json_encode([])); }

$bots = json_decode(file_get_contents($botsFile), true);
if(!is_array($bots)) $bots = [];

// Get params - support both GET and POST
$botHash = $_GET['botHash'] ?? $_POST['botHash'] ?? $_GET['id'] ?? '';
$botUsername = $_GET['bot'] ?? $_POST['bot'] ?? '';
$webhookUrl = $_GET['webhook_url'] ?? $_POST['webhook_url'] ?? '';
$botToken = $_GET['bot_token'] ?? $_POST['bot_token'] ?? '';

// Clean bot username (remove @ if present for storage, but keep @ for display)
$botUsernameClean = ltrim($botUsername, '@');

if(!$botHash){
    echo json_encode(['status'=>'error','message'=>'botHash / id missing']);
    exit;
}
if(!$botUsernameClean){
    echo json_encode(['status'=>'error','message'=>'bot username missing']);
    exit;
}
if(!$botToken){
    echo json_encode(['status'=>'error','message'=>'bot_token missing']);
    exit;
}

// Check if bot already exists
$foundIndex = -1;
foreach($bots as $idx => $b){
    if(isset($b['bot_username']) && (ltrim($b['bot_username'],'@') === $botUsernameClean)){
        $foundIndex = $idx; break;
    }
    if(isset($b['bot_hash']) && $b['bot_hash'] === $botHash){
        $foundIndex = $idx; break;
    }
}

// Generate randomized ID (like your requirement: id se khulega)
$randomizedId = bin2hex(random_bytes(6)) . time() % 1000; // e.g. a1b2c3d4e5f6 + random
// More simple readable randomized id: 8 chars hex + 4 random
$randomizedId = substr(md5($botUsernameClean . time() . rand()), 0, 12);

if($foundIndex >= 0){
    // Update existing bot
    $bots[$foundIndex]['bot_hash'] = $botHash;
    $bots[$foundIndex]['random_id'] = $randomizedId;
    $bots[$foundIndex]['id'] = $randomizedId;
    $bots[$foundIndex]['bot_token'] = $botToken;
    $bots[$foundIndex]['webhook_url'] = $webhookUrl;
    $bots[$foundIndex]['last_update'] = date('Y-m-d H:i:s');
    // keep stats
    $responseId = $randomizedId;
}else{
    // New bot
    $newBot = [
        'id' => $randomizedId,
        'random_id' => $randomizedId,
        'bot_hash' => $botHash,
        'bot_username' => '@'.$botUsernameClean,
        'bot_username_clean' => $botUsernameClean,
        'bot_token' => $botToken,
        'webhook_url' => $webhookUrl,
        'created_at' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s'),
        'total_users' => 0,
        'unique_ips' => 0,
        'vpn_users' => 0,
        'multiple_accounts' => 0,
        'android_only' => true
    ];
    $bots[] = $newBot;
    $responseId = $randomizedId;
}

file_put_contents($botsFile, json_encode($bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Also log for admin panel
echo json_encode([
    'status' => 'success',
    'id' => $responseId,
    'random_id' => $responseId,
    'bot' => '@'.$botUsernameClean,
    'message' => 'Bot registered successfully',
    'verify_url' => "./index.html?id=".$randomizedId
]);
?>
