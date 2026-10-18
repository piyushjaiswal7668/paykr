<?php

declare(strict_types=1);

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException as DomainError;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('GET /health returns UP', function () {
    foreach (['/health', '/api/v1/health'] as $path) {
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'UP')->assertJsonStructure(['clock' => ['status', 'healthy', 'heartbeat', 'recovery']])->assertHeader('X-Request-Id');
    }
});

it('renders all API failures through the appropriate error contract', function (string $prefix, bool $legacy) {
    Route::post($prefix.'/validate', fn (Request $request) => $request->validate(['amount' => ['required', 'integer', 'min:1']]));
    Route::get($prefix.'/closed', fn () => throw new DomainError(ErrorCode::PAYMENT_CLOSED, ['status' => 'CANCELLED'], ['action' => 'END_SESSION_AND_CREATE_NEW']));
    Route::get($prefix.'/unexpected', fn () => throw new RuntimeException('SECRET SQL password'));
    foreach (['missing' => [404, 'NOT_FOUND'], 'closed' => [409, 'PAYMENT_CLOSED'], 'unexpected' => [500, 'INTERNAL_ERROR'], 'validate' => [422, 'VALIDATION_FAILED']] as $endpoint => [$status, $code]) {
        $response = $endpoint === 'validate' ? $this->postJson($prefix.'/'.$endpoint, []) : $this->getJson($prefix.'/'.$endpoint);
        $response->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath($legacy ? 'code' : 'error.code', $code)->assertHeader('X-Request-Id');
        expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
        expect($response->getContent())->not->toContain('SECRET SQL');
        expect($response->json('error'))->when($legacy, fn ($e) => $e->toBeString());
        if ($endpoint === 'validate') {
            $response->assertJsonStructure($legacy ? ['fields' => ['amount']] : ['error' => ['fields' => ['amount']]]);
        }
        if ($endpoint === 'closed' && $legacy) {
            $response->assertJsonPath('action', 'END_SESSION_AND_CREATE_NEW');
        }
    }
})->with([['/api/v1/test', true], ['/api/v1/merchant/test', false], ['/internal/test', false]]);

it('preserves safe request IDs and replaces unsafe ones', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'request-123'])->assertHeader('X-Request-Id', 'request-123');
    $response = $this->getJson('/api/v1/missing', ['X-Request-Id' => str_repeat('x', 200)]);
    expect($response->json('request_id'))->toHaveLength(26);
});

it('renders authentication and throttling errors without leaking details', function () {
    Route::get('/api/v1/auth-error', fn () => throw new AuthenticationException);
    Route::get('/internal/forbidden', fn () => throw new AuthorizationException);
    Route::get('/api/v1/throttled', fn () => abort(429, 'private', ['Retry-After' => '60']));
    $this->getJson('/api/v1/auth-error')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    $this->getJson('/internal/forbidden')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->getJson('/api/v1/throttled')->assertStatus(429)->assertHeader('Retry-After', '60');
    $this->get('/missing')->assertNotFound()->assertHeader('X-Request-Id');
});

it('wraps new API success responses', function () {
    Route::get('/internal/success', fn () => ApiResponse::success(['id' => 'one']));
    $this->getJson('/internal/success')->assertExactJson(['success' => true, 'data' => ['id' => 'one'], 'meta' => []]);
});
