<?php
ob_start(); error_reporting(0); ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ echo json_encode(['ok'=>true]); exit; }

$dataDir = __DIR__ . '/data'; $botsFile = $dataDir . '/bots.json'; $usersFile = $dataDir . '/users.json';
if(!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
if(!file_exists($botsFile)) @file_put_contents($botsFile, '[]');
if(!file_exists($usersFile)) @file_put_contents($usersFile, '[]');

function ipCheck($ip){
  $ch=curl_init("http://ip-api.com/json/{$ip}?fields=proxy,hosting,org,status");
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>false]);
  $r=@curl_exec($ch); @curl_close($ch); return json_decode($r,true);
}
function jsonOut($arr){ ob_clean(); echo json_encode($arr); exit; }

if(isset($_GET['collect'])){
  $bid=$_GET['id']??''; $uid=$_GET['user_id']??'';
  $users=@json_decode(@file_get_contents($usersFile),true)?:[];
  $users[]=['bot_id'=>$bid,'user_id'=>$uid,'username'=>$_GET['username']??'','type'=>$_GET['type']??'page_open','ua'=>$_GET['ua']??'','ip'=>$_SERVER['REMOTE_ADDR']??'','time'=>date('Y-m-d H:i:s')];
  @file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
  jsonOut(['ok'=>true]);
}

$in = @json_decode(file_get_contents('php://input'), true) ?: [];
$botId = $in['bot_id'] ?? $in['id'] ?? $in['bot'] ?? $_GET['id'] ?? '';
$uid = $in['user_id'] ?? ''; $un = $in['username'] ?? '';
$vid = $in['visitorId'] ?? ''; $info = $in['information'] ?? $vid;
$ua = $in['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''; $initData = $in['initData'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if(empty($botId) || empty($uid)){
  jsonOut(['status'=>'error','title'=>'Invalid Request','message'=>'Missing bot ID or user ID. Open via bot button.']);
}

$low = strtolower($ua);
if(strpos($low,'turbotel')!==false || strpos($low,'turbo')!==false || empty($initData) || strlen($initData) < 10){
  $users=@json_decode(@file_get_contents($usersFile),true)?:[];
  $users[]=['bot_id'=>$botId,'user_id'=>$uid,'username'=>$un,'type'=>'blocked_turbotel','status'=>'blocked','visitorId'=>$vid,'ua'=>$ua,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
  @file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
  jsonOut(['status'=>'error','title'=>'TurboTel Detected','message'=>'TurboTel / Fake Telegram Client Detected! Use Official Telegram App only. You are banned.']);
}

if($ip!='127.0.0.1' && $ip!='::1' && !empty($ip)){
  $chk = ipCheck($ip);
  if($chk && (($chk['proxy']??false) || ($chk['hosting']??false))){
    $users=@json_decode(@file_get_contents($usersFile),true)?:[];
    $users[]=['bot_id'=>$botId,'user_id'=>$uid,'type'=>'blocked_vpn','status'=>'blocked','org'=>$chk['org']??'','ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
    @file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    jsonOut(['status'=>'error','title'=>'VPN Detected','message'=>'VPN / Proxy / Hosting IP Detected! Disable VPN. You are banned.']);
  }
}

$users = @json_decode(@file_get_contents($usersFile),true)?:[];
foreach($users as $u){
  if(($u['status']??'')==='verified' && ($u['bot_id']??'')==$botId){
    $d = $u['visitorId']??$u['information']??''; $dPure = explode('x',$d)[0]; $vidPure = explode('x',$vid)[0];
    if(!empty($dPure) && !empty($vidPure) && $dPure==$vidPure && ($u['user_id']??'')!=$uid){
      jsonOut(['status'=>'error','title'=>'Multiple Device Detected','message'=>'This device is already verified with another Telegram account. You are permanently banned.']);
    }
    if(($u['user_id']??'')==$uid){
      jsonOut(['status'=>'info','title'=>'Already Verified','message'=>'This Account Has Already Completed Verification.']);
    }
  }
}
$users[]=['bot_id'=>$botId,'user_id'=>$uid,'username'=>$un,'type'=>'verified','status'=>'verified','visitorId'=>$vid,'information'=>$info,'ua'=>$ua,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
@file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
jsonOut(['status'=>'success','title'=>'Device Verified Successfully','message'=>'Your Device Is Now Verified.']);
?>
