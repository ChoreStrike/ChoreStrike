<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Recording work for other people against the real database (CS-PLAN-091
 * WP-B2, ADR-021 decisions 4 and 10): the recorder need not take part, only
 * members who may see a task are credited on it, a takeover keeps its
 * digest receipt, and one cycle is completed exactly once also when two
 * members record it at the same moment.
 */
class RecordingForOthersTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'rfo_owner';
	protected const HELPER = 'rfo_helper';

	private CompletionService $completionService;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->initToday();
	}

	private function bookedMinor(int $houseId, string $userId): int {
		return (int)\OC::$server->get(WalletMapper::class)->findByHouseAndUser($houseId, $userId)?->getBookedMinor();
	}

	/** @return list<array<string, mixed>> */
	private function takeoverReceipts(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_digest_changes')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('takeover')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function testRecordingForSomeoneElse_CreditsOnlyThem_AndTheHistoryNamesTheRecorder(): void {
		$f = $this->fixture();
		$helper = $this->member($f['houseId'], self::HELPER);

		$result = $this->completeOccurrenceWithParticipants(
			$this->completionService, $f['houseId'], self::HELPER, $f['roomTaskId'],
			[['userId' => self::OWNER, 'sharePct' => 100]]
		);

		// Energy 2 at the frozen rate: 2000 coin minor, all of it the owner's.
		self::assertSame([2000, 0], [$this->bookedMinor($f['houseId'], self::OWNER), $this->bookedMinor($f['houseId'], self::HELPER)]);
		self::assertSame([self::OWNER], array_column($result['rewards']['participants'], 'userId'));
		$ledger = $this->rows('chorestrike_completed_tasks', 'id', $result['completionId'])[0];
		self::assertSame((int)$helper->getId(), (int)$ledger['user_id'], 'the entry keeps who recorded it');
		self::assertSame(0, (int)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['was_claimed'], 'work recorded for its assignee is no takeover');

		$entry = \OC::$server->get(ActivityService::class)->getRoomTaskHistory($f['houseId'], $f['roomTaskId'], 10, $helper)['completions'][0];
		self::assertTrue($entry['recordedForOthers']);
		self::assertSame('Helper', $entry['displayName']);
		self::assertSame([self::OWNER], array_column($entry['allocation'], 'userId'));
		self::assertTrue($entry['canCorrect'], 'the recorder corrects their own latest entry');
		$shown = \OC::$server->get(\OCA\ChoreStrike\Service\CompletionReadModel::class)->show($f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId']);
		self::assertSame(self::HELPER, $shown['recordedBy']['userId']);

		// The recorder takes the entry back; what was paid reverses exactly.
		$this->completionService->undo($f['houseId'], $helper, $result['completionId'], $this->newCommandId(), 0, null);
		self::assertSame(0, $this->bookedMinor($f['houseId'], self::OWNER));
	}

	public function testHoldingSomeoneElsesTask_CreditsItsAssignees(): void {
		$f = $this->fixture();

		// No participants: the default split, which need not name the recorder.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], self::HELPER, $f['roomTaskId']);

		self::assertSame([self::OWNER], array_column($result['rewards']['participants'], 'userId'));
		self::assertSame([2000, 0], [$this->bookedMinor($f['houseId'], self::OWNER), $this->bookedMinor($f['houseId'], self::HELPER)]);
	}

	public function testPrivateTask_CreditsOnlyWhoMaySeeIt(): void {
		// Private: its creator and the room task's assignees (ADR-010) — here both the owner.
		$f = $this->fixture(['visibility_type' => 'private', 'owner_user_id' => self::OWNER]);

		try {
			$this->completeOccurrenceWithParticipants(
				$this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId'],
				[['userId' => self::HELPER, 'sharePct' => 100]]
			);
			self::fail('a member who may not see the task must not be credited on it');
		} catch (ForbiddenException $e) {
			self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']), 'the refusal writes nothing');
			self::assertSame(0, $this->bookedMinor($f['houseId'], self::HELPER));
		}

		$this->completeOccurrenceWithParticipants(
			$this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId'],
			[['userId' => self::OWNER, 'sharePct' => 100]]
		);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::OWNER));
	}

	/** D-091-56: the owner's attribution credits nobody who may not see the task either. */
	public function testAttributionOfAPrivateTask_CreditsOnlyWhoMaySeeIt(): void {
		$f = $this->fixture(['visibility_type' => 'private', 'owner_user_id' => self::OWNER]);
		$owner = $this->member($f['houseId'], self::OWNER);
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId']);

		try {
			$this->completionService->attribute(
				$f['houseId'], $owner, $result['completionId'], $this->newCommandId(), 0,
				[['userId' => self::HELPER, 'sharePct' => 100]], true, null
			);
			self::fail('the owner must not credit a member who may not see the task');
		} catch (ForbiddenException $e) {
			self::assertSame(0, (int)$this->rows('chorestrike_completed_tasks', 'id', $result['completionId'])[0]['completion_version'], 'the refusal writes nothing');
			self::assertSame([2000, 0], [$this->bookedMinor($f['houseId'], self::OWNER), $this->bookedMinor($f['houseId'], self::HELPER)]);
		}
	}

	public function testTakeover_OfSomeoneElsesRedEntry_KeepsItsDigestReceipt(): void {
		$f = $this->fixture([], $this->day(-3));
		$owner = $this->member($f['houseId'], self::OWNER);

		$result = $this->completeOccurrenceWithParticipants(
			$this->completionService, $f['houseId'], self::HELPER, $f['roomTaskId'],
			[['userId' => self::HELPER, 'sharePct' => 100]]
		);

		$roomTask = $this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0];
		self::assertSame([1, self::OWNER, self::HELPER], [(int)$roomTask['was_claimed'], $roomTask['original_assignee'], $roomTask['claimed_by_user']]);
		$receipts = $this->takeoverReceipts($f['houseId']);
		self::assertCount(1, $receipts, 'the assignee is told once, with the digest');
		self::assertSame([(int)$owner->getId(), (string)$result['completionId']], [(int)$receipts[0]['house_user_id'], $receipts[0]['object_id']]);
	}

	public function testLateWorkRecordedForItsAssignee_IsNoTakeover(): void {
		$f = $this->fixture([], $this->day(-3));

		$result = $this->completeOccurrence($this->completionService, $f['houseId'], self::HELPER, $f['roomTaskId']);

		self::assertSame(CompletedTask::CLASS_LATE, $this->rows('chorestrike_completed_tasks', 'id', $result['completionId'])[0]['completion_class']);
		self::assertSame(0, (int)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['was_claimed']);
		self::assertSame([], $this->takeoverReceipts($f['houseId']));
	}

	/**
	 * Two members record the same cycle at the same moment, each with a
	 * command of their own: exactly one completion closes it (D-091-08).
	 */
	public function testConcurrentCompletionsOfOneCycle_CloseItOnce(): void {
		$f = $this->fixture();
		$block = $this->block($f);
		$base = sys_get_temp_dir() . '/cs-cycle-complete-' . bin2hex(random_bytes(6));
		$script = $base . '.php';
		file_put_contents($script, <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

[, $houseId, $userId, $roomTaskId, $commandId, $cycleKey, $version, $bootedFile, $goFile] = $argv;
$service = \OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class);
file_put_contents($bootedFile, 'booted');
$deadline = microtime(true) + 30.0;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'go signal never arrived');
        exit(1);
    }
    usleep(2000);
}
try {
    $result = $service->complete((int)$houseId, $userId, (int)$roomTaskId, $commandId, $cycleKey, (int)$version);
    echo json_encode(['completionId' => $result['completionId']]);
} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
    echo json_encode(['conflict' => $e->getReason()]);
}
PHP);

		$workers = [];
		$outcomes = [];
		try {
			foreach ([1 => self::OWNER, 2 => self::HELPER] as $n => $userId) {
				$process = proc_open(
					[PHP_BINARY, $script, (string)$f['houseId'], $userId, (string)$f['roomTaskId'], $this->newCommandId(), $block['cycleKey'], (string)$block['expectedVersion'], $base . ".booted$n", $base . '.go'],
					[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
					$pipes
				);
				self::assertIsResource($process, 'failed to start completion worker ' . $n);
				$workers[$n] = [$process, $pipes];
			}
			$deadline = microtime(true) + 30.0;
			while (!file_exists($base . '.booted1') || !file_exists($base . '.booted2')) {
				self::assertLessThan($deadline, microtime(true), 'the completion workers did not boot in time');
				usleep(20000);
			}
			file_put_contents($base . '.go', 'go');

			foreach ($workers as $n => [$process, $pipes]) {
				$stdout = stream_get_contents($pipes[1]);
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				self::assertSame(0, proc_close($process), "completion worker $n failed: $stderr");
				$outcomes[] = json_decode((string)$stdout, true);
			}
		} finally {
			foreach (glob($base . '*') ?: [] as $file) {
				@unlink($file);
			}
		}

		$won = array_values(array_filter($outcomes, static fn (?array $outcome): bool => isset($outcome['completionId'])));
		$lost = array_values(array_filter($outcomes, static fn (?array $outcome): bool => isset($outcome['conflict'])));
		self::assertCount(1, $won, 'exactly one of the two commands completed the cycle');
		self::assertCount(1, $lost);
		self::assertContains($lost[0]['conflict'], ['ALREADY_COMPLETED', 'STALE_TARGET'], 'the other one is told that the cycle is closed');

		self::assertCount(1, $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
		self::assertCount(1, $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));
		// The default split credits the assignee, whoever recorded it: paid once.
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::OWNER) + $this->bookedMinor($f['houseId'], self::HELPER));
	}
}
