<?php
/*
 * BnhHub — Instagram → Artes
 *
 * Este endpoint mantém o token da Meta no servidor e entrega somente os dados
 * públicos necessários para os cards do BnhHub.
 *
 * CONFIGURAÇÃO RECOMENDADA (variáveis de ambiente):
 *   INSTAGRAM_ACCESS_TOKEN
 *   IG_USER_ID_DANBNHTV
 *   IG_USER_ID_VISUALLAB
 *   IG_USER_ID_GABRIEL_JUSTE
 *   INSTAGRAM_GRAPH_VERSION (opcional; padrão v23.0)
 *
 * Se o servidor não tiver variáveis de ambiente, substitua os valores abaixo.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$token = getenv('INSTAGRAM_ACCESS_TOKEN') ?: '';
$graphVersion = getenv('INSTAGRAM_GRAPH_VERSION') ?: 'v23.0';
$accounts = [
    'danbnhtv' => getenv('IG_USER_ID_DANBNHTV') ?: '',
    'visuallabagency.psd' => getenv('IG_USER_ID_VISUALLAB') ?: '',
    'gabriel.juste' => getenv('IG_USER_ID_GABRIEL_JUSTE') ?: '',
];

if (!$token || in_array('', $accounts, true)) {
    http_response_code(503);
    echo json_encode([
        'configured' => false,
        'message' => 'Configure INSTAGRAM_ACCESS_TOKEN e os três IDs de usuário do Instagram no servidor.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min(60, (int)$_GET['limit'])) : 60;
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bnhhub_instagram_feed_' . md5(implode('|', array_values($accounts))) . '.json';
$cacheTtl = 300;

if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false) {
        echo $cached;
        exit;
    }
}

function http_get_json(string $url, string $token): array {
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'access_token=' . rawurlencode($token);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'BnhHub Instagram Feed/1.0'
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error ?: 'Instagram Graph API respondeu HTTP ' . $status);
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "Accept: application/json\r\nUser-Agent: BnhHub Instagram Feed/1.0\r\n"
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) throw new RuntimeException('Não foi possível consultar o Instagram Graph API.');
    }
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('Resposta inválida do Instagram Graph API.');
    if (isset($json['error'])) throw new RuntimeException($json['error']['message'] ?? 'Erro do Instagram Graph API.');
    return $json;
}

$posts = [];
$fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username';
$errors = [];

foreach ($accounts as $profile => $userId) {
    try {
        $url = 'https://graph.facebook.com/' . rawurlencode($graphVersion) . '/' . rawurlencode($userId) . '/media?fields=' . rawurlencode($fields) . '&limit=20';
        $json = http_get_json($url, $token);
        foreach (($json['data'] ?? []) as $item) {
            $mediaUrl = $item['media_type'] === 'VIDEO' ? ($item['thumbnail_url'] ?? $item['media_url'] ?? '') : ($item['media_url'] ?? '');
            if (!$mediaUrl || empty($item['permalink'])) continue;
            $posts[] = [
                'id' => (string)($item['id'] ?? ''),
                'profile' => $profile,
                'media_type' => (string)($item['media_type'] ?? 'IMAGE'),
                'media_url' => $mediaUrl,
                'permalink' => (string)$item['permalink'],
                'timestamp' => (string)($item['timestamp'] ?? ''),
                'caption' => trim((string)($item['caption'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $errors[$profile] = $e->getMessage();
    }
}

usort($posts, static function($a, $b) {
    return strcmp($b['timestamp'], $a['timestamp']);
});
$posts = array_slice($posts, 0, $limit);

$result = [
    'configured' => true,
    'generated_at' => gmdate('c'),
    'posts' => $posts,
];
if ($errors) $result['warnings'] = $errors;

$jsonOut = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonOut === false) $jsonOut = json_encode(['configured'=>true,'posts'=>[]]);
@file_put_contents($cacheFile, $jsonOut, LOCK_EX);
echo $jsonOut;
