<?php
// Independent of LifeOS installation/database. PHP 7.0+; Apache protects private/.
date_default_timezone_set('UTC');ini_set('display_errors','0');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/').'/';$secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
session_name('lifeos_access_'.substr(hash('sha256',__DIR__),0,10));
if(PHP_VERSION_ID>=70300)session_set_cookie_params(array('lifetime'=>0,'path'=>$base,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'));else session_set_cookie_params(0,$base.'; SameSite=Lax','',$secure,true);
session_start();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
function access_reply($data,$code=200){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
function access_text($data,$field,$limit,$required=false){$text=isset($data[$field])?$data[$field]:'';if(!is_string($text)||preg_match('//u',$text)!==1||strlen($text)>$limit*4)access_reply(array('error'=>'Please check the '.$field.' field.'),400);$text=trim($text);$length=preg_match_all('/./us',$text,$unused);if($length>$limit||($required&&$text===''))access_reply(array('error'=>'Please check the '.$field.' field.'),400);return $text;}
if($_SERVER['REQUEST_METHOD']==='GET')access_reply(array('csrf'=>$_SESSION['csrf']));
if($_SERVER['REQUEST_METHOD']!=='POST')access_reply(array('error'=>'Method not allowed.'),405);
if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16384)access_reply(array('error'=>'This request is too large.'),413);
$token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token))access_reply(array('error'=>'The form session changed. Please submit again.'),419);
$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data))access_reply(array('error'=>'Invalid request.'),400);
if(!empty($data['website']))access_reply(array('ok'=>true));
$name=access_text($data,'name',100,true);$email=strtolower(access_text($data,'email',190,true));if(!filter_var($email,FILTER_VALIDATE_EMAIL))access_reply(array('error'=>'Enter a valid email address.'),400);
$use=access_text($data,'useCase',80,true);if(!in_array($use,array('Personal goals and habits','Work and personal projects','Family plans and shared tasks','A bit of everything'),true))access_reply(array('error'=>'Choose what you would like to organize.'),400);
$people=access_text($data,'people',40,true);if(!in_array($people,array('Just me','Me and a few others','A small team','Not sure yet'),true))access_reply(array('error'=>'Choose who will use LifeOS.'),400);
if(($data['consent'] ?? '')!=='yes')access_reply(array('error'=>'Please agree to being contacted about your request.'),400);
$zone=access_text($data,'timezone',80);$message=access_text($data,'message',2000);$variant=access_text($data,'variant',20);if(!in_array($variant,array('lavender'),true))$variant='unknown';
$storage=getenv('LIFEOS_LANDING_STORAGE')?:__DIR__.'/private';
if(!is_dir($storage)||!is_writable($storage))access_reply(array('error'=>'The request form is temporarily unavailable. Please try again later.'),503);
// File locks keep the rate limit consistent across simultaneous requests.
$bucket=$storage.'/rate-'.hash('sha256',__DIR__.($_SERVER['REMOTE_ADDR'] ?? 'unknown')).'.json';$rate=@fopen($bucket,'c+');if(!$rate||!flock($rate,LOCK_EX))access_reply(array('error'=>'The request form is temporarily unavailable.'),503);
$history=json_decode(stream_get_contents($rate),true);$history=is_array($history)?array_values(array_filter($history,function($time){return is_int($time)&&$time>time()-3600;})):array();
if(count($history)>=5){flock($rate,LOCK_UN);fclose($rate);header('Retry-After: 3600');access_reply(array('error'=>'Too many requests. Please try again in an hour.'),429);}
$history[]=time();rewind($rate);ftruncate($rate,0);fwrite($rate,json_encode($history));fflush($rate);flock($rate,LOCK_UN);fclose($rate);
$record=array('id'=>bin2hex(random_bytes(12)),'created_at'=>gmdate('c'),'status'=>'new','name'=>$name,'email'=>$email,'use'=>$use,'people'=>$people,'timezone'=>$zone,'message'=>$message,'consent'=>true,'privacy_version'=>'1','design'=>$variant);
$line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;$file=@fopen($storage.'/requests.jsonl','ab');
if(!$file||!flock($file,LOCK_EX))access_reply(array('error'=>'Your request could not be saved. Please try again later.'),503);
$written=fwrite($file,$line);fflush($file);flock($file,LOCK_UN);fclose($file);if($written!==strlen($line))access_reply(array('error'=>'Your request could not be saved. Please try again later.'),503);
access_reply(array('ok'=>true));
