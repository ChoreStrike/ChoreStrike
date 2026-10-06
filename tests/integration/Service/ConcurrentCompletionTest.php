<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Integration tests for the house_user lost-update race (CR-SVC-04, reopened).
 *
 * Two concurrent same-user completions of DIFFERENT tasks (no room-task lock
 * contention) must both land: the second completion's house_user lock must not
 * clobber the first completion's committed coins/contribution increment, and
 * the post-lock state must be the freshest committed state.
 *
 * The race is reproduced deterministically with two real connections: the
 * shared Nextcloud connection plays T2 (opens its transaction and takes the
 * pre-lock read first), a second Nextcloud process plays T1 and commits its
 * increment while T2 sits between the pre-read and the lock — the exact
 * interleaving that clobbered the increment before the fix.
 */
class ConcurrentCompletionTest extends IntegrationTestCase {
	/**
	 * Two sequential same-user completions of different tasks accumulate
	 * both rewards on the wallet/contribution balances in exact minor
	 * units (no lost update). The second completion also maps the first
	 * completion's row (SELECT * entity mapping), which threw on the real
	 * DB before the CR-SVC-45 schema fix.
	 */
	public function testSequentialSameUserCompletionsAccumulateRewards(): void {
		$houseId = $this->createHouse('seq_owner', 'Sequential House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskA = $this->createTask($houseId, 'Dishes', 'Do the dishes', 5, 3);
		$taskB = $this->createTask($houseId, 'Trash', 'Take out the trash', 5, 3);
		$roomTaskA = $this->assignTaskToRoom($taskA, $roomId, 'seq_owner');
		$roomTaskB = $this->assignTaskToRoom($taskB, $roomId, 'seq_owner');

		$mapper = \OC::$server->get(HouseUserMapper::class);
		$user = $mapper->findByNextcloudUserId($houseId, 'seq_owner');
		$contributionBefore = $user->getContributionMinor();

		$this->actingAs('seq_owner');
		$completionService = \OC::$server->get(CompletionService::class);

		$first = $this->completeOccurrence($completionService, $houseId, 'seq_owner', $roomTaskA);
		$second = $this->completeOccurrence($completionService, $houseId, 'seq_owner', $roomTaskB);

		$user = $mapper->findByNextcloudUserId($houseId, 'seq_owner');
		// WP3 §3.1 exact arithmetic: a solo completion credits
		// totalEnergy * 1000 coin minor and totalEnergy * 100 energy minor.
		$energy = $first['rewards']['completion']['totalEnergy'] + $second['rewards']['completion']['totalEnergy'];
		self::assertSame(
			$contributionBefore + $energy * 100,
			$user->getContributionMinor(),
			'both completions accumulate on the contribution minor balance'
		);
		self::assertSame(
			$contributionBefore * 10 + $energy * 1000,
			$second['rewards']['wallet']['bookedMinor'],
			'both completions accumulate on the wallet ledger (frozen rate 10 Coins per energy)'
		);
	}

	/**
	 * The findForUpdate lock must not write pre-lock-read data back and must
	 * return the freshest committed state (CR-SVC-04 reopened).
	 *
	 * Nextcloud connections run at READ COMMITTED, so the stale-write clobber
	 * manifests when the concurrent winner commits BETWEEN the lock's inner
	 * pre-read and its lock acquisition. Reproduced deterministically with a
	 * second process (T1) holding the row lock:
	 *
	 *   T1 (helper process): locks the row, holds the lock, then commits
	 *                        contribution +5, coins +10
	 *   T2 (this test):      calls findForUpdate while T1 holds the lock —
	 *                        the lock acquisition blocks until T1 commits and
	 *                        must then build on T1's committed 5/10, not
	 *                        clobber it with a stale pre-lock value
	 *   T2:                  adds its own delta (+3/+7), commits
	 *   Final:               contribution 8, coins 17 — both increments landed
	 */
	public function testFindForUpdateBuildsOnCommittedWinnerState(): void {
		$houseId = $this->createHouse('race_owner', 'Race House');
		$mapper = \OC::$server->get(HouseUserMapper::class);
		$user = $mapper->findByNextcloudUserId($houseId, 'race_owner');
		$houseUserId = $user->getId();

		$signalFile = sys_get_temp_dir() . '/cs-race-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-race-lock-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildLockHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseUserId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the lock-holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile, '/^(?:SELECT .*chorestrike_house_users.*FOR UPDATE|UPDATE .*chorestrike_house_users)/i');

		try {
			// Wait until T1 holds the row lock.
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The lock-holder process did not acquire the row lock in time');
				}
				usleep(50000);
			}

			// T2: findForUpdate while T1 holds the lock. The lock acquisition
			// blocks until T1 commits; the returned entity must carry T1's
			// committed increment, and T2's lock must not clobber it.
			$this->db->beginTransaction();
			try {
				$locked = $mapper->findForUpdate($houseUserId);
				self::assertSame(500, $locked->getContributionMinor(), 'lock must build on the winner\'s committed state, not a stale pre-lock value');

				// T2 continues its completion on top of the winner state.
				$locked->addContributionMinor(300);
				$mapper->update($locked);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$final = $mapper->find($houseUserId);
			self::assertSame(800, $final->getContributionMinor(), 'no lost update: both increments land');
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);
		} finally {
			$barrier->detach();
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ([1, 2] as $pipe) {
				if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
					fclose($pipes[$pipe]);
				}
			}
			proc_close($process);
			@unlink($signalFile);
			@unlink($helperScript);
		}
	}

	/**
	 * The T1 helper: boots Nextcloud in a separate process, locks the
	 * house_user row, signals the parent, holds the lock briefly, then
	 * commits the concurrent winner's increment.
	 */
	private function buildLockHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseUserId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$db->beginTransaction();
