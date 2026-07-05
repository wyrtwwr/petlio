<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

json_response([
    'message' => 'Legacy endpoint disabled. Use backend/create-payment.php.',
], 410);
