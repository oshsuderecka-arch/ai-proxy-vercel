<?php
// Vercel API endpoint для ИИ-прокси (универсальный)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, User-Agent');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204); exit;
}

/* ---------- Health-check на GET ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode([
    'ok' => true,
    'message' => 'Vercel API работает!',
    'timestamp' => gmdate('Y-m-d H:i:s'),
    'environment' => [
      'vercel' => true,
      'openai_key_set' => !!getenv('OPENAI_API_KEY'),
      'gemini_key_set' => !!getenv('GEMINI_API_KEY'),
      'php_version' => PHP_VERSION,
      'request_method' => 'GET',
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Только POST дальше ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

/* ---------- Ввод ---------- */
$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit;
}

/* ---------- Параметры ---------- */
$provider        = (string)($data['provider'] ?? 'openai');
$model           = (string)($data['model']    ?? 'gpt-4o-mini');
$messages        = $data['messages']          ?? null;
$prompt          = isset($data['prompt']) ? (string)$data['prompt'] : null; // поддержка "prompt"
$temperature     = is_numeric($data['temperature'] ?? null) ? (float)$data['temperature'] : 0.6;
$max_tokens      = is_numeric($data['max_tokens']  ?? null) ? (int)$data['max_tokens']  : 2000;
$response_format = $data['response_format'] ?? null;

/* ---------- Конвертация prompt→messages при необходимости ---------- */
if (!$messages && $prompt !== null) {
  $messages = [
    ['role' => 'system', 'content' => 'Return ONLY a JSON object if requested; otherwise, concise text.'],
    ['role' => 'user',   'content' => $prompt]
  ];
} elseif (!is_array($messages)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'messages or prompt required']); exit;
}

/* ---------- Ключи из ENV ---------- */
$openai_key = getenv('OPENAI_API_KEY') ?: '';
$gemini_key = getenv('GEMINI_API_KEY') ?: '';

/* ---------- Утилиты ---------- */
function http_post_json(string $url, array $payload, array $headers=[], int $timeout=60): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$code, $body, $err];
}

/* ---------- Система замены абстрактных вариантов ---------- */
function replaceAbstractOptions(array $items, string $topic): array {
    $replacements = [
        'сетевые технологии' => [
            'основной принцип' => 'TCP/IP',
            'ключевой фактор' => 'HTTP',
            'характерная черта' => 'Wi-Fi',
            'важное условие' => 'Ethernet',
            'основная характеристика' => 'DNS',
            'важное свойство' => 'VPN',
            'необходимое требование' => 'FTP',
            'специфическая особенность' => 'SSH'
        ],
        'программирование' => [
            'основной принцип' => 'Python',
            'ключевой фактор' => 'JavaScript',
            'характерная черта' => 'Java',
            'важное условие' => 'C++',
            'основная характеристика' => 'Git',
            'важное свойство' => 'Docker',
            'необходимое требование' => 'MySQL',
            'специфическая особенность' => 'React'
        ],
        'математика' => [
            'основной принцип' => 'Производная',
            'ключевой фактор' => 'Интеграл',
            'характерная черта' => 'Матрица',
            'важное условие' => 'Вектор',
            'основная характеристика' => 'Функция',
            'важное свойство' => 'График',
            'необходимое требование' => 'Уравнение',
            'специфическая особенность' => 'Теорема'
        ]
    ];

    $topic_key = 'общая тема';
    $topic_lower = mb_strtolower($topic, 'UTF-8');

    if (strpos($topic_lower, 'сетев') !== false || strpos($topic_lower, 'network') !== false) {
        $topic_key = 'сетевые технологии';
    } elseif (strpos($topic_lower, 'программ') !== false || strpos($topic_lower, 'programming') !== false || strpos($topic_lower, 'код') !== false) {
        $topic_key = 'программирование';
    } elseif (strpos($topic_lower, 'математ') !== false || strpos($topic_lower, 'math') !== false) {
        $topic_key = 'математика';
    }

    // Дополнительная проверка по заголовку вопроса
    foreach ($items as $item) {
        $title_lower = mb_strtolower($item['title'] ?? '', 'UTF-8');
        if (strpos($title_lower, 'программ') !== false || strpos($title_lower, 'код') !== false) {
            $topic_key = 'программирование';
            break;
        } elseif (strpos($title_lower, 'сетев') !== false || strpos($title_lower, 'протокол') !== false) {
            $topic_key = 'сетевые технологии';
            break;
        } elseif (strpos($title_lower, 'математ') !== false || strpos($title_lower, 'функц') !== false) {
            $topic_key = 'математика';
            break;
        }
    }

    $replacement_map = $replacements[$topic_key] ?? [];

    foreach ($items as &$item) {
        if (isset($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as &$option) {
                $option_text_lower = mb_strtolower($option['text'] ?? '', 'UTF-8');
                if (isset($replacement_map[$option_text_lower])) {
                    $option['text'] = $replacement_map[$option_text_lower];
                }
            }
        }
    }
    return $items;
}

