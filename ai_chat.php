<?php
/**
 * BioTrack AI Assistant endpoint (admin only, read-only).
 * POST JSON {"messages":[{"role":"user","content":"..."}, ...]}  ->  {"ok":true,"reply":"..."}
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'config/ai_config.php';
require_once 'includes/ai_context.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ai_out(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') ai_out(['ok' => false, 'error' => 'Please sign in as admin.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ai_out(['ok' => false, 'error' => 'POST only.'], 405);
// A JSON body can't be sent by another website's form, which keeps this endpoint safe from cross-site requests.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) ai_out(['ok' => false, 'error' => 'Bad request.'], 400);

$provider = (defined('AI_PROVIDER') && AI_PROVIDER === 'claude') ? 'claude' : 'gemini';
if ($provider === 'gemini') {
    $key = trim((string)(defined('AI_GEMINI_KEY') ? AI_GEMINI_KEY : '')) ?: trim((string)getenv('GEMINI_API_KEY'));
    $setup = "The assistant isn't set up yet. Get a free key at **aistudio.google.com** (Get API key), open **config/ai_config.php**, paste it into `AI_GEMINI_KEY`, save, then try again.";
} else {
    $key = trim((string)(defined('AI_API_KEY') ? AI_API_KEY : '')) ?: trim((string)getenv('ANTHROPIC_API_KEY'));
    $setup = "The assistant isn't set up yet. Open **config/ai_config.php**, paste your Anthropic key into `AI_API_KEY`, save, then try again.";
}
if ($key === '') ai_out(['ok' => true, 'reply' => $setup]);
if (!function_exists('curl_init')) {
    ai_out(['ok' => true, 'reply' => "PHP's cURL extension is off. In XAMPP open php.ini, remove the `;` before `extension=curl`, then restart Apache."]);
}

$in = json_decode(file_get_contents('php://input'), true);
$msgs = [];
foreach ((array)($in['messages'] ?? []) as $m) {
    $role = $m['role'] ?? '';
    $text = trim((string)($m['content'] ?? ''));
    if (!in_array($role, ['user', 'assistant'], true) || $text === '') continue;
    $text = mb_substr($text, 0, 3000);
    if ($msgs && $msgs[count($msgs) - 1]['role'] === $role) { $msgs[count($msgs) - 1]['content'] .= "\n" . $text; continue; }
    $msgs[] = ['role' => $role, 'content' => $text];
}
$msgs = array_slice($msgs, -12);
while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
if (!$msgs || $msgs[count($msgs) - 1]['role'] !== 'user') ai_out(['ok' => false, 'error' => 'Type a question first.'], 400);

// Build the live snapshot, then free the session so other pages don't wait on this request.
try {
    $system = ai_system_prompt() . "\n\n" . ai_build_context($pdo);
} catch (Throwable $e) {
    ai_out(['ok' => false, 'error' => 'Could not read the attendance data.'], 500);
}
session_write_close();

function ai_post(string $url, string $payload, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int)AI_TIMEOUT,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $code, $err];
}

/**
 * Google renames and retires models often. If the configured model is gone (HTTP 404),
 * ask Google which models this key can use and pick a current Flash model automatically.
 */
function ai_gemini_pick_model(string $key, string $skip): ?string {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key]]);
    $body = curl_exec($ch);
    curl_close($ch);
    $list = json_decode((string)$body, true)['models'] ?? [];
    $cands = [];
    foreach ($list as $m) {
        $name = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if ($name === '' || $name === $skip || strpos($name, 'gemini-') !== 0 || strpos($name, 'flash') === false) continue;
        if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
        if (preg_match('/(tts|image|live|embed|omni|audio|robotics|computer|research|antigravity|exp|latest|-\d{3}$)/', $name)) continue;
        $ver = preg_match('/gemini-(\d+(?:\.\d+)?)/', $name, $mm) ? (float)$mm[1] : 0;
        $cands[] = [
            'name'    => $name,
            'preview' => strpos($name, 'preview') !== false ? 1 : 0,   // stable first
            'lite'    => strpos($name, 'lite') !== false ? 0 : 1,      // lite first (lightest limits on free tier)
            'ver'     => $ver,
        ];
    }
    usort($cands, fn($x, $y) => [$x['preview'], $x['lite'], -$x['ver']] <=> [$y['preview'], $y['lite'], -$y['ver']]);
    return $cands ? $cands[0]['name'] : null;
}

