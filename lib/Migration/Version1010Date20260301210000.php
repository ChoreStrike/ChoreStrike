<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaTools;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1010Date20260301210000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string
    {
        $schema = $schemaClosure();

        // Create HouseUser table for multi-user support
        if (!$schema->hasTable('chorestrike_house_users')) {
            $table = $schema->createTable('chorestrike_house_users');

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

            $table->addColumn('role', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'member',
            ]);

            $table->addColumn('joined_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->addColumn('is_active', 'boolean', [
                'notnull' => false,
                'default' => true,
            ]);

            $table->setPrimaryKey(['id'], 'pk_house_users');
            $table->addUniqueIndex(['house_id', 'user_id'], 'uniq_house_user');
            $table->addIndex(['house_id'], 'idx_house_users_house');
            $table->addIndex(['user_id'], 'idx_house_users_user');
            $table->addIndex(['role'], 'idx_house_users_role');
        }

        // Create UserStats table for tracking per-user metrics
        if (!$schema->hasTable('chorestrike_user_stats')) {
            $table = $schema->createTable('chorestrike_user_stats');

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

            $table->addColumn('total_points', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('current_streak', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('longest_streak', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('tasks_completed', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);

            $table->addColumn('last_completion_date', 'datetime', [
                'notnull' => false,
            ]);

            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'pk_user_stats');
            $table->addUniqueIndex(['house_id', 'user_id'], 'uniq_stats');
            $table->addIndex(['house_id'], 'idx_stats_house');
            $table->addIndex(['user_id'], 'idx_stats_user');
            $table->addIndex(['total_points'], 'idx_stats_points');
            $table->addIndex(['current_streak'], 'idx_stats_streak');
        }

        return null;
    }
}
