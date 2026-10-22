<?php
ob_start(); error_reporting(0); ini_set('display_errors',0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ echo json_encode(['ok'=>true]); exit; }

$dataDir=__DIR__.'/data'; 
$usersFile=$dataDir.'/users.json';
if(!is_dir($dataDir)) @mkdir($dataDir,0777,true);
if(!file_exists($usersFile)) file_put_contents($usersFile,'[]',LOCK_EX);

function jsonOut($a){ 
    if(ob_get_length()) ob_clean(); 
    echo json_encode($a); 
    exit; 
}

function ipCheck($ip){
  if($ip=='127.0.0.1' || $ip=='::1') return null;
  $ch=curl_init("http://ip-api.com/json/{$ip}?fields=proxy,hosting,org,status");
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>4,
    CURLOPT_CONNECTTIMEOUT=>2,
    CURLOPT_SSL_VERIFYPEER=>false,
    CURLOPT_USERAGENT=>'Mozilla/5.0'
  ]);
  $r=@curl_exec($ch); 
  $code=curl_getinfo($ch, CURLINFO_HTTP_CODE);
  @curl_close($ch); 
  if($code!=200 || !$r) return null; // API fail = skip check, don't block
  $d=json_decode($r,true);
  if(($d['status']??'')!=='success') return null;
  return $d;
}

