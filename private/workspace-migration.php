<?php
// Runs under the schema migration lock. Every step can safely resume after interruption.
function migrate_workspace($pdo,$schema) {
    foreach(array('goals','schedule') as $table){
        try{$pdo->exec('ALTER TABLE lifeos_'.$table.' ADD owner_id BIGINT UNSIGNED NULL, ADD KEY(owner_id)');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}
        $pdo->exec('UPDATE lifeos_'.$table.' x JOIN lifeos_boards b ON b.id=x.board_id SET x.owner_id=b.owner_id WHERE x.owner_id IS NULL');
        $pdo->exec('ALTER TABLE lifeos_'.$table.' MODIFY owner_id BIGINT UNSIGNED NOT NULL');
        $ownerKey=$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lifeos_".$table."' AND COLUMN_NAME='owner_id' AND REFERENCED_TABLE_NAME='lifeos_users'")->fetchColumn();
        if(!$ownerKey)$pdo->exec('ALTER TABLE lifeos_'.$table.' ADD FOREIGN KEY(owner_id) REFERENCES lifeos_users(id)');
        $stmt=$pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lifeos_".$table."' AND COLUMN_NAME='board_id' AND REFERENCED_TABLE_NAME='lifeos_boards'");
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $key)$pdo->exec('ALTER TABLE lifeos_'.$table.' DROP FOREIGN KEY `'.str_replace('`','``',$key).'`');
        $pdo->exec('ALTER TABLE lifeos_'.$table.' MODIFY board_id BIGINT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE lifeos_'.$table.' ADD FOREIGN KEY(board_id) REFERENCES lifeos_boards(id) ON DELETE SET NULL');
    }
    try{$pdo->exec('ALTER TABLE lifeos_goals ADD achieved_at DATETIME NULL');}catch(PDOException $e){if($e->getCode()!=='42S21')throw $e;}
    $pdo->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$schema['board_goals']));
    $pdo->exec('INSERT IGNORE INTO lifeos_board_goals(board_id,goal_id) SELECT board_id,id FROM lifeos_goals WHERE board_id IS NOT NULL');
    // Keep each member’s former board timetable and check-ins as a personal copy.
    $pdo->beginTransaction();
    try{
        $entries=$pdo->query('SELECT * FROM lifeos_schedule WHERE board_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        foreach($entries as $entry){
            $members=$pdo->prepare('SELECT user_id FROM lifeos_members WHERE board_id=? AND user_id<>? UNION SELECT user_id FROM lifeos_habit_checks WHERE schedule_id=? AND user_id<>?');
            $members->execute(array($entry['board_id'],$entry['owner_id'],$entry['id'],$entry['owner_id']));
            foreach($members->fetchAll(PDO::FETCH_COLUMN) as $user){
                $insert=$pdo->prepare('INSERT INTO lifeos_schedule(owner_id,board_id,title,description,days,start_time,end_time,start_date,end_date,track_habit,goal_id,color,version,created_at) VALUES(?,NULL,?,?,?,?,?,?,?,?,?,?,?,?)');
                // A private routine cannot retain somebody else’s workspace goal.
                $insert->execute(array($user,$entry['title'],$entry['description'],$entry['days'],$entry['start_time'],$entry['end_time'],$entry['start_date'],$entry['end_date'],$entry['track_habit'],null,$entry['color'],$entry['version'],$entry['created_at']));
                $newId=$pdo->lastInsertId();$move=$pdo->prepare('UPDATE lifeos_habit_checks SET schedule_id=? WHERE schedule_id=? AND user_id=?');$move->execute(array($newId,$entry['id'],$user));
            }
        }
        $pdo->exec('UPDATE lifeos_schedule SET board_id=NULL');
        $pdo->exec('UPDATE lifeos_users SET sync_revision=sync_revision+1');
        $pdo->exec('UPDATE lifeos_boards SET sync_revision=sync_revision+1');
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
