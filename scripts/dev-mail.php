<?php
// Local Docker stack only. Capture dev mail instead of attempting external delivery.
$path = '/var/www/html/configuration.php';
require $path;
$config = new JConfig();
if ($config->mailer !== 'mail' && !($config->mailer === 'smtp' && $config->smtphost === 'mailpit')) {
    echo "Existing mail transport preserved.\n";
    exit;
}
$source = file_get_contents($path);
foreach (['mailer' => 'smtp', 'smtphost' => 'mailpit', 'smtpport' => 1025, 'smtpauth' => false, 'smtpsecure' => 'none'] as $key => $value) {
    $source = preg_replace('/public \\$' . $key . '\\s*=\\s*[^;]*;/', 'public $' . $key . ' = ' . var_export($value, true) . ';', $source, 1, $count);
    if ($count !== 1) throw new RuntimeException('Local mail configuration field unavailable');
}
if (file_put_contents($path, $source) === false) throw new RuntimeException('Could not configure local mailbox');
echo "Local mail is captured by Mailpit.\n";
