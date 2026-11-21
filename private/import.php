<?php
// Called inside the authenticated API transaction. Imports into NEW, privately owned boards.
function import_prototype($data,$uid){
    if(!is_array($data))fail('No prototype data supplied.');
    $boards=$data['boards'] ?? array();$tasks=$data['tasks'] ?? array();$goals=$data['goals'] ?? array();$columns=$data['columns'] ?? array();
    if(!is_array($boards)||!is_array($tasks)||!is_array($goals)||!is_array($columns)||count($boards)>100||count($tasks)>3000||count($goals)>1000||count($boards)*count($goals)>5000)fail('Prototype is too large for a single import.');
    $maps=array();$goalMaps=array();$first=null;
    $sharedGoals=array();
    foreach($goals as $g){if(!is_array($g))fail('Invalid prototype goal.');query('INSERT INTO lifeos_goals(owner_id,name,description,priority,created_at) VALUES(?,?,?,?,?)',array($uid,text_value($g,'name',150),text_value($g,'description',10000,false),'Medium',import_date($g['createdAt'] ?? null)));$sharedGoals[(string)($g['id'] ?? '')]=(string)db()->lastInsertId();}
    foreach($boards as $name){
        $label=text_value(array('name'=>$name),'name',80);if(isset($maps[$label]))continue;
        $bid=create_board($uid,$label.' (imported)');if(!$first)$first=$bid;$maps[$label]=$bid;
        $source=isset($columns[$label])&&is_array($columns[$label])?$columns[$label]:array('todo'=>'To do','progress'=>'In progress','review'=>'In review','done'=>'Done');
        // Keep default columns; custom columns are appended and source names are respected.
        $position=4;
        foreach($source as $key=>$title){
            if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,60}$/',$key))fail('Invalid prototype column ID.');$title=text_value(array('name'=>$title),'name',80);
            query('INSERT INTO lifeos_columns(board_id,id,name,position,is_done) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)',array($bid,$key,$title,$position++,$key==='done'?1:0));
        }
        foreach($sharedGoals as $old=>$id){query('INSERT INTO lifeos_board_goals(board_id,goal_id) VALUES(?,?)',array($bid,$id));$goalMaps[$label][$old]=$id;}
    }
    foreach($tasks as $t){
        if(!is_array($t))fail('Invalid prototype task.');$label=$t['board'] ?? '';if(!is_string($label)||!isset($maps[$label]))fail('A task references a missing prototype board.');$bid=$maps[$label];
        $status=text_value($t,'status',60);if(!row('SELECT id FROM lifeos_columns WHERE board_id=? AND id=?',array($bid,$status)))fail('A task references a missing prototype column.');
        $priority=$t['priority'] ?? 'Medium';if(!in_array($priority,array('Low','Medium','High'),true))fail('Invalid task priority.');
        $due=$t['due'] ?? '';if($due!==''&&(!is_string($due)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||!checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4))))fail('Invalid imported due date.');
        $checks=$t['checks'] ?? array();if(!is_array($checks)||count($checks)>200)fail('Invalid imported checklist.');$clean=array();foreach($checks as $c)$clean[]=array('text'=>text_value($c,'text',500),'done'=>!empty($c['done']));
        $goal=isset($goalMaps[$label][(string)($t['goal'] ?? '')])?$goalMaps[$label][(string)$t['goal']]:null;
        query('INSERT INTO lifeos_tasks(board_id,column_id,title,description,priority,area,due,goal_id,assignee_id,checks,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',array($bid,$status,text_value($t,'title',250),text_value($t,'description',20000,false),$priority,text_value($t,'area',80,false),$due?:null,$goal,$uid,json_encode($clean),import_date($t['createdAt'] ?? null)));
        $tid=db()->lastInsertId();$comments=$t['comments'] ?? array();if(!is_array($comments)||count($comments)>500)fail('Invalid imported comments.');
        foreach($comments as $c){$body='[Imported comment'.(!empty($c['author'])?' from '.text_value($c,'author',100):'')."]\n".text_value($c,'text',4500);query('INSERT INTO lifeos_comments(task_id,user_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP())',array($tid,$uid,$body));}
    }
    return $first;
}
function import_date($value){if(!$value)return null;if(!is_string($value)||strlen($value)>40)fail('Invalid creation date.');$time=strtotime($value);if($time===false||$time>time()+60||$time<946684800)fail('Invalid creation date.');return gmdate('Y-m-d H:i:s',$time);}
