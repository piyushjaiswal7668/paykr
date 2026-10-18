<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

final class TenantContext
{
    private ?string $merchantId = null;

    private bool $unscoped = false;

    /** Retain the authenticated panel tenant for the remainder of this scoped request. */
    public function establish(string $merchantId): void
    {
        $this->merchantId = $merchantId;
        $this->unscoped = false;
    }

    public function merchantId(): ?string
    {
        return $this->merchantId;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /** @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $merchantId, callable $callback): mixed
    {
        return $this->within($merchantId, false, $callback);
    }

    /** Explicit administrative/maintenance boundary. Never infer bypass from an authenticated user.
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runUnscoped(callable $callback): mixed
    {
        return $this->within(null, true, $callback);
    }

    /** @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(?string $merchantId, bool $unscoped, callable $callback): mixed
    {
        $previous = [$this->merchantId, $this->unscoped];
        [$this->merchantId, $this->unscoped] = [$merchantId, $unscoped];
        try {
            return $callback();
        } finally {
            [$this->merchantId, $this->unscoped] = $previous;
        }
    }
}
