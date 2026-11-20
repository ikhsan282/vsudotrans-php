<?php
/**
 * Klien AI untuk bantu input transaksi & AI Assistant (OpenAI-compatible).
 * Mendukung provider utama (9router) dengan failover otomatis ke DeepSeek
 * API cadangan saat provider utama mati/timeout/gagal merespons.
 * Prinsip: model HANYA menghasilkan kandidat JSON/jawaban. Semua field divalidasi
 * ulang di PHP sebelum dipakai. Konfigurasi key/base/model di config/config.php.
 */

/** Normalisasi base URL endpoint OpenAI-compatible tanpa trailing /chat/completions. */
function _ai_normalize_base_url(string $base): string
{
    $clean = rtrim(trim($base), '/');
    if ($clean === '') {
        return '';
    }
    return (string) preg_replace('#/chat/completions/?$#i', '', $clean);
}

/** Model aktif untuk chat AI Assistant (pakai AI_CHAT_MODEL bila ada, fallback AI_MODEL). */
function _ai_active_chat_model(): string
{
    if (defined('AI_CHAT_MODEL') && trim((string) AI_CHAT_MODEL) !== '') {
        return trim((string) AI_CHAT_MODEL);
    }
    return defined('AI_MODEL') ? trim((string) AI_MODEL) : '';
}

/**
 * Daftar konfigurasi provider AI berurutan: [0] utama (9router), [1] cadangan (DeepSeek).
 * @param 'chat'|'json' $purpose
 * @return array<int,array{name:string,base:string,key:string,model:string}>
 */
function _ai_provider_configs(string $purpose = 'chat'): array
{
    $providers = [];

    $pKey   = defined('AI_API_KEY') ? trim((string) AI_API_KEY) : '';
    $pBase  = defined('AI_BASE_URL') ? _ai_normalize_base_url((string) AI_BASE_URL) : '';
    $pModel = $purpose === 'chat'
        ? _ai_active_chat_model()
        : (defined('AI_MODEL') ? trim((string) AI_MODEL) : '');
    if ($pKey !== '' && $pBase !== '' && $pModel !== '') {
        $providers[] = [
            'name'  => 'primary',
            'base'  => $pBase,
            'key'   => $pKey,
            'model' => $pModel,
        ];
    }

    $fKey   = defined('AI_FALLBACK_API_KEY') ? trim((string) AI_FALLBACK_API_KEY) : '';
    $fBase  = defined('AI_FALLBACK_BASE_URL') ? _ai_normalize_base_url((string) AI_FALLBACK_BASE_URL) : '';
    $fModel = $purpose === 'chat'
        ? (defined('AI_FALLBACK_CHAT_MODEL') && trim((string) AI_FALLBACK_CHAT_MODEL) !== ''
            ? trim((string) AI_FALLBACK_CHAT_MODEL)
            : (defined('AI_FALLBACK_MODEL') ? trim((string) AI_FALLBACK_MODEL) : ''))
        : (defined('AI_FALLBACK_MODEL') ? trim((string) AI_FALLBACK_MODEL) : '');
    if ($fKey !== '' && $fBase !== '' && $fModel !== '') {
        $isSameAsPrimary = !empty($providers)
            && $providers[0]['base'] === $fBase
            && $providers[0]['key'] === $fKey
            && $providers[0]['model'] === $fModel;
        if (!$isSameAsPrimary) {
            $providers[] = [
                'name'  => 'fallback',
                'base'  => $fBase,
                'key'   => $fKey,
                'model' => $fModel,
            ];
        }
    }

    return $providers;
}

/** Cek apakah minimal 1 provider AI (utama atau cadangan DeepSeek) terkonfigurasi lengkap. */
function ai_has_configured_provider(string $purpose = 'chat'): bool
{
    return !empty(_ai_provider_configs($purpose));
}

/**
 * Kirim request chat/completions ke 1 endpoint OpenAI-compatible dan
 * kembalikan isi `choices[0].message.content` (termasuk reassembly SSE bila server mengirim chunk).
 *
 * @param array<string,mixed> $body
 * @param array{name?:string,base?:string,key?:string,model?:string}|null $provider
 * @return array{ok:bool,content?:string,code?:string,error?:string}
 */