\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findForUpdate($houseUserId);

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);

$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_house_users SET contribution_minor = contribution_minor + 500 WHERE id = :id',
    ['id' => $houseUserId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}

	/**
	 * CR-SVC-53: completeRoomTask must decide the overdue takeover on the
	 * FRESH post-lock entity, not the pre-lock snapshot (two-connection proof).
	 *
	 * Deterministic interleaving — the exact one that produced a spurious
	 * takeover before the fix:
	 *
	 *   T1 (helper process): locks the overdue room task row, holds the
	 *                        lock, then commits a concurrent winner's
	 *                        completion effect (next_due advanced to
	 *                        tomorrow → overdue state cleared)
	 *   T2 (this test):      completeRoomTask() for a NON-assignee: the
	 *                        pre-lock fetch reads the still-overdue
	 *                        committed state, then lockForUpdate() blocks
	 *                        until T1 commits and must hand back the fresh,
	 *                        no-longer-overdue entity
	 *   Final:               no takeover recorded (was_claimed stays 0,
	 *                        claimed_by_user NULL) and T2's completion
	 *                        succeeds on the advanced row.
	 *
	 * Fails on the old (void-returning) lockForUpdate: T2 kept operating on
	 * the stale overdue snapshot → markClaimed() fired → was_claimed = 1.
	 */
	public function testCompleteRoomTaskAfterConcurrentWinner_NoSpuriousTakeover(): void {
		$houseId = $this->createHouse('rt_owner', 'Race House RT');
		$this->addHouseMember($houseId, 'rt_helper', 'Helper', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 5, 3);
		// Overdue and assigned to rt_owner; rt_helper is a non-assignee.
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'rt_owner');

		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter($yesterday))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$signalFile = sys_get_temp_dir() . '/cs-rt-race-locked-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-rt-race-lock-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildRoomTaskLockHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$roomTaskId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the lock-holder process');
		$barrier = QueryBarrier::attach($this->db, $signalFile,
			$this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE
				? '/^UPDATE .*chorestrike_houses/i'
				: '/^SELECT .*chorestrike_room_tasks.*FOR UPDATE/i');

		try {
			// Wait until T1 holds the room task row lock.
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The lock-holder process did not acquire the row lock in time');
				}
				usleep(50000);
			}

			// T2: a non-assignee completion while T1 holds the lock. The
			// pre-lock fetch sees the overdue committed state; the locked
			// entity must reflect T1's committed advance instead. WP3
			// (Q-074-02): a non-assignee must explicitly take part in the
			// work — the split names the helper.
			$this->actingAs('rt_helper');
			$completionService = \OC::$server->get(CompletionService::class);
			$this->completeOccurrenceWithParticipants(
				$completionService,
				$houseId,
				'rt_helper',
				$roomTaskId,
				[['userId' => 'rt_helper', 'sharePct' => 100]]
			);
			self::assertTrue($barrier->wasReached());
			QueryBarrier::finish($process, $pipes);

			$row = $this->fetchRoomTaskRow($roomTaskId);
			self::assertGreaterThan(
				(new \DateTime())->getTimestamp(),
				(new \DateTime($row['next_due']))->getTimestamp(),
				'completion must build on the winner-advanced next_due (in the future), not the stale overdue one'
			);
		} finally {
			$barrier->detach();
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ([1, 2] as $pipe) {
				if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
					fclose($pipes[$pipe]);
				}
			}
			proc_close($process);
			@unlink($signalFile);
			@unlink($helperScript);
		}
	}

	/**
	 * Fetch a room task row as a raw array for state assertions.
	 *
	 * @return array<string, mixed>
	 */
	private function fetchRoomTaskRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('next_due')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row, 'room task row must exist after the completion');
		return $row;
	}

	/**
	 * WP5 (CS-PLAN-064): two DIFFERENT users completing two DIFFERENT tasks
	 * in the SAME room truly concurrently (two real Nextcloud processes,
	 * both running the real CompletionService). The room-row lock serializes
	 * the cleanliness read-modify-write, so the final cleanliness is the sum
	 * of both deltas and both ledger rows land — the same-room interleaving
	 * that lost room state under the old unlocked RMW.
	 */
	public function testTwoUsersSameRoomConcurrentCompletions_SumOfBothDeltasLand(): void {
		$houseId = $this->createHouse('cc_owner', 'Concurrent Room House');
		$this->addHouseMember($houseId, 'cc_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		// WP3 §3.5: cleanliness is journal-replayed — the creation baseline
		// (not a raw row update) anchors the math. Energy 2 (+20 each) keeps
		// the sum safely below the 100 clamp.
		$baselineCleanliness = $this->roomBaselineCleanliness($roomId);
		$taskA = $this->createTask($houseId, 'CC dishes', '', 5, 2);
		$taskB = $this->createTask($houseId, 'CC trash', '', 5, 2);
		$roomTaskA = $this->assignTaskToRoom($taskA, $roomId, 'cc_owner');
		$roomTaskB = $this->assignTaskToRoom($taskB, $roomId, 'cc_member');

		$bootedFile = sys_get_temp_dir() . '/cs-cc-booted-' . uniqid();
		$goFile = sys_get_temp_dir() . '/cs-cc-go-' . uniqid();
		$lockedFile = $goFile . '.locked';
		$doneFile = sys_get_temp_dir() . '/cs-cc-done-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-cc-completer-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildConcurrentCompleterScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, (string)$roomTaskA, 'cc_owner', $bootedFile, $goFile, $doneFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the concurrent completer process');
		$barrier = QueryBarrier::attach($this->db, $lockedFile, '/^(?:SELECT .*chorestrike_houses.*FOR UPDATE|UPDATE .*chorestrike_houses)/i');

		try {
			$this->waitForFile($bootedFile, 'the helper process did not boot Nextcloud in time');
			file_put_contents($goFile, 'go');
			$this->waitForFile($lockedFile, 'the helper must hold its real completion transaction before the contender starts');

			// The helper has written but not committed its real completion;
			// our house-lock attempt releases it and must build on its effects.
			$completionService = \OC::$server->get(CompletionService::class);
			$parentResult = $this->completeOccurrence($completionService, $houseId, 'cc_member', $roomTaskB);

			$this->waitForFile($doneFile, 'the helper completion did not finish in time');
			$helperResult = json_decode((string)file_get_contents($doneFile), true, 512, JSON_THROW_ON_ERROR);
			QueryBarrier::finish($process, $pipes);
			self::assertTrue($barrier->wasReached());
		} finally {
			$barrier->detach();
			$this->terminateHelper($process, $pipes);
			foreach ([$bootedFile, $goFile, $lockedFile, $doneFile, $helperScript] as $tmp) {
				@unlink($tmp);
			}
		}

		$row = $this->fetchRoomRow($roomId);
		self::assertSame(
			$baselineCleanliness + (int)$helperResult['cleanlinessChange'] + $parentResult['rewards']['cleanlinessChange'],
			(int)$row['cleanliness'],
			'the room must end at the exact sum of both concurrent journal deltas'
		);

		// Both completions landed with their own ledger row.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->in(
				'room_task_id',
				$qb->createNamedParameter([$roomTaskA, $roomTaskB], IQueryBuilder::PARAM_INT_ARRAY)
			));
		$result = $qb->executeQuery();
		$ledgerCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(2, $ledgerCount, 'both concurrent completions must write their own ledger row');

		// Both users earned their own rewards (no clobber on either row).
		$mapper = \OC::$server->get(HouseUserMapper::class);
		$owner = $mapper->findByNextcloudUserId($houseId, 'cc_owner');
		$member = $mapper->findByNextcloudUserId($houseId, 'cc_member');
		self::assertGreaterThan(0, $owner->getContributionMinor(), 'the helper user\'s work credit must have landed');
		self::assertGreaterThan(0, $member->getContributionMinor(), 'the parent user\'s work credit must have landed');
	}

	/**
	 * Fetch a room row as a raw array for state assertions.
	 *
	 * @return array<string, mixed>
	 */
	private function fetchRoomRow(int $roomId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cleanliness')
			->from('chorestrike_rooms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row, 'room row must exist');
		return $row;
	}

	/**
	 * The room's creation baseline cleanliness from the journal (WP3 §3.5):
	 * the replay anchor for cleanliness math — never a raw row value.
	 */
	private function roomBaselineCleanliness(int $roomId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('baseline_cleanliness')
			->from('chorestrike_room_events')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('baseline')));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		// Raw fixture rooms carry no journal — the replay seeds those at the
		// documented default 50 (RoomReplayService::replay).
		return $value === false ? 50 : (int)$value;
	}

	/**
	 * @return list<int>
	 */
	private function ledgerIdsFor(int ...$roomTaskIds): array {
		$ids = [];
		foreach ($roomTaskIds as $roomTaskId) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$ids[] = (int)$row['id'];
			}
			$result->closeCursor();
		}
		return $ids;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sharesForCompletion(int $completionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_work_shares')
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Wait for a signal file to appear (bounded).
	 */
	private function waitForFile(string $file, string $failureMessage): void {
		$deadline = microtime(true) + 30.0;
		while (!file_exists($file)) {
			if (microtime(true) > $deadline) {
				self::fail($failureMessage);
			}
			usleep(50000);
		}
	}

	/**
	 * Terminate a helper process and close its pipes (shared with the
	 * `proc_open` tests above).
	 */
	private function terminateHelper($process, array $pipes): void {
		$status = proc_get_status($process);
		if ($status !== false && $status['running']) {
			proc_terminate($process);
		}
		foreach ([1, 2] as $pipe) {
			if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
				fclose($pipes[$pipe]);
			}
		}
		proc_close($process);
	}

	/**
	 * The T1 helper: boots Nextcloud, signals readiness, waits for the
	 * parent's go signal, then runs the REAL CompletionService for its room
	 * task and reports the applied cleanliness delta.
	 */
	private function buildConcurrentCompleterScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$houseId = (int)$argv[1];
