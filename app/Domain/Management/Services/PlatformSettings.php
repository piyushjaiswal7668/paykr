<?php

declare(strict_types=1);

namespace App\Domain\Management\Services;

use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\Services\TenantContext;
use App\Models\PlatformOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use ZipArchive;

final class PlatformSettings
{
    public function __construct(private PanelAccess $access, private TenantContext $tenants) {}

    public static function value(string $key, mixed $default): mixed
    {
        return PlatformOption::find('defaults')?->value[$key] ?? $default;
    }

    /** @param array<string, mixed> $data */
    public function save(string $userId, array $data, ?string $ip): void
    {
        $actor = $this->access->admin($userId, $ip);
        $data = Validator::make($data, ['app_name' => ['required', 'string', 'max:80'], 'primary_color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/D'], 'otp_max_attempts' => ['required', 'integer', 'min:1', 'max:10'], 'otp_resend_cooldown_seconds' => ['required', 'integer', 'min:30', 'max:300'], 'otp_sends_per_mobile_10_minutes' => ['required', 'integer', 'min:1', 'max:10'], 'otp_sends_per_ip_hour' => ['required', 'integer', 'min:1', 'max:100']])->validate();
        $this->tenants->runUnscoped(fn () => DB::transaction(function () use ($data, $actor): void {
            PlatformOption::updateOrCreate(['key' => 'defaults'], ['value' => $data]);
            $this->access->audit($actor, 'PLATFORM_SETTINGS_UPDATED', 'PLATFORM', null);
        }));
    }

    /** @return array<string, mixed> */
    public function publishApk(string $userId, string $path, string $version, ?string $ip): array
    {
        $actor = $this->access->admin($userId, $ip);
        Validator::make(['version' => $version], ['version' => ['required', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9]+)?$/D']])->validate();
        $real = realpath($path);
        $root = realpath(storage_path('app'));
        if ($real === false || $root === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || filesize($real) > 50 * 1024 * 1024) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $zip = new ZipArchive;
        if ($zip->open($real) !== true) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $valid = $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
        $zip->close();
        if (! $valid) {
            throw new ValidationException(ErrorCode::VALIDATION_FAILED);
        }
        $hash = hash_file('sha256', $real);
        $directory = storage_path('app/private/releases');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $versioned = $directory.'/paykr-observer-'.$version.'-'.$hash.'.apk';
        if (! copy($real, $versioned)) {
            throw new ValidationException(ErrorCode::INTERNAL_ERROR);
        }
        $pending = $directory.'/pending-'.bin2hex(random_bytes(8)).'.apk';
        if (! copy($versioned, $pending) || ! rename($pending, $directory.'/paykr-sms-gateway.apk')) {
            throw new ValidationException(ErrorCode::INTERNAL_ERROR);
        }
        $result = ['version' => $version, 'sha256' => $hash];
        $this->tenants->runUnscoped(fn () => $this->access->audit($actor, 'APK_PUBLISHED', 'PLATFORM', null, $result));

        return $result;
    }
}
