<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Actions\ImportSqliteAction;
use App\Domain\Platform\Data\ImportSqliteData;
use Illuminate\Console\Command;

final class ImportSqlite extends Command
{
    protected $signature = 'paykr:import-sqlite {path : Read-only source SQLite file}';

    protected $description = 'Import legacy Paykr data transactionally into an empty domain database';

    public function handle(ImportSqliteAction $action): int
    {
        $result = $action->handle(new ImportSqliteData((string) $this->argument('path')));
        $this->table(['Table', 'Imported rows'], collect($result->counts)->map(fn ($count, $table) => [$table, $count])->values()->all());
        $this->info('Import complete. Source unchanged; counts verified.');

        return self::SUCCESS;
    }
}