function _ai_post_chat_completions(array $body, int $timeout = 90, int $connectTimeout = 15, ?array $provider = null): array
{
    if ($provider !== null) {
        $key      = trim((string) ($provider['key'] ?? ''));
        $base     = _ai_normalize_base_url((string) ($provider['base'] ?? ''));
        $model    = trim((string) ($provider['model'] ?? ''));
        $provTag  = (string) ($provider['name'] ?? 'provider');
    } else {
        $key      = defined('AI_API_KEY') ? trim((string) AI_API_KEY) : '';
        $base     = defined('AI_BASE_URL') ? _ai_normalize_base_url((string) AI_BASE_URL) : '';
        $model    = defined('AI_MODEL') ? trim((string) AI_MODEL) : '';
        $provTag  = 'primary';
    }
    if ($key === '' || $base === '' || $model === '') {
        return [
            'ok'    => false,
            'code'  => 'NOT_CONFIGURED',
            'error' => 'AI belum dikonfigurasi (AI_BASE_URL / AI_API_KEY / AI_MODEL).',
        ];
    }

    if ($provider !== null || empty($body['model'])) {
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
            error_log("ai_chat [{$provTag}]: curl error: " . $err);
            return ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($http !== 200) {
            error_log("ai_chat [{$provTag}]: HTTP " . $http . ' body: ' . substr((string) $raw, 0, 500));
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
            error_log("ai_chat [{$provTag}]: stream gagal menghubungi " . $base);
            return ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
        }
        $http = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $http = (int) $m[1];
            }
        }
        if ($http !== 200) {
            error_log("ai_chat [{$provTag}]: HTTP " . $http . ' body: ' . substr((string) $raw, 0, 500));
            return ['ok' => false, 'code' => 'HTTP_' . $http, 'error' => 'Server AI balas HTTP ' . $http . '.'];
        }
    }

    $rawStr = (string) $raw;
    // Endpoint OpenAI-compatible kadang mengirim SSE chunk walaupun stream false/tidak diset.
    if (str_starts_with(ltrim($rawStr), 'data:')) {
        $assembled  = '';
        $parsedAny  = false;
        foreach (preg_split('/\r?\n/', $rawStr) as $sseLine) {
            $sseLine = trim($sseLine);
            if ($sseLine === '' || !str_starts_with($sseLine, 'data:')) {
                continue;
            }
            $dataPayload = trim(substr($sseLine, 5));
            if ($dataPayload === '' || $dataPayload === '[DONE]') {
                continue;
            }
            $chunk = json_decode($dataPayload, true);
            if (is_array($chunk)) {
                $parsedAny = true;
                $part = $chunk['choices'][0]['delta']['content'] ?? $chunk['choices'][0]['message']['content'] ?? '';
                if (is_string($part) && $part !== '') {
                    $assembled .= $part;
                }
            }
        }
        if ($parsedAny && trim($assembled) !== '') {
            return ['ok' => true, 'content' => trim($assembled)];
        }
    }

    $i = strpos($rawStr, 'data: [DONE]');
    if ($i !== false) {
        $rawStr = substr($rawStr, 0, $i);
    }

    $json = json_decode($rawStr, true);
    if (!is_array($json)) {
        error_log("ai_chat [{$provTag}]: respons bukan JSON: " . substr($rawStr, 0, 300));
        return ['ok' => false, 'code' => 'BAD_RESPONSE', 'error' => 'Respons server AI tidak valid.'];
    }
    if (!isset($json['choices'][0]['message']['content'])) {
        error_log("ai_chat [{$provTag}]: struktur respons tak terduga: " . substr((string) json_encode($json), 0, 300));
        return ['ok' => false, 'code' => 'BAD_RESPONSE', 'error' => 'Respons server AI tidak lengkap.'];
    }

    return ['ok' => true, 'content' => trim((string) $json['choices'][0]['message']['content'])];
}

/**
 * Bersihkan blok <think>...</think> (termasuk tag <think> menggantung)
 * yang sering dikeluarkan oleh model reasoning.
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
 * Panggil model (utama 9router -> cadangan DeepSeek), paksa JSON mode, kembalikan array.
 * @param array<int,array{role:string,content:string}> $messages
 * @param array<string,mixed> $extra
 * @return array{ok:bool,data?:array,error?:string}
 */
