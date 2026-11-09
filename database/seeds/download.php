<?php
declare(strict_types=1);

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$files = [
    'wilayah.sql' => 'https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql',
    'wilayah_kodepos.sql' => 'https://raw.githubusercontent.com/cahyadsn/wilayah_kodepos/master/db/wilayah_kodepos.sql',
];

$ctx = stream_context_create([
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        'timeout' => 120,
    ],
]);

foreach ($files as $name => $url) {
    $target = $dir . '/' . $name;
    if (file_exists($target) && filesize($target) > 100000) {
        echo "Already exists: $name (" . round(filesize($target) / 1024 / 1024, 2) . " MB)\n";
        continue;
    }
    echo "Downloading $name from $url ...\n";
    $in = fopen($url, 'r', false, $ctx);
    if (!$in) {
        die("Failed to open $url\n");
    }
    $out = fopen($target, 'w');
    $bytes = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    echo "Downloaded $name: " . round($bytes / 1024 / 1024, 2) . " MB\n";
}
echo "Download complete.\n";

