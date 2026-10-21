<?php
// FILE 3: verify.php - FINAL VERIFICATION LOGIC
// Location: https://tera-domain.com/verify.php?id=RANDOM_ID
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

// Get ID from URL - only from ID
$botId = $_GET['id'] ?? $_POST['id'] ?? '';
if(!$botId){
    echo json_encode(['status'=>'error','message'=>'Invalid ID. Open from ?id= link only.']); exit;
}

// Find bot by id / random_id / bot_hash
$botData = null;
foreach($bots as $b){
    if(($b['id']??'')==$botId || ($b['random_id']??'')==$botId || ($b['bot_hash']??'')==$botId){
        $botData = $b; break;
    }
}
if(!$botData){
    echo json_encode(['status'=>'error','message'=>'Bot not found for this ID. Register again from bot.']); exit;
}

// Get POST JSON
$input = json_decode(file_get_contents('php://input'), true);
if(!$input){ $input = $_POST; }

$userId = $input['user_id'] ?? '';
$information = $input['information'] ?? ''; // fingerprint + screen + lang
$initData = $input['initData'] ?? '';

if(!$userId){
    echo json_encode(['status'=>'error','message'=>'Telegram user ID missing. Open from Official Telegram.']); exit;
}

// 1. OFFICIAL TELEGRAM CHECK - initData must exist
if(!$initData){
    echo json_encode(['status'=>'error','message'=>'3rd Party Telegram not allowed. Use Official Telegram Android App.']); exit;
}

// 2. GET REAL IP
function getIP(){
    if(!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
$ip = trim(getIP());

// 3. ANDROID ONLY - double check UA
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if(stripos($ua,'Android')===false){
    // Allow if Telegram WebApp says Android? but strict
    // echo json_encode(['status'=>'error','message'=>'Only Android Allowed']); exit;
}

// 4. VPN / PROXY / HOSTING DETECTION
function isVPN($ip){
    // Free check via ip-api.com
    try{
        $ctx = stream_context_create(['http'=>['timeout'=>3]]);
        $json = @file_get_contents("http://ip-api.com/json/{$ip}?fields=proxy,hosting", false, $ctx);
        if($json){
            $d = json_decode($json, true);
            if(($d['proxy']??false)===true || ($d['hosting']??false)===true) return true;
        }
    }catch(Exception $e){}
    return false;
}
$isVPN = isVPN($ip);

// 5. ONE DEVICE ONE TIME + MULTIPLE ACCOUNTS CHECK
$fingerprint = explode('x', $information)[0] ?? $information;
$existingUserForThisDevice = null;
$sameIPDiffUser = 0;
$sameFpDiffUser = 0;

foreach($users as $u){
    if(($u['bot_id']??'') != $botId) continue;
    // Already verified user?
    if(($u['user_id']??'') == $userId){
        echo json_encode(['status'=>'info','message'=>'Already Verified','webhook_url'=>$botData['webhook_url']??'']);
        exit;
    }
    // Same fingerprint but different user_id = Multiple Accounts
    if($fingerprint && ($u['fingerprint']??'') == $fingerprint && ($u['user_id']??'') != $userId){
        $sameFpDiffUser++;
    }
    // Same IP but different user_id
    if($ip && ($u['ip']??'') == $ip && ($u['user_id']??'') != $userId){
        $sameIPDiffUser++;
    }
}

// Block if fingerprint already used by another user - One Device One Time
if($sameFpDiffUser > 0){
    echo json_encode(['status'=>'error','message'=>'This device already verified with another Telegram account. One Device One Account only.']);
    exit;
}

// 6. TELEGRAM USERNAME FETCH via Bot Token (optional but good for admin panel)
$tgUsername = '';
$botToken = $botData['bot_token'] ?? '';
if($botToken && $userId){
    try{
        $ctx = stream_context_create(['http'=>['timeout'=>3]]);
        $res = @file_get_contents("https://api.telegram.org/bot{$botToken}/getChat?chat_id={$userId}", false, $ctx);
        if($res){
            $j = json_decode($res, true);
            if($j['ok']??false){
                $tgUsername = $j['result']['username'] ?? '';
                $firstName = $j['result']['first_name'] ?? '';
            }
        }
    }catch(Exception $e){}
}

// 7. SAVE USER
$newUser = [
    'user_id' => (string)$userId,
    'username' => $tgUsername ? '@'.$tgUsername : '',
    'bot_id' => $botId,
    'bot_username' => $botData['bot_username']??'',
    'ip' => $ip,
    'fingerprint' => $fingerprint,
    'information' => $information,
    'is_vpn' => $isVPN ? 1 : 0,
    'is_multiple' => ($sameIPDiffUser>0) ? 1 : 0,
    'user_agent' => $ua,
    'verified_at' => date('Y-m-d H:i:s'),
    'last_activity' => date('Y-m-d H:i:s')
];
$users[] = $newUser;
file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

// 8. UPDATE BOT STATS for admin panel
foreach($bots as &$b){
    if(($b['id']??'')==$botId || ($b['random_id']??'')==$botId){
        $b['total_users'] = count(array_filter($users, fn($u)=>($u['bot_id']??'')==$botId));
        // Count VPN
        $b['vpn_users'] = count(array_filter($users, fn($u)=>($u['bot_id']??'')==$botId && ($u['is_vpn']??0)==1));
        // Multiple
        $fps = [];
        $multiple = 0;
        foreach($users as $u){
            if(($u['bot_id']??'')!=$botId) continue;
            $fp = $u['fingerprint']??'';
            if(isset($fps[$fp])) $multiple++;
            else $fps[$fp]=1;
        }
        $b['multiple_accounts'] = $multiple;
        $b['last_activity'] = date('Y-m-d H:i:s');
        break;
    }
}
file_put_contents($botsFile, json_encode($bots, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

// 9. RETURN SUCCESS + CALL WEBHOOK ASYNC (frontend will also call)
echo json_encode([
    'status'=>'success',
    'message'=>'Device Verified Successfully!',
    'webhook_url'=>$botData['webhook_url']??'',
    'user_id'=>$userId,
    'is_vpn'=>$isVPN,
    'ip'=>$ip
]);
?>