function ai_chat_json(array $messages, array $extra = [], int $timeout = 90, int $connectTimeout = 15): array
{
    $providers = _ai_provider_configs('json');
    if (!$providers) {
        return ['ok' => false, 'error' => 'AI belum dikonfigurasi (AI_BASE_URL / AI_API_KEY / AI_MODEL).'];
    }

    $lastErr = 'Gagal memanggil AI.';
    $total   = count($providers);
    foreach ($providers as $idx => $prov) {
        $isLast   = ($idx === $total - 1);
        $curConn  = $isLast ? $connectTimeout : min($connectTimeout, 5);
        $curTime  = $isLast ? $timeout : min($timeout, 12);
        $body = array_merge([
            'model'           => $prov['model'],
            'messages'        => $messages,
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
        ], $extra, ['model' => $prov['model']]);

        $res = _ai_post_chat_completions($body, $curTime, $curConn, $prov);
        if (!$res['ok']) {
            $lastErr = $res['error'] ?? $lastErr;
            if (!$isLast) {
                error_log("ai_chat_json: failover dari {$prov['name']} ({$lastErr}) ke provider cadangan.");
            }
            continue;
        }

        $content = ai_strip_reasoning((string) $res['content']);
        if (str_starts_with($content, '```')) {
            $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content));
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            error_log("ai_chat_json [{$prov['name']}]: konten model bukan JSON: " . substr($content, 0, 300));
            $lastErr = 'Model tidak membalas JSON valid.';
            continue;
        }
        return ['ok' => true, 'data' => $data];
    }

    return ['ok' => false, 'error' => $lastErr];
}

/**
 * Ekstrak marker opsional `[[ACTIONS:url1,url2]]` di ekor jawaban Markdown asisten.
 * @return array{reply:string,actions:array<int,array{label:string,url:string}>}
 */
function ai_extract_action_marker(string $text): array
{
    $actions = [];
    $clean = preg_replace_callback('/\[\[\s*ACTIONS\s*:\s*([^\]]*)\]\]/i', static function (array $m) use (&$actions): string {
        foreach (explode(',', (string) $m[1]) as $rawUrl) {
            $u = trim($rawUrl, " \t\n\r\0\x0B`'\"");
            if ($u !== '') {
                $actions[] = ['label' => '', 'url' => $u];
            }
        }
        return '';
    }, $text);

    if (!is_string($clean)) {
        $clean = $text;
    }
    return [
        'reply'   => trim($clean),
        'actions' => $actions,
    ];
}

/** Cek apakah potongan teks mengandung penanda internal yang tidak boleh tampil saat streaming. */
function _ai_contains_internal_marker(string $text): bool
{
    foreach ([
        'SNAPSHOT ERP', 'AKSI DIIZINKAN', '9router', 'deepseek',
        'VSUDO_AI_API_KEY', 'VSUDO_AI_FALLBACK_API_KEY', 'DEEPSEEK_API_KEY',
        'AI_API_KEY', 'AI_BASE_URL', 'AI_FALLBACK_API_KEY', 'AI_FALLBACK_BASE_URL',
    ] as $marker) {
        if (stripos($text, $marker) !== false) {
            return true;
        }
    }
    foreach (['AI_MODEL', 'AI_CHAT_MODEL', 'AI_FALLBACK_MODEL', 'AI_FALLBACK_CHAT_MODEL'] as $constName) {
        if (defined($constName) && strlen((string) constant($constName)) >= 4 && stripos($text, (string) constant($constName)) !== false) {
            return true;
        }
    }
    return false;
}

