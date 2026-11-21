<?php
// Integration tests only. Never included in the hosting package.
$action=$argv[1] ?? '';$name=$argv[2] ?? '';
if(!preg_match('/^lifeos_test_[a-f0-9]{12}$/',$name))exit(2);
$pdo=new PDO('mysql:host=127.0.0.1;charset=utf8mb4',getenv('LIFEOS_TEST_DB_USER')?:'root',getenv('LIFEOS_TEST_DB_PASSWORD')?:'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
if($action==='create')$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
elseif($action==='drop')$pdo->exec('DROP DATABASE `'.$name.'`');
elseif($action==='downgrade'){$pdo->exec('USE `'.$name.'`');$pdo->exec('DROP TABLE lifeos_habit_checks');$pdo->exec('DROP TABLE lifeos_schedule');$pdo->exec('UPDATE lifeos_meta SET schema_version=1');}
elseif($action==='drop-backgrounds'){$pdo->exec('USE `'.$name.'`');$pdo->exec('DROP TABLE lifeos_backgrounds');}
elseif($action==='age-logins'){$pdo->exec('USE `'.$name.'`');$pdo->exec('UPDATE lifeos_logins SET last_seen=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 4 DAY),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY)');}
elseif($action==='task-times-downgrade'){$pdo->exec('USE `'.$name.'`');$pdo->exec('ALTER TABLE lifeos_tasks DROP start_time, DROP end_time');$pdo->exec('UPDATE lifeos_meta SET schema_version=7');}
elseif($action==='legacy-workspace'){
    $pdo->exec('USE `'.$name.'`');
    $board=$pdo->query('SELECT MIN(id) FROM lifeos_boards')->fetchColumn();$owner=$pdo->query('SELECT owner_id FROM lifeos_boards WHERE id='.$board)->fetchColumn();
    $pdo->exec("INSERT INTO lifeos_goals(owner_id,board_id,name,description,created_at) VALUES($owner,$board,'Legacy health','Preserve me',UTC_TIMESTAMP())");$goal=$pdo->lastInsertId();
    $pdo->exec("INSERT INTO lifeos_schedule(owner_id,board_id,title,description,days,start_time,end_time,start_date,goal_id,created_at) VALUES($owner,$board,'Legacy routine','Preserve me','1,2,3,4,5,6,7','06:00','06:30','2026-01-01',$goal,UTC_TIMESTAMP())");$entry=$pdo->lastInsertId();
    $pdo->exec("INSERT INTO lifeos_habit_checks(schedule_id,user_id,day,created_at) SELECT $entry,id,'2026-11-15',UTC_TIMESTAMP() FROM lifeos_users");
    foreach(array('goals','schedule') as $table){
        $pdo->exec('UPDATE lifeos_'.$table.' SET board_id='.$board.' WHERE board_id IS NULL');
        $keys=$pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lifeos_$table' AND COLUMN_NAME IN ('owner_id','board_id') AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach($keys as $key)$pdo->exec('ALTER TABLE lifeos_'.$table.' DROP FOREIGN KEY `'.$key.'`');
        $pdo->exec('ALTER TABLE lifeos_'.$table.' DROP owner_id, MODIFY board_id BIGINT UNSIGNED NOT NULL, ADD FOREIGN KEY(board_id) REFERENCES lifeos_boards(id) ON DELETE CASCADE');
    }
    $pdo->exec('ALTER TABLE lifeos_goals DROP achieved_at');$pdo->exec('DROP TABLE lifeos_board_goals');$pdo->exec('UPDATE lifeos_meta SET schema_version=6');
}
elseif($action==='expire-login'){$selector=$argv[3] ?? '';if(!preg_match('/^[a-f0-9]{32}$/',$selector))exit(2);$pdo->exec('USE `'.$name.'`');$q=$pdo->prepare('UPDATE lifeos_logins SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE selector=?');$q->execute(array($selector));}
else exit(2);
