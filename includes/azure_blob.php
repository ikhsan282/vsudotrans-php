<?php
/**
 * Azure Blob Storage Client (Native PHP 8 + cURL + HMAC-SHA256 SharedKey & Service SAS)
 *
 * Struktur Container & Folder (Opsi A):
 *  - Container tunggal: vsudotrans (Private)
 *  - Struktur Path    : {company_code}/{module}/{sub_path}/{filename}.{ext}
 *    Contoh:
 *      mtm/company/logo_1731200000.png
 *      mtm/finance/qris/qris_bca_1731200000.png
 *      mtm/vehicles/B-1234-TNG/photo_1731200000.jpg
 *      mtm/vehicles/B-1234-TNG/stnk_1731200000.pdf
 *      mtm/employees/EMP-001/photo_1731200000.jpg
 *      mtm/employees/EMP-001/ktp_1731200000.jpg
 *      global/masters/...
 */

define('AZURE_BLOB_API_VERSION', '2020-10-02');

/**
 * Parse BLOB_STORAGE_CONNECTION_STRING menjadi array konfigurasi.
 */
function azure_blob_config(): ?array
{
    static $parsed = false;
    if ($parsed !== false) {
        return $parsed;
    }

    $conn = defined('BLOB_STORAGE_CONNECTION_STRING')
        ? (string) BLOB_STORAGE_CONNECTION_STRING
        : (string) (getenv('BLOB_STORAGE_CONNECTION_STRING') ?: '');
    $conn = trim($conn);
    if ($conn === '') {
        $parsed = null;
        return null;
    }

    $parts = [];
    foreach (explode(';', $conn) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || !str_contains($seg, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $seg, 2);
        $parts[trim($k)] = trim($v);
    }

    $account_name = $parts['AccountName'] ?? '';
    $account_key  = $parts['AccountKey'] ?? '';
    $protocol     = $parts['DefaultEndpointsProtocol'] ?? 'https';
    $suffix       = $parts['EndpointSuffix'] ?? 'core.windows.net';
    $container    = defined('BLOB_STORAGE_CONTAINER')
        ? strtolower(trim((string) BLOB_STORAGE_CONTAINER))
        : 'vsudotrans';

    if ($account_name === '' || $account_key === '') {
        $parsed = null;
        return null;
    }

    $parsed = [
        'protocol'     => $protocol,
        'account_name' => $account_name,
        'account_key'  => $account_key,
        'suffix'       => $suffix,
        'container'    => $container,
        'base_url'     => "{$protocol}://{$account_name}.blob.{$suffix}",
    ];
    return $parsed;
}

function azure_blob_is_enabled(): bool
{
    return azure_blob_config() !== null;
}

/**
 * Encode setiap segmen path blob (mempertahankan '/' sebagai pemisah virtual folder).
 */
function azure_blob_encode_path(string $blob_path): string
{
    $blob_path = ltrim(str_replace('\\', '/', trim($blob_path)), '/');
    $segments = explode('/', $blob_path);
    return implode('/', array_map('rawurlencode', $segments));
}

/**
 * Ambil kode perusahaan huruf kecil (mis. 'mtm', 'ntm', 'gtm', 'ttm', 'liz')
 * dari ID perusahaan; bila null (lintas perusahaan), kembalikan 'global'.
 */
function azure_blob_company_prefix(?int $company_id = null): string
{
    static $cache = [];
    $cid = $company_id ?? (function_exists('active_company_id') ? active_company_id() : null);
    if ($cid === null || $cid <= 0) {
        return 'global';
    }
    if (isset($cache[$cid])) {
        return $cache[$cid];
    }
    if (function_exists('db')) {
        $st = db()->prepare('SELECT code FROM companies WHERE id = ? LIMIT 1');
        $st->bind_param('i', $cid);
        $st->execute();
        $code = $st->get_result()->fetch_column();
        $st->close();
        if ($code) {
            $clean = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', (string) $code));
            if ($clean !== '') {
                return $cache[$cid] = $clean;
            }
        }
    }
    return $cache[$cid] = 'comp-' . $cid;
}