/** Parse respons teks mentah asisten (strip <think>, ekstrak JSON opsional atau [[ACTIONS:...]]). */
function _ai_parse_assistant_reply(string $rawContent, string $model): array
{
    $cleaned = ai_strip_reasoning($rawContent);
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
                if (is_array($act) && !empty($act['url'])) {
                    $lbl = trim((string) ($act['label'] ?? ''));
                    $url = trim((string) $act['url']);
                    $actions[] = ['label' => $lbl, 'url' => $url];
                }
            }
        }
    } else {
        $ext     = ai_extract_action_marker($cleaned);
        $reply   = $ext['reply'];
        $actions = $ext['actions'];
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
 * Panggil AI Assistant multi-turn (utama 9router -> cadangan DeepSeek bila gagal/timeout).
 *
 * @param array<int,array{role:string,content:string}> $messages
 * @param array<string,mixed> $extra
 * @return array{ok:bool,reply?:string,actions?:array<int,array{label:string,url:string}>,model?:string,code?:string,error?:string}
 */
function ai_chat_assistant(array $messages, array $extra = []): array
{
    $providers = _ai_provider_configs('chat');
    if (!$providers) {
        return [
            'ok'    => false,
            'code'  => 'NOT_CONFIGURED',
            'error' => 'AI belum dikonfigurasi (AI_BASE_URL / AI_API_KEY / AI_MODEL).',
        ];
    }

    $lastErr = ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
    $total   = count($providers);

    foreach ($providers as $idx => $prov) {
        $isLast  = ($idx === $total - 1);
        $connSec = $isLast ? 10 : 5;
        $readSec = $isLast ? 45 : 25;

        $body = array_merge([
            'model'       => $prov['model'],
            'messages'    => $messages,
            'temperature' => 0.2,
        ], $extra, ['model' => $prov['model']]);

        $res = _ai_post_chat_completions($body, $readSec, $connSec, $prov);
        if (!$res['ok']) {
            $lastErr = $res;
            if (!$isLast) {
                error_log("ai_chat_assistant: failover dari {$prov['name']} ({$res['code']}) ke provider cadangan.");
            }
            continue;
        }

        $parsedRes = _ai_parse_assistant_reply((string) $res['content'], $prov['model']);
        if ($parsedRes['ok']) {
            return $parsedRes;
        }
        $lastErr = $parsedRes;
        if (!$isLast) {
            error_log("ai_chat_assistant: failover dari {$prov['name']} ({$parsedRes['code']}) ke provider cadangan.");
        }
    }

    return $lastErr;
}

/**
 * Eksekusi SSE stream terhadap 1 provider; mengembalikan flag `emitted` agar caller
 * tahu aman melakukan failover atau sudah ada token terkirim ke client.
 *
 * @param array{name:string,base:string,key:string,model:string} $provider
 * @param array<int,array{role:string,content:string}> $messages
 * @param callable(string):void $onDelta
 * @param array<string,mixed> $extra
 * @return array{ok:bool,emitted?:bool,reply?:string,actions?:array<int,array{label:string,url:string}>,model?:string,code?:string,error?:string}
 */
function _ai_stream_single_provider(
    array $provider,
    array $messages,
    callable $onDelta,
    array $extra = [],
    int $timeout = 60,
    int $connectTimeout = 10
): array {
    $key     = (string) ($provider['key'] ?? '');
    $base    = _ai_normalize_base_url((string) ($provider['base'] ?? ''));
    $model   = (string) ($provider['model'] ?? '');
    $provTag = (string) ($provider['name'] ?? 'provider');

    if ($key === '' || $base === '' || $model === '') {
        return ['ok' => false, 'emitted' => false, 'code' => 'NOT_CONFIGURED', 'error' => 'AI belum dikonfigurasi.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'emitted' => false, 'code' => 'NO_CURL', 'error' => 'Ekstensi curl tidak tersedia.'];
    }

    $body = array_merge([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.2,
        'stream'      => true,
    ], $extra, ['model' => $model, 'stream' => true]);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $httpCode       = 0;
    $sseBuf         = '';
    $errBody        = '';
    $fullRaw        = '';
    $pending        = '';
    $inThink        = false;
    $inActions      = false;
    $emittedAny     = false;
    $suppressStream = false;

    $emitSafe = static function (string $chunk) use (&$emittedAny, &$suppressStream, $onDelta): void {
        if ($chunk === '' || $suppressStream) {
            return;
        }
        if (_ai_contains_internal_marker($chunk)) {
            $suppressStream = true;
            return;
        }
        $emittedAny = true;
        $onDelta($chunk);
    };

    $processToken = static function (string $tok) use (&$pending, &$inThink, &$inActions, &$emittedAny, &$suppressStream, $emitSafe): void {
        if ($tok === '' || $inActions) {
            return;
        }
        $pending .= $tok;

        while ($pending !== '') {
            if ($inThink) {
                $endPos = stripos($pending, '</think>');
                if ($endPos !== false) {
                    $pending = ltrim((string) substr($pending, $endPos + 8));
                    $inThink = false;
                    continue;
                }
                if (strlen($pending) > 12) {
                    $pending = substr($pending, -12);
                }
                break;
            }

            if (!$emittedAny && str_starts_with(ltrim($pending), '{')) {
                $suppressStream = true;
                break;
            }

            $thinkPos = stripos($pending, '<think');
            $actPos   = stripos($pending, '[[actions');
            $cutPos   = false;
            $hitType  = '';
            if ($thinkPos !== false && ($actPos === false || $thinkPos < $actPos)) {
                $cutPos  = $thinkPos;
                $hitType = 'think';
            } elseif ($actPos !== false) {
                $cutPos  = $actPos;
                $hitType = 'actions';
            }

            if ($cutPos !== false) {
                if ($cutPos > 0) {
                    $emitSafe(substr($pending, 0, $cutPos));
                }
                if ($hitType === 'think') {
                    $gt = strpos($pending, '>', $cutPos);
                    if ($gt === false) {
                        $pending = substr($pending, $cutPos);
                        break;
                    }
                    $pending = substr($pending, $gt + 1);
                    $inThink = true;
                    continue;
                }
                $inActions = true;
                $pending   = '';
                break;
            }

            $len = mb_strlen($pending, 'UTF-8');
            if ($len <= 20) {
                break;
            }
            $safe    = mb_substr($pending, 0, $len - 20, 'UTF-8');
            $pending = mb_substr($pending, $len - 20, null, 'UTF-8');
            $emitSafe($safe);
            break;
        }
    };

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $hdr) use (&$httpCode): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $httpCode = (int) $m[1];
            }
            return strlen($hdr);
        },
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$httpCode, &$sseBuf, &$errBody, &$fullRaw, $processToken): int {
            $len = strlen($data);
            if ($httpCode >= 400) {
                $errBody .= $data;
                return $len;
            }
            $sseBuf .= $data;
            while (($nl = strpos($sseBuf, "\n")) !== false) {
                $line   = trim(substr($sseBuf, 0, $nl));
                $sseBuf = substr($sseBuf, $nl + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '' || $jsonStr === '[DONE]') {
                    continue;
                }
                $chunk = json_decode($jsonStr, true);
                if (!is_array($chunk)) {
                    continue;
                }
                $delta = $chunk['choices'][0]['delta']['content'] ?? ($chunk['choices'][0]['message']['content'] ?? null);
                if (is_string($delta) && $delta !== '') {
                    $fullRaw .= $delta;
                    $processToken($delta);
                }
            }
            return $len;
        },
    ]);

    $ok        = curl_exec($ch);
    $curlErr   = curl_error($ch);
    $finalCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($finalCode > 0) {
        $httpCode = $finalCode;
    }

    if ($ok === false && $fullRaw === '') {
        error_log("ai_chat_stream [{$provTag}]: curl error: " . $curlErr);
        return ['ok' => false, 'emitted' => $emittedAny, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
    }
    if ($httpCode !== 200) {
        error_log("ai_chat_stream [{$provTag}]: HTTP " . $httpCode . ' body: ' . substr($errBody, 0, 300));
        return ['ok' => false, 'emitted' => $emittedAny, 'code' => 'HTTP_' . $httpCode, 'error' => 'Server AI balas HTTP ' . $httpCode . '.'];
    }

    if (trim($sseBuf) !== '' && str_starts_with(trim($sseBuf), 'data:')) {
        $jsonStr = trim(substr(trim($sseBuf), 5));
        if ($jsonStr !== '' && $jsonStr !== '[DONE]') {
            $chunk = json_decode($jsonStr, true);
            $delta = is_array($chunk) ? ($chunk['choices'][0]['delta']['content'] ?? ($chunk['choices'][0]['message']['content'] ?? null)) : null;
            if (is_string($delta) && $delta !== '') {
                $fullRaw .= $delta;
                $processToken($delta);
            }
        }
    }

    if (!$inThink && !$inActions && $pending !== '') {
        $emitSafe($pending);
        $pending = '';
    }

    $parsed = _ai_parse_assistant_reply($fullRaw, $model);
    $parsed['emitted'] = $emittedAny;
    return $parsed;
}

