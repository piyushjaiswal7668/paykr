<?php

declare(strict_types=1);

namespace App\Http\Requests\Realtime;

use App\Domain\Realtime\Data\ChannelAccess;
use Illuminate\Foundation\Http\FormRequest;

final class ChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['socket_id' => ['required', 'string', 'max:100', 'regex:/^\d+\.\d+$/D'], 'channel_name' => ['required', 'string', 'max:150'], 'callback' => ['prohibited']];
    }

    public function toData(): ChannelAccess
    {
        return new ChannelAccess($this->validated('channel_name'), $this->user()?->getAuthIdentifier(), $this->header('X-Device-Id'), $this->header('X-Device-Token'), $this->bearerToken() ?? $this->cookie('paykr_session'));
    }
}
