<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope('merchant', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->isUnscoped()) {
                return;
            }
            if ($context->merchantId() === null) {
                $builder->whereRaw('1 = 0');
            } else {
                $builder->where($builder->getModel()->qualifyColumn('merchant_id'), $context->merchantId());
            }
        });
        static::saving(function (Model $model): void {
            $context = app(TenantContext::class);
            if ($model->exists && $model->isDirty('merchant_id')) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            if ($context->isUnscoped()) {
                return;
            }
            if ($context->merchantId() === null || ($model->getAttribute('merchant_id') !== null && $model->getAttribute('merchant_id') !== $context->merchantId())) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
            $model->setAttribute('merchant_id', $context->merchantId());
        });
        static::deleting(function (Model $model): void {
            $context = app(TenantContext::class);
            if (! $context->isUnscoped() && ($context->merchantId() === null || $model->getAttribute('merchant_id') !== $context->merchantId())) {
                throw new ValidationException(ErrorCode::FORBIDDEN);
            }
        });
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
