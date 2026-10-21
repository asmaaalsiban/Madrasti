<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Actions\SeedStandardGrades;
use App\Central\Models\Tenant;
use Illuminate\Console\Command;

class SeedGradesCommand extends Command
{
    protected $signature = 'school:seed-grades {tenant : School id (e.g. school2)}';

    protected $description = 'Add the standard levels (buds, KG1, KG2, grades 1-12) to a school and put them in order.';

    public function handle(SeedStandardGrades $seeder): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("School '{$this->argument('tenant')}' not found.");

            return self::FAILURE;
        }

        $created = $tenant->run(fn () => $seeder->handle());
        $this->info("Done: $created level(s) added, the others were put in order.");

        return self::SUCCESS;
    }
}