/**
 * Streaming SSE dari endpoint OpenAI-compatible (utama 9router -> cadangan DeepSeek).
 * Menyaring <think>...</think>, [[ACTIONS:...]], serta penanda sensitif secara inkremental
 * sebelum memanggil $onDelta(string $chunk).
 *
 * @param array<int,array{role:string,content:string}> $messages
 * @param callable(string):void $onDelta
 * @param array<string,mixed> $extra
 * @return array{ok:bool,reply?:string,actions?:array<int,array{label:string,url:string}>,model?:string,code?:string,error?:string}
 */
function ai_chat_assistant_stream(array $messages, callable $onDelta, array $extra = []): array
{
    $providers = _ai_provider_configs('chat');
    if (!$providers) {
        return ['ok' => false, 'code' => 'NOT_CONFIGURED', 'error' => 'AI belum dikonfigurasi.'];
    }

    $lastErr = ['ok' => false, 'code' => 'UNREACHABLE', 'error' => 'Tidak dapat menghubungi server AI.'];
    $total   = count($providers);

    foreach ($providers as $idx => $prov) {
        $isLast  = ($idx === $total - 1);
        $connSec = $isLast ? 10 : 5;
        $readSec = $isLast ? 60 : 35;

        $res = _ai_stream_single_provider($prov, $messages, $onDelta, $extra, $readSec, $connSec);
        if (!empty($res['ok'])) {
            unset($res['emitted']);
            return $res;
        }
        $lastErr = $res;
        if (!empty($res['emitted']) || $isLast) {
            break;
        }
        error_log("ai_chat_assistant_stream: failover dari {$prov['name']} ({$res['code']}) ke provider cadangan.");
    }

    unset($lastErr['emitted']);
    return $lastErr;
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