<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

// API-ключи будут храниться в переменных окружения Vercel
$OPENAI_KEY = getenv("OPENAI_API_KEY");
$GEMINI_KEY = getenv("GEMINI_API_KEY");

// Получаем JSON-запрос от клиента
$raw = file_get_contents("php://input");
$req = json_decode($raw, true) ?: [];

$provider = $req["provider"] ?? "openai";   // "openai" или "gemini"
$prompt   = $req["prompt"] ?? "Hello AI, give me one test question.";

// --- OpenAI ---
if ($provider === "openai") {
    $url = "https://api.openai.com/v1/chat/completions";
    $payload = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ]
    ];
    $headers = [
        "Authorization: Bearer $OPENAI_KEY",
        "Content-Type: application/json"
    ];
}
// --- Gemini ---
elseif ($provider === "gemini") {
    $model = "gemini-1.5-flash";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$GEMINI_KEY";
    $payload = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ]
    ];
    $headers = ["Content-Type: application/json"];
}
// --- Ошибка ---
else {
    echo json_encode(["ok"=>false,"error"=>"Unknown provider"]);
    exit;
}

// Выполняем запрос
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60
]);
$resp = curl_exec($ch);
if ($resp === false) {
    echo json_encode(["ok"=>false,"error"=>"cURL: ".curl_error($ch)]);
    exit;
}
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($code);
echo $resp;
