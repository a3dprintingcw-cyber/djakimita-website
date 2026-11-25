<?php
require __DIR__ . '/../../app/bootstrap.php';
unset($_SESSION['pp_uid']);
session_regenerate_id(true);
header('Location: /partner-login.php');
