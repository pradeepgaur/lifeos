<?php
require __DIR__.'/private/bootstrap.php';require __DIR__.'/private/notes.php';require __DIR__.'/private/background-tools.php';
try{
    if(!installed())fail('Setup required.',503);$u=current_user();$post=$_SERVER['REQUEST_METHOD']==='POST';if(!$post&&$_SERVER['REQUEST_METHOD']!=='GET')fail('Method not allowed.',405);
    if(!$post){$f=row('SELECT * FROM lifeos_note_files WHERE id=?',array(id_value($_GET['id'] ?? '')));if(!$f)fail('Attachment not found.',404);note_access($f['note_id'],$u['id']);
        header("Content-Security-Policy: default-src 'none'; sandbox");header('Cache-Control: private, no-store');header('Content-Type: '.$f['mime']);header('Content-Length: '.$f['bytes']);$inline=strpos($f['mime'],'image/')===0;header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="attachment'.($inline?'':'-download').'"; filename*=UTF-8\'\''.rawurlencode($f['name']));echo $f['data'];exit;
    }
    csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');$limit=upload_limit_bytes(ini_get('post_max_size'));if($limit>0&&(int)($_SERVER['CONTENT_LENGTH'] ?? 0)>$limit)fail('Upload exceeds your hosting post_max_size setting. Try a smaller file.',413);
    db()->beginTransaction();$n=note_access($_POST['note'] ?? '',$u['id'],true,true);check_version($n,$_POST);
    if(($_POST['action'] ?? '')==='delete'){
        $f=row('SELECT id FROM lifeos_note_files WHERE id=? AND note_id=?',array(id_value($_POST['file'] ?? ''),$n['id']));if(!$f)fail('Attachment not found.',404);query('DELETE FROM lifeos_note_files WHERE id=?',array($f['id']));$body=note_sanitize($n['body'],$n['id']);query('UPDATE lifeos_notes SET body=? WHERE id=?',array($body,$n['id']));$result=array();
    }else{
        if(empty($_FILES['file']))fail('Choose a file to upload.');$f=$_FILES['file'];if($f['error']!==UPLOAD_ERR_OK)fail(upload_error_message($f['error']));if(!is_uploaded_file($f['tmp_name']))fail('Invalid upload.');if($f['size']>2*1024*1024)fail('Each attachment must be 2 MB or smaller.',413);
        $usage=row('SELECT COUNT(*) AS count,COALESCE(SUM(bytes),0) AS total FROM lifeos_note_files WHERE note_id=?',array($n['id']));if($usage['count']>=10||$usage['total']+$f['size']>10*1024*1024)fail('Use up to 10 attachments and 10 MB total per note.',413);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=array('image/jpeg','image/png','image/webp','application/pdf','text/plain');if(!in_array($mime,$allowed,true))fail('Use JPEG, PNG, WebP, PDF, or plain-text files.');
        if(strpos($mime,'image/')===0){$size=@getimagesize($f['tmp_name']);if(!$size||$size[0]*$size[1]>20000000)fail('Images must be valid and below 20 megapixels.');}
        $name=text_value(array('name'=>preg_replace('/[\x00-\x1f\x7f\\\\\/]/','_',basename($f['name']))),'name',200);$data=file_get_contents($f['tmp_name']);if($data===false)fail('Could not read uploaded file.');
        query('INSERT INTO lifeos_note_files(note_id,name,mime,bytes,data,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',array($n['id'],$name,$mime,strlen($data),$data));$result=array('file'=>array('id'=>(string)db()->lastInsertId(),'name'=>$name,'mime'=>$mime,'bytes'=>strlen($data)));
    }
    query('UPDATE lifeos_notes SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',array($n['id']));note_touch($n['id'],$n['owner_id']);db()->commit();$result['version']=(int)$n['version']+1;out(array_merge(array('ok'=>true),$result));
}catch(LifeError $e){try{if(installed()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}out(array('error'=>$e->getMessage()),$e->getCode()?:400);}
catch(Throwable $e){try{if(installed()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_log('LifeOS attachment: '.$e->getMessage());out(array('error'=>'Could not upload the attachment. Check hosting upload and database packet limits.'),500);}