$cacheFile = __DIR__ . '/config/ai_model.cache';
$model = '';

if ($provider === 'gemini') {
    $contents = [];
    foreach ($msgs as $m) {
        $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
    }
    $payload = json_encode([
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => $contents,
        // Gemini models "think" first and that counts toward the limit, so allow extra room.
        'generationConfig'  => ['maxOutputTokens' => max(2048, (int)AI_MAX_TOKENS * 2)],
    ], JSON_UNESCAPED_UNICODE);
    $headers = ['content-type: application/json', 'x-goog-api-key: ' . $key];

    $model = trim((string)@file_get_contents($cacheFile)) ?: AI_GEMINI_MODEL;
    $url = fn($mod) => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($mod) . ':generateContent';
    [$body, $code, $err] = ai_post($url($model), $payload, $headers);
    if ($code === 404) {
        $alt = ai_gemini_pick_model($key, $model);
        if ($alt) {
            [$body, $code, $err] = ai_post($url($alt), $payload, $headers);
            if ($code === 200) { @file_put_contents($cacheFile, $alt); $model = $alt; }
        }
    }
    // 503 / 500 = Google's model is overloaded right now. Retry once, then try a different Flash model.
    if ($code === 503 || $code === 500) {
        sleep(2);
        [$body, $code, $err] = ai_post($url($model), $payload, $headers);
        if ($code === 503 || $code === 500) {
            $alt = ai_gemini_pick_model($key, $model);
            if ($alt) [$body, $code, $err] = ai_post($url($alt), $payload, $headers);   // one-time fallback, not saved
        }
    }
} else {
    $payload = json_encode([
        'model'      => AI_MODEL,
        'max_tokens' => (int)AI_MAX_TOKENS,
        'system'     => $system,
        'messages'   => $msgs,
    ], JSON_UNESCAPED_UNICODE);
    $headers = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    [$body, $code, $err] = ai_post('https://api.anthropic.com/v1/messages', $payload, $headers);
}

if ($body === false) {
    ai_out(['ok' => false, 'error' => "Can't reach the AI service. Check the internet connection on this PC. ($err)"], 502);
}
$res = json_decode($body, true);
if ($code !== 200) {
    $why = $res['error']['message'] ?? 'Unknown error';
    if ($code === 401 || $code === 403 || ($code === 400 && stripos($why, 'API key') !== false)) $why = 'The API key was rejected. Check the key in config/ai_config.php.';
    if ($code === 404) $why = "No available AI model was found for this key. Open aistudio.google.com, copy a current Flash model name and put it in AI_GEMINI_MODEL (config/ai_config.php).";
    if ($code === 429) $why = $provider === 'gemini'
        ? 'The free limit was reached for now. Wait a minute and try again.'
        : 'Too many requests right now. Wait a moment and try again.';
    if ($code === 503 || $code === 529) $why = 'The AI service is busy. Try again in a moment.';
    ai_out(['ok' => false, 'error' => $why], 502);
}

$reply = '';
if ($provider === 'gemini') {
    foreach ((array)($res['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text']) && empty($part['thought'])) $reply .= $part['text'];
    }
    if (trim($reply) === '' && !empty($res['promptFeedback']['blockReason'])) {
        ai_out(['ok' => false, 'error' => 'That request was blocked by the AI service. Try rewording it.'], 502);
    }
} else {
    foreach ((array)($res['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') $reply .= $blk['text'];
}
ai_out(['ok' => true, 'reply' => trim($reply) !== '' ? trim($reply) : "I couldn't come up with an answer. Try rephrasing."]);
