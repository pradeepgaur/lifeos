<?php
require __DIR__.'/private/bootstrap.php';
require __DIR__.'/private/background-tools.php';
try{
    if(!installed())fail('Setup required.',503);
    $u=current_user();$post=$_SERVER['REQUEST_METHOD']==='POST';
    if(!$post&&$_SERVER['REQUEST_METHOD']!=='GET')fail('Method not allowed.',405);
    if($post){
        csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $postLimit=upload_limit_bytes(ini_get('post_max_size'));
        if($postLimit>0&&(int)($_SERVER['CONTENT_LENGTH'] ?? 0)>$postLimit)fail('PHP rejected the request: post_max_size is '.ini_get('post_max_size').'. Choose a smaller image or increase that limit in cPanel.',413);
        board_access($_POST['board'] ?? '',$u['id'],array('owner'));
        // Repair an incomplete file update before beginning a transaction (DDL commits in MySQL).
        try{query('SELECT board_id FROM lifeos_backgrounds LIMIT 0');}catch(PDOException $e){
            if(!isset($e->errorInfo[1])||(int)$e->errorInfo[1]!==1146)throw $e;
            $schema=require __DIR__.'/private/schema.php';if(empty($schema['backgrounds']))fail('Upload the latest private/schema.php and private/bootstrap.php, then retry.');
            query(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$schema['backgrounds']));
        }
        db()->beginTransaction();
        $b=board_access($_POST['board'] ?? '',$u['id'],array('owner'),true);check_version($b,$_POST);
        if(($_POST['remove'] ?? '')==='1')query('DELETE FROM lifeos_backgrounds WHERE board_id=?',array($b['id']));
        else{
            $file=$_FILES['image'] ?? null;
            if(!$file)fail('PHP did not receive the image. Check file_uploads is enabled and the request is allowed by your host.');
            if($file['error']!==UPLOAD_ERR_OK)fail(upload_error_message($file['error']),400);
            if(!is_uploaded_file($file['tmp_name']))fail('PHP could not validate the uploaded temporary file. Please retry.');
            if($file['size']>2*1024*1024)fail('Use an image smaller than 2 MB.',413);
            $info=@getimagesize($file['tmp_name']);
            if(!$info||!in_array($info['mime'],array('image/jpeg','image/png','image/webp'),true)||$info[0]*$info[1]>20000000)fail('Choose a JPEG, PNG, or WebP image, up to 20 megapixels.');
            $image=file_get_contents($file['tmp_name']);if($image===false)fail('PHP could not read the uploaded temporary file.');
            $insert=db()->prepare('INSERT INTO lifeos_backgrounds(board_id,mime,image,etag) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),image=VALUES(image),etag=VALUES(etag)');
            $insert->bindValue(1,$b['id']);$insert->bindValue(2,$info['mime']);$insert->bindValue(3,$image,PDO::PARAM_LOB);$insert->bindValue(4,hash('sha256',$image));$insert->execute();
        }
        query('UPDATE lifeos_boards SET version=version+1 WHERE id=?',array($b['id']));bump($b['id']);db()->commit();out(array('ok'=>true));
    }
    $b=board_access($_GET['board'] ?? '',$u['id']);$image=row('SELECT * FROM lifeos_backgrounds WHERE board_id=?',array($b['id']));if(!$image)fail('No board background.',404);
    header('Cache-Control: private, no-cache');header('ETag: "'.$image['etag'].'"');
    if(($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')==='"'.$image['etag'].'"'){http_response_code(304);exit;}
    header('Content-Type: '.$image['mime']);header('Content-Length: '.strlen($image['image']));echo $image['image'];
}catch(LifeError $e){try{if(installed()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}out(array('error'=>$e->getMessage()),$e->getCode()?:400);}
catch(Throwable $e){try{if(installed()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}$reference=bin2hex(random_bytes(4));error_log('LifeOS background ['.$reference.']: '.$e->getMessage());out(array('error'=>background_error($e).' Reference: '.$reference),500);}
