<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add flex-window fields for scheduling
 */
class Version1020Date20260321000000 extends SimpleMigrationStep
{
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure
     * @param array $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add flex_days to tasks table
        if ($schema->hasTable('chorestrike_tasks')) {
            $table = $schema->getTable('chorestrike_tasks');
            
            if (!$table->hasColumn('flex_days')) {
                $table->addColumn('flex_days', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        // Add ideal_due_date to room_tasks table
        if ($schema->hasTable('chorestrike_room_tasks')) {
            $table = $schema->getTable('chorestrike_room_tasks');
            
            if (!$table->hasColumn('ideal_due_date')) {
                $table->addColumn('ideal_due_date', 'datetime', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }
    }
}
