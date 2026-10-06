<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

class QueryBarrierTest extends IntegrationTestCase {
	public function testMatchingQuery_ReleasesOnce_AndRestoresPreviousLogger(): void {
		$signal = sys_get_temp_dir() . '/cs-barrier-' . uniqid();
		$counter = QueryCounter::attach($this->db);
		$barrier = QueryBarrier::attach($this->db, $signal, '/^SELECT 2$/');
		try {
			$this->db->executeQuery('SELECT 1')->closeCursor();
			self::assertFalse($barrier->wasReached());
			self::assertFileDoesNotExist($signal . '.attempt');
			$this->db->executeQuery('SELECT 2')->closeCursor();
			self::assertTrue($barrier->wasReached());
			self::assertSame('attempting', file_get_contents($signal . '.attempt'));
			QueryBarrier::hold($signal, 0.01);
			self::assertSame('locked', file_get_contents($signal));
			unlink($signal . '.attempt');
			$this->db->executeQuery('SELECT 2')->closeCursor();
			self::assertFileDoesNotExist($signal . '.attempt', 'a retry cannot release a second holder');
			$barrier->detach();
			$this->db->executeQuery('SELECT 1')->closeCursor();
			self::assertSame(4, $counter->count(), 'the original logger observes queries before and after detach');
		} finally {
			$barrier->detach();
			$counter->detach();
			if (file_exists($signal)) {
				unlink($signal);
			}
		}
	}

	public function testMissingQuery_HolderFailsWithinBound(): void {
		$signal = sys_get_temp_dir() . '/cs-barrier-absent-' . uniqid();
		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('The contender did not reach the required SQL barrier.');
			QueryBarrier::hold($signal, 0.01);
		} finally {
			unlink($signal);
		}
	}

	public function testCommitHook_HoldsRealTransactionUntilAttempt(): void {
		$signal = sys_get_temp_dir() . '/cs-barrier-commit-' . uniqid();
		$barrier = QueryBarrier::attach($this->db, $signal, '/^"COMMIT"$/', true);
		try {
			$this->db->beginTransaction();
			self::assertFalse($barrier->wasReached());
			file_put_contents($signal . '.attempt', 'attempting');
			$this->db->commit();
			self::assertTrue($barrier->wasReached());
			self::assertSame('locked', file_get_contents($signal));
			self::assertFalse($this->db->inTransaction());
		} finally {
			$barrier->detach();
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if (file_exists($signal)) {
				unlink($signal);
			}
		}
	}

	/** @dataProvider helperOutcomes */
	public function testHelperOutcome_RequiresNormalExitAndEmptyStderr(string $code, ?string $error): void {
		$process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		try {
			if ($error !== null) {
				$this->expectException(\RuntimeException::class);
				$this->expectExceptionMessage($error);
			}
			self::assertSame('committed', QueryBarrier::finish($process, $pipes, 1.0));
		} finally {
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
		}
	}

	public static function helperOutcomes(): array {
		return [
			'normal exit' => ['echo "committed";', null],
			'aborted holder' => ['exit(7);', 'The holder failed:'],
			'worker error' => ['fwrite(STDERR, "worker error");', 'The holder failed: worker error'],
			'stalled holder' => ['fgets(STDIN);', 'The holder did not exit within the barrier deadline.'],
		];
	}
}