/**
 * Susun path blob standar: {company_code}/{module}/{sub_folder}/{slug}_{timestamp}_{rand}.{ext}
 */
function azure_blob_build_path(?int $company_id, string $module, string $sub_folder, string $slug, string $ext, ?string $company_code = null): string
{
    // $company_code dipakai bila perusahaan belum punya ID (mis. saat membuat perusahaan baru).
    $code_clean = $company_code !== null ? strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $company_code)) : '';
    $prefix = $code_clean !== '' ? $code_clean : azure_blob_company_prefix($company_id);
    $module = strtolower(preg_replace('/[^a-zA-Z0-9\-_\/]/', '', trim($module, '/')));
    $sub    = trim(preg_replace('/[^a-zA-Z0-9\-_\/]/', '-', trim($sub_folder, '/')), '-/');
    $slug   = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $slug), '-')) ?: 'file';
    $ext    = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext)) ?: 'bin';
    $rand   = substr(bin2hex(random_bytes(4)), 0, 6);
    $fname  = $slug . '_' . date('YmdHis') . '_' . $rand . '.' . $ext;

    $parts = array_filter([$prefix, $module, $sub, $fname], static fn ($p) => $p !== '');
    return implode('/', $parts);
}

/**
 * Buat Authorization header SharedKey untuk Azure Storage REST API (versi 2020-10-02).
 */
function azure_blob_sign_request(
    array $cfg,
    string $method,
    string $resource_path,
    array $query_params,
    array $headers,
    int $content_length = 0,
    string $content_type = ''
): string {
    $x_ms = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower(trim($k));
        if (str_starts_with($lk, 'x-ms-')) {
            $x_ms[$lk] = preg_replace('/\s+/', ' ', trim((string) $v));
        }
    }
    ksort($x_ms);
    $canonical_headers = '';
    foreach ($x_ms as $k => $v) {
        $canonical_headers .= "{$k}:{$v}\n";
    }

    $canonical_resource = '/' . $cfg['account_name'] . '/' . ltrim($resource_path, '/');
    if ($query_params) {
        $qp = [];
        foreach ($query_params as $k => $v) {
            $qp[strtolower(trim($k))] = (string) $v;
        }
        ksort($qp);
        foreach ($qp as $k => $v) {
            $canonical_resource .= "\n{$k}:{$v}";
        }
    }

    $len_str = $content_length > 0 ? (string) $content_length : '';

    $string_to_sign = implode("\n", [
        strtoupper($method),
        '', // Content-Encoding
        '', // Content-Language
        $len_str,
        '', // Content-MD5
        $content_type,
        '', // Date (kita gunakan x-ms-date)
        '', // If-Modified-Since
        '', // If-Match
        '', // If-None-Match
        '', // If-Unmodified-Since
        '', // Range
    ]) . "\n" . $canonical_headers . $canonical_resource;

    $key_bin = base64_decode($cfg['account_key'], true);
    $sig = base64_encode(hash_hmac('sha256', $string_to_sign, $key_bin, true));
    return 'SharedKey ' . $cfg['account_name'] . ':' . $sig;
}

/**
 * Eksekusi HTTP request ke Azure Blob REST API.
 * Mengembalikan ['status' => int, 'body' => string, 'headers' => array, 'error' => string]
 */
