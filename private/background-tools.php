<?php
function upload_limit_bytes($value){$value=trim($value);if($value==='')return 0;$number=(float)$value;$suffix=strtolower(substr($value,-1));if($suffix==='g')$number*=1024*1024*1024;elseif($suffix==='m')$number*=1024*1024;elseif($suffix==='k')$number*=1024;return (int)$number;}
function background_error($e){
    if($e instanceof PDOException){
        $driver=isset($e->errorInfo[1])?(int)$e->errorInfo[1]:0;
        if($driver===1146)return 'Background storage is missing. Upload the latest private/schema.php and private/bootstrap.php along with background.php, then reload the page.';
        if(in_array($driver,array(1142,1143,1044,1045),true))return 'The database user does not have permission to save board images. Check the database user privileges in cPanel.';
        if(in_array($driver,array(1153,2006,2013),true))return 'The database connection closed while saving the image (DB '.$driver.'). Retry with a smaller image; if it repeats, check the MySQL packet limit or server error log.';
        if($driver===1406)return 'The database image column is too small. Update the lifeos_backgrounds.image column to MEDIUMBLOB, then retry.';
        if($driver===1114||$driver===1021)return 'The database or hosting disk is full. Free storage or increase the hosting quota.';
        return 'Database error while saving the background (DB '.$driver.'). Check the PHP error log for this request.';
    }
    return 'The server could not process the image. Check the PHP error log using the reference shown below.';
}
function upload_error_message($code){
    $messages=array(UPLOAD_ERR_INI_SIZE=>'PHP rejected the image: upload_max_filesize is '.ini_get('upload_max_filesize').'. Choose a smaller image or increase that limit in cPanel.',UPLOAD_ERR_FORM_SIZE=>'The image exceeds the upload form limit.',UPLOAD_ERR_PARTIAL=>'The image was only partially uploaded. Please retry.',UPLOAD_ERR_NO_FILE=>'Choose an image first.',UPLOAD_ERR_NO_TMP_DIR=>'PHP has no upload temporary folder. Ask the host to configure upload_tmp_dir.',UPLOAD_ERR_CANT_WRITE=>'PHP cannot write the upload temporary file. Check hosting disk space and temporary-folder permissions.',UPLOAD_ERR_EXTENSION=>'A PHP extension blocked the upload. Check the hosting PHP error log.');
    return isset($messages[$code])?$messages[$code]:'PHP did not receive a valid uploaded image.';
}
