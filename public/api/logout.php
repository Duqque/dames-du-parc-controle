<?php
declare(strict_types=1);

use DDP\{Auth, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('POST');
Auth::requireCsrf();
Auth::logout();
Http::json(['ok' => true, 'redirect' => '/login.php']);
