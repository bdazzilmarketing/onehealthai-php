<?php

namespace App\Console\Commands;

use App\Database\SqlMigrator;
use Illuminate\Console\Command;
use Throwable;

class MigrateSql extends Command
{
    protected $signature = 'onehealth:migrate';

    protected $description = 'Apply database/sql/migrations to MIGRATION_DATABASE_URL (the migration login, not the runtime login)';

    public function handle(): int
    {
        $url = env('MIGRATION_DATABASE_URL');
        if (! $url) {
            $this->error('MIGRATION_DATABASE_URL is required (the migration login, not the runtime login).');

            return self::FAILURE;
        }
        try {
            $result = SqlMigrator::migrate(SqlMigrator::connect($url), database_path('sql/migrations'), fn ($line) => $this->line($line));
            $this->info(count($result['applied']).' applied, '.count($result['skipped']).' already applied');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
