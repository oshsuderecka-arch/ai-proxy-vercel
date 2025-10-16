<?php
// Vercel API endpoint для ИИ-прокси
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$provider = $data['provider'] ?? 'openai';
$model = $data['model'] ?? 'gpt-4o-mini';
$messages = $data['messages'] ?? [];
$temperature = $data['temperature'] ?? 0.6;
$max_tokens = $data['max_tokens'] ?? 2000;
$response_format = $data['response_format'] ?? null;

// Получаем API ключи из переменных окружения Vercel
$openai_key = getenv('OPENAI_API_KEY');
$gemini_key = getenv('GEMINI_API_KEY');

// Пробуем разные API в порядке приоритета
$success = false;
$response_text = '';

// 1. Пробуем OpenAI напрямую
if ($openai_key && !$success) {
    try {
        $api_url = 'https://api.openai.com/v1/chat/completions';
        
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens
        ];
        
        if ($response_format) {
            $payload['response_format'] = $response_format;
        }
        
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openai_key,
                'Content-Type: application/json',
                'User-Agent: MyTE-Vercel-Proxy/1.0'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$error && $http_code >= 200 && $http_code < 300) {
            $result = json_decode($resp, true);
            if ($result && isset($result['choices'][0]['message']['content'])) {
                $response_text = $result['choices'][0]['message']['content'];
                $success = true;
            }
        } else {
            error_log('OpenAI API error: HTTP ' . $http_code . ' - ' . $resp);
        }
    } catch (Exception $e) {
        error_log('OpenAI API error: ' . $e->getMessage());
    }
}

// 2. Пробуем Gemini API напрямую
if ($gemini_key && !$success) {
    try {
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';
        
        $payload = [
            'contents' => [
                ['parts' => [['text' => $messages[count($messages)-1]['content']]]]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $max_tokens
            ]
        ];
        
        $ch = curl_init($api_url . '?key=' . $gemini_key);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: MyTE-Vercel-Proxy/1.0'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$error && $http_code >= 200 && $http_code < 300) {
            $result = json_decode($resp, true);
            if ($result && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $response_text = $result['candidates'][0]['content']['parts'][0]['text'];
                $success = true;
            }
        } else {
            error_log('Gemini API error: HTTP ' . $http_code . ' - ' . $resp);
        }
    } catch (Exception $e) {
        error_log('Gemini API error: ' . $e->getMessage());
    }
}

// 3. Fallback - генерируем простые вопросы локально
if (!$success) {
    $response_text = generateFallbackQuestions($messages, $temperature);
    $success = true;
}

if ($success) {
    echo json_encode([
        'ok' => true,
        'text' => $response_text,
        'source' => 'vercel-proxy'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'All API providers failed'
    ]);
}

function generateFallbackQuestions($messages, $temperature) {
    // Простая генерация контента без внешних API
    $system_message = '';
    $user_message = '';
    
    foreach ($messages as $msg) {
        if ($msg['role'] === 'system') {
            $system_message = $msg['content'];
        } elseif ($msg['role'] === 'user') {
            $user_message = $msg['content'];
        }
    }
    
    // Извлекаем тему
    preg_match('/Тема:\s*([^\n]+)/', $user_message, $topic_matches);
    $topic = isset($topic_matches[1]) ? trim($topic_matches[1]) : 'общая тема';
    
    // Извлекаем количество вопросов
    preg_match('/Сгенерируй (\d+) вопросов/', $user_message, $matches);
    $count = isset($matches[1]) ? (int)$matches[1] : 3;
    
    $questions = [];
    for ($i = 0; $i < $count; $i++) {
        $question_types = ['radio', 'checkbox'];
        $type = $question_types[array_rand($question_types)];
        
        $templates = [
            'radio' => [
                'Что является основным принципом ' . $topic . '?',
                'Какая характеристика наиболее важна для ' . $topic . '?',
                'Что определяет эффективность ' . $topic . '?',
                'Какой подход лучше всего подходит для ' . $topic . '?',
                'Что является ключевым фактором в ' . $topic . '?'
            ],
            'checkbox' => [
                'Какие из следующих утверждений верны для ' . $topic . '?',
                'Отметьте все правильные характеристики ' . $topic . '.',
                'Выберите все верные принципы ' . $topic . '.',
                'Какие методы используются в ' . $topic . '?',
                'Отметьте все важные аспекты ' . $topic . '.'
            ],
        ];
        
        $title = $templates[$type][array_rand($templates[$type])];
        
        $question = [
            'type' => $type,
            'title' => $title,
            'required' => true,
            'points' => 1
        ];
        
        if ($type === 'radio' || $type === 'checkbox') {
            $option_templates = [
                'Основной принцип',
                'Важное условие', 
                'Ключевой фактор',
                'Специфическая особенность',
                'Характерная черта',
                'Необходимое требование',
                'Основная характеристика',
                'Важное свойство'
            ];
            
            $options = [];
            $correct_count = ($type === 'radio') ? 1 : rand(2, 3);
            $used_options = [];
            
            for ($j = 0; $j < 4; $j++) {
                do {
                    $option_text = $option_templates[array_rand($option_templates)];
                } while (in_array($option_text, $used_options));
                
                $used_options[] = $option_text;
                $is_correct = ($j < $correct_count);
                
                $options[] = [
                    'text' => $option_text,
                    'correct' => $is_correct
                ];
            }
            
            // Перемешиваем варианты
            shuffle($options);
            $question['options'] = $options;
        }
        
        $questions[] = $question;
    }
    
    return json_encode(['items' => $questions], JSON_UNESCAPED_UNICODE);
}
?>