$raw=file_get_contents('php://input');
$in=@json_decode($raw,true)?:[];
$botId=$in['bot_id']??$in['id']??$_GET['id']??'';
$uid=$in['user_id']??''; 
$hash=$in['bot_hash']??$in['hash']??$_GET['bot_hash']??$_GET['hash']??'';
$vid=$in['visitorId']??''; 
$info=$in['information']??'';
$canvas=$in['canvasHash']??''; 
$webgl=$in['webglHash']??'';
$screenInfo=$in['screenInfo']??''; 
$tz=$in['timezone']??''; 
$tzOff=$in['tzOffset']??'';
$lang=$in['language']??''; 
$plat=$in['platform']??''; 
$hw=$in['hardware']??'';
$touch=$in['touchPoints']??''; 
$ua=$in['ua']??$_SERVER['HTTP_USER_AGENT']??'';
$init=$in['initData']??''; 
$username=$in['username']??'';
$ip=$_SERVER['REMOTE_ADDR']??'';
if(!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip=$_SERVER['HTTP_CF_CONNECTING_IP'];
if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip=explode(',',$_SERVER['HTTP_X_FORWARDED_FOR'])[0];

if(empty($botId)||empty($uid)) jsonOut(['status'=>'error','title'=>'Invalid Request','message'=>'Open via bot button.','hash'=>$hash,'bot_hash'=>$hash]);

// TURBOTEL CHECK
$low=strtolower($ua);
if(strpos($low,'turbotel')!==false||strpos($low,'turbo')!==false||empty($init)||strlen($init)<10){
  $u=@json_decode(@file_get_contents($usersFile),true)?:[];
  $u[]=['bot_id'=>$botId,'user_id'=>$uid,'username'=>$username,'type'=>'blocked_turbotel','status'=>'blocked','visitorId'=>$vid,'canvas'=>$canvas,'webgl'=>$webgl,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')];
  file_put_contents($usersFile, json_encode($u,JSON_PRETTY_PRINT), LOCK_EX);
  jsonOut(['status'=>'error','title'=>'TurboTel Detected','message'=>'TurboTel Detected! Use Official Telegram Only. Banned.','hash'=>$hash,'bot_hash'=>$hash]);
}

// VPN CHECK - Agar API fail hua to block mat karo
if($ip!='127.0.0.1' && $ip!='::1'){
  $chk=ipCheck(trim($ip));
  if($chk && (($chk['proxy']??false)===true || ($chk['hosting']??false)===true)){
    $u=@json_decode(@file_get_contents($usersFile),true)?:[];
    $u[]=['bot_id'=>$botId,'user_id'=>$uid,'username'=>$username,'type'=>'blocked_vpn','status'=>'blocked','ip'=>$ip,'org'=>$chk['org']??'','time'=>date('Y-m-d H:i:s')];
    file_put_contents($usersFile, json_encode($u,JSON_PRETTY_PRINT), LOCK_EX);
    jsonOut(['status'=>'error','title'=>'VPN Detected','message'=>'VPN/Proxy Detected! Disable VPN. Banned.','hash'=>$hash,'bot_hash'=>$hash]);
  }
}

// LOAD USERS
$users=@json_decode(@file_get_contents($usersFile),true)?:[];
if(!is_array($users)) $users=[];

foreach($users as $x){
  if(($x['bot_id']??'')!=$botId) continue;
  if(($x['status']??'')!=='verified') continue;
  
  // SAME USER - Already Verified
  if(($x['user_id']??'')==$uid){
    jsonOut(['status'=>'info','title'=>'Already Verified','message'=>'Already Verified.','hash'=>$hash,'bot_hash'=>$hash]);
  }
  
  // SAME DEVICE CHECK - 2 MATCH REQUIRED
  $matchCount=0;
  $reasons=[];
  
  $oldVid=explode('x',$x['visitorId']??$x['information']??'')[0]??'';
  $newVid=explode('x',$vid)[0]??'';
  if(!empty($oldVid)&&!empty($newVid)&&$oldVid==$newVid&&strlen($oldVid)>10){ $matchCount++; $reasons[]="visitorId"; }
  if(!empty($canvas)&&!empty($x['canvas']??'')&&$canvas==$x['canvas']&&strlen($canvas)>20){ $matchCount++; $reasons[]="canvas"; }
  if(!empty($webgl)&&!empty($x['webgl']??'')&&$webgl==$x['webgl']&&$webgl!='no-webgl'&&strlen($webgl)>5){ $matchCount++; $reasons[]="webgl"; }
  if(!empty($screenInfo)&&!empty($x['screenInfo']??'')&&$screenInfo==$x['screenInfo']){ $matchCount++; $reasons[]="screen"; }
  if(!empty($tz)&&!empty($x['timezone']??'')&&$tz==$x['timezone']&&strval($tzOff)===strval($x['tzOffset']??'')){ $matchCount++; $reasons[]="timezone"; }
  if(!empty($hw)&&!empty($x['hardware']??'')&&$hw==$x['hardware']&&$hw!='0x0'&&$hw!=''){ $matchCount++; $reasons[]="hardware"; }
  
  if($matchCount>=2){
    $users[]=[
      'bot_id'=>$botId,'user_id'=>$uid,'username'=>$username,'type'=>'blocked_same_device','status'=>'blocked',
      'matched_with'=>$x['user_id'],'match_count'=>$matchCount,'reasons'=>implode(',',$reasons),
      'visitorId'=>$vid,'canvas'=>$canvas,'webgl'=>$webgl,'screenInfo'=>$screenInfo,'ip'=>$ip,'time'=>date('Y-m-d H:i:s')
    ];
    file_put_contents($usersFile, json_encode($users,JSON_PRETTY_PRINT), LOCK_EX);
    // TITLE = Used Device Detected (isse tere red screen ayega)
    jsonOut([
      'status'=>'error',
      'title'=>'Used Device Detected',
      'message'=>'Warning: This physical phone is already linked to another account (matched: '.implode(',',$reasons).').',
      'hash'=>$hash,
      'bot_hash'=>$hash,
      'match_reasons'=>$reasons
    ]);
  }
}

// NEW USER - SAVE
$users[]=[
  'bot_id'=>$botId,'user_id'=>$uid,'username'=>$username,'type'=>'verified','status'=>'verified',
  'visitorId'=>$vid,'information'=>$info,'canvas'=>$canvas,'webgl'=>$webgl,'screenInfo'=>$screenInfo,
  'timezone'=>$tz,'tzOffset'=>$tzOff,'language'=>$lang,'platform'=>$plat,'hardware'=>$hw,'touchPoints'=>$touch,
  'ua'=>$ua,'ip'=>$ip,'hash'=>$hash,'time'=>date('Y-m-d H:i:s')
];
file_put_contents($usersFile, json_encode($users,JSON_PRETTY_PRINT), LOCK_EX);

jsonOut(['status'=>'success','title'=>'Device Verified Successfully','message'=>'Your Device Is Now Verified.','hash'=>$hash,'bot_hash'=>$hash]);
?>
