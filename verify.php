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
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// === PAGE OPEN COLLECT ===
if(isset($_GET['collect'])){
    $botId = $_GET['id'] ?? $_GET['bot_hash'] ?? '';
    $userId = $_GET['user_id'] ?? '';
    $type = $_GET['type'] ?? 'page_open';
    $users = json_decode(file_get_contents($usersFile), true) ?: [];
    $last = end($users);
    // 5 sec duplicate block
    if($last && ($last['type']??'')=='page_open' && ($last['bot_id']??'')==$botId && ($last['user_id']??'')==$userId && (time()-strtotime($last['time']??'now')<5)){
        echo json_encode(['ok'=>true]); exit;
    }
    $users[] = [
        'bot_id'=> $botId,
        'user_id'=> $userId,
        'username'=> $_GET['username'] ?? '',
        'type'=> $type,
        'ua'=> $_GET['ua'] ?? '',
        'platform'=> $_GET['platform'] ?? '',
        'time'=> date('Y-m-d H:i:s'),
        'ip'=> $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode(['ok'=>true]); exit;
}

// === MAIN VERIFICATION ===
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$botId = $input['bot_id'] ?? $input['id'] ?? $_GET['id'] ?? '';
$userId = $input['user_id'] ?? '';
$username = $input['username'] ?? '';
$visitorId = $input['visitorId'] ?? $input['information'] ?? '';
$information = $input['information'] ?? '';
$ua = $input['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// 1. TurboTel Block
if(preg_match('/TurboTel|turbotel/i', $ua)){
    $users = json_decode(file_get_contents($usersFile), true) ?: [];
    $users[] = ['bot_id'=>$botId,'user_id'=>$userId,'username'=>$username,'type'=>'blocked_turbotel','status'=>'blocked','ua'=>$ua,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode(['status'=>'error','message'=>'TurboTel Detected! Use Official Telegram Android App only.']); exit;
}

// 2. VPN / Hosting IP Block
if($ip && $ip!='127.0.0.1' && $ip!='::1'){
    $ipData = getIPInfo($ip);
    if($ipData && (($ipData['proxy']??false) || ($ipData['hosting']??false))){
        $users = json_decode(file_get_contents($usersFile), true) ?: [];
        $users[] = ['bot_id'=>$botId,'user_id'=>$userId,'username'=>$username,'type'=>'blocked_vpn','status'=>'blocked','ua'=>$ua,'ip'=>$ip,'org'=>$ipData['org']??'','time'=>date('Y-m-d H:i:s')];
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        echo json_encode(['status'=>'error','message'=>'VPN / Hosting IP Detected! Please disable VPN and use real network.']); exit;
    }
}

// 3. Same Device + Already Verified Check
$users = json_decode(file_get_contents($usersFile), true) ?: [];
foreach($users as $u){
    if(($u['status']??'')==='verified' && ($u['bot_id']??'')===$botId){
        $dev = $u['visitorId'] ?? $u['information'] ?? '';
        if($dev && $dev===$visitorId && ($u['user_id']??'')!=$userId){
            echo json_encode(['status'=>'error','message'=>'This device is already verified with another Telegram account.']); exit;
        }
        if(($u['user_id']??'')==$userId){
            echo json_encode(['status'=>'already','message'=>'Already Verified']); exit;
        }
    }
}

// 4. Save as Verified
$users[] = [
    'bot_id'=>$botId, 'user_id'=>$userId, 'username'=>$username,
    'type'=>'verified', 'status'=>'verified',
    'visitorId'=>$visitorId, 'information'=>$information,
    'ua'=>$ua, 'time'=>date('Y-m-d H:i:s'), 'ip'=>$ip
];
file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
echo json_encode(['status'=>'success','message'=>'Device Verified Successfully!']);
?>