/* ---------- 1) OpenAI ---------- */
if ($openai_key) {
  try {
    $payload = [
      'model'       => $model,             // напр. gpt-4o-mini
      'messages'    => $messages,
      'temperature' => $temperature,
      'max_tokens'  => $max_tokens,
    ];
    if ($response_format) {
      $payload['response_format'] = $response_format; // json_object / json_schema / …
    }

    [$code, $body, $err] = http_post_json(
      'https://api.openai.com/v1/chat/completions',
      $payload,
      ['Authorization: Bearer '.$openai_key, 'User-Agent: MyTE-Vercel-Proxy/1.0']
    );

    if ($code >= 200 && $code < 300 && $body) {
      $res = json_decode($body, true);
      $text = $res['choices'][0]['message']['content'] ?? null;
      if ($text !== null) {
        // Применяем систему замены
        try {
          $parsed = json_decode($text, true);
          if ($parsed && isset($parsed['items']) && is_array($parsed['items'])) {
            // Определяем тему из сообщений
            $topic = '';
            foreach ($messages as $msg) {
              if (isset($msg['content']) && is_string($msg['content'])) {
                if (preg_match('/теме\s+[\'"]([^\'"]+)[\'"]/', $msg['content'], $matches)) {
                  $topic = $matches[1];
                  break;
                }
              }
            }
            $parsed['items'] = replaceAbstractOptions($parsed['items'], $topic);
            $text = json_encode($parsed, JSON_UNESCAPED_UNICODE);
          }
        } catch (Throwable $e) {
          error_log('[Vercel] Option replacement failed: ' . $e->getMessage());
        }
        
        echo json_encode(['ok'=>true,'text'=>$text,'source'=>'openai'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    error_log('OpenAI error: HTTP '.$code.' | '.$err.' | '.substr((string)$body,0,500));
  } catch (Throwable $e) {
    error_log('OpenAI exception: '.$e->getMessage());
  }
}

/* ---------- 2) Gemini ---------- */
if ($gemini_key) {
  try {
    // Поддержим переопределение модели через $model, если она начинается с "gemini-"
    $gem_model = (stripos($model, 'gemini-') === 0) ? $model : 'gemini-1.5-flash';

    // Берём последний user/assistant/system контент и склеиваем — Gemini ест plain text
    $joined = '';
    foreach ((array)$messages as $m) {
      $joined .= (string)($m['content'] ?? '')."\n";
    }

    $payload = [
      'contents' => [
        ['parts' => [['text' => $joined]]]
      ],
      'generationConfig' => [
        'temperature' => $temperature,
        'maxOutputTokens' => $max_tokens
      ]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$gem_model}:generateContent?key={$gemini_key}";
    [$code, $body, $err] = http_post_json($url, $payload, ['User-Agent: MyTE-Vercel-Proxy/1.0']);

    if ($code >= 200 && $code < 300 && $body) {
      $res  = json_decode($body, true);
      $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? null;
      if ($text !== null) {
        // Применяем систему замены
        try {
          $parsed = json_decode($text, true);
          if ($parsed && isset($parsed['items']) && is_array($parsed['items'])) {
            // Определяем тему из сообщений
            $topic = '';
            foreach ($messages as $msg) {
              if (isset($msg['content']) && is_string($msg['content'])) {
                if (preg_match('/теме\s+[\'"]([^\'"]+)[\'"]/', $msg['content'], $matches)) {
                  $topic = $matches[1];
                  break;
                }
              }
            }
            $parsed['items'] = replaceAbstractOptions($parsed['items'], $topic);
            $text = json_encode($parsed, JSON_UNESCAPED_UNICODE);
          }
        } catch (Throwable $e) {
          error_log('[Vercel] Option replacement failed: ' . $e->getMessage());
        }
        
        echo json_encode(['ok'=>true,'text'=>$text,'source'=>'gemini'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    error_log('Gemini error: HTTP '.$code.' | '.$err.' | '.substr((string)$body,0,500));
  } catch (Throwable $e) {
    error_log('Gemini exception: '.$e->getMessage());
  }
}

/* ---------- 3) УЛУЧШЕННЫЙ FALLBACK - КОНКРЕТНЫЕ ВАРИАНТЫ ---------- */
echo json_encode([
  'ok' => true,
  'text' => improved_fallback_payload($messages),
  'source' => 'improved-fallback'
], JSON_UNESCAPED_UNICODE);

function improved_fallback_payload(array $messages): string {
  // Извлекаем тему и количество вопросов
  $topic = 'общая тема';
  $count = 3;
  
  foreach ($messages as $m) {
    if (!empty($m['content'])) {
      if (preg_match('/теме\s+[\'"]([^\'"]+)[\'"]/', $m['content'], $matches)) {
        $topic = trim($matches[1]);
      }
      if (preg_match('/Сгенерируй\s+(\d+)\s+вопрос/iu', $m['content'], $matches)) {
        $count = max(2, min(8, (int)$matches[1]));
      }
    }
  }
  
  // Определяем тип темы для конкретных вариантов
  $topic_lower = mb_strtolower($topic, 'UTF-8');
  $concrete_options = [];
  
  if (strpos($topic_lower, 'сетев') !== false || strpos($topic_lower, 'network') !== false) {
    // Сетевые технологии - конкретные протоколы и технологии
    $concrete_options = ['TCP/IP', 'HTTP', 'Wi-Fi', 'Ethernet', 'DNS', 'VPN', 'FTP', 'SSH', 'SMTP', 'POP3', 'IMAP', 'UDP'];
  } elseif (strpos($topic_lower, 'программ') !== false || strpos($topic_lower, 'programming') !== false || strpos($topic_lower, 'код') !== false) {
    // Программирование - конкретные языки и инструменты
    $concrete_options = ['Python', 'JavaScript', 'Java', 'C++', 'Git', 'Docker', 'MySQL', 'React', 'Node.js', 'API', 'Kubernetes', 'SQL'];
  } elseif (strpos($topic_lower, 'математ') !== false || strpos($topic_lower, 'math') !== false) {
    // Математика - конкретные понятия
    $concrete_options = ['Производная', 'Интеграл', 'Матрица', 'Вектор', 'Функция', 'График', 'Уравнение', 'Теорема', 'Аксиома', 'Предел', 'Ряд', 'Определитель'];
  } else {
    // Общая тема - универсальные варианты
    $concrete_options = ['Концепция', 'Элемент', 'Свойство', 'Требование', 'Аспект', 'Принцип', 'Условие', 'Особенность', 'Пример', 'Ошибка', 'Инструмент', 'Утверждение'];
  }
  
  $items = [];
  for ($i = 1; $i <= $count; $i++) {
    $isRadio = ($i % 2 === 1);
    $type = $isRadio ? 'radio' : 'checkbox';
    
    // Перемешиваем варианты для разнообразия
    shuffle($concrete_options);
    $options = array_slice($concrete_options, 0, 4);
    
    // Создаём варианты ответов
    $opts = [];
    foreach ($options as $j => $option) {
      $opts[] = [
        'text' => $option,
        'correct' => $isRadio ? ($j === 0) : ($j < 2) // Для radio - первый правильный, для checkbox - первые два
      ];
    }
    
    // Перемешиваем варианты
    shuffle($opts);
    
    $items[] = [
      'type' => $type,
      'title' => $isRadio 
        ? "Какой протокол используется в {$topic}?"
        : "Отметьте все технологии, используемые в {$topic}:",
      'required' => true,
      'points' => 1,
      'options' => $opts
    ];
  }
  
  return json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
}
