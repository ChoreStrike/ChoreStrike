<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Migration;

use OCA\ChoreStrike\Migration\Version1015Date20260304000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Version1015Date20260304000000 migration.
 */
class Version1015Date20260304000000Test extends TestCase {
	/**
	 * Ensures migration uses schema from closure and returns it.
	 */
	public function testChangeSchema_UsesSchemaFromClosureAndReturnsIt(): void {
		$migration = new Version1015Date20260304000000();
		$output = $this->createMock(IOutput::class);
		$schema = $this->createMock(ISchemaWrapper::class);

		// CS-PLAN-055: four table guards remain after the orphan-table index
		// blocks (availability / task_scopes / task_claims / task_participants)
		// were removed from the migration.
		$schema->expects($this->exactly(4))
			->method('hasTable')
			->willReturn(false);

		$closureCallCount = 0;
		$closure = function () use ($schema, &$closureCallCount): ISchemaWrapper {
			$closureCallCount++;
			return $schema;
		};

		$result = $migration->changeSchema($output, $closure, []);

		$this->assertSame($schema, $result);
		$this->assertSame(1, $closureCallCount);
	}
}
