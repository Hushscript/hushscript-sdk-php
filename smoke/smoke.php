<?php

declare(strict_types=1);

// Production smoke on the promotion PR: one authenticated read. HUSHSCRIPT_TOKEN and HUSHSCRIPT_BASE_URL come from the environment.

require __DIR__.'/../vendor/autoload.php';

$account = (new Hushscript\Client())->api()->request('GET', '/v1/account');
if (! isset($account['balance_seconds'])) {
    fwrite(STDERR, "smoke: /v1/account answered without balance_seconds\n");
    exit(1);
}
echo "smoke ok\n";
