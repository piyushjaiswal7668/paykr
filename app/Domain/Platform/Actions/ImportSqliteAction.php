<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Customers\Enums\OtpPurpose;
use App\Domain\Platform\Data\ImportSqliteData;
use App\Domain\Platform\Data\ImportSqliteResult;
use App\Domain\Platform\Services\ImportMerchantSettings;
use App\Domain\Platform\Services\LegacyRowMapper;
use App\Domain\Shared\Errors\ErrorCode;
use App\Domain\Shared\Errors\ImportException;
use App\Domain\Shared\Services\TenantContext;
use App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Throwable;

final class ImportSqliteAction
{
    /** @var list<class-string<Model>> */
    private const MODELS = [
        Models\Merchant::class, Models\Customer::class, Models\Device::class,
        Models\CustomerSession::class, Models\PaymentReceivingAccount::class,
        Models\PaymentSession::class, Models\PaymentObservation::class,
        Models\AndroidDevice::class, Models\WebhookDelivery::class,
        Models\SupportTicket::class, Models\TicketMessage::class,
        Models\AuditLog::class, Models\SecurityEvent::class,
    ];

    public function __construct(private TenantContext $tenants, private LegacyRowMapper $mapper, private ImportMerchantSettings $settings) {}

    public function handle(ImportSqliteData $data): ImportSqliteResult
    {
        $path = realpath($data->path);
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new ImportException(ErrorCode::IMPORT_SOURCE_INVALID);
        }
        $source = null;
        try {
            $source = new PDO('sqlite:file:'.str_replace(['?', '#'], ['%3F', '%23'], $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $source->exec('PRAGMA query_only = ON');
            $source->beginTransaction();
            foreach (self::MODELS as $class) {
                $table = (new $class)->getTable();
                $source->query('SELECT id FROM "'.$table.'" LIMIT 0');
            }
        } catch (Throwable) {
            throw new ImportException(ErrorCode::IMPORT_SOURCE_INVALID);
        }
        try {
            return $this->tenants->runUnscoped(fn () => DB::transaction(function () use ($source): ImportSqliteResult {
                // Lock the existing users row to serialize imports into a normally seeded installation.
                // Database unique constraints also prevent concurrent inserts into an empty installation.
                Models\User::query()->lockForUpdate()->get();
                foreach (self::MODELS as $class) {
                    if ($class::query()->exists()) {
                        throw new ImportException(ErrorCode::IMPORT_TARGET_NOT_EMPTY);
                    }
                }
                $counts = [];
                $deferred = [];
                foreach (self::MODELS as $class) {
                    $template = new $class;
                    $table = $template->getTable();
                    $counts[$table] = 0;
                    // ERRMODE_EXCEPTION makes query failures throw instead of returning false.
                    /** @var \PDOStatement $rows */
                    $rows = $source->query('SELECT * FROM "'.$table.'"');
                    foreach ($rows as $row) {
                        $attributes = $this->mapper->map($template, $row);
                        foreach (['current_active_session_id', 'attributed_observation_id'] as $column) {
                            if (isset($attributes[$column])) {
                                $deferred[] = [$class, $attributes['id'], $column, $attributes[$column]];
                                $attributes[$column] = null;
                            }
                        }
                        $model = new $class;
                        $model->timestamps = false;
                        $model->forceFill($attributes)->save();
                        if ($model instanceof Models\Merchant) {
                            $branding = $this->mapper->json($row['branding'] ?? '{}');
                            $model->forceFill(['legacy_settings' => $branding])->save();
                            $this->settings->import($model, $branding);
                        }
                        if ($model instanceof Models\Customer && ! empty($row['otp_code']) && ! empty($row['otp_expires_at'])) {
                            Models\CustomerOtpChallenge::create(['mobile_number' => $model->mobile_number, 'code_hash' => Hash::make($row['otp_code']), 'purpose' => OtpPurpose::LOGIN, 'expires_at' => LegacyRowMapper::date($row['otp_expires_at'])]);
                        }
                        $counts[$table]++;
                    }
                }
                foreach ($deferred as [$class, $id, $column, $value]) {
                    $model = $class::whereKey($id)->firstOrFail();
                    $model->timestamps = false;
                    $model->forceFill([$column => $value])->save();
                }
                foreach (self::MODELS as $class) {
                    if ($class::count() !== $counts[(new $class)->getTable()]) {
                        throw new ImportException(ErrorCode::IMPORT_FAILED);
                    }
                }

                return new ImportSqliteResult($counts);
            }));
        } catch (ImportException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Do not expose SQL bindings, OAuth tokens or raw messages in command output/logs.
            throw new ImportException(ErrorCode::IMPORT_FAILED);
        } finally {
            if ($source->inTransaction()) {
                $source->rollBack();
            }
        }
    }
}
