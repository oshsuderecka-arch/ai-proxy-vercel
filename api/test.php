<?php
// Тестовый endpoint для проверки работы Vercel API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = [
    'ok' => true,
    'message' => 'Vercel API работает!',
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => [
        'vercel' => true,
        'php_version' => PHP_VERSION,
        'openai_key_set' => !empty(getenv('OPENAI_API_KEY')),
        'gemini_key_set' => !empty(getenv('GEMINI_API_KEY')),
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data) {
        $response['received_data'] = $data;
        $response['message'] = 'POST данные получены успешно!';
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