function azure_blob_request(
    string $method,
    string $resource_path,
    array $query_params = [],
    string $body = '',
    string $content_type = '',
    array $extra_headers = []
): array {
    $cfg = azure_blob_config();
    if (!$cfg) {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Azure Blob belum dikonfigurasi.'];
    }

    $gmdate = gmdate('D, d M Y H:i:s') . ' GMT';
    $headers = array_merge([
        'x-ms-date'    => $gmdate,
        'x-ms-version' => AZURE_BLOB_API_VERSION,
    ], $extra_headers);

    $content_len = strlen($body);
    $auth = azure_blob_sign_request($cfg, $method, $resource_path, $query_params, $headers, $content_len, $content_type);

    $http_headers = [
        'Authorization: ' . $auth,
        'Content-Length: ' . $content_len,
    ];
    if ($content_type !== '') {
        $http_headers[] = 'Content-Type: ' . $content_type;
    }
    foreach ($headers as $k => $v) {
        $http_headers[] = "{$k}: {$v}";
    }

    $url = $cfg['base_url'] . '/' . ltrim($resource_path, '/');
    if ($query_params) {
        $url .= '?' . http_build_query($query_params);
    }

    $resp_headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $http_headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resp_headers): int {
            $len = strlen($line);
            if (str_contains($line, ':')) {
                [$hk, $hv] = explode(':', $line, 2);
                $resp_headers[strtolower(trim($hk))] = trim($hv);
            }
            return $len;
        },
    ]);
    if ($content_len > 0) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp_body = curl_exec($ch);
    $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => $resp_body === false ? '' : (string) $resp_body,
        'headers' => $resp_headers,
        'error'   => $err,
    ];
}

/**
 * Pastikan container utama (`vsudotrans`) sudah ada di Azure Storage.
 * Otomatis membuat container private bila belum ada (sekali per sesi/proses).
 */
function azure_blob_ensure_container(): bool
{
    static $ensured = false;
    if ($ensured) {
        return true;
    }
    if (!empty($_SESSION['_azure_container_ready'])) {
        return $ensured = true;
    }
    $cfg = azure_blob_config();
    if (!$cfg) {
        return false;
    }

    $res = azure_blob_request('PUT', $cfg['container'], ['restype' => 'container']);
    // 201 Created atau 409 ContainerAlreadyExists berarti container siap dipakai
    if (in_array($res['status'], [201, 409], true)) {
        $ensured = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_azure_container_ready'] = 1;
        }
        return true;
    }
    return false;
}

/**
 * Unggah data biner ke Azure Blob Storage. false bila Azure belum dikonfigurasi / gagal.
 */
function azure_blob_put(string $blob_path, string $binary_data, string $content_type = 'application/octet-stream'): bool
{
    $blob_path = ltrim(str_replace('\\', '/', trim($blob_path)), '/');
    $cfg = azure_blob_config();
    if (!$cfg || $blob_path === '') {
        return false;
    }

    azure_blob_ensure_container();
    $encoded = azure_blob_encode_path($blob_path);
    $res = azure_blob_request(
        'PUT',
        $cfg['container'] . '/' . $encoded,
        [],
        $binary_data,
        $content_type,
        ['x-ms-blob-type' => 'BlockBlob']
    );
    return $res['status'] === 201;
}

/**
 * Unduh isi blob dari Azure Storage.
 * Mengembalikan ['data' => string, 'mime' => string] atau null bila tidak ditemukan.
 */
function azure_blob_get(string $blob_path): ?array
{
    $blob_path = ltrim(str_replace('\\', '/', trim($blob_path)), '/');
    $cfg = azure_blob_config();
    if (!$cfg || $blob_path === '') {
        return null;
    }

    $encoded = azure_blob_encode_path($blob_path);
    $res = azure_blob_request('GET', $cfg['container'] . '/' . $encoded);
    if ($res['status'] !== 200) {
        return null;
    }
    return [
        'data' => $res['body'],
        'mime' => $res['headers']['content-type'] ?? 'application/octet-stream',
    ];
}

/**
 * Hapus blob dari Azure Storage (404 dianggap sudah terhapus).
 */
function azure_blob_delete(?string $blob_path): bool
{
    $blob_path = ltrim(str_replace('\\', '/', trim((string) $blob_path)), '/');
    $cfg = azure_blob_config();
    if (!$cfg || $blob_path === '') {
        return false;
    }

    $encoded = azure_blob_encode_path($blob_path);
    $res = azure_blob_request('DELETE', $cfg['container'] . '/' . $encoded);
    return in_array($res['status'], [202, 404], true);
}

