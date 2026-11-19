<?php
/**
 * Klien AI untuk bantu input transaksi (9router, OpenAI-compatible).
 * Prinsip: model HANYA menghasilkan kandidat JSON. Semua field divalidasi
 * ulang di PHP terhadap daftar asli sebelum dipakai mengisi form.
 * Key/base/model ada di config/config.php (gitignored) AI_*.
 */

/**
 * Kirim request chat/completions ke endpoint OpenAI-compatible (9router) dan
 * kembalikan isi `choices[0].message.content` yang sudah dibersihkan dari artefak SSE.
 *
 * @param array<string,mixed> $body
 * @return array{ok:bool,content?:string,code?:string,error?:string}
 */
function _ai_post_chat_completions(array $body, int $timeout = 90, int $connectTimeout = 15): array
{
    $key   = defined('AI_API_KEY') ? (string) AI_API_KEY : '';
    $base  = defined('AI_BASE_URL') ? rtrim((string) AI_BASE_URL, '/') : '';
    $model = defined('AI_MODEL') ? (string) AI_MODEL : '';
    if ($key === '' || $base === '' || $model === '') {
        return [
            'ok'    => false,
            'code'  => 'NOT_CONFIGURED',
            'error' => 'AI belum dikonfigurasi (AI_BASE_URL / AI_API_KEY / AI_MODEL).',
        ];
    }

    if (empty($body['model'])) {
        $body['model'] = $model;
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $headers = ["Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n"];

    if (function_exists('curl_init')) {
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('ai_chat: curl error: ' . $err);
            return ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($http !== 200) {
            error_log('ai_chat: HTTP ' . $http . ' body: ' . substr((string) $raw, 0, 500));
            return ['ok' => false, 'code' => 'HTTP_' . $http, 'error' => 'Server AI balas HTTP ' . $http . '.'];
        }
    } else {
        // Fallback tanpa ekstensi curl (HTTP stream handler bawaan PHP).
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode('', $headers),
            'content'       => $payload,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($base . '/chat/completions', false, $ctx);
        if ($raw === false) {
            error_log('ai_chat: stream gagal menghubungi ' . $base);
            return ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $http = (int) $m[1];
            }
        }
        if ($http !== 200) {
            error_log('ai_chat: HTTP ' . $http . ' body: ' . substr((string) $raw, 0, 500));
            return ['ok' => false, 'code' => 'HTTP_' . $http, 'error' => 'Server AI balas HTTP ' . $http . '.'];
        }
    }

    // 9router menempel 'data: [DONE]' di ekor respons non-streaming (artefak SSE).
    $i = strpos($raw, 'data: [DONE]');
    if ($i !== false) {
        $raw = substr($raw, 0, $i);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        error_log('ai_chat: respons bukan JSON: ' . substr($raw, 0, 300));
        return ['ok' => false, 'code' => 'BAD_RESPONSE', 'error' => 'Respons server AI tidak valid.'];
    }
    if (!isset($json['choices'][0]['message']['content'])) {
        error_log('ai_chat: struktur respons tak terduga: ' . substr((string) json_encode($json), 0, 300));
        return ['ok' => false, 'code' => 'BAD_RESPONSE', 'error' => 'Respons server AI tidak lengkap.'];
    }

    return ['ok' => true, 'content' => trim((string) $json['choices'][0]['message']['content'])];
}

/**
 * Bersihkan blok <think>...</think> (termasuk tag <think> menggantung)
 * yang sering dikeluarkan oleh model reasoning pada 9router.
 */
function ai_strip_reasoning(string $text): string
{
    $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text);
    if (!is_string($cleaned)) {
        $cleaned = $text;
    }
    $cleaned = preg_replace('/<think\b[^>]*>.*$/is', '', $cleaned);
    if (!is_string($cleaned)) {
        return trim($text);
    }
    return trim($cleaned);
}

/**
 * Panggil model, paksa JSON mode, buang artefak SSE, kembalikan array.
 * @param array<int,array{role:string,content:string}> $messages
 * @param array<string,mixed> $extra
 * @return array{ok:bool,data?:array,error?:string}
 */
function ai_chat_json(array $messages, array $extra = []): array
{
    $body = array_merge([
        'model'           => defined('AI_MODEL') ? (string) AI_MODEL : '',
        'messages'        => $messages,
        'temperature'     => 0,
        'response_format' => ['type' => 'json_object'],
    ], $extra);

    $res = _ai_post_chat_completions($body, 90, 15);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Gagal memanggil AI.'];
    }

    $content = ai_strip_reasoning((string) $res['content']);
    // Lepas code-fence bila model tetap membungkus.
    if (str_starts_with($content, '```')) {
        $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content));
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        error_log('ai_chat_json: konten model bukan JSON: ' . substr($content, 0, 300));
        return ['ok' => false, 'error' => 'Model tidak membalas JSON valid.'];
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * Panggil model 9router untuk AI Assistant multi-turn (tahan model reasoning & text/JSON fleksibel).
 *
 * @param array<int,array{role:string,content:string}> $messages
 * @param array<string,mixed> $extra
 * @return array{ok:bool,reply?:string,actions?:array<int,array{label:string,url:string}>,model?:string,code?:string,error?:string}
 */
function ai_chat_assistant(array $messages, array $extra = []): array
{
    $model = defined('AI_MODEL') ? (string) AI_MODEL : '';
    $body  = array_merge([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.2,
    ], $extra);

    $res = _ai_post_chat_completions($body, 45, 10);
    if (!$res['ok']) {
        return $res;
    }

    $cleaned = ai_strip_reasoning((string) $res['content']);
    $jsonCandidate = $cleaned;
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $cleaned, $m)) {
        $jsonCandidate = trim($m[1]);
    } elseif (!str_starts_with($cleaned, '{') && preg_match('/(\{[\s\S]*"reply"\s*:[\s\S]*\})/i', $cleaned, $m)) {
        $jsonCandidate = trim($m[1]);
    }

    $parsed  = json_decode($jsonCandidate, true);
    $reply   = $cleaned;
    $actions = [];

    if (is_array($parsed) && isset($parsed['reply']) && is_string($parsed['reply'])) {
        $reply = trim($parsed['reply']);
        if (!empty($parsed['actions']) && is_array($parsed['actions'])) {
            foreach ($parsed['actions'] as $act) {
                if (!is_array($act)) {
                    continue;
                }
                $lbl = trim((string) ($act['label'] ?? ''));
                $url = trim((string) ($act['url'] ?? ''));
                if ($lbl !== '' && $url !== '') {
                    $actions[] = ['label' => $lbl, 'url' => $url];
                }
            }
        }
    }

    if ($reply === '') {
        return ['ok' => false, 'code' => 'EMPTY_REPLY', 'error' => 'Model mengembalikan balasan kosong.'];
    }

    return [
        'ok'      => true,
        'reply'   => $reply,
        'actions' => $actions,
        'model'   => $model,
    ];
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