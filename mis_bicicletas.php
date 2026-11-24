<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();
redirect('mis_alquileres.php');
