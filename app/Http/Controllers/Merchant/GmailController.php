<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Observers\Services\GmailOAuth;
use App\Http\Requests\Api\GmailRequest;
use App\Http\Resources\V1\GmailResource;
use Symfony\Component\HttpFoundation\Response;

final class GmailController
{
    public function __invoke(GmailRequest $request, GmailOAuth $gmail): Response
    {
        return GmailResource::response($gmail->execute($request->toDto()), $request->route('operation') === 'callback');
    }
}
