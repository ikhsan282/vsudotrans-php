<?php
/**
 * Klien AI untuk bantu input transaksi (9router, OpenAI-compatible).
 * Prinsip: model HANYA menghasilkan kandidat JSON. Semua field divalidasi
 * ulang di PHP terhadap daftar asli sebelum dipakai mengisi form.
 * Key/base/model ada di config/config.php (gitignored) AI_*.
 */

/**
 * Panggil model, paksa JSON mode, buang artefak SSE, kembalikan array.
 * @param array<int,array{role:string,content:string}> $messages
 * @param array<string,mixed> $extra
 * @return array{ok:bool,data?:array,error?:string}
 */
function ai_chat_json(array $messages, array $extra = []): array
{
    $key = defined('AI_API_KEY') ? (string) AI_API_KEY : '';
    $base = defined('AI_BASE_URL') ? rtrim((string) AI_BASE_URL, '/') : '';
    $model = defined('AI_MODEL') ? (string) AI_MODEL : '';
    if ($key === '' || $base === '' || $model === '') {
        return ['ok' => false, 'error' => 'AI belum dikonfigurasi (AI_BASE_URL / AI_API_KEY / AI_MODEL di config/config.php).'];
    }

    $body = array_merge([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0,
        'response_format' => ['type' => 'json_object'],
    ], $extra);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $headers = ["Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n"];

    if (function_exists('curl_init')) {
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('ai_chat_json: curl error: ' . $err);
            return ['ok' => false, 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($http !== 200) {
            error_log('ai_chat_json: HTTP ' . $http . ' body: ' . substr((string) $raw, 0, 500));
            return ['ok' => false, 'error' => 'Server AI balas HTTP ' . $http . '.'];
        }
    } else {
        // Fallback tanpa ekstensi curl (HTTP stream handler bawaan PHP).
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode('', $headers),
            'content' => $payload,
            'timeout' => 90,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($base . '/chat/completions', false, $ctx);
        if ($raw === false) {
            error_log('ai_chat_json: stream gagal menghubungi ' . $base);
            return ['ok' => false, 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) $http = (int) $m[1];
        }
        if ($http !== 200) {
            error_log('ai_chat_json: HTTP ' . $http . ' body: ' . substr((string) $raw, 0, 500));
            return ['ok' => false, 'error' => 'Server AI balas HTTP ' . $http . '.'];
        }
    }

    // 9router menempel 'data: [DONE]' di ekor respons non-streaming (artefak SSE).
    $i = strpos($raw, 'data: [DONE]');
    if ($i !== false) {
        $raw = substr($raw, 0, $i);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        error_log('ai_chat_json: respons bukan JSON: ' . substr($raw, 0, 300));
        return ['ok' => false, 'error' => 'Respons server AI tidak valid.'];
    }
    if (!isset($json['choices'][0]['message']['content'])) {
        error_log('ai_chat_json: struktur respons tak terduga: ' . substr((string) json_encode($json), 0, 300));
        return ['ok' => false, 'error' => 'Respons server AI tidak lengkap.'];
    }
    $content = trim((string) $json['choices'][0]['message']['content']);
    // Lepas code-fence bila model tetap membungkus.
    if (str_starts_with($content, '```')) {
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content));
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        error_log('ai_chat_json: konten model bukan JSON: ' . substr($content, 0, 300));
        return ['ok' => false, 'error' => 'Model tidak membalas JSON valid.'];
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * Kandidat field yang boleh dipakai mengisi form, SETELAH divalidasi keras.
 * @param array $parsed JSON dari model
 * @param array $peta   daftar pilihan asli: ['branch_id' => [id=>label], ...]
 * @param array $fields field teks: contoh ['pickup_address' => '...']
 * @return array hanya field yang lolos validasi
 */
function ai_sanitize_candidate(array $parsed, array $peta, array $fields = []): array
{
    $out = [];
    foreach ($peta as $field => $allowed) {
        if (!array_key_exists($field, $parsed)) {
            continue;
        }
        $v = $parsed[$field];
        if (is_array($v) || (string) $v === '') {
            continue; // kosong/aneh = tidak diset, biarkan user pilih sendiri
        }
        $id = (int) $v;
        if ($id > 0 && isset($allowed[$id])) {
            $out[$field] = $id;
        }
    }
    foreach ($fields as $field => $max) {
        if (!array_key_exists($field, $parsed) || !is_scalar($parsed[$field])) {
            continue;
        }
        $v = trim((string) $parsed[$field]);
        if ($v !== '') {
            $out[$field] = mb_substr($v, 0, $max);
        }
    }
    return $out;
}