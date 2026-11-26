<?php
// Syntax deliberately compatible with PHP 7.0 and later.
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
define('LIFEOS_LOGIN_LIFETIME',7*24*60*60);
ini_set('session.gc_maxlifetime',(string)LIFEOS_LOGIN_LIFETIME);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base = rtrim($base, '/') . '/';
session_name('lifeos_' . substr(hash('sha256', __DIR__), 0, 12));
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array('lifetime'=>LIFEOS_LOGIN_LIFETIME,'path'=>$base,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'));
} else {
    session_set_cookie_params(LIFEOS_LOGIN_LIFETIME, $base . '; SameSite=Lax', '', $secure, true);
}
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function config_path() { $override = getenv('LIFEOS_CONFIG'); return $override ? $override : __DIR__ . '/config.php'; }
function installed() { return is_file(config_path()); }
function db_connect($c) {
    return new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
}
function db() { static $db; if (!$db) { $db = db_connect(require config_path()); ensure_schema($db); } return $db; }
function ensure_schema($pdo) {
    // Upgrade before any application transaction. DDL is deliberately outside transactions.
    try{$version=(int)$pdo->query('SELECT schema_version FROM lifeos_meta WHERE id=1')->fetchColumn();}
    catch(PDOException $e){if($e->getCode()!=='42S22')throw $e;$pdo->exec('ALTER TABLE lifeos_meta ADD schema_version INT NOT NULL DEFAULT 1');$version=1;}
    if($version>=11)return;
    $config=require config_path();$name='lifeos-migrate-'.substr(hash('sha256',$config['database']),0,32);$stmt=$pdo->prepare('SELECT GET_LOCK(?,10)');$stmt->execute(array($name));if(!$stmt->fetchColumn())throw new RuntimeException('Schema update busy. Retry shortly.');
    try{
        $version=(int)$pdo->query('SELECT schema_version FROM lifeos_meta WHERE id=1')->fetchColumn();if($version>=11)return;
        $schema=require __DIR__.'/schema.php';foreach(array('schedule','habit_checks','backgrounds','logins') as $table)$pdo->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$schema[$table]));
        foreach(array('users','boards') as $table){try{$pdo->exec('ALTER TABLE lifeos_'.$table.' ADD sync_revision BIGINT NOT NULL DEFAULT 1');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}}
        try{$pdo->exec('ALTER TABLE lifeos_users ADD font_scale SMALLINT NOT NULL DEFAULT 112');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}
        require_once __DIR__.'/workspace-migration.php';
        if($version<7)migrate_workspace($pdo,$schema);
        foreach(array('start_time','end_time') as $field){try{$pdo->exec('ALTER TABLE lifeos_tasks ADD '.$field.' CHAR(5) NULL');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}}
        foreach(array('notes','note_prefs','note_files','note_boards','note_tasks','note_goals') as $table)$pdo->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$schema[$table]));
        // Repair attachment columns from the first Notes rollout, keeping every file.
        $fileColumns=$pdo->query('SHOW COLUMNS FROM lifeos_note_files')->fetchAll(PDO::FETCH_COLUMN);
        if(!in_array('bytes',$fileColumns,true)&&in_array('size',$fileColumns,true))$pdo->exec('ALTER TABLE lifeos_note_files CHANGE size bytes INT NOT NULL');
        if(!in_array('data',$fileColumns,true)&&in_array('content',$fileColumns,true))$pdo->exec('ALTER TABLE lifeos_note_files CHANGE content data MEDIUMBLOB NOT NULL');
        try{$pdo->exec('ALTER TABLE lifeos_tasks ADD sort_order BIGINT NOT NULL DEFAULT 0, ADD KEY task_order(board_id,column_id,sort_order,id)');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}
        $pdo->exec('UPDATE lifeos_meta SET schema_version=11 WHERE id=1');
    }
    finally{$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute(array($name));}
}
function query($sql, $args=array()) { $s = db()->prepare($sql); $s->execute($args); return $s; }
function row($sql, $args=array()) { return query($sql,$args)->fetch(); }
function rows($sql, $args=array()) { return query($sql,$args)->fetchAll(); }
function out($value, $code=200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE_FLAG()); exit; }
function JSON_INVALID_UTF8_SUBSTITUTE_FLAG() { return defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0; }
function fail($message, $code=400) { throw new LifeError($message,$code); }
class LifeError extends Exception {}
function text_value($input,$key,$limit,$required=true) {
    if (!isset($input[$key]) || !is_string($input[$key])) { if ($required) fail('Missing '.$key.'.'); return ''; }
    $v=trim($input[$key]); if (($required && $v==='') || strlen($v)>$limit*4 || preg_match('//u',$v)!==1) fail('Invalid '.$key.'.');
    $count=preg_match_all('/./us',$v,$m); if($count>$limit) fail(ucfirst($key).' is too long.'); return $v;
}
function id_value($v) { if(!is_scalar($v)||!preg_match('/^[1-9][0-9]{0,18}$/',(string)$v)) fail('Invalid record ID.'); return (string)$v; }
function email_value($v) { if(!is_string($v)||strlen($v)>190||!filter_var($v,FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.'); return strtolower(trim($v)); }
function date_value($v) { if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))fail('Invalid date.'); return $v; }
function password_value($v) { if(!is_string($v)||strlen($v)<10||strlen($v)>72) fail('Use a password between 10 and 72 bytes.'); return $v; }
function csrf_check($token) { if(!is_string($token)||!hash_equals($_SESSION['csrf'],$token)) fail('Your session changed. Reload the page and try again.',419); }
function current_user() {
    if(empty($_SESSION['uid'])) fail('Please sign in.',401);
    if(empty($_SESSION['last'])||time()-$_SESSION['last']>LIFEOS_LOGIN_LIFETIME){revoke_browser_login();$_SESSION=array();fail('Sign-in expired after seven days of inactivity. Please sign in.',401);}
    $u=!empty($_SESSION['login_selector'])
        ?row('SELECT u.* FROM lifeos_users u JOIN lifeos_logins l ON l.user_id=u.id AND l.session_version=u.session_version WHERE u.id=? AND l.selector=? AND l.expires_at>UTC_TIMESTAMP()',array($_SESSION['uid'],$_SESSION['login_selector']))
        :row('SELECT * FROM lifeos_users WHERE id=?',array($_SESSION['uid']));
    if(!$u || (int)$u['session_version']!==(int)$_SESSION['sv']){revoke_browser_login();$_SESSION=array();fail('Please sign in again.',401);}
    renew_browser_login($u);$_SESSION['last']=time(); return $u;
}
function sign_in($u) { if(!empty($_SESSION['login_selector']))revoke_browser_login();session_regenerate_id(true); $_SESSION['uid']=$u['id']; $_SESSION['sv']=$u['session_version']; $_SESSION['last']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));remember_browser($u); }
function public_user($u) { return array('id'=>(string)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'theme'=>$u['theme'],'fontScale'=>(int)$u['font_scale']); }
function utc_iso($v) { return $v ? str_replace(' ','T',$v).'Z' : null; }
function revision() {
    // One indexed query. Other people's private boards never invalidate this user's cache.
    $versions=rows('SELECT u.sync_revision AS user_revision,b.id,b.sync_revision,m.role FROM lifeos_users u LEFT JOIN lifeos_members m ON m.user_id=u.id LEFT JOIN lifeos_boards b ON b.id=m.board_id WHERE u.id=? ORDER BY b.id',array($_SESSION['uid']));
    return hash('sha256',json_encode($versions));
}
function bump($boardId=null) {
    if($boardId)query('UPDATE lifeos_boards SET sync_revision=sync_revision+1 WHERE id=?',array($boardId));
    elseif(!empty($_SESSION['uid']))query('UPDATE lifeos_boards b JOIN lifeos_members m ON m.board_id=b.id SET b.sync_revision=b.sync_revision+1 WHERE m.user_id=?',array($_SESSION['uid']));
    if(!empty($_SESSION['uid']))query('UPDATE lifeos_users SET sync_revision=sync_revision+1 WHERE id=?',array($_SESSION['uid']));
}
function board_access($id,$uid,$roles=null,$lock=false) {
    $b=row('SELECT b.*, m.role FROM lifeos_boards b JOIN lifeos_members m ON m.board_id=b.id WHERE b.id=? AND m.user_id=?'.($lock?' FOR UPDATE':''),array(id_value($id),$uid));
    if(!$b) fail('Board not found or access removed.',404);
    if($roles && !in_array($b['role'],$roles,true)) fail('You do not have permission for this action.',403);
    return $b;
}
function check_version($entity,$d) { if(!isset($d['version'])||(int)$d['version']!==(int)$entity['version']) fail('Someone changed this item. Reload its latest version before saving.',409); }
function create_board($uid,$name) {
    query('INSERT INTO lifeos_boards(name,owner_id,created_at) VALUES(?,?,UTC_TIMESTAMP())',array($name,$uid)); $id=(string)db()->lastInsertId();
    query('INSERT INTO lifeos_members(board_id,user_id,role) VALUES(?,?,?)',array($id,$uid,'owner'));
    $position=0;
    foreach(array('todo'=>'To do','progress'=>'In progress','review'=>'In review','done'=>'Done') as $key=>$title) query('INSERT INTO lifeos_columns(board_id,id,name,position,is_done) VALUES(?,?,?,?,?)',array($id,$key,$title,$position++,$key==='done'?1:0));
    return $id;
}
function throttle($label,$max=12) {
    $c=require config_path(); $bucket=hash_hmac('sha256',$label,$c['key']); $now=time();
    query('INSERT INTO lifeos_attempts(bucket,attempts,window_start) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(window_start<?,1,attempts+1),window_start=IF(window_start<?,VALUES(window_start),window_start)',array($bucket,$now,$now-900,$now-900));
    $v=row('SELECT attempts FROM lifeos_attempts WHERE bucket=?',array($bucket)); if((int)$v['attempts']>$max) fail('Too many attempts. Try again in 15 minutes.',429);
    if(random_int(1,50)===1)query('DELETE FROM lifeos_attempts WHERE window_start<?',array($now-86400));
}
require __DIR__.'/sessions.php';
restore_browser_login();
