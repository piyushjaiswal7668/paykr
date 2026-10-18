<?php

return ['paths' => ['api/v1/payments', 'api/v1/payments/*'], 'allowed_methods' => ['GET', 'POST', 'OPTIONS'], 'allowed_origins' => ['*'], 'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Accept', 'X-Merchant-Key', 'X-Api-Key', 'Authorization', 'Idempotency-Key'], 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => false];
