<?php
// Build a clean, secret-free shared-hosting ZIP. CLI only.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$path=$root.'/lifeos-hosting.zip';$zip=new ZipArchive();
if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create archive.');
$files=array('.htaccess','index.php','index.html','setup.php','login.php','api.php','background.php','app.js','render.js','actions.js','columns.js','overview.js','overview.css','insights.js','insights.css','notes.php','note-file.php','notes.js','notes.css','private/notes.php','private/task-order.php','background-client.js','auth.js','timetable.js','style.css','themes.css','timetable.css','auth.css','responsive.css','responsive.js','README.md','private/.htaccess','private/bootstrap.php','private/sessions.php','private/workspace-migration.php','private/schema.php','private/import.php','private/background-tools.php');
foreach($files as $file){if(!is_file($root.'/'.$file))throw new RuntimeException('Missing '.$file);$zip->addFile($root.'/'.$file,$file);}
$zip->close();echo 'Packaged '.count($files).' files in lifeos-hosting.zip; no configuration, credentials, test accounts, or test utilities included.'.PHP_EOL;
