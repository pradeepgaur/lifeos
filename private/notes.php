<?php
function note_scope_sql(){return '(SELECT note_id,board_id FROM lifeos_note_boards UNION SELECT l.note_id,t.board_id FROM lifeos_note_tasks l JOIN lifeos_tasks t ON t.id=l.task_id UNION SELECT l.note_id,bg.board_id FROM lifeos_note_goals l JOIN lifeos_board_goals bg ON bg.goal_id=l.goal_id)';}
function note_access($id,$uid,$edit=false,$lock=false){
    $n=row('SELECT * FROM lifeos_notes WHERE id=?'.($lock?' FOR UPDATE':''),array(id_value($id)));if(!$n)fail('Note not found.',404);
    $owner=(string)$n['owner_id']===(string)$uid;
    $roles=$owner?array():query('SELECT DISTINCT m.role FROM '.note_scope_sql().' s JOIN lifeos_members m ON m.board_id=s.board_id WHERE s.note_id=? AND m.user_id=?',array($n['id'],$uid))->fetchAll(PDO::FETCH_COLUMN);
    if(!$owner&&!$roles)fail('Note not found or access removed.',404);
    $n['canEdit']=$owner||(bool)array_intersect($roles,array('owner','editor'));$n['canManage']=$owner;
    if($edit&&!$n['canEdit'])fail('You can read this note, but cannot edit it.',403);return $n;
}
function note_links_bulk($ids,$uid){
    $map=array();if(!$ids)return $map;$in=implode(',',array_map('intval',$ids));
    $groups=array(
      'board'=>rows('SELECT l.note_id,l.board_id AS id,b.name FROM lifeos_note_boards l JOIN lifeos_boards b ON b.id=l.board_id JOIN lifeos_members m ON m.board_id=b.id AND m.user_id=? WHERE l.note_id IN ('.$in.')',array($uid)),
      'task'=>rows('SELECT l.note_id,l.task_id AS id,t.title AS name FROM lifeos_note_tasks l JOIN lifeos_tasks t ON t.id=l.task_id JOIN lifeos_members m ON m.board_id=t.board_id AND m.user_id=? WHERE l.note_id IN ('.$in.')',array($uid)),
      'goal'=>rows('SELECT l.note_id,l.goal_id AS id,g.name FROM lifeos_note_goals l JOIN lifeos_goals g ON g.id=l.goal_id WHERE l.note_id IN ('.$in.') AND (g.owner_id=? OR EXISTS(SELECT 1 FROM lifeos_board_goals bg JOIN lifeos_members m ON m.board_id=bg.board_id WHERE bg.goal_id=g.id AND m.user_id=?))',array($uid,$uid))
    );
    foreach($groups as $kind=>$rows)foreach($rows as $r)$map[$r['note_id']][]=array('kind'=>$kind,'id'=>(string)$r['id'],'name'=>$r['name']);return $map;
}
function note_links($id,$uid){$map=note_links_bulk(array($id),$uid);return isset($map[$id])?$map[$id]:array();}
function note_touch($id,$owner,$oldBoards=array()){
    $boards=query('SELECT DISTINCT board_id FROM '.note_scope_sql().' s WHERE s.note_id=?',array($id))->fetchAll(PDO::FETCH_COLUMN);
    foreach(array_unique(array_merge($oldBoards,$boards)) as $bid)query('UPDATE lifeos_boards SET sync_revision=sync_revision+1 WHERE id=?',array($bid));
    query('UPDATE lifeos_users SET sync_revision=sync_revision+1 WHERE id=?',array($owner));
}
function note_snapshot($uid,$search=''){
    $args=array($uid,$uid,$uid);$where='';if($search!==''){$where=' AND (n.title LIKE ? OR n.plain_text LIKE ?)';$needle='%'.strtr($search,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';$where=' AND (n.title LIKE ? ESCAPE \'!\' OR n.plain_text LIKE ? ESCAPE \'!\')';$args[]=$needle;$args[]=$needle;}
    $list=rows('SELECT n.id,n.owner_id,n.title,n.color,n.version,n.created_at,n.updated_at,LEFT(n.plain_text,320) AS excerpt,u.name AS author,COALESCE(p.pinned,0) AS pinned,COALESCE(p.archived,0) AS archived,(SELECT COUNT(*) FROM lifeos_note_files f WHERE f.note_id=n.id) AS files,(SELECT MIN(f.id) FROM lifeos_note_files f WHERE f.note_id=n.id AND f.mime LIKE \'image/%\') AS cover FROM lifeos_notes n JOIN lifeos_users u ON u.id=n.owner_id LEFT JOIN lifeos_note_prefs p ON p.note_id=n.id AND p.user_id=? WHERE (n.owner_id=? OR EXISTS(SELECT 1 FROM '.note_scope_sql().' s JOIN lifeos_members m ON m.board_id=s.board_id WHERE s.note_id=n.id AND m.user_id=?))'.$where.' ORDER BY pinned DESC,n.updated_at DESC,n.id DESC',$args);
    $linkMap=note_links_bulk(array_column($list,'id'),$uid);
    foreach($list as &$n){$n['id']=(string)$n['id'];$n['owner']=(string)$n['owner_id'];unset($n['owner_id']);$n['version']=(int)$n['version'];$n['pinned']=(bool)$n['pinned'];$n['archived']=(bool)$n['archived'];$n['files']=(int)$n['files'];$n['cover']=$n['cover']?(string)$n['cover']:'';$n['createdAt']=utc_iso($n['created_at']);$n['updatedAt']=utc_iso($n['updated_at']);unset($n['created_at'],$n['updated_at']);$n['links']=isset($linkMap[$n['id']])?$linkMap[$n['id']]:array();}unset($n);return $list;
}
function note_set_links($id,$uid,$links){
    if(!is_array($links)||count($links)>50)fail('Use up to 50 connections per note.');$clean=array();
    foreach($links as $l){if(!is_array($l)||!isset($l['kind'])||!in_array($l['kind'],array('board','task','goal'),true))fail('Invalid note connection.');$target=id_value($l['id'] ?? '');
        if($l['kind']==='board')board_access($target,$uid,array('owner','editor'),true);
        elseif($l['kind']==='task'){$t=row('SELECT board_id FROM lifeos_tasks WHERE id=?',array($target));if(!$t)fail('Task not found.',404);board_access($t['board_id'],$uid,array('owner','editor'),true);}
        else{if(!row('SELECT g.id FROM lifeos_goals g WHERE g.id=? AND (g.owner_id=? OR EXISTS(SELECT 1 FROM lifeos_board_goals bg JOIN lifeos_members m ON m.board_id=bg.board_id WHERE bg.goal_id=g.id AND m.user_id=? AND m.role IN (\'owner\',\'editor\')))',array($target,$uid,$uid)))fail('Choose a goal you own or can help maintain.',403);}
        $clean[$l['kind'].':'.$target]=array($l['kind'],$target);
    }
    // Keep inaccessible existing connections until their owner can deliberately remove them.
    $visible=note_links($id,$uid);foreach($visible as $l)query('DELETE FROM lifeos_note_'.$l['kind'].'s WHERE note_id=? AND '.$l['kind'].'_id=?',array($id,$l['id']));
    foreach($clean as $l)query('INSERT IGNORE INTO lifeos_note_'.$l[0].'s(note_id,'.$l[0].'_id) VALUES(?,?)',array($id,$l[1]));
}
function note_sanitize($html,$noteId){
    if(!is_string($html)||strlen($html)>100000||preg_match('//u',$html)!==1)fail('Use up to 100 KB of formatted text per note.');
    if(!class_exists('DOMDocument'))fail('The PHP DOM extension is required for rich-text notes.',503);
    $doc=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
    $files=query('SELECT id FROM lifeos_note_files WHERE note_id=? AND mime LIKE \'image/%\'',array($noteId))->fetchAll(PDO::FETCH_COLUMN);$allowed=array('p','div','br','strong','b','em','i','u','s','strike','mark','h1','h2','h3','ul','ol','li','blockquote','pre','code','a','input','img');
    $walk=function($parent)use(&$walk,$doc,$allowed,$files){foreach(iterator_to_array($parent->childNodes) as $node){
        if($node instanceof DOMText)continue;
        if(!($node instanceof DOMElement)){$parent->removeChild($node);continue;}
        $tag=strtolower($node->tagName);
        if(in_array($tag,array('script','style','iframe','object','embed','svg','math','form','textarea','select','button','template'),true)){$parent->removeChild($node);continue;}
        $href=$node->getAttribute('href');$file=$node->getAttribute('data-file');$checked=$node->hasAttribute('checked');$type=$node->getAttribute('type');
        while($node->attributes->length)$node->removeAttributeNode($node->attributes->item(0));
        if($tag==='a'&&preg_match('~^(https?://|mailto:)[^\s<>]+$~i',$href)){$node->setAttribute('href',$href);$node->setAttribute('rel','noopener noreferrer');}
        if($tag==='input'){if($type!=='checkbox'){$parent->removeChild($node);continue;}$node->setAttribute('type','checkbox');$node->setAttribute('aria-label','Checklist item');if($checked)$node->setAttribute('checked','checked');}
        if($tag==='img'){if(!ctype_digit($file)||!in_array($file,$files)){$parent->removeChild($node);continue;}$node->setAttribute('data-file',$file);$node->setAttribute('src','note-file.php?id='.$file);$node->setAttribute('alt','Attached image');}
        $walk($node);if(!in_array($tag,$allowed,true)){while($node->firstChild)$parent->insertBefore($node->firstChild,$node);$parent->removeChild($node);}
    }};$body=$doc->getElementsByTagName('body')->item(0);$walk($body);$out='';foreach($body->childNodes as $node)$out.=$doc->saveHTML($node);return $out;
}
