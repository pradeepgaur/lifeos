<?php
require dirname(__DIR__).'/private/background-tools.php';
function expect($test){if(!$test)throw new RuntimeException('Upload error regression failed.');}
expect(upload_limit_bytes('2M')===2097152);expect(upload_limit_bytes('512K')===524288);expect(upload_limit_bytes('0')===0);
expect(strpos(upload_error_message(UPLOAD_ERR_NO_TMP_DIR),'temporary folder')!==false);
expect(strpos(upload_error_message(UPLOAD_ERR_CANT_WRITE),'cannot write')!==false);
foreach(array(1146=>'storage is missing',1142=>'permission',1153=>'DB 1153',1406=>'MEDIUMBLOB') as $code=>$message){$error=new PDOException('secret database details');$error->errorInfo=array('HY000',$code,'secret details');$result=background_error($error);expect(strpos($result,$message)!==false);expect(strpos($result,'secret')===false);}
echo 'PASS: upload/post limits, PHP upload errors, safe database diagnostics.'.PHP_EOL;
