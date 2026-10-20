<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\L10N\IFactory;

/**
 * Real-DB aggregate proofs for the scheduling preview/apply pair
 * (CS-PLAN-065 §4.1): preview purity, exact applied state, all-or-nothing
 * rollback, stale-input rejection without writes, no-op/retry behavior,
 * the authorization matrix and the ADR-011 undo interactions.
 */
class SchedulePlanAggregateTest extends IntegrationTestCase {
	private SchedulePlanService $service;
	private CompletionService $completionService;
	private HouseService $houseService;
	private string $todayKey;

	/** WP2 §3.5: distinct command UUIDs per apply receipt. */
	private static int $planCommandSeq = 0;

	private static function planCommandUuid(): string {
		return sprintf('a1b2c3d4-0000-4000-8000-%012d', ++self::$planCommandSeq);
	}

	/** @var array<int> Ledger rows created through the completion service. */
	private array $cleanupLedgerRoomTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->service = \OC::$server->get(SchedulePlanService::class);
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->houseService = \OC::$server->get(HouseService::class);
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	protected function tearDown(): void {
		// The injected evaluation clock must never leak across tests.
		$this->service->setClock(null);

		foreach ($this->cleanupLedgerRoomTaskIds as $roomTaskId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$this->cleanupLedgerRoomTaskIds = [];

		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// RF-073-WP2-21: joint/replacement capacity in the planner
	// -----------------------------------------------------------------

	/**
	 * A same-day REPLACEMENT is tested after removing exactly the row's
	 * old allocation: collapsing a 50/50 joint plan onto dad alone
	 * (100% of the energy) fits his budget although the baseline still
	 * charges his old 50% share — the pre-fix planner double-counted the
	 * replaced load and reported the feasible plan as unplaceable.
	 */
	public function testApply_SameDayReplacement_RemovesOldAllocationBeforeCheck(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Joint dishes', 10);

		// Pin dad's budget to exactly the task energy on every weekday
		// (deterministic against the weekend default of 15).
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode([
				'energyBudget' => array_fill_keys(
					['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
					10
				),
			])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('dad')))
			->executeStatement();

		$actor = $this->ownerActor($houseId);
		$this->bindWallets($houseId, ['dad', 'mom']);
		$roomTaskService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$roomTaskService->setAssignees($roomTaskId, ['dad', 'mom'], $houseId, $actor);

		$preview = $this->service->preview($houseId, $actor, $this->todayKey, [$roomTaskId], [
			$roomTaskId => ['assigneeUserIds' => ['dad']],
		]);

		self::assertCount(1, $preview['changes'], 'the feasible same-day replacement must be a change, never unplaced');
		self::assertEmpty($preview['unplaced']);
		$change = $preview['changes'][0];
		self::assertSame(['dad'], $change['proposed']['assignees']);
		self::assertSame($this->todayKey, $change['proposed']['dueDate'], 'the replacement stays on the same day');
		self::assertSame(['dad' => 100], $change['proposed']['shares'], 'the exact share vector rides the proposal');

		$result = $this->service->apply($houseId, $actor, $this->todayKey, [$roomTaskId], [
			$roomTaskId => ['assigneeUserIds' => ['dad']],
		], $preview['previewVersion'], self::planCommandUuid(), true);
		self::assertSame(1, $result['appliedCount']);
		self::assertSame(['dad'], array_column($this->assigneeRows($roomTaskId), 'user_id'));
	}

	/**
	 * A preserved/overridden JOINT candidate set is validated as the FULL
	 * proposed share vector — no successful placement based on only one
	 * member: the child's daily limit rejects the whole arrangement
	 * (NO_CAPACITY) even though the co-member fits easily.
	 */
	public function testPreview_JointSet_FailsWhenAnyMemberDoesNotFit(): void {
		['houseId' => $houseId, 'roomId' => $roomId, 'makeTask' => $makeTask] = $this->familyHouse();
		$this->addHouseMember($houseId, 'kid', 'Kid', 'child');
		$this->bindWallets($houseId, ['kid']);

		$actor = $this->ownerActor($houseId);
		$this->bindWallets($houseId, ['dad', 'mom']);
		$roomTaskService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$tomorrowKey = (new \DateTime($this->todayKey))->modify('+1 day')->format('Y-m-d');

		// The child's only daily slot is consumed tomorrow by its own task.
		$kidTask = $this->assignTaskToRoom($this->createTask($houseId, 'Kid own task', '', 5, 2), $roomId, null, $tomorrowKey . ' 00:00:00');
		$roomTaskService->setAssignees($kidTask, ['kid'], $houseId, $actor);

		// The joint plan (mom + kid, 50/50 of a small energy) gets a
		// ±1-day flex window so tomorrow is a legal placement candidate.
		$jointTaskDef = $this->createTask($houseId, 'Joint task', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('flex_days', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($jointTaskDef, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$jointTask = $this->assignTaskToRoom($jointTaskDef, $roomId, null);
		$roomTaskService->setAssignees($jointTask, ['mom', 'kid'], $houseId, $actor);

		// Tighten the child's limit AFTER the assignments exist (a legal
		// owner preference change): moving the joint plan onto the child's
		// full day must now fail for the WHOLE set, even though mom fits.
		$kidId = $this->findHouseUserIdForPlan($houseId, 'kid');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode(['maxDailyTasks' => 1])))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($kidId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$preview = $this->service->preview($houseId, $actor, $this->todayKey, [$jointTask], [
			$jointTask => ['date' => $tomorrowKey],
		]);

		self::assertSame([], $preview['changes'], 'a joint set is never placed on the strength of one fitting member');
		self::assertCount(1, $preview['unplaced']);
		self::assertSame($jointTask, $preview['unplaced'][0]['roomTaskId']);
		self::assertSame('NO_CAPACITY', $preview['unplaced'][0]['reason']);
	}

	private function findHouseUserIdForPlan(int $houseId, string $ncUserId): int {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ncUserId)->getId();
	}

	/**
	 * Production parity: bind the persistent WP0 wallet of each listed
	 * member (the agreement defaults and the capacity evaluator resolve
	 * per wallet).
	 *
	 * @param list<string> $userIds
	 */
	private function bindWallets(int $houseId, array $userIds): void {
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		foreach ($userIds as $userId) {
			$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}
	}

	// -----------------------------------------------------------------
	// CR-SVC-64: the local-midnight crossing (RF-073-WP2-11)
	// -----------------------------------------------------------------

	/**
	 * A valid preview taken BEFORE the house-local midnight crossing must
	 * yield a WRITE-FREE 409 when the apply runs after midnight — the
	 * fingerprint's evaluation day is part of the preview identity, and the
	 * 409 fires BEFORE any relative-to-today rejection. A newly malformed
	 * request stays a 400 (CR-SVC-64, plan §3.5).
	 */
	public function testApply_CrossingLocalMidnight_ConflictsWithoutWrites(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Midnight dishes', 2);

		// 23:50 UTC "yesterday evening" relative to the apply instant.
		$beforeMidnight = new \DateTime($this->todayKey . ' 23:50:00', new \DateTimeZone('UTC'));
		$afterMidnight = (clone $beforeMidnight)->modify('+20 minutes');
		self::assertNotSame(
			$beforeMidnight->format('Y-m-d'),
			$afterMidnight->format('Y-m-d'),
			'the fixture instants must straddle local midnight'
		);

		$this->service->setClock($beforeMidnight);
		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		self::assertNotEmpty($preview['changes'], 'the preview proposes the placement');

		$before = $this->roomTaskRow($roomTaskId);
		$assigneesBefore = $this->assigneeRows($roomTaskId);
		$snapshot = $this->snapshotDomainCounts();

		$this->service->setClock($afterMidnight);
		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
			self::fail('a preview that crossed local midnight must conflict');
		} catch (ConflictException $e) {
			self::assertSame('The schedule preview is no longer current. Generate a new preview.', $e->getMessage());
		}

		self::assertSame($before, $this->roomTaskRow($roomTaskId), 'the midnight conflict wrote nothing');
		self::assertSame($assigneesBefore, $this->assigneeRows($roomTaskId));
		self::assertSame($snapshot, $this->snapshotDomainCounts(), 'no receipt, agreement or occurrence row was persisted');

		// A NEWLY malformed request remains a 400 — the shape validation
		// runs before the fingerprint comparison even with the stale clock.
		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), 'not-a-date', [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
			self::fail('a malformed startDate must stay a validation error');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('startDate', $e->getMessage());
		}

		// With the clock back on the preview's day the SAME preview still
		// applies — the conflict was the day crossing, not a consumed plan.
		$this->service->setClock($beforeMidnight);
		$result = $this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
		self::assertSame(1, $result['appliedCount']);
	}

