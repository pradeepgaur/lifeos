<?php
// Durable browser sign-in, independent of a host's short-lived PHP session files.
// The cookie holds a random secret; only its SHA-256 digest is stored in MySQL.
function login_cookie_name(){return session_name().'_login';}
function auth_cookie($name,$value,$expires){
    $p=session_get_cookie_params();
    if(PHP_VERSION_ID>=70300)setcookie($name,$value,array('expires'=>$expires,'path'=>$p['path'],'secure'=>$p['secure'],'httponly'=>true,'samesite'=>'Lax'));
    else setcookie($name,$value,$expires,$p['path'],'',$p['secure'],true);
}
function login_cookie_parts(){
    $value=$_COOKIE[login_cookie_name()] ?? '';
    if(!is_string($value)||!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/',$value,$m))return null;
    return array($m[1],$m[2]);
}
function revoke_browser_login(){
    $selector=$_SESSION['login_selector'] ?? null;
    if($selector)query('DELETE FROM lifeos_logins WHERE selector=?',array($selector));
    unset($_SESSION['login_selector'],$_SESSION['login_renewed']);
    auth_cookie(login_cookie_name(),'',time()-3600);unset($_COOKIE[login_cookie_name()]);
}
function remember_browser($u){
    if(!empty($_SESSION['login_selector']))query('DELETE FROM lifeos_logins WHERE selector=?',array($_SESSION['login_selector']));
    $selector=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));$now=time();
    query('INSERT INTO lifeos_logins(selector,user_id,validator_hash,csrf,session_version,expires_at,last_seen) VALUES(?,?,?,?,?,?,?)',array($selector,$u['id'],hash('sha256',$secret),$_SESSION['csrf'],$u['session_version'],gmdate('Y-m-d H:i:s',$now+LIFEOS_LOGIN_LIFETIME),gmdate('Y-m-d H:i:s',$now)));
    $_SESSION['login_selector']=$selector;$_SESSION['login_renewed']=$now;
    $_COOKIE[login_cookie_name()]=$selector.':'.$secret;
    auth_cookie(login_cookie_name(),$_COOKIE[login_cookie_name()],$now+LIFEOS_LOGIN_LIFETIME);
    auth_cookie(session_name(),session_id(),$now+LIFEOS_LOGIN_LIFETIME);
    if(random_int(1,20)===1)query('DELETE FROM lifeos_logins WHERE expires_at<UTC_TIMESTAMP()');
}
function restore_browser_login(){
    if(!empty($_SESSION['uid'])||!installed())return;
    $parts=login_cookie_parts();if(!$parts)return;
    $login=row('SELECT l.*,u.session_version AS current_version FROM lifeos_logins l JOIN lifeos_users u ON u.id=l.user_id WHERE l.selector=? AND l.expires_at>UTC_TIMESTAMP()',array($parts[0]));
    if(!$login||!hash_equals($login['validator_hash'],hash('sha256',$parts[1]))||(int)$login['session_version']!==(int)$login['current_version']){
        auth_cookie(login_cookie_name(),'',time()-3600);unset($_COOKIE[login_cookie_name()]);return;
    }
    session_regenerate_id(true);
    $_SESSION['uid']=$login['user_id'];$_SESSION['sv']=$login['session_version'];
    // Preserve the CSRF token so an open editor can save after PHP garbage collection.
    $_SESSION['csrf']=$login['csrf'];$_SESSION['last']=strtotime($login['last_seen'].' UTC');
    $_SESSION['login_selector']=$login['selector'];$_SESSION['login_renewed']=$_SESSION['last'];
}
function renew_browser_login($u){
    if(empty($_SESSION['login_selector'])){remember_browser($u);return;}
    // Coalesce writes/cookie updates across frequent live-refresh requests.
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'&&time()-(int)($_SESSION['login_renewed'] ?? 0)<300)return;
    $parts=login_cookie_parts();
    if(!$parts||$parts[0]!==$_SESSION['login_selector']){remember_browser($u);return;}
    $now=time();query('UPDATE lifeos_logins SET expires_at=?,last_seen=? WHERE selector=? AND user_id=? AND session_version=?',array(gmdate('Y-m-d H:i:s',$now+LIFEOS_LOGIN_LIFETIME),gmdate('Y-m-d H:i:s',$now),$parts[0],$u['id'],$u['session_version']));
    $_SESSION['login_renewed']=$now;
    auth_cookie(login_cookie_name(),$parts[0].':'.$parts[1],$now+LIFEOS_LOGIN_LIFETIME);
    auth_cookie(session_name(),session_id(),$now+LIFEOS_LOGIN_LIFETIME);
}
function invalidate_other_logins($uid,$version){
    if(!empty($_SESSION['login_selector'])){
        query('DELETE FROM lifeos_logins WHERE user_id=? AND selector<>?',array($uid,$_SESSION['login_selector']));
        query('UPDATE lifeos_logins SET session_version=? WHERE selector=? AND user_id=?',array($version,$_SESSION['login_selector'],$uid));
    }else query('DELETE FROM lifeos_logins WHERE user_id=?',array($uid));
}
function sign_out(){
    revoke_browser_login();$_SESSION=array();auth_cookie(session_name(),'',time()-3600);session_destroy();
}
