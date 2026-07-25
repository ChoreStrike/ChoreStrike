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

        // Create Combo table for tracking task completion combos
        if (!$schema->hasTable('chorestrike_combos')) {
            $table = $schema->createTable('chorestrike_combos');

            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('house_id', 'bigint', [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('task_count', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('started_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->addColumn('last_task_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->addColumn('combo_bonus_points', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('is_active', 'boolean', [
                'notnull' => false,
                'default' => true,
            ]);

            $table->setPrimaryKey(['id'], 'pk_combos');
            $table->addIndex(['house_id'], 'idx_combos_house');
            $table->addIndex(['user_id'], 'idx_combos_user');
            $table->addIndex(['is_active'], 'idx_combos_active');
            $table->addUniqueIndex(['house_id', 'user_id', 'is_active'], 'uniq_active_combo');
        }

        // The runner persists schema diffs produced by changeSchema(), so the
        // modified wrapper MUST be returned (returning null silently drops it).
        return $schema;
    }
}
