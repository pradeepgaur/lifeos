<?php
require __DIR__.'/private/bootstrap.php';
require_once __DIR__.'/private/notes.php';
require __DIR__.'/private/task-order.php';
try {
    if(!installed()) out(array('error'=>'Setup is required.','setup'=>true),503);
    $method=$_SERVER['REQUEST_METHOD'];
    $d=array();
    if($method==='POST') {
        if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>1048576) fail('Request is too large.',413);
        $d=json_decode(file_get_contents('php://input'),true);
        if(!is_array($d)) fail('Invalid JSON request.');
        csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    } elseif($method!=='GET') fail('Method not allowed.',405);
    $action=$method==='GET'?($_GET['action'] ?? 'state'):($d['action'] ?? '');
    $getActions=array('session','state','invitation');
    if($method==='GET'&&!in_array($action,$getActions,true))fail('Use POST for this action.',405);
    if($action==='session') {
        $u=null; if(!empty($_SESSION['uid'])) { try{$u=current_user();}catch(LifeError $e){ if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32)); } }
        out(array('user'=>$u?public_user($u):null,'csrf'=>$_SESSION['csrf']));
    }
    if($action==='invitation') {
        $token=$_GET['token'] ?? ''; if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token))fail('Invalid invitation.',404);
        $v=row('SELECT i.email,i.role,i.expires_at,b.name FROM lifeos_invites i JOIN lifeos_boards b ON b.id=i.board_id WHERE i.token_hash=? AND i.expires_at>UTC_TIMESTAMP()',array(hash('sha256',$token)));
        if(!$v)fail('This invitation expired or was revoked.',404);
        out(array('invitation'=>$v));
    }
    if($action==='login') {
        throttle('login-ip:'.($_SERVER['REMOTE_ADDR'] ?? ''),40);
        $email=email_value($d['email'] ?? ''); throttle('login-email:'.$email);
        $u=row('SELECT * FROM lifeos_users WHERE email=?',array($email));
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $password=$d['password'] ?? ''; if(!is_string($password)||strlen($password)>200)fail('Invalid email or password.',401);
        $valid=password_verify($password,$u?$u['password']:$dummy);
        if(!$u||!$valid) fail('Invalid email or password.',401);
        sign_in($u); out(array('user'=>public_user($u),'csrf'=>$_SESSION['csrf']));
    }
    if($action==='register') {
        throttle('register:'.($_SERVER['REMOTE_ADDR'] ?? ''),10);
        $email=email_value($d['email'] ?? ''); $name=text_value($d,'name',100); $pass=password_value($d['password'] ?? '');
        $token=text_value($d,'token',64); if(!preg_match('/^[a-f0-9]{64}$/',$token))fail('Invalid invitation.');
        db()->beginTransaction();
        $inv=row('SELECT * FROM lifeos_invites WHERE token_hash=? AND email=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE',array(hash('sha256',$token),$email));
        if(!$inv)fail('No active invitation for this email address.',403);
        if(row('SELECT id FROM lifeos_users WHERE email=?',array($email)))fail('This email already has an account. Sign in to accept the invitation.',409);
        query('INSERT INTO lifeos_users(name,email,password,created_at) VALUES(?,?,?,UTC_TIMESTAMP())',array($name,$email,password_hash($pass,PASSWORD_DEFAULT)));
        $uid=db()->lastInsertId();
        query('INSERT INTO lifeos_members(board_id,user_id,role) VALUES(?,?,?)',array($inv['board_id'],$uid,$inv['role']));
        query('DELETE FROM lifeos_invites WHERE id=?',array($inv['id']));bump($inv['board_id']);db()->commit();
        $u=row('SELECT * FROM lifeos_users WHERE id=?',array($uid));sign_in($u);out(array('user'=>public_user($u),'csrf'=>$_SESSION['csrf']));
    }
    $u=current_user();$uid=$u['id'];
    if($action==='logout') { sign_out(); out(array('ok'=>true)); }
    if($action==='state') {
        db()->beginTransaction();$rev=revision();
        if(isset($_GET['revision'])&&(string)$_GET['revision']===$rev){db()->commit();out(array('unchanged'=>true,'revision'=>$rev));}
        $boards=rows('SELECT b.id,b.name,b.version,b.created_at,m.role,IF(bg.board_id IS NULL,0,1) AS hasBackground FROM lifeos_boards b JOIN lifeos_members m ON m.board_id=b.id LEFT JOIN lifeos_backgrounds bg ON bg.board_id=b.id WHERE m.user_id=? ORDER BY b.id',array($uid));
        $ids=array();foreach($boards as &$b){$b['id']=(string)$b['id'];$b['version']=(int)$b['version'];$b['createdAt']=utc_iso($b['created_at']);unset($b['created_at']);$ids[]=(int)$b['id'];}unset($b);
        $columns=array();$goals=array();$tasks=array();$members=array();$invites=array();$schedule=array();$habitChecks=array();
        if($ids){
            $in=implode(',',$ids);
            foreach(rows('SELECT * FROM lifeos_columns WHERE board_id IN ('.$in.') ORDER BY position,id') as $c){$bid=(string)$c['board_id'];if(!isset($columns[$bid]))$columns[$bid]=array();$columns[$bid][]=array('id'=>$c['id'],'name'=>$c['name'],'done'=>(bool)$c['is_done'],'version'=>(int)$c['version']);}

            $allComments=rows('SELECT c.*,u.name FROM lifeos_comments c JOIN lifeos_tasks t ON t.id=c.task_id JOIN lifeos_users u ON u.id=c.user_id WHERE t.board_id IN ('.$in.') ORDER BY c.id');
            $commentMap=array();foreach($allComments as $c)$commentMap[$c['task_id']][]=array('id'=>(string)$c['id'],'author'=>$c['name'],'userId'=>(string)$c['user_id'],'text'=>$c['body'],'createdAt'=>utc_iso($c['created_at']));
            foreach(rows('SELECT t.*,u.name AS assignee_name FROM lifeos_tasks t LEFT JOIN lifeos_users u ON u.id=t.assignee_id WHERE t.board_id IN ('.$in.') ORDER BY t.board_id,t.column_id,t.sort_order,t.id') as $t)$tasks[]=array('id'=>(string)$t['id'],'board'=>(string)$t['board_id'],'status'=>$t['column_id'],'title'=>$t['title'],'description'=>$t['description'],'priority'=>$t['priority'],'area'=>$t['area'],'due'=>$t['due'] ?: '', 'startTime'=>$t['start_time']?:'','endTime'=>$t['end_time']?:'', 'goal'=>$t['goal_id']?(string)$t['goal_id']:'','assignee'=>$t['assignee_id']?(string)$t['assignee_id']:'','assigneeName'=>$t['assignee_name'] ?: 'Unassigned','checks'=>json_decode($t['checks'],true) ?: array(),'comments'=>isset($commentMap[$t['id']])?$commentMap[$t['id']]:array(),'version'=>(int)$t['version'],'createdAt'=>utc_iso($t['created_at']));
            $members=rows('SELECT m.board_id AS board,u.id,u.name,u.email,m.role FROM lifeos_members m JOIN lifeos_users u ON u.id=m.user_id WHERE m.board_id IN ('.$in.') ORDER BY u.name');foreach($members as &$m){$m['board']=(string)$m['board'];$m['id']=(string)$m['id'];}unset($m);
            $invites=rows('SELECT i.id,i.board_id AS board,i.email,i.role,i.expires_at FROM lifeos_invites i JOIN lifeos_boards b ON b.id=i.board_id WHERE b.owner_id=? AND i.expires_at>UTC_TIMESTAMP() ORDER BY i.id',array($uid));


        }
        $goalLinks=rows('SELECT bg.board_id,bg.goal_id FROM lifeos_board_goals bg JOIN lifeos_members m ON m.board_id=bg.board_id WHERE m.user_id=?',array($uid));
        foreach(rows('SELECT DISTINCT g.* FROM lifeos_goals g LEFT JOIN lifeos_board_goals bg ON bg.goal_id=g.id LEFT JOIN lifeos_members m ON m.board_id=bg.board_id AND m.user_id=? WHERE g.owner_id=? OR m.user_id IS NOT NULL ORDER BY g.id',array($uid,$uid)) as $g){$links=array();foreach($goalLinks as $link)if($link['goal_id']==$g['id'])$links[]=(string)$link['board_id'];$goals[]=array('id'=>(string)$g['id'],'owner'=>(string)$g['owner_id'],'boards'=>$links,'name'=>$g['name'],'description'=>$g['description'],'priority'=>$g['priority'],'icon'=>'◎','version'=>(int)$g['version'],'createdAt'=>utc_iso($g['created_at']),'achievedAt'=>utc_iso($g['achieved_at']));}
            foreach(rows('SELECT * FROM lifeos_schedule WHERE owner_id=? ORDER BY start_time,id',array($uid)) as $entry)$schedule[]=array('id'=>(string)$entry['id'],'board'=>'','owner'=>(string)$entry['owner_id'],'title'=>$entry['title'],'description'=>$entry['description'],'days'=>array_map('intval',explode(',',$entry['days'])),'startTime'=>$entry['start_time'],'endTime'=>$entry['end_time'],'startDate'=>$entry['start_date'],'endDate'=>$entry['end_date']?:'','trackHabit'=>(bool)$entry['track_habit'],'goal'=>$entry['goal_id']?(string)$entry['goal_id']:'','color'=>$entry['color'],'version'=>(int)$entry['version'],'createdAt'=>utc_iso($entry['created_at']));
        $habitChecks=rows('SELECT c.schedule_id AS schedule,c.day FROM lifeos_habit_checks c JOIN lifeos_schedule s ON s.id=c.schedule_id WHERE c.user_id=? AND s.owner_id=? ORDER BY c.day',array($uid,$uid));foreach($habitChecks as &$check)$check['schedule']=(string)$check['schedule'];unset($check);
        db()->commit();out(array('revision'=>$rev,'user'=>public_user($u),'boards'=>$boards,'columns'=>(object)$columns,'goals'=>$goals,'tasks'=>$tasks,'members'=>$members,'invites'=>$invites,'schedule'=>$schedule,'habitChecks'=>$habitChecks,'notes'=>note_snapshot($uid)));
    }
    db()->beginTransaction();$result=array();
    if($action==='prototype_import') {require __DIR__.'/private/import.php';$bid=import_prototype($d['data'] ?? null,$uid);$result['board']=$bid;}
    elseif($action==='board_create') {$bid=create_board($uid,text_value($d,'name',100));$result['id']=$bid;}
    elseif($action==='board_update'||$action==='board_delete') {
        $b=board_access($d['id'] ?? '',$uid,array('owner'),true);$bid=$b['id'];check_version($b,$d);
        if($action==='board_delete'){query('DELETE FROM lifeos_tasks WHERE board_id=?',array($b['id']));query('DELETE FROM lifeos_boards WHERE id=?',array($b['id']));}
        else query('UPDATE lifeos_boards SET name=?,version=version+1 WHERE id=?',array(text_value($d,'name',100),$b['id']));
    }
    elseif(strpos($action,'column_')===0) {
        $bid=id_value($d['board'] ?? '');board_access($bid,$uid,array('owner','editor'),true);
        if($action==='column_create') {
            $position=(int)query('SELECT COALESCE(MAX(position),-1)+1 FROM lifeos_columns WHERE board_id=?',array($bid))->fetchColumn();
            $id='col-'.bin2hex(random_bytes(12));query('INSERT INTO lifeos_columns(board_id,id,name,position,is_done) VALUES(?,?,?,?,?)',array($bid,$id,text_value($d,'name',80),$position,!empty($d['done'])?1:0));$result['id']=$id;
        }elseif($action==='column_reorder') {
            $order=$d['order'] ?? array();$all=query('SELECT id FROM lifeos_columns WHERE board_id=?',array($bid))->fetchAll(PDO::FETCH_COLUMN);
            if(!is_array($order)||count($order)!==count($all)||count(array_unique($order))!==count($all)||array_diff($order,$all))fail('Invalid column order.');
            foreach($order as $i=>$id)query('UPDATE lifeos_columns SET position=?,version=version+1 WHERE board_id=? AND id=?',array($i,$bid,$id));
        }else{
            $id=text_value($d,'id',60);$c=row('SELECT * FROM lifeos_columns WHERE board_id=? AND id=?',array($bid,$id));if(!$c)fail('Column not found.',404);check_version($c,$d);
            if($action==='column_update')query('UPDATE lifeos_columns SET name=?,is_done=?,version=version+1 WHERE board_id=? AND id=?',array(text_value($d,'name',80),!empty($d['done'])?1:0,$bid,$id));
            elseif($action==='column_delete') {
                $target=text_value($d,'moveTo',60);if($target===$id||!row('SELECT id FROM lifeos_columns WHERE board_id=? AND id=?',array($bid,$target)))fail('Select another column to receive its tasks.');
                task_append_column($bid,$id,$target);query('DELETE FROM lifeos_columns WHERE board_id=? AND id=?',array($bid,$id));
            }else fail('Unknown column action.');
        }
    }
    elseif($action==='board_goals') {
        $bid=id_value($d['board'] ?? '');board_access($bid,$uid,array('owner','editor'),true);
        $selected=$d['goals'] ?? array();if(!is_array($selected)||count($selected)>1000)fail('Invalid goal selection.');
        $existing=query('SELECT goal_id FROM lifeos_board_goals WHERE board_id=?',array($bid))->fetchAll(PDO::FETCH_COLUMN);
        $selected=array_unique(array_map('id_value',$selected));
        foreach($selected as $id)if(!in_array($id,$existing)&&!row('SELECT id FROM lifeos_goals WHERE id=? AND owner_id=?',array($id,$uid)))fail('Choose a goal from your workspace.',403);
        $removed=array_diff($existing,$selected);
        foreach($removed as $id){if(row('SELECT id FROM lifeos_tasks WHERE board_id=? AND goal_id=? LIMIT 1',array($bid,$id)))fail('Move or clear this goal on its board tasks before detaching it.',409);query('DELETE FROM lifeos_board_goals WHERE board_id=? AND goal_id=?',array($bid,$id));}
        foreach($selected as $id)query('INSERT IGNORE INTO lifeos_board_goals(board_id,goal_id) VALUES(?,?)',array($bid,$id));
    }
    elseif(strpos($action,'goal_')===0) {
        $g=null;if($action!=='goal_create'){$g=row('SELECT * FROM lifeos_goals WHERE id=? AND owner_id=? FOR UPDATE',array(id_value($d['id'] ?? ''),$uid));if(!$g)fail('Goal not found or not owned by you.',404);check_version($g,$d);}
        if($action==='goal_delete') {
            query('UPDATE lifeos_boards b JOIN lifeos_board_goals bg ON bg.board_id=b.id SET b.sync_revision=b.sync_revision+1 WHERE bg.goal_id=?',array($g['id']));
            query('UPDATE lifeos_tasks SET goal_id=NULL,version=version+1 WHERE goal_id=?',array($g['id']));query('UPDATE lifeos_schedule SET goal_id=NULL,version=version+1 WHERE goal_id=?',array($g['id']));query('DELETE FROM lifeos_goals WHERE id=?',array($g['id']));
        }elseif($action==='goal_create'||$action==='goal_update'){
            $name=text_value($d,'name',150);$description=text_value($d,'description',10000,false);$priority=$d['priority'] ?? 'Medium';if(!in_array($priority,array('Low','Medium','High'),true))fail('Invalid priority.');
            if($g){query('UPDATE lifeos_goals SET name=?,description=?,priority=?,achieved_at=?,version=version+1 WHERE id=?',array($name,$description,$priority,!empty($d['achieved'])?($g['achieved_at']?:gmdate('Y-m-d H:i:s')):null,$g['id']));query('UPDATE lifeos_boards b JOIN lifeos_board_goals bg ON bg.board_id=b.id SET b.sync_revision=b.sync_revision+1 WHERE bg.goal_id=?',array($g['id']));}
            else {
                if(!empty($d['board'])){$bid=id_value($d['board']);board_access($bid,$uid,array('owner','editor'),true);}
                query('INSERT INTO lifeos_goals(owner_id,name,description,priority,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())',array($uid,$name,$description,$priority));$result['id']=(string)db()->lastInsertId();
                if(!empty($bid))query('INSERT INTO lifeos_board_goals(board_id,goal_id) VALUES(?,?)',array($bid,$result['id']));
            }
        }else fail('Unknown goal action.');
    }
    elseif(in_array($action,array('task_create','task_update','task_delete','task_move','comment_add'),true)) {
        $t=null;if($action!=='task_create'){$t=row('SELECT * FROM lifeos_tasks WHERE id=?',array(id_value($d['id'] ?? '')));if(!$t)fail('Task not found.',404);}
        $bid=$t?$t['board_id']:id_value($d['board'] ?? '');
        board_access($bid,$uid,$action==='comment_add'?array('owner','editor','reviewer'):array('owner','editor'),true);
        // Read again after locking the board: concurrent edits cannot bypass the version check.
        if($t){$t=row('SELECT * FROM lifeos_tasks WHERE id=? FOR UPDATE',array($t['id']));if(!$t)fail('Task no longer exists.',404);}if($action!=='comment_add'&&$t)check_version($t,$d);
        if($action==='comment_add'){query('INSERT INTO lifeos_comments(task_id,user_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP())',array($t['id'],$uid,text_value($d,'text',5000)));}
        elseif($action==='task_delete')query('DELETE FROM lifeos_tasks WHERE id=?',array($t['id']));
        else {
            $status=text_value($d,'status',60);if(!row('SELECT id FROM lifeos_columns WHERE board_id=? AND id=?',array($bid,$status)))fail('Column not found.',404);
            if($action==='task_move')task_reposition($t,$status,empty($d['before'])?null:id_value($d['before']));
            else {
                $title=text_value($d,'title',250);$description=text_value($d,'description',20000,false);$area=text_value($d,'area',80,false);$priority=$d['priority'] ?? 'Medium';if(!in_array($priority,array('Low','Medium','High'),true))fail('Invalid priority.');
                $due=$d['due'] ?? '';if($due!==''&&(!is_string($due)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||!checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4))))fail('Invalid due date.');
                $start=$d['startTime'] ?? '';$end=$d['endTime'] ?? '';
                if($start!==''||$end!==''){if(!$due||!is_string($start)||!is_string($end)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$end<=$start)fail('Timed tasks need a due date and both times, with end after start.');}
                $goal=empty($d['goal'])?null:id_value($d['goal']);if($goal&&!row('SELECT goal_id FROM lifeos_board_goals WHERE goal_id=? AND board_id=?',array($goal,$bid)))fail('Attach this goal to the board first.');
                $assignee=empty($d['assignee'])?null:id_value($d['assignee']);if($assignee&&!row('SELECT user_id FROM lifeos_members WHERE board_id=? AND user_id=?',array($bid,$assignee)))fail('Assignee must be a board member.');
                $checks=$d['checks'] ?? array();if(!is_array($checks)||count($checks)>200)fail('Use up to 200 checklist items per task.');$clean=array();foreach($checks as $c){if(!is_array($c))fail('Invalid checklist.');$clean[]=array('text'=>text_value($c,'text',500),'done'=>!empty($c['done']));}
                $position=$t&&$t['column_id']===$status?$t['sort_order']:task_next_position($bid,$status);
                $args=array($status,$position,$title,$description,$priority,$area,$due?:null,$start?:null,$end?:null,$goal,$assignee,json_encode($clean));
                if($t){$args[]=$t['id'];query('UPDATE lifeos_tasks SET column_id=?,sort_order=?,title=?,description=?,priority=?,area=?,due=?,start_time=?,end_time=?,goal_id=?,assignee_id=?,checks=?,version=version+1 WHERE id=?',$args);}
                else{$args[]=$bid;query('INSERT INTO lifeos_tasks(column_id,sort_order,title,description,priority,area,due,start_time,end_time,goal_id,assignee_id,checks,board_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',$args);$result['id']=(string)db()->lastInsertId();}
            }
        }
    }
    elseif(in_array($action,array('schedule_create','schedule_update','schedule_delete','habit_check'),true)) {
        $entry=null;if($action!=='schedule_create'){$entry=row('SELECT * FROM lifeos_schedule WHERE id=?',array(id_value($d['id'] ?? '')));if(!$entry)fail('Timetable entry not found.',404);}
        if($entry&&(string)$entry['owner_id']!==(string)$uid)fail('Timetable entry not found.',404);
        if($entry){$entry=row('SELECT * FROM lifeos_schedule WHERE id=? FOR UPDATE',array($entry['id']));if(!$entry)fail('Entry no longer exists.',404);if($action!=='habit_check')check_version($entry,$d);}
        if($action==='habit_check'){
            $day=date_value($d['day'] ?? '');$weekday=(int)gmdate('N',strtotime($day.' 12:00:00 UTC'));
            if(!empty($d['checked'])&&(!$entry['track_habit']||$day<$entry['start_date']||($entry['end_date']&&$day>$entry['end_date'])||!in_array((string)$weekday,explode(',',$entry['days']),true)))fail('This habit is not scheduled for that date.');
            if(!empty($d['checked']))query('INSERT IGNORE INTO lifeos_habit_checks(schedule_id,user_id,day,created_at) VALUES(?,?,?,UTC_TIMESTAMP())',array($entry['id'],$uid,$day));
            else query('DELETE FROM lifeos_habit_checks WHERE schedule_id=? AND user_id=? AND day=?',array($entry['id'],$uid,$day));
        }elseif($action==='schedule_delete')query('DELETE FROM lifeos_schedule WHERE id=?',array($entry['id']));
        else {
            $title=text_value($d,'title',150);$description=text_value($d,'description',10000,false);$days=$d['days'] ?? array();if(!is_array($days)||!$days||count($days)>7)fail('Select at least one weekday.');foreach($days as $day)if(!is_int($day)||$day<1||$day>7)fail('Invalid weekday.');$days=array_unique($days);sort($days);
            $start=text_value($d,'startTime',5);$end=text_value($d,'endTime',5);if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$end<=$start)fail('End time must be after start time. For overnight routines, create two entries.');
            $from=date_value($d['startDate'] ?? '');$until=empty($d['endDate'])?null:date_value($d['endDate']);if($until&&$until<$from)fail('End date must be on or after the start date.');
            $goal=empty($d['goal'])?null:id_value($d['goal']);if($goal&&!row('SELECT id FROM lifeos_goals WHERE id=? AND owner_id=?',array($goal,$uid)))fail('Choose a goal from your workspace.');
            $color=$d['color'] ?? 'blue';if(!in_array($color,array('blue','green','red','gray'),true))fail('Invalid timetable color.');
            $args=array($title,$description,implode(',',$days),$start,$end,$from,$until,!empty($d['trackHabit'])?1:0,$goal,$color);
            if($entry){$args[]=$entry['id'];query('UPDATE lifeos_schedule SET title=?,description=?,days=?,start_time=?,end_time=?,start_date=?,end_date=?,track_habit=?,goal_id=?,color=?,version=version+1 WHERE id=?',$args);}
            else{$args[]=$uid;query('INSERT INTO lifeos_schedule(title,description,days,start_time,end_time,start_date,end_date,track_habit,goal_id,color,owner_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',$args);$result['id']=(string)db()->lastInsertId();}
        }
    }
    elseif($action==='invite_create') {
        $bid=id_value($d['board'] ?? '');board_access($bid,$uid,array('owner'),true);$email=email_value($d['email'] ?? '');$role=$d['role'] ?? '';if(!in_array($role,array('viewer','reviewer','editor'),true))fail('Invalid invitation role.');
        if(row('SELECT m.user_id FROM lifeos_members m JOIN lifeos_users u ON u.id=m.user_id WHERE m.board_id=? AND u.email=?',array($bid,$email)))fail('This person is already a member.');
        $token=bin2hex(random_bytes(32));query('DELETE FROM lifeos_invites WHERE board_id=? AND email=?',array($bid,$email));query('INSERT INTO lifeos_invites(board_id,email,role,token_hash,expires_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),UTC_TIMESTAMP())',array($bid,$email,$role,hash('sha256',$token)));$result['token']=$token;
    }
    elseif($action==='invite_revoke') {
        $i=row('SELECT * FROM lifeos_invites WHERE id=?',array(id_value($d['id'] ?? '')));if(!$i)fail('Invitation not found.',404);$bid=$i['board_id'];board_access($bid,$uid,array('owner'),true);query('DELETE FROM lifeos_invites WHERE id=?',array($i['id']));
    }
    elseif($action==='invite_accept') {
        $token=text_value($d,'token',64);$i=row('SELECT * FROM lifeos_invites WHERE token_hash=? AND email=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE',array(hash('sha256',$token),$u['email']));if(!$i)fail('No active invitation matches your account email.',403);
        query('INSERT IGNORE INTO lifeos_members(board_id,user_id,role) VALUES(?,?,?)',array($i['board_id'],$uid,$i['role']));query('DELETE FROM lifeos_invites WHERE id=?',array($i['id']));$bid=$i['board_id'];$result['board']=(string)$bid;
    }
    elseif($action==='member_update'||$action==='member_remove') {
        $bid=id_value($d['board'] ?? '');board_access($bid,$uid,array('owner'),true);$member=id_value($d['userId'] ?? '');$m=row('SELECT * FROM lifeos_members WHERE board_id=? AND user_id=?',array($bid,$member));if(!$m||$m['role']==='owner')fail('The board owner cannot be removed or demoted.');
        if($action==='member_remove'){query('UPDATE lifeos_tasks SET assignee_id=NULL,version=version+1 WHERE board_id=? AND assignee_id=?',array($bid,$member));query('DELETE FROM lifeos_members WHERE board_id=? AND user_id=?',array($bid,$member));}
        else{$role=$d['role'] ?? '';if(!in_array($role,array('viewer','reviewer','editor'),true))fail('Invalid member role.');query('UPDATE lifeos_members SET role=? WHERE board_id=? AND user_id=?',array($role,$bid,$member));}
    }
    elseif($action==='profile_update') {
        $name=text_value($d,'name',100);$theme=$d['theme'] ?? $u['theme'];if(!in_array($theme,array('facebook','slate','forest','trello','notion','asana','monday','linear','todoist','mono','linen','wine','lavender'),true))fail('Invalid theme.');
        $scale=isset($d['fontScale'])?$d['fontScale']:(int)$u['font_scale'];if(!is_int($scale)||!in_array($scale,array(90,100,112,125,140),true))fail('Invalid font size.');
        query('UPDATE lifeos_users SET name=?,theme=?,font_scale=? WHERE id=?',array($name,$theme,$scale,$uid));
    }
    elseif($action==='password_change') {
        $old=$d['currentPassword'] ?? '';if(!is_string($old)||!password_verify($old,$u['password']))fail('Current password is incorrect.',403);
        query('UPDATE lifeos_users SET password=?,session_version=session_version+1 WHERE id=?',array(password_hash(password_value($d['password'] ?? ''),PASSWORD_DEFAULT),$uid));$_SESSION['sv']=(int)$u['session_version']+1;invalidate_other_logins($uid,$_SESSION['sv']);session_regenerate_id(true);
    }
    else fail('Unknown action.',404);
    if(in_array($action,array('habit_check','schedule_create','schedule_update','schedule_delete'),true))query('UPDATE lifeos_users SET sync_revision=sync_revision+1 WHERE id=?',array($uid));
    else bump(isset($bid)?$bid:null);
    db()->commit();out(array_merge(array('ok'=>true),$result));
} catch(LifeError $e) { if(installed()&&db()->inTransaction())db()->rollBack();out(array('error'=>$e->getMessage()),$e->getCode()?:400); }
catch(Throwable $e) { if(installed()){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}}error_log('LifeOS API: '.$e->getMessage());out(array('error'=>'The request could not be completed. Check the server error log or database connection.'),500); }

