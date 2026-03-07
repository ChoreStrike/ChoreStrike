<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaTools;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1013Date20260301210000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string
    {
        $schema = $schemaClosure();

        // Create AvailabilitySettings table for smart scheduling
        if (!$schema->hasTable('chorestrike_availability')) {
            $table = $schema->createTable('chorestrike_availability');

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

            $table->addColumn('weekday_availability', 'json', [
                'notnull' => false,
            ]);

            $table->addColumn('time_ranges', 'json', [
                'notnull' => false,
            ]);

            $table->addColumn('energy_budget', 'json', [
                'notnull' => false,
            ]);

            $table->addColumn('planning_mode', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'auto_balance',
            ]);

            $table->addColumn('max_tasks_per_day', 'integer', [
                'notnull' => true,
                'default' => 5,
                'unsigned' => true,
            ]);

            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'pk_availability');
            $table->addUniqueIndex(['house_id', 'user_id'], 'uniq_user_availability');
            $table->addIndex(['house_id'], 'idx_availability_house');
            $table->addIndex(['user_id'], 'idx_availability_user');
            $table->addIndex(['planning_mode'], 'idx_availability_mode');
        }

        return null;
    }
}
