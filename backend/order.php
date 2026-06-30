<?php

http_response_code(501);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'message' => 'Order endpoint will be added later.',
], JSON_UNESCAPED_UNICODE);
