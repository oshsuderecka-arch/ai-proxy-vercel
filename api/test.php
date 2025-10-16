<?php
// /api/test.php — health-check для Vercel API
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, User-Agent');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* CORS preflight */
if ($method === 'OPTIONS') {
  header('Access-Control-Max-Age: 86400');
  http_response_code(204);
  exit;
}

/* Разрешаем только GET/POST */
if ($method !== 'GET' && $method !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method not allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$response = [
  'ok' => true,
  'message' => 'Vercel API работает!',
  'timestamp' => gmdate('Y-m-d H:i:s'),            // UTC стабильно для Vercel
  'environment' => [
    'vercel' => true,
    'php_version' => PHP_VERSION,
    'openai_key_set' => (bool) getenv('OPENAI_API_KEY'),
    'gemini_key_set' => (bool) getenv('GEMINI_API_KEY'),
    'request_method' => $method,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
  ]
];

/* Эхо POST-данных c валидацией */
if ($method === 'POST') {
  $input = file_get_contents('php://input');
  $data  = json_decode($input, true);

  if ($input !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'Invalid JSON',
      'json_error' => json_last_error_msg()
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (is_array($data)) {
    $response['received_data'] = $data;
    $response['message'] = 'POST данные получены успешно!';
  }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
