<?php
require __DIR__.'/private/bootstrap.php';
if(!installed()){header('Location: setup.php');exit;}
try{current_user();}catch(Throwable $e){header('Location: login.php');exit;}
readfile(__DIR__.'/index.html');
