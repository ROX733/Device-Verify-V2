<?php
header('Content-Type: application/json');
$dataDir = __DIR__ . '/data';
$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
if(!is_dir($dataDir)) mkdir($dataDir, 0777, true);
if(!file_exists($botsFile)) file_put_contents($botsFile, '[]');
if(!file_exists($usersFile)) file_put_contents($usersFile, '[]');

function getIPInfo($ip){
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=org,proxy,hosting");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

if(isset($_GET['collect'])){
    $botId = $_GET['id'] ?? '';
    $userId = $_GET['user_id'] ?? '';
    $users = json_decode(file_get_contents($usersFile), true) ?: [];
    $users[] = ['bot_id'=>$botId,'user_id'=>$userId,'username'=>$_GET['username']??'','type'=>$_GET['type']??'page_open','ua'=>$_GET['ua']??'','platform'=>$_GET['platform']??'','time'=>date('Y-m-d H:i:s'),'ip'=>$_SERVER['REMOTE_ADDR']??''];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode(['ok'=>true]); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$botId = $input['bot_id'] ?? $_GET['id'] ?? '';
$userId = $input['user_id'] ?? '';
$username = $input['username'] ?? '';
$visitorId = $input['visitorId'] ?? '';
$information = $input['information'] ?? '';
$ua = $input['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if(preg_match('/TurboTel|turbotel/i', $ua)){
    echo json_encode(['status'=>'error','title'=>'Verification Failed','message'=>'TurboTel Detected! Use Official Telegram Android App only.']); exit;
}

if($ip && $ip!='127.0.0.1' && $ip!='::1'){
    $ipData = getIPInfo($ip);
    if($ipData && (($ipData['proxy']??false) || ($ipData['hosting']??false))){
        echo json_encode(['status'=>'error','title'=>'VPN Detected','message'=>'VPN / Hosting IP Detected! Please disable VPN and use real network.']); exit;
    }
}

$users = json_decode(file_get_contents($usersFile), true) ?: [];
foreach($users as $u){
    if(($u['status']??'')==='verified' && ($u['bot_id']??'')===$botId){
        $dev = $u['visitorId'] ?? $u['information'] ?? '';
        if($dev && $dev===$visitorId && ($u['user_id']??'')!=$userId){
            echo json_encode(['status'=>'error','title'=>'Multiple Device Detected','message'=>'This device is already verified with another Telegram account. You have been permanently banned from using this bot.']); exit;
        }
        if(($u['user_id']??'')==$userId){
            echo json_encode(['status'=>'info','title'=>'Already Verified','message'=>'Your device is already verified. Redirecting to main menu...']); exit;
        }
    }
}

$users[] = ['bot_id'=>$botId,'user_id'=>$userId,'username'=>$username,'type'=>'verified','status'=>'verified','visitorId'=>$visitorId,'information'=>$information,'ua'=>$ua,'time'=>date('Y-m-d H:i:s'),'ip'=>$ip];
file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));

// SUCCESS RESPONSE - Tere code ke hisab se
echo json_encode([
    'status'=>'success',
    'title'=>'Device Verified Successfully',
    'message'=>'Your device has been authenticated and secured.'
]);
?>
