<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_login();
redirect('seguimiento.php?codigo=' . urlencode((string)($_GET['codigo'] ?? '')));
