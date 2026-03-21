<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add coin_multiplier field to house_users
 */
class Version1019Date20260321000000 extends SimpleMigrationStep
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

        if ($schema->hasTable('chorestrike_house_users')) {
            $table = $schema->getTable('chorestrike_house_users');
            
            if (!$table->hasColumn('coin_multiplier')) {
                $table->addColumn('coin_multiplier', 'decimal', [
                    'notnull' => true,
                    'precision' => 3,
                    'scale' => 2,
                    'default' => 1.00,
                ]);
            }
        }
    }
}