/**
 * Hasilkan URL akses langsung dengan Service SAS Token (Read-Only, berlaku $ttl_minutes menit)
 * untuk membaca gambar/dokumen di container private `vsudotrans`.
 */
function azure_blob_url(?string $blob_path, int $ttl_minutes = 120): string
{
    $blob_path = ltrim(str_replace('\\', '/', trim((string) $blob_path)), '/');
    if ($blob_path === '') {
        return '';
    }
    if (str_starts_with($blob_path, 'http://') || str_starts_with($blob_path, 'https://')) {
        return $blob_path;
    }

    $cfg = azure_blob_config();
    if (!$cfg) {
        return '';
    }

    $sp  = 'r';
    $st  = gmdate('Y-m-d\TH:i:s\Z', time() - 300); // toleransi clock skew 5 menit
    $se  = gmdate('Y-m-d\TH:i:s\Z', time() + max(60, $ttl_minutes * 60));
    $sv  = AZURE_BLOB_API_VERSION;
    $sr  = 'b';
    $spr = 'https';

    // CanonicalizedResource untuk Service SAS versi 2020-10-02: /blob/{account}/{container}/{blob}
    $canonical_resource = '/blob/' . $cfg['account_name'] . '/' . $cfg['container'] . '/' . $blob_path;

    $string_to_sign = implode("\n", [
        $sp,                  // signedPermissions
        $st,                  // signedStart
        $se,                  // signedExpiry
        $canonical_resource,  // canonicalizedResource
        '',                   // signedIdentifier
        '',                   // signedIP
        $spr,                 // signedProtocol
        $sv,                  // signedVersion
        $sr,                  // signedResource
        '',                   // signedSnapshotTime
        '',                   // rscc
        '',                   // rscd
        '',                   // rsce
        '',                   // rscl
        '',                   // rsct
    ]);

    $key_bin = base64_decode($cfg['account_key'], true);
    $sig = base64_encode(hash_hmac('sha256', $string_to_sign, $key_bin, true));

    $query = http_build_query([
        'sv'  => $sv,
        'st'  => $st,
        'se'  => $se,
        'sr'  => $sr,
        'sp'  => $sp,
        'spr' => $spr,
        'sig' => $sig,
    ], '', '&', PHP_QUERY_RFC3986);

    return $cfg['base_url'] . '/' . $cfg['container'] . '/' . azure_blob_encode_path($blob_path) . '?' . $query;
}

/**
 * URL tampilan untuk path berkas yang tersimpan di database.
 * Semua berkas unggahan disimpan di Azure Blob ('{company_code}/{module}/...'),
 * sehingga cukup diteruskan ke azure_blob_url() (SAS token). URL absolut diteruskan apa adanya.
 */
function resolve_asset_url(?string $path, int $ttl_minutes = 120): string
{
    return azure_blob_url($path, $ttl_minutes);
}

/**
 * Konversi berkas lokal / Azure Blob menjadi Data URI (base64) untuk disematkan ke PDF (Dompdf).
 * Dompdf memblokir request HTTP eksternal (isRemoteEnabled = false) demi keamanan (anti-SSRF),
 * sehingga gambar di PDF wajib berupa Data URI (data:image/png;base64,...).
 */
