<?php
require __DIR__.'/private/bootstrap.php';require __DIR__.'/private/notes.php';
try{
    if(!installed())fail('Setup required.',503);$u=current_user();$uid=$u['id'];$post=$_SERVER['REQUEST_METHOD']==='POST';if(!$post&&$_SERVER['REQUEST_METHOD']!=='GET')fail('Method not allowed.',405);
    if(!$post){$action=$_GET['action'] ?? 'list';if($action==='list'){out(array('notes'=>note_snapshot($uid,text_value($_GET,'q',200,false))));}if($action!=='get')fail('Unknown action.',404);
        $n=note_access($_GET['id'] ?? '',$uid);$files=rows('SELECT id,name,mime,bytes FROM lifeos_note_files WHERE note_id=? ORDER BY id',array($n['id']));foreach($files as &$f){$f['id']=(string)$f['id'];$f['bytes']=(int)$f['bytes'];}unset($f);
        out(array('note'=>array('id'=>(string)$n['id'],'owner'=>(string)$n['owner_id'],'title'=>$n['title'],'body'=>$n['body'],'color'=>$n['color'],'version'=>(int)$n['version'],'canEdit'=>$n['canEdit'],'canManage'=>$n['canManage'],'shared'=>(bool)row('SELECT note_id FROM '.note_scope_sql().' s WHERE note_id=? LIMIT 1',array($n['id'])),'createdAt'=>utc_iso($n['created_at']),'updatedAt'=>utc_iso($n['updated_at']),'links'=>note_links($n['id'],$uid),'files'=>$files)));
    }
    csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>200000)fail('Note request is too large.',413);$d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))fail('Invalid request.');$action=$d['action'] ?? '';
    db()->beginTransaction();
    if($action==='create'){
        query('INSERT INTO lifeos_notes(owner_id,title,body,plain_text,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',array($uid,'Untitled note','',''));$id=(string)db()->lastInsertId();if(isset($d['links']))note_set_links($id,$uid,$d['links']);note_touch($id,$uid);$result=array('id'=>$id,'version'=>1);
    }else{
        $n=note_access($d['id'] ?? '',$uid,$action==='update',true);$id=$n['id'];$oldBoards=query('SELECT DISTINCT board_id FROM '.note_scope_sql().' s WHERE s.note_id=?',array($id))->fetchAll(PDO::FETCH_COLUMN);
        if($action==='prefs'){
            query('INSERT INTO lifeos_note_prefs(note_id,user_id,pinned,archived) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE pinned=VALUES(pinned),archived=VALUES(archived)',array($id,$uid,!empty($d['pinned'])?1:0,0));query('UPDATE lifeos_users SET sync_revision=sync_revision+1 WHERE id=?',array($uid));$result=array();
        }elseif($action==='private'){
            if(!$n['canManage'])fail('Only the note creator can change sharing.',403);check_version($n,$d);foreach(array('boards','tasks','goals') as $kind)query('DELETE FROM lifeos_note_'.$kind.' WHERE note_id=?',array($id));query('UPDATE lifeos_notes SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',array($id));note_touch($id,$n['owner_id'],$oldBoards);$result=array('version'=>(int)$n['version']+1);
        }elseif($action==='delete'){
            if(!$n['canManage'])fail('Only the note creator can delete it.',403);check_version($n,$d);query('DELETE FROM lifeos_notes WHERE id=?',array($id));note_touch($id,$n['owner_id'],$oldBoards);$result=array();
        }elseif($action==='update'){
            check_version($n,$d);$title=text_value($d,'title',200,false);$color=$d['color'] ?? 'default';if(!in_array($color,array('default','yellow','green','blue','pink','purple'),true))fail('Invalid note color.');
            $body=note_sanitize($d['body'] ?? '',$id);$plain=html_entity_decode(strip_tags(preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|h[1-3]|blockquote|pre))>~i',"\n",$body)),ENT_QUOTES,'UTF-8');if(strlen($plain)>60000)fail('Keep note text below 60 KB.');
            if(isset($d['links'])){if(!$n['canManage'])fail('Only the note creator can change its connections.',403);note_set_links($id,$uid,$d['links']);}
            query('UPDATE lifeos_notes SET title=?,body=?,plain_text=?,color=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',array($title?:'Untitled note',$body,$plain,$color,$id));note_touch($id,$n['owner_id'],$oldBoards);$result=array('version'=>(int)$n['version']+1,'body'=>$body);
        }else fail('Unknown action.',404);
    }
    db()->commit();out(array_merge(array('ok'=>true),$result));
}catch(LifeError $e){if(installed()&&db()->inTransaction())db()->rollBack();out(array('error'=>$e->getMessage()),$e->getCode()?:400);}
catch(Throwable $e){try{if(installed()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_log('LifeOS notes: '.$e->getMessage());out(array('error'=>'Could not save the note. Check the server log or database limits.'),500);}
