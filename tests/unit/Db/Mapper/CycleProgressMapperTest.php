<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\CycleProgressMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The first tick of a sub-task isolates its insert in a savepoint. The pin
 * moved here from the progress mapper of the stored occurrences, whose
 * toggle CS-PLAN-091 WP-B7 retired.
 */
class CycleProgressMapperTest extends TestCase {
	/** @dataProvider unexpectedInsertFailures */
	public function testToggleLocked_UnexpectedInsertFailure_PropagatesWithoutFurtherSql(\Throwable $failure): void {
		$db = $this->createMock(IDBConnection::class);
		$mapper = $this->getMockBuilder(CycleProgressMapper::class)
			->setConstructorArgs([$db])->onlyMethods(['findForUpdate', 'insert'])->getMock();
		$mapper->method('findForUpdate')->willReturn(null);
		$mapper->method('insert')->willThrowException($failure);
		// A deadlock can abort the whole transaction: no savepoint cleanup
		// may mask the original error that the owning executor must handle.
		$db->expects($this->once())->method('executeStatement')->with('SAVEPOINT cs_cycle_tick');
		$this->expectExceptionObject($failure);
		$mapper->toggleLocked(1, 'c2', 3, 4);
	}

	public static function unexpectedInsertFailures(): array {
		return [
			'non-database failure' => [new \RuntimeException('injected insert failure')],
			'non-unique database failure' => [new \OCP\DB\Exception('injected deadlock')],
		];
	}
}
