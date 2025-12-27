<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = [
    'opcache_enabled' => function_exists('opcache_reset'),
    'opcache_cleared' => false,
    'timestamp' => date('Y-m-d H:i:s'),
];

if (function_exists('opcache_reset')) {
    $result['opcache_cleared'] = opcache_reset();
    $result['message'] = 'OPCache cleared successfully';
} else {
    $result['message'] = 'OPCache not enabled on this server';
}

// Also clear APCu cache if available
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    $result['apcu_cleared'] = true;
}

echo json_encode($result, JSON_PRETTY_PRINT);
