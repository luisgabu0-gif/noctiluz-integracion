<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/inicio.php';
require dirname(__DIR__) . '/app/Panel.php';

Panel::iniciarSesion();
$_SESSION = [];
session_destroy();
header('Location: index.php');
