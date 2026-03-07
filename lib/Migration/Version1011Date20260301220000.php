<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaTools;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1011Date20260301220000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string
    {
        $schema = $schemaClosure();

        // Add assignment tracking columns to room_tasks if not exists
        if ($schema->hasTable('chorestrike_room_tasks')) {
            $table = $schema->getTable('chorestrike_room_tasks');

            if (!$table->hasColumn('assigned_by_user_id')) {
                $table->addColumn('assigned_by_user_id', 'string', [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }

            if (!$table->hasColumn('assigned_at')) {
                $table->addColumn('assigned_at', 'datetime', [
                    'notnull' => false,
                ]);
            }

            if (!$table->hasIndex('idx_room_tasks_assigned')) {
                $table->addIndex(['assigned_to_user_id'], 'idx_room_tasks_assigned');
            }

            if (!$table->hasIndex('idx_room_tasks_claimed')) {
                $table->addIndex(['claimed_by_user'], 'idx_room_tasks_claimed');
            }
        }

        // Create CompletedTask enhancement table for tracking who completed tasks
        if (!$schema->hasTable('chorestrike_completed_tasks')) {
            $table = $schema->getTable('chorestrike_completed_tasks');

            if (!$table->hasColumn('completed_by_user_id')) {
                $table->addColumn('completed_by_user_id', 'string', [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }

            if (!$table->hasColumn('points_earned')) {
                $table->addColumn('points_earned', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            if (!$table->hasColumn('bonus_points')) {
                $table->addColumn('bonus_points', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                    'unsigned' => true,
                ]);
            }

            if (!$table->hasIndex('idx_completed_user')) {
                $table->addIndex(['completed_by_user_id'], 'idx_completed_user');
            }

            if (!$table->hasIndex('idx_completed_points')) {
                $table->addIndex(['points_earned'], 'idx_completed_points');
            }
        }

        return null;
    }
}
