<?php

declare(strict_types=1);

it('rejects web mutations without CSRF even before authentication', function () {
    // Laravel skips CSRF in testing; exercise the real middleware path explicitly.
    $this->app->instance('env', 'local');
    foreach (['/admin/end-impersonation', '/merchant/gmail/test', '/merchant/gmail/disconnect', '/broadcasting/auth'] as $path) {
        $this->postJson($path)->assertStatus(419);
    }
});
