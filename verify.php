<?php
ob_start(); error_reporting(0); ini_set('display_errors',0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ echo json_encode(['ok'=>true]); exit; }

$dataDir=__DIR__.'/data'; $usersFile=$dataDir.'/users.json';
if(!is_dir($dataDir)) @mkdir($dataDir,0777,true);
if(!file_exists($usersFile)) file_put_contents($usersFile,'[]');

function jsonOut($a){ ob_clean(); echo json_encode($a); exit; }
function ipCheck($ip){
  $ch=curl_init("http://ip-api.com/json/{$ip}?fields=proxy,hosting,org");
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>false]);
  $r=@curl_exec($ch); @curl_close($ch); return json_decode($r,true);
}

$in=@json_decode(file_get_contents('php://input'),true)?:[];
$botId=$in['bot_id']??$in['id']??$_GET['id']??'';
$uid=$in['user_id']??''; $hash=$in['bot_hash']??$_GET['bot_hash']??'';
$vid=$in['visitorId']??''; $info=$in['information']??'';
$canvas=$in['canvasHash']??''; $webgl=$in['webglHash']??'';
$screenInfo=$in['screenInfo']??''; $tz=$in['timezone']??''; $tzOff=$in['tzOffset']??'';
$lang=$in['language']??''; $plat=$in['platform']??''; $hw=$in['hardware']??'';
$touch=$in['touchPoints']??''; $ua=$in['ua']??$_SERVER['HTTP_USER_AGENT']??'';
$init=$in['initData']??''; $ip=$_SERVER['REMOTE_ADDR']??'';

if(empty($botId)||empty($uid)) jsonOut(['status'=>'error','title'=>'Invalid Request','message'=>'Open via bot button.']);

// TURBOTEL + VPN CHECK
$low=strtolower($ua);
if(strpos($low,'turbotel')!==false||strpos($low,'turbo')!==false||empty($init)||strlen($init)<10){
  $u=@json_decode(file_get_contents($usersFile),true)?:[];
  $u[]=['bot_id'=>$botId,'user_id'=>$uid,'type'=>'blocked_turbotel','status'=>'blocked','visitorId'=>$vid,'canvas'=>$canvas,'webgl'=>$webgl,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
  file_put_contents($usersFile, json_encode($u,JSON_PRETTY_PRINT));
  jsonOut(['status'=>'error','title'=>'TurboTel Detected','message'=>'TurboTel Detected! Use Official Telegram Only. Banned.','hash'=>$hash]);
}
if($ip!='127.0.0.1'){
  $chk=ipCheck($ip);
  if($chk&&(($chk['proxy']??false)||($chk['hosting']??false))){
    $u=@json_decode(file_get_contents($usersFile),true)?:[];
    $u[]=['bot_id'=>$botId,'user_id'=>$uid,'type'=>'blocked_vpn','status'=>'blocked','ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
    file_put_contents($usersFile, json_encode($u,JSON_PRETTY_PRINT));
    jsonOut(['status'=>'error','title'=>'VPN Detected','message'=>'VPN/Proxy Detected! Disable VPN. Banned.','hash'=>$hash]);
  }
}

// LOAD ALL USERS FOR THIS BOT
$users=@json_decode(file_get_contents($usersFile),true)?:[];
foreach($users as $x){
  if(($x['bot_id']??'')!=$botId) continue;
  if(($x['status']??'')!=='verified') continue;
  if(($x['user_id']??'')==$uid){
    jsonOut(['status'=>'info','title'=>'Already Verified','message'=>'Already Verified.','hash'=>$hash]);
  }
  
  // SAME DEVICE CHECK - MATCH ANY 2 DATA POINTS
  $matchCount=0;
  $reasons=[];
  
  $oldVid=explode('x',$x['visitorId']??$x['information']??'')[0];
  $newVid=explode('x',$vid)[0];
  if(!empty($oldVid)&&!empty($newVid)&&$oldVid==$newVid){ $matchCount++; $reasons[]="visitorId"; }
  
  if(!empty($canvas)&&!empty($x['canvas']??'')&&$canvas==$x['canvas']){ $matchCount++; $reasons[]="canvas"; }
  if(!empty($webgl)&&!empty($x['webgl']??'')&&$webgl==$x['webgl']&&$webgl!='no-webgl'){ $matchCount++; $reasons[]="webgl"; }
  if(!empty($screenInfo)&&!empty($x['screenInfo']??'')&&$screenInfo==$x['screenInfo']){ $matchCount++; $reasons[]="screen"; }
  if(!empty($tz)&&!empty($x['timezone']??'')&&$tz==$x['timezone']&&$tzOff==($x['tzOffset']??'')){ $matchCount++; $reasons[]="timezone"; }
  if(!empty($hw)&&!empty($x['hardware']??'')&&$hw==$x['hardware']&&$hw!='0x0'){ $matchCount++; $reasons[]="hardware"; }
  
  // If 2 or more match -> SAME DEVICE
  if($matchCount>=2){
    $users[]=['bot_id'=>$botId,'user_id'=>$uid,'type'=>'blocked_same_device','status'=>'blocked','matched_with'=>$x['user_id'],'match_count'=>$matchCount,'reasons'=>implode(',',$reasons),'visitorId'=>$vid,'canvas'=>$canvas,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
    file_put_contents($usersFile, json_encode($users,JSON_PRETTY_PRINT));
    jsonOut(['status'=>'error','title'=>'Multiple Device Detected','message'=>'This device is already verified with another Telegram account (matched: '.implode(',',$reasons).'). You are permanently banned.','hash'=>$hash]);
  }
}

// NEW USER - SAVE ALL DATA
$users[]=[
  'bot_id'=>$botId,'user_id'=>$uid,'username'=>$in['username']??'','type'=>'verified','status'=>'verified',
  'visitorId'=>$vid,'information'=>$info,'canvas'=>$canvas,'webgl'=>$webgl,'screenInfo'=>$screenInfo,
  'timezone'=>$tz,'tzOffset'=>$tzOff,'language'=>$lang,'platform'=>$plat,'hardware'=>$hw,'touchPoints'=>$touch,
  'ua'=>$ua,'ip'=>$ip,'hash'=>$hash,'time'=>date('Y-m-d H:i:s')
];
file_put_contents($usersFile, json_encode($users,JSON_PRETTY_PRINT));
jsonOut(['status'=>'success','title'=>'Device Verified Successfully','message'=>'Your Device Is Now Verified.','hash'=>$hash]);
?>
