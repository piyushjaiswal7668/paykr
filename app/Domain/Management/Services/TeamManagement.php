<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Management\Data\PanelCommand;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

final class TeamManagement
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants) {}

    /** @return array<string, mixed> */
    public function execute(PanelCommand $command): array
    {
        $actor = $this->access->authorize($command, 'team');

        return $this->tenants->run($command->merchantId, fn () => DB::transaction(function () use ($command, $actor): array {
            $merchant = Merchant::whereKey($command->merchantId)->lockForUpdate()->firstOrFail();
            if ($command->operation === 'invite') {
                $data = Validator::make($command->data, ['email' => ['required', 'email', 'max:254'], 'name' => ['required', 'string', 'max:100'], 'role' => ['required', 'in:owner,manager,cashier,developer']])->validate();
                $user = User::where('email', $data['email'])->first();
                $new = $user === null;
                if ($new) {
                    $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => bin2hex(random_bytes(32))]);
                }
                if ($user->is_platform_admin || $merchant->users()->whereKey($user->id)->exists()) {
                    throw new ValidationException(ErrorCode::VALIDATION_FAILED);
                }
                $merchant->users()->attach($user->id, ['role' => $data['role']]);
                $result = ['userId' => $user->id, 'status' => 'ADDED'];
                if ($new) {
                    $result['invitationToken'] = Password::broker()->createToken($user);
                }
            } else {
                $user = $merchant->users()->whereKey($command->recordId)->firstOrFail();
                $data = $command->operation === 'role' ? Validator::make($command->data, ['role' => ['required', 'in:owner,manager,cashier,developer']])->validate() : [];
                if ($user->getRelation('pivot')->role === 'owner' && ($data['role'] ?? '') !== 'owner' && $merchant->users()->wherePivot('role', 'owner')->count() <= 1) {
                    throw new ValidationException(ErrorCode::FORBIDDEN);
                }
                if ($command->operation === 'remove') {
                    $merchant->users()->detach($user->id);
                } elseif ($command->operation === 'role') {
                    $merchant->users()->updateExistingPivot($user->id, $data);
                } else {
                    throw new ValidationException(ErrorCode::NOT_FOUND);
                }
                $result = ['status' => 'UPDATED'];
            }
            $this->access->audit($actor, 'TEAM_'.strtoupper($command->operation), 'USER', $user->id, ['role' => $data['role'] ?? null]);

            return $result;
        }));
    }
}