function resolve_asset_data_uri(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';

    // 1. Cek bila path adalah berkas lokal di web root
    $localFile = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (is_file($localFile) && is_readable($localFile)) {
        $ext = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
        $raw = file_get_contents($localFile);
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    // 2. Cek aset default di folder assets/brand/ (misal fallback logo/stempel MTM)
    if (str_contains($path, 'stamp_mtm') || str_contains($path, 'stamp-mtm')) {
        $brandStamp = dirname(__DIR__) . '/assets/brand/stamp-mtm.png';
        if (is_file($brandStamp)) {
            return 'data:image/png;base64,' . base64_encode(file_get_contents($brandStamp));
        }
    }
    if (str_contains($path, 'logo_mtm') || str_contains($path, 'logo-mtm')) {
        $brandLogo = dirname(__DIR__) . '/assets/brand/logo-mtm.png';
        if (is_file($brandLogo)) {
            return 'data:image/png;base64,' . base64_encode(file_get_contents($brandLogo));
        }
    }

    // 3. Unduh dari Azure Blob Storage jika berupa path blob
    $blob = azure_blob_get($path);
    if ($blob && !empty($blob['data'])) {
        return 'data:' . ($blob['mime'] ?? 'image/png') . ';base64,' . base64_encode($blob['data']);
    }

    // 4. Fallback: coba unduh via SAS URL jika azure_blob_get belum terkonfigurasi
    $url = resolve_asset_url($path);
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        $raw = @file_get_contents($url);
        if ($raw !== false && $raw !== '') {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_buffer($finfo, $raw) ?: 'image/png';
            finfo_close($finfo);
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }
    }

    return '';
}

/**
 * Validasi & unggah file dari $_FILES[$field_name] ke Azure Blob Storage.
 *
 * @param string   $field_name   Nama input file di $_FILES
 * @param ?int     $company_id   ID perusahaan pemilik data (untuk prefix folder {company_code}/...)
 * @param string   $module       Nama modul (mis. 'company', 'finance', 'vehicles', 'employees')
 * @param string   $sub_folder   Sub-folder (mis. 'qris', nomor polisi, kode karyawan, atau '')
 * @param string   $slug         Nama dasar file (mis. 'logo', 'stnk', 'ktp')
 * @param bool     $allow_pdf    Izinkan berkas PDF selain gambar (default false)
 * @param int      $max_mb       Ukuran maksimal dalam MB (default 5 MB)
 * @param ?string  $company_code Kode perusahaan eksplisit untuk prefix (opsional; menimpa lookup dari $company_id)
 * @return ?string Path blob relatif bila ada file baru yang diunggah, atau null bila tidak ada file diunggah.
 * @throws InvalidArgumentException Bila validasi ukuran/tipe file gagal atau unggah gagal.
 */
function azure_blob_handle_upload(
    string $field_name,
    ?int $company_id,
    string $module,
    string $sub_folder = '',
    string $slug = 'file',
    bool $allow_pdf = false,
    int $max_mb = 5,
    ?string $company_code = null
): ?string {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['tmp_name'])) {
        return null;
    }
    $err_code = (int) ($_FILES[$field_name]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err_code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err_code !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Gagal mengunggah berkas ' . $field_name . ' (kode: ' . $err_code . ').');
    }

    $tmp  = (string) $_FILES[$field_name]['tmp_name'];
    $size = (int) ($_FILES[$field_name]['size'] ?? 0);
    if ($size <= 0 || $size > $max_mb * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran berkas ' . $field_name . ' maksimal ' . $max_mb . ' MB.');
    }

    $mime_map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if ($allow_pdf) {
        $mime_map['application/pdf'] = 'pdf';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmp);
    if (!isset($mime_map[$mime])) {
        $allowed_label = $allow_pdf ? 'JPG, PNG, WEBP, atau PDF' : 'gambar (JPG, PNG, WEBP)';
        throw new InvalidArgumentException('Format berkas ' . $field_name . ' harus berupa ' . $allowed_label . '.');
    }

    $data = file_get_contents($tmp);
    if ($data === false) {
        throw new InvalidArgumentException('Gagal membaca berkas unggahan ' . $field_name . '.');
    }

    $ext = $mime_map[$mime];
    $blob_path = azure_blob_build_path($company_id, $module, $sub_folder, $slug, $ext, $company_code);

    if (!azure_blob_put($blob_path, $data, $mime)) {
        throw new InvalidArgumentException('Gagal mengunggah berkas ke Azure Blob Storage.');
    }

    return $blob_path;
}