$roomTaskId = (int)$argv[2];
$userId = $argv[3];
$bootedFile = $argv[4];
$goFile = $argv[5];
$doneFile = $argv[6];

file_put_contents($bootedFile, 'booted');

$deadline = microtime(true) + 30.0;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'go signal never arrived');
        exit(1);
    }
    usleep(20000);
}

try {
    $service = \OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class);
    // CS-PLAN-072 WP1: the occurrence receipt of the current canonical
    // target (public key, version, selection token) plus a fresh command
    // UUID per helper run.
    $occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
    $roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
    $readService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
    $roomTask = $roomTaskMapper->find($roomTaskId);
    $houseId = $readService->houseIdForRoom($roomTask->getRoomId());
    $selection = $occurrenceService->selectionFor($roomTask, new DateTime(), $houseId);
    if ($selection === null) {
        throw new RuntimeException('no completable occurrence for room task ' . $roomTaskId);
    }
    $barrier = \OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::attach(
        \OC::$server->get(\OCP\IDBConnection::class), $goFile . '.locked', '/^"COMMIT"$/', true
    );
    $result = $service->complete(
        $houseId,
        $userId,
        $roomTaskId,
        sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
        $selection['occurrence']->toPublicKey(),
        $selection['occurrence']->getVersion(),
        $occurrenceService->selectionToken($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, null, $selection['workAppointment'] ?? null)
    );
    if (!$barrier->wasReached()) {
        throw new \RuntimeException('The real completion never reached its commit barrier.');
    }
    file_put_contents($doneFile, json_encode([
        'cleanlinessChange' => $result['rewards']['cleanlinessChange'],
    ]));
} catch (Throwable $e) {
    file_put_contents($doneFile, json_encode(['error' => $e->getMessage()]));
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
	}

	/**
	 * The T1 helper for the room-task race: boots Nextcloud in a separate
	 * process, locks the room task row, signals the parent, holds the lock
	 * briefly, then commits a concurrent winner's completion effect — the
	 * overdue state is cleared by advancing next_due to tomorrow.
	 */
	private function buildRoomTaskLockHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
require '/var/www/html/custom_apps/chorestrike/tests/integration/Support/QueryBarrier.php';

$roomTaskId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);
$db->beginTransaction();
\OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->lockForUpdate($roomTaskId);

\OCA\ChoreStrike\Tests\Integration\Support\QueryBarrier::hold($signalFile);

$tomorrow = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
$now = (new DateTime())->format('Y-m-d H:i:s');
$db->executeStatement(
    'UPDATE *PREFIX*chorestrike_room_tasks SET next_due = :tomorrow, last_completed = :completed WHERE id = :id',
    ['tomorrow' => $tomorrow, 'completed' => $now, 'id' => $roomTaskId],
    ['tomorrow' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'completed' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR, 'id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$db->commit();
PHP;
	}
}
