<?php
// auth/logout.php - Logout handler
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

Session::logout();

header('Location: /');
exit;
