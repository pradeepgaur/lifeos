<?php
// Call only inside an API transaction after taking the board lock.
function task_next_position($board,$column){return (int)query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM lifeos_tasks WHERE board_id=? AND column_id=?',array($board,$column))->fetchColumn();}
function task_reposition($task,$column,$before=null){
    $items=rows('SELECT id,sort_order FROM lifeos_tasks WHERE board_id=? AND column_id=? AND id<>? ORDER BY sort_order,id',array($task['board_id'],$column,$task['id']));
    $order=array_column($items,'id');$at=$before===null?count($order):array_search($before,array_map('strval',$order),true);
    if($at===false)fail('The card you dropped before has moved. Refresh and try again.',409);
    array_splice($order,$at,0,array($task['id']));
    foreach($order as $i=>$id){if((string)$id===(string)$task['id'])query('UPDATE lifeos_tasks SET column_id=?,sort_order=?,version=version+1 WHERE id=?',array($column,$i,$id));else query('UPDATE lifeos_tasks SET sort_order=?,version=version+1 WHERE id=? AND sort_order<>?',array($i,$id,$i));}
}
function task_append_column($board,$from,$to){
    $next=task_next_position($board,$to);
    foreach(rows('SELECT id FROM lifeos_tasks WHERE board_id=? AND column_id=? ORDER BY sort_order,id',array($board,$from)) as $task)query('UPDATE lifeos_tasks SET column_id=?,sort_order=?,version=version+1 WHERE id=?',array($to,$next++,$task['id']));
}
