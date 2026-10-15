<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/page.php';

$u = DDP\Auth::user();
DDP\Http::redirect(!$u ? '/login.php' : ($u['role'] === 'admin' ? '/admin/' : '/scan.php'));
