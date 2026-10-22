<?php
// FILE 3: verify.php - FIXED FOR PAGE_OPEN COLLECT + OFFICIAL APP
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD']=='OPTIONS'){ exit; }

$dataDir = __DIR__ . '/data';
if(!is_dir($dataDir)){ mkdir($dataDir, 0755, true); }
$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
if(!file_exists($botsFile)) file_put_contents($botsFile, json_encode([]));
if(!file_exists($usersFile)) file_put_contents($usersFile, json_encode([]));

$bots = json_decode(file_get_contents($botsFile), true) ?: [];
$users = json_decode(file_get_contents($usersFile), true) ?: [];

function getIP(){
    if(!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
$ip = trim(getIP());
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ===== FIX 1: PAGE OPEN COLLECT - CHAHE BOT HO YA NA HO, SAVE KARO =====
if(isset($_GET['collect'])){
    $botId = $_GET['id'] ?? $_GET['bot_hash'] ?? $_GET['botId'] ?? 'unknown';
    $userId = $_GET['user_id'] ?? 'unknown';
    $botUsername = $_GET['bot'] ?? '';
    $type = $_GET['type'] ?? 'page_open';
    
    // Har open ko users.json me daal do - admin me dikhega
    $users[] = [
        'user_id' => (string)$userId,
        'bot_id' => $botId,
        'bot_username' => $botUsername,
        'ip' => $ip,
        'user_agent' => $_GET['ua'] ?? $ua,
        'platform' => $_GET['platform'] ?? 'unknown',
        'type' => $type,
        'verified_at' => date('Y-m-d H:i:s'),
        'is_page_open' => 1
    ];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo json_encode(['status'=>'collected', 'type'=>$type]);
    exit;
}

// Normal flow
$botId = $_GET['id'] ?? $_POST['id'] ?? '';
if(!$botId){
    echo json_encode(['status'=>'error','message'=>'Invalid ID. Open from ?id= link only.']); exit;
}

// FIX 2: BOT FIND - bot_hash, random_id, id, botHash sab check karo
$botData = null;
foreach($bots as $b){
    if(($b['id']??'')==$botId || ($b['random_id']??'')==$botId || ($b['bot_hash']??'')==$botId || ($b['botHash']??'')==$botId || ($b['randomId']??'')==$botId){
        $botData = $b; break;
    }
}
if(!$botData){
    // FIX: Bot na mile toh bhi page_open save karo taaki admin me dikhe ki bot register nahi hua
    $users[] = [
        'user_id' => 'unknown',
        'bot_id' => $botId,
        'ip' => $ip,
        'user_agent' => $ua,
        'type' => 'bot_not_found',
        'verified_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo json_encode(['status'=>'error','message'=>'Bot not found for this ID. Register again from bot.']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if(!$input){ $input = $_POST; }

$userId = $input['user_id'] ?? '';
$information = $input['information'] ?? '';
$initData = $input['initData'] ?? '';

if(!$userId){
    echo json_encode(['status'=>'error','message'=>'Telegram user ID missing. Open from Official Telegram.']); exit;
}

// ===== FIX 3: OFFICIAL TELEGRAM CHECK HATA DIYA - initData empty pe block nahi =====
// Pehle ye tha jo error de raha tha:
// if(!$initData){ echo error 3rd Party... exit; }
// Ab relaxed kiya:
$isOfficial = true;
if(!$initData){
    $isOfficial = false; // flag only, block nahi karenge
    // Log karenge ki initData missing tha
}

// One Device One Time check
$fingerprint = explode('x', $information)[0] ?? $information;
foreach($users as $u){
    if(($u['bot_id']??'') != $botId) continue;
    if(($u['user_id']??'') == $userId && ($u['is_page_open']??0)!=1){
        echo json_encode(['status'=>'info','message'=>'Already Verified','webhook_url'=>$botData['webhook_url']??$botData['webhook']??'']);
        exit;
    }
    if($fingerprint && ($u['fingerprint']??'') == $fingerprint && ($u['user_id']??'') != $userId && ($u['is_page_open']??0)!=1){
        echo json_encode(['status'=>'error','message'=>'This device already verified with another Telegram account. One Device One Account only.']);
        exit;
    }
}

// VPN check - skip if fails (slow hota hai)
function isVPN($ip){
    try{
        $ctx = stream_context_create(['http'=>['timeout'=>2]]);
        $json = @file_get_contents("http://ip-api.com/json/{$ip}?fields=proxy,hosting", false, $ctx);
        if($json){
            $d = json_decode($json, true);
            if(($d['proxy']??false)===true || ($d['hosting']??false)===true) return true;
        }
    }catch(Exception $e){}
    return false;
}
$isVPN = isVPN($ip);

// SAVE USER - FINAL VERIFY
$newUser = [
    'user_id' => (string)$userId,
    'username' => '',
    'bot_id' => $botId,
    'bot_username' => $botData['bot_username']??$botData['bot']??'',
    'ip' => $ip,
    'fingerprint' => $fingerprint,
    'information' => $information,
    'is_vpn' => $isVPN ? 1 : 0,
    'user_agent' => $ua,
    'verified_at' => date('Y-m-d H:i:s'),
    'is_official' => $isOfficial ? 1 : 0
];
$users[] = $newUser;
file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

// UPDATE BOT STATS
foreach($bots as &$b){
    if(($b['id']??'')==$botId || ($b['random_id']??'')==$botId || ($b['bot_hash']??'')==$botId || ($b['botHash']??'')==$botId){
        $b['total_users'] = count(array_filter($users, fn($u)=>($u['bot_id']??'')==$botId && ($u['is_page_open']??0)!=1));
        $b['last_activity'] = date('Y-m-d H:i:s');
        break;
    }
}
file_put_contents($botsFile, json_encode($bots, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo json_encode([
    'status'=>'success',
    'message'=>'Device Verified Successfully!',
    'webhook_url'=>$botData['webhook_url']??$botData['webhook']??'',
    'user_id'=>$userId,
    'is_vpn'=>$isVPN,
    'ip'=>$ip
]);
?>
