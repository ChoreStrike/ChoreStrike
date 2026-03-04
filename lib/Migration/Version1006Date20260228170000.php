<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add enhanced description fields for tasks
 */
class Version1006Date20260228170000 extends SimpleMigrationStep
{
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('chorestrike_tasks')) {
            $table = $schema->getTable('chorestrike_tasks');

            // Add instructions field (step-by-step guide)
            if (!$table->hasColumn('instructions')) {
                $table->addColumn('instructions', 'text', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            // Add tips field (helpful hints)
            if (!$table->hasColumn('tips')) {
                $table->addColumn('tips', 'text', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            // Add estimated time range fields
            if (!$table->hasColumn('estimated_minutes_min')) {
                $table->addColumn('estimated_minutes_min', 'integer', [
                    'notnull' => false,
                    'default' => null,
                    'unsigned' => true,
                ]);
            }

            if (!$table->hasColumn('estimated_minutes_max')) {
                $table->addColumn('estimated_minutes_max', 'integer', [
                    'notnull' => false,
                    'default' => null,
                    'unsigned' => true,
                ]);
            }
        }

        return $schema;
    }
}
