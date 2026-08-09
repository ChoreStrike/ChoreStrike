<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1012Date20260301230000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        // The chorestrike_combos table was removed with the dead smart-scheduler
        // cluster (CS-PLAN-025 / CR-SVC-08): GameLogicService inlines its own
        // combo logic and no live code referenced ComboService/ComboMapper. This
        // step is now a no-op (pre-release: fresh installs only — project-rules §1).

        // The runner persists schema diffs produced by changeSchema(), so the
        // modified wrapper MUST be returned (returning null silently drops it).
        return $schema;
    }
}
