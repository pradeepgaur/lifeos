<?php
// Integration tests only. Never included in the hosting package.
$action=$argv[1] ?? '';$name=$argv[2] ?? '';
if(!preg_match('/^lifeos_test_[a-f0-9]{12}$/',$name))exit(2);
$pdo=new PDO('mysql:host=127.0.0.1;charset=utf8mb4',getenv('LIFEOS_TEST_DB_USER')?:'root',getenv('LIFEOS_TEST_DB_PASSWORD')?:'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
if($action==='create')$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
elseif($action==='drop')$pdo->exec('DROP DATABASE `'.$name.'`');
elseif($action==='downgrade'){$pdo->exec('USE `'.$name.'`');$pdo->exec('DROP TABLE lifeos_habit_checks');$pdo->exec('DROP TABLE lifeos_schedule');$pdo->exec('UPDATE lifeos_meta SET schema_version=1');}
elseif($action==='drop-backgrounds'){$pdo->exec('USE `'.$name.'`');$pdo->exec('DROP TABLE lifeos_backgrounds');}
else exit(2);
