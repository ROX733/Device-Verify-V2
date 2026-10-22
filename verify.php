<?php
header('Content-Type: application/json');
$dataDir = __DIR__ . '/data';
$botsFile = $dataDir . '/bots.json';
$usersFile = $dataDir . '/users.json';
if(!is_dir($dataDir)) mkdir($dataDir, 0777, true);
if(!file_exists($botsFile)) file_put_contents($botsFile, '[]');
if(!file_exists($usersFile)) file_put_contents($usersFile, '[]');

if(isset($_GET['collect'])){
    $botId = $_GET['id'] ?? $_GET['bot_hash'] ?? '';
    $userId = $_GET['user_id'] ?? '';
    $type = $_GET['type'] ?? 'page_open';
    $users = json_decode(file_get_contents($usersFile), true);
    if(!is_array($users)) $users = [];
    $last = end($users);
    if($last && ($last['type']??'')==='page_open' && ($last['bot_id']??'')===$botId && ($last['user_id']??'')===$userId && (time()-strtotime($last['time']??'now')<5)){
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

$input = json_decode(file_get_contents('php://input'), true);
$botId = $input['bot_id'] ?? $input['id'] ?? $_GET['id'] ?? '';
$userId = $input['user_id'] ?? '';
$username = $input['username'] ?? '';
$visitorId = $input['visitorId'] ?? $input['information'] ?? '';
$information = $input['information'] ?? '';
$ua = $input['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';

// === 1. TURBOTEL / FAKE UA BLOCK ===
if(preg_match('/TurboTel|turbotel|FakeApp|Emulator/i', $ua)){
    echo json_encode(['status'=>'error','message'=>'VPN / Turbotel Detected! Use Official Telegram Android App only.']);
    exit;
}

// === 2. VPN / PROXY / DATACENTER IP BLOCK (Server Side) ===
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if($ip){
    $ipInfo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=org,proxy,hosting");
    if($ipInfo){
        $ipData = json_decode($ipInfo, true);
        if(($ipData['proxy']??false)===true || ($ipData['hosting']??false)===true || preg_match('/vpn|proxy|hosting|datacenter|turbo/i', $ipData['org']??'')){
            // Log as blocked
            $users = json_decode(file_get_contents($usersFile), true);
            $users[] = [
                'bot_id'=>$botId, 'user_id'=>$userId, 'username'=>$username,
                'type'=>'blocked_vpn', 'status'=>'blocked',
                'ua'=>$ua, 'ip'=>$ip, 'org'=>$ipData['org']??'',
                'time'=>date('Y-m-d H:i:s')
            ];
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            echo json_encode(['status'=>'error','message'=>'VPN / Proxy / Turbotel Detected! Please disable VPN and use real network.']);
            exit;
        }
    }
}

$bots = json_decode(file_get_contents($botsFile), true);
$users = json_decode(file_get_contents($usersFile), true);
if(!is_array($users)) $users=[];

foreach($users as $u){
    if(($u['status']??'')==='verified' && ($u['bot_id']??'')===$botId){
        $dev = $u['visitorId'] ?? $u['information'] ?? '';
        if($dev && $dev===$visitorId && ($u['user_id']??'')!=$userId){
            echo json_encode(['status'=>'error','message'=>'This device is already verified with another Telegram account.']);
            exit;
        }
        if(($u['user_id']??'')==$userId){
            echo json_encode(['status'=>'already','message'=>'Already Verified']); exit;
        }
    }
}

$users[] = [
    'bot_id'=>$botId, 'user_id'=>$userId, 'username'=>$username,
    'type'=>'verified', 'status'=>'verified',
    'visitorId'=>$visitorId, 'information'=>$information,
    'ua'=>$ua,
    'time'=>date('Y-m-d H:i:s'), 'ip'=>$ip
];
file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
echo json_encode(['status'=>'success','message'=>'Device Verified Successfully!']);