	// -----------------------------------------------------------------
	// Fixture
	// -----------------------------------------------------------------

	/**
	 * A house with the owner `dad` and one member; returns the house and
	 * member rows plus a factory for unassigned room tasks due today.
	 *
	 * @return array{houseId: int, roomId: int, makeTask: callable(string, int): int}
	 */
	private function familyHouse(): array {
		$houseId = $this->createHouse('dad', 'Plan House');
		$this->addHouseMember($houseId, 'mom', 'Mom', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$makeTask = function (string $name, int $energyCost = 2) use ($houseId, $roomId): int {
			$taskId = $this->createTask($houseId, $name, '', 5, $energyCost);
			return $this->assignTaskToRoom($taskId, $roomId, null);
		};

		return ['houseId' => $houseId, 'roomId' => $roomId, 'makeTask' => $makeTask];
	}

	private function ownerActor(int $houseId): HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'dad');
	}

	private function roomTaskRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function assigneeRows(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	// -----------------------------------------------------------------
	// Preview purity and exact apply
	// -----------------------------------------------------------------

	public function testPreview_LeavesAllDomainTablesUnchanged(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$snapshot = $this->snapshotDomainCounts();
		$this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);

		self::assertSame($snapshot, $this->snapshotDomainCounts(), 'a preview must not write any domain table');
		self::assertNull($this->roomTaskRow($roomTaskId)['claimed_by_user']);
		self::assertSame([], $this->assigneeRows($roomTaskId));
	}

	public function testApply_UnassignedTaskDueToday_ExactAssignmentAndDate(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);
		$before = $this->roomTaskRow($roomTaskId);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		self::assertCount(1, $preview['changes'], 'the unassigned due-today task must get an assignee proposal');

		$result = $this->service->apply(
			$houseId,
			$this->ownerActor($houseId),
			$this->todayKey,
			[$roomTaskId],
			[],
			$preview['previewVersion'],
			self::planCommandUuid(),
			true
		);

		self::assertSame(1, $result['appliedCount']);
		$row = $this->roomTaskRow($roomTaskId);
		// The stored day equals the proposed house-local day (UTC midnight
		// storage convention); unrelated fields are unchanged.
		self::assertSame(
			$preview['changes'][0]['proposed']['dueDate'],
			substr((string)$row['next_due'], 0, 10)
		);
		self::assertSame($before['is_active'], $row['is_active']);
		self::assertSame($before['created_at'], $row['created_at']);
		$assignees = array_map(static fn (array $r): string => (string)$r['user_id'], $this->assigneeRows($roomTaskId));
		sort($assignees);
		self::assertSame($preview['changes'][0]['proposed']['assignees'], $assignees);
		self::assertSame($assignees, $result['roomTasks'][0]['assignees']);
		// CR-084-03: the response row reports the same status as the list.
		$readService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$fresh = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);
		self::assertSame(
			$readService->selectedRoomTaskPayload($houseId, $fresh, $readService->timeZoneForHouse($houseId))['status'],
			$result['roomTasks'][0]['status']
		);
	}

	/**
	 * CR-PERF-01: both write phases take each row's proposal from the plan
	 * by room-task id. Three rows with three different explicit assignee
	 * sets would swap or collapse their proposals if the lookup picked the
	 * wrong change.
	 */
	public function testApply_SeveralChangedRows_EachRowGetsItsOwnProposal(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$toMom = $makeTask('To Mom', 2);
		$toDad = $makeTask('To Dad', 2);
		$toBoth = $makeTask('To Both', 2);
		$roomTaskIds = [$toBoth, $toMom, $toDad];
		$overrides = [
			$toMom => ['assigneeUserIds' => ['mom']],
			$toDad => ['assigneeUserIds' => ['dad']],
			$toBoth => ['assigneeUserIds' => ['dad', 'mom']],
		];

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, $roomTaskIds, $overrides);
		self::assertCount(3, $preview['changes']);

		$result = $this->service->apply(
			$houseId,
			$this->ownerActor($houseId),
			$this->todayKey,
			$roomTaskIds,
			$overrides,
			$preview['previewVersion'],
			self::planCommandUuid(),
			true
		);

		self::assertSame(3, $result['appliedCount']);
		$expected = [$toMom => ['mom'], $toDad => ['dad'], $toBoth => ['dad', 'mom']];
		foreach ($preview['changes'] as $change) {
			$roomTaskId = (int)$change['roomTaskId'];
			$assignees = array_map(static fn (array $r): string => (string)$r['user_id'], $this->assigneeRows($roomTaskId));
			sort($assignees);
			self::assertSame($expected[$roomTaskId], $assignees, 'assignees of room task ' . $roomTaskId);
			self::assertSame($change['proposed']['assignees'], $assignees);
			self::assertSame(
				$change['proposed']['dueDate'],
				substr((string)$this->roomTaskRow($roomTaskId)['next_due'], 0, 10),
				'due date of room task ' . $roomTaskId
			);
		}
	}

	public function testApply_ConsumedPreview_RetryConflictsWithoutWrites(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
		$afterFirst = $this->roomTaskRow($roomTaskId);

		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
			self::fail('a consumed preview must conflict on retry');
		} catch (ConflictException $e) {
			self::assertSame('The schedule preview is no longer current. Generate a new preview.', $e->getMessage());
		}

		self::assertSame($afterFirst, $this->roomTaskRow($roomTaskId), 'the conflicted retry must not write');
	}

	public function testApply_NoChangePlan_PerformsZeroWrites(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		// Assign the row first so the plan is an UP_TO_DATE no-change plan.
		$assignees = \OC::$server->get(TaskAssigneeMapper::class);
		$assignees->addAssignee($roomTaskId, 'dad', 'dad');

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		self::assertSame([], $preview['changes']);
		self::assertSame('UP_TO_DATE', $preview['unchanged'][0]['reason']);
		$before = $this->roomTaskRow($roomTaskId);

		$result = $this->service->apply(
			$houseId,
			$this->ownerActor($houseId),
			$this->todayKey,
			[$roomTaskId],
			[],
			$preview['previewVersion'],
			self::planCommandUuid(),
			true
		);

		self::assertSame(0, $result['appliedCount']);
		self::assertSame([], $result['roomTasks']);
		self::assertSame($before, $this->roomTaskRow($roomTaskId), 'the no-change apply must not write');
	}

	// -----------------------------------------------------------------
	// All-or-nothing rollback
	// -----------------------------------------------------------------

	public function testApply_FaultAfterFirstWrite_RollsBackEntireBatch(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$first = $makeTask('First', 2);
		$second = $makeTask('Second', 2);

		$preview = $this->service->preview(
			$houseId,
			$this->ownerActor($houseId),
			$this->todayKey,
			[$first, $second],
			[]
		);
		self::assertCount(2, $preview['changes']);
		$beforeFirst = $this->roomTaskRow($first);
		$beforeSecond = $this->roomTaskRow($second);

		// Sabotage: the assignee-writes tail throws on the SECOND row's
		// delete — by then both due-date writes are already issued, so the
		// rollback must undo them too.
		$s = \OC::$server;
		$sabotaged = new class($s->get(\OCP\IDBConnection::class)) extends TaskAssigneeMapper {
			public int $calls = 0;

			public function deleteByRoomTask(int $roomTaskId): void {
				$this->calls++;
				if ($this->calls >= 2) {
					throw new \RuntimeException('sabotaged assignee write');
				}
				parent::deleteByRoomTask($roomTaskId);
			}
		};

		$service = new SchedulePlanService(
			$s->get(RoomTaskMapper::class),
			$s->get(TaskMapper::class),
			$s->get(RoomMapper::class),
			$s->get(HouseMapper::class),
			$s->get(HouseUserMapper::class),
			$sabotaged,
			$s->get(CompletedTaskMapper::class),
			$s->get(\OCA\ChoreStrike\Service\OccurrenceService::class),
			$s->get(\OCA\ChoreStrike\Service\CommandService::class),
			$s->get(\OCA\ChoreStrike\Service\DailyCapacityService::class),
			$s->get(\OCA\ChoreStrike\Service\PauseProjectionService::class),
			$s->get(TransactionExecutor::class),
			$s->get(CalendarService::class),
			$s->get(TaskAccessPolicy::class),
			$s->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class),
			$s->get(IFactory::class)->get('chorestrike'),
		);

		try {
			$service->apply(
				$houseId,
				$this->ownerActor($houseId),
				$this->todayKey,
				[$first, $second],
				[],
				$preview['previewVersion'],
				self::planCommandUuid(),
				true
			);
			self::fail('the sabotaged apply must fail');
		} catch (\RuntimeException $e) {
			self::assertSame('sabotaged assignee write', $e->getMessage());
		}

		self::assertSame($beforeFirst, $this->roomTaskRow($first), 'the first due-date write must be rolled back');
		self::assertSame($beforeSecond, $this->roomTaskRow($second));
		self::assertSame([], $this->assigneeRows($first), 'no partial assignee rows may survive');
		self::assertSame([], $this->assigneeRows($second));
	}

	// -----------------------------------------------------------------
	// Stale-input rejection (no writes)
	// -----------------------------------------------------------------

	/**
	 * @return array{houseId: int, roomTaskId: int, preview: array}
	 */
	private function previewWithMemberBudget(): array {
		return $this->previewWithMemberBudgetAt($this->todayKey);
	}

	/**
	 * Preview with exactly one unassigned-change proposal for a plan whose
	 * window starts at $startKey — the room task is seeded due that day so
	 * the proposal is inside the window (BUG-E2E-006 fixture variant).
	 */
	private function previewWithMemberBudgetAt(string $startKey): array {
		$f = $this->familyHouse();
		$roomTaskId = $f['makeTask']('Dishes', 2);
		// CS-PLAN-072 WP1: the due-date move goes through the agreement
		// writer — a raw next_due update would diverge from the occurrence
		// timeline (the legal-slot anchor and the projection are derived
		// from the agreed occurrence, not the raw column).
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->updateDueDate($roomTaskId, $startKey, $this->ownerActor($f['houseId']));
		$preview = $this->service->preview($f['houseId'], $this->ownerActor($f['houseId']), $startKey, [$roomTaskId], []);
		self::assertCount(1, $preview['changes']);
		return ['houseId' => $f['houseId'], 'roomTaskId' => $roomTaskId, 'preview' => $preview];
	}

	private function assertStaleApplyConflictsWithoutWrites(int $houseId, int $roomTaskId, array $preview, ?string $startKey = null): void {
		$before = $this->roomTaskRow($roomTaskId);
		$assigneesBefore = $this->assigneeRows($roomTaskId);

		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), $startKey ?? $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
			self::fail('a stale preview input must reject the batch');
		} catch (ConflictException $e) {
			self::assertSame('The schedule preview is no longer current. Generate a new preview.', $e->getMessage());
		}

		self::assertSame($before, $this->roomTaskRow($roomTaskId));
		self::assertSame($assigneesBefore, $this->assigneeRows($roomTaskId));
	}

	public function testApply_StaleAssignmentInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		\OC::$server->get(TaskAssigneeMapper::class)->addAssignee($f['roomTaskId'], 'mom', 'dad');

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	public function testApply_StaleDateInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		// Simulate a concurrent date move with a raw row update (the
		// planner fingerprint reads the projection; the agreement writer
		// itself is covered by the occurrence timeline suites).
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter((new \DateTime($this->todayKey))->modify('-1 day')->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['roomTaskId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	public function testApply_StaleDefinitionInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		$row = $this->roomTaskRow($f['roomTaskId']);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('energy_cost', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['task_id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	public function testApply_StaleMemberRoleInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		// CS-PLAN-081 H3: the role mutation is owner-gated service-side —
		// act as the owner BEFORE the direct service call (the actor
		// identity now comes from the session, not a parameter).
		$this->actingAs('dad');
		$this->houseService->updateUserRole(
			(int)\OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], 'mom')->getId(),
			'child'
		);

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	public function testApply_StalePreferenceInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		// RF-058-05: the member-availability owner path gates service-side —
		// act as the house owner ('dad' owns the plan house).
		$this->actingAs('dad');
		$scheduling = \OC::$server->get(\OCA\ChoreStrike\Service\SchedulingService::class);
		$scheduling->updateMemberAvailabilityByNextcloudUserId($f['houseId'], 'mom', [
			'energyBudget' => ['monday' => 3, 'tuesday' => 3, 'wednesday' => 3, 'thursday' => 3, 'friday' => 3, 'saturday' => 3, 'sunday' => 3],
		]);

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	/**
	 * BUG-E2E-006 (CS-PLAN-068 §3.4): the stale-timezone apply must conflict
	 * on the fingerprint — never fail earlier on house-local "today"
	 * validation. The request start date therefore stays one UTC day ahead:
	 * a Europe/Berlin house-local "today" is at most UTC today + 1, so the
	 * date is legal in BOTH the pre- and post-change timezone at any wall
	 * clock, including the late-UTC-day transition where Berlin is already
	 * tomorrow, and in both transition directions (west-of-UTC likewise).
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function timezoneTransitions(): array {
		return [
			// Original defect: east of UTC (late UTC day → Berlin is tomorrow).
			'UTC to Europe/Berlin' => ['Europe/Berlin'],
			// Opposite direction: west of UTC (house-local today falls behind).
			'UTC to America/Los_Angeles' => ['America/Los_Angeles'],
		];
	}

	/**
	 * A start date that stays "today or later" under every compared
	 * timezone around the transition: UTC tomorrow is never before any
	 * UTC-offset house-local today (BUG-E2E-006 fixture, CS-PLAN-068 §3.4).
	 * Computed from the UTC clock only — pinned at midnight boundaries
	 * because the +1 day roll uses the UTC calendar day, not wall time.
	 */
	private static function dualTimezoneStartKey(\DateTimeImmutable $now): string {
		return $now->setTimezone(new \DateTimeZone('UTC'))->modify('+1 day')->format('Y-m-d');
	}

	/**
	 * @dataProvider timezoneTransitions
	 */
	public function testApply_StaleTimezoneInput_RejectsWithoutWrites(string $newTimezone): void {
		$startKey = self::dualTimezoneStartKey(new \DateTimeImmutable('now'));
		$f = $this->previewWithMemberBudgetAt($startKey);
		// HouseService::updateHouse authorizes the SESSION user — restore
		// the owner session (member fixtures switched it to mom).
		$this->actingAs('dad');
		$this->houseService->updateHouse($f['houseId'], 'Plan House', $newTimezone);

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview'], $startKey);
	}

	/** @dataProvider clockBoundaries */
	public function testFixtureStart_MidnightAndDst_RemainsValidInBothTimezones(string $instant, string $timezone): void {
		$now = new \DateTimeImmutable($instant);
		$key = self::dualTimezoneStartKey($now);
		foreach (['UTC', $timezone] as $zone) {
			self::assertGreaterThanOrEqual($now->setTimezone(new \DateTimeZone($zone))->format('Y-m-d'), $key);
		}
		self::assertSame($key, self::dualTimezoneStartKey($now));
	}

	public static function clockBoundaries(): array {
		return [
			['2026-09-14T00:00:00Z', 'Pacific/Kiritimati'],
			['2026-09-14T23:59:59Z', 'Pacific/Pago_Pago'],
			['2026-03-29T00:59:59Z', 'Europe/Berlin'],
			['2026-03-29T01:00:00Z', 'Europe/Berlin'],
			['2026-10-25T00:59:59Z', 'Europe/Berlin'],
			['2026-10-25T01:00:00Z', 'Europe/Berlin'],
		];
	}

	public function testApply_StaleCompletionInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
		// A completion of the SELECTED task changes its row and the
		// capacity-source rows (ledger).
		$this->completeOccurrence($this->completionService, $f['houseId'], 'dad', $f['roomTaskId']);
		$this->cleanupLedgerRoomTaskIds[] = $f['roomTaskId'];

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	// -----------------------------------------------------------------
	// Authorization matrix
	// -----------------------------------------------------------------

	public function testApply_MemberActor_Rejected(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'mom');
		$this->expectException(ForbiddenException::class);
		$this->service->apply($houseId, $member, $this->todayKey, [$roomTaskId], [], 'anything', self::planCommandUuid(), true);
	}

	public function testPreview_MemberActor_Rejected(): void {
		['houseId' => $houseId] = $this->familyHouse();

		$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'mom');
		$this->expectException(ForbiddenException::class);
		$this->service->preview($houseId, $member, $this->todayKey, [1], []);
	}

	public function testPreview_ChildActor_Rejected(): void {
		['houseId' => $houseId] = $this->familyHouse();
		$this->addHouseMember($houseId, 'kid', 'Kid', 'child');

		$child = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'kid');
		$this->expectException(ForbiddenException::class);
		$this->service->preview($houseId, $child, $this->todayKey, [1], []);
	}

	public function testApply_ForeignHouseActor_Rejected(): void {
		$f = $this->familyHouse();
		$otherHouseId = $this->createHouse('other_owner', 'Other House');

		$foreign = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($otherHouseId, 'other_owner');
		$this->expectException(ForbiddenException::class);
		$this->service->apply($f['houseId'], $foreign, $this->todayKey, [1], [], 'anything', self::planCommandUuid(), true);
	}

	// -----------------------------------------------------------------
	// ADR-011 undo interactions (decision D8)
	// -----------------------------------------------------------------

	public function testOrdering_PreviewCompleteUndo_StaleApplyRejected(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->completeOccurrence($this->completionService, $houseId, 'dad', $roomTaskId);
		$this->cleanupLedgerRoomTaskIds[] = $roomTaskId;
		$this->undoLatestForRoomTask($this->completionService, $houseId, 'dad', $roomTaskId);

		$this->assertStaleApplyConflictsWithoutWrites($houseId, $roomTaskId, $preview);
	}

	public function testOrdering_ApplyCompleteUndo_RestoresAppliedOccurrence(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion'], self::planCommandUuid(), true);
		$appliedDue = $this->roomTaskRow($roomTaskId)['next_due'];

		$this->completeOccurrence($this->completionService, $houseId, 'dad', $roomTaskId);
		$this->cleanupLedgerRoomTaskIds[] = $roomTaskId;
		$afterComplete = $this->roomTaskRow($roomTaskId)['next_due'];
		self::assertNotSame($appliedDue, $afterComplete, 'the completion must advance the recurrence');

		$this->undoLatestForRoomTask($this->completionService, $houseId, 'dad', $roomTaskId);
		self::assertSame(
			$appliedDue,
			$this->roomTaskRow($roomTaskId)['next_due'],
			'the undo must restore the recorded applied occurrence (ADR-011)'
		);
	}

	public function testOrdering_CompleteApplyUndo_UndoRestoresPreCompletionStateOverAppliedMove(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);
		// Flex room so the owner override below has a second legal day.
		$row = $this->roomTaskRow($roomTaskId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('flex_days', $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['task_id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$beforeComplete = $this->roomTaskRow($roomTaskId)['next_due'];

		// 1. Complete: the ledger records the pre-completion state, the row
		// is assigned to the completer and the recurrence advances.
		$this->completeOccurrence($this->completionService, $houseId, 'dad', $roomTaskId);
		$this->cleanupLedgerRoomTaskIds[] = $roomTaskId;
		$afterComplete = $this->roomTaskRow($roomTaskId)['next_due'];

		// 2. The owner overrides the advanced occurrence onto another legal
		// day inside the flex window and applies.
		$appliedDate = (new \DateTime($this->todayKey))->modify('+2 days')->format('Y-m-d');
		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [
			$roomTaskId => ['date' => $appliedDate],
		]);
		self::assertCount(1, $preview['changes'], 'the override within the flex window must produce a proposal');
		$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [
			$roomTaskId => ['date' => $appliedDate],
		], $preview['previewVersion'], self::planCommandUuid(), true);
		self::assertSame(
			$appliedDate,
			substr((string)$this->roomTaskRow($roomTaskId)['next_due'], 0, 10)
		);

		// 3. The undo restores the LEDGER's recorded pre-completion state —
		// it wins over the applied move (decision D8, ADR-011); the applied
		// assignment survives because assignments are not ledger state.
		$this->undoLatestForRoomTask($this->completionService, $houseId, 'dad', $roomTaskId);
		self::assertSame($beforeComplete, $this->roomTaskRow($roomTaskId)['next_due']);
		$assignees = array_map(static fn (array $r): string => (string)$r['user_id'], $this->assigneeRows($roomTaskId));
		self::assertSame(['dad'], $assignees);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Row counts of every domain table a preview could conceivably touch.
	 *
	 * @return array<string, int>
	 */
	private function snapshotDomainCounts(): array {
		$tables = [
			'chorestrike_room_tasks',
			'chorestrike_task_assignees',
			'chorestrike_house_users',
			'chorestrike_tasks',
			'chorestrike_completed_tasks',
			'chorestrike_houses',
		];
		$counts = [];
		foreach ($tables as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))->from($table);
			$result = $qb->executeQuery();
			$counts[$table] = (int)$result->fetchOne();
			$result->closeCursor();
		}
		return $counts;
	}
}
