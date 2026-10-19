<?php

declare(strict_types=1);

use App\Http\Responses\ApiExceptionRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('maps HTTP errors to safe API codes without leaking exception details', function (int $status, string $code) {
    $response = (new ApiExceptionRenderer)(new HttpException($status, 'private implementation detail'), Request::create('/api/v1/example'));
    expect($response->getData(true)['code'])->toBe($code);
    expect($response->getContent())->not->toContain('private implementation detail');
})->with([[401, 'UNAUTHENTICATED'], [403, 'FORBIDDEN'], [503, 'SERVICE_UNAVAILABLE'], [500, 'INTERNAL_ERROR']]);
