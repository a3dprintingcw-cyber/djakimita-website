<?php
require __DIR__ . '/../../app/bootstrap.php';
unset($_SESSION['pp_uid'], $_SESSION['pp_seen']);
pp_forget();
session_regenerate_id(true);
header('Location: /partner-login.php');
