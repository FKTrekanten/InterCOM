<?php
$failed = false;
foreach (['src', 'tests'] as $folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder)) as $file) {
        if ($file->getExtension() !== 'php') continue;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $output, $code);
        if ($code) { echo implode("\n", $output); $failed = true; }
        $output = [];
    }
}
foreach (['site', 'administrator'] as $client) {
    $base = "src/component/$client/language";
    $en = parse_ini_file("$base/en-GB/com_intercom.ini");
    $da = parse_ini_file("$base/da-DK/com_intercom.ini");
    if (!$en || !$da || array_keys($en) !== array_keys($da)) { fwrite(STDERR, "Language key mismatch\n"); $failed = true; }
}
exit($failed ? 1 : 0);
