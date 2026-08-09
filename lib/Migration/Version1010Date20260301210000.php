<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1010Date20260301210000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
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

        // The chorestrike_user_stats table was removed with the dead
        // smart-scheduler cluster (CS-PLAN-025 / CR-SVC-08): no live code
        // referenced UserStatsService/UserStatsMapper. Pre-release: fresh
        // installs only — project-rules §1.

        // The runner persists schema diffs produced by changeSchema(), so the
        // modified wrapper MUST be returned (returning null silently drops it).
        return $schema;
    }
}
