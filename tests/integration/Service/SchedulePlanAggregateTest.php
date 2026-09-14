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
			$preview['previewVersion']
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
	}

	public function testApply_ConsumedPreview_RetryConflictsWithoutWrites(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion']);
		$afterFirst = $this->roomTaskRow($roomTaskId);

		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion']);
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
			$preview['previewVersion']
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
			$s->get(TransactionExecutor::class),
			$s->get(CalendarService::class),
			$s->get(TaskAccessPolicy::class),
			$s->get(IFactory::class)->get('chorestrike'),
		);

		try {
			$service->apply(
				$houseId,
				$this->ownerActor($houseId),
				$this->todayKey,
				[$first, $second],
				[],
				$preview['previewVersion']
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
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter($startKey . ' 00:00:00'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$preview = $this->service->preview($f['houseId'], $this->ownerActor($f['houseId']), $startKey, [$roomTaskId], []);
		self::assertCount(1, $preview['changes']);
		return ['houseId' => $f['houseId'], 'roomTaskId' => $roomTaskId, 'preview' => $preview];
	}

	private function assertStaleApplyConflictsWithoutWrites(int $houseId, int $roomTaskId, array $preview, ?string $startKey = null): void {
		$before = $this->roomTaskRow($roomTaskId);
		$assigneesBefore = $this->assigneeRows($roomTaskId);

		try {
			$this->service->apply($houseId, $this->ownerActor($houseId), $startKey ?? $this->todayKey, [$roomTaskId], [], $preview['previewVersion']);
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
		\OC::$server->get(RoomTaskMapper::class)->updateNextDue(
			$f['roomTaskId'],
			(new \DateTime($this->todayKey))->modify('-1 day')
		);

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
		$this->houseService->updateUserRole(
			(int)\OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], 'mom')->getId(),
			'child',
			'dad'
		);

		$this->assertStaleApplyConflictsWithoutWrites($f['houseId'], $f['roomTaskId'], $f['preview']);
	}

	public function testApply_StalePreferenceInput_RejectsWithoutWrites(): void {
		$f = $this->previewWithMemberBudget();
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
		$this->completionService->complete($f['houseId'], 'dad', $f['roomTaskId']);
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
		$this->service->apply($houseId, $member, $this->todayKey, [$roomTaskId], [], 'anything');
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
		$this->service->apply($f['houseId'], $foreign, $this->todayKey, [1], [], 'anything');
	}

	// -----------------------------------------------------------------
	// ADR-011 undo interactions (decision D8)
	// -----------------------------------------------------------------

	public function testOrdering_PreviewCompleteUndo_StaleApplyRejected(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->completionService->complete($houseId, 'dad', $roomTaskId);
		$this->cleanupLedgerRoomTaskIds[] = $roomTaskId;
		$this->completionService->undo($houseId, $this->ownerActor($houseId), $roomTaskId);

		$this->assertStaleApplyConflictsWithoutWrites($houseId, $roomTaskId, $preview);
	}

	public function testOrdering_ApplyCompleteUndo_RestoresAppliedOccurrence(): void {
		['houseId' => $houseId, 'makeTask' => $makeTask] = $this->familyHouse();
		$roomTaskId = $makeTask('Dishes', 2);

		$preview = $this->service->preview($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], []);
		$this->service->apply($houseId, $this->ownerActor($houseId), $this->todayKey, [$roomTaskId], [], $preview['previewVersion']);
		$appliedDue = $this->roomTaskRow($roomTaskId)['next_due'];

		$this->completionService->complete($houseId, 'dad', $roomTaskId);
		$this->cleanupLedgerRoomTaskIds[] = $roomTaskId;
		$afterComplete = $this->roomTaskRow($roomTaskId)['next_due'];
		self::assertNotSame($appliedDue, $afterComplete, 'the completion must advance the recurrence');

		$this->completionService->undo($houseId, $this->ownerActor($houseId), $roomTaskId);
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
		$this->completionService->complete($houseId, 'dad', $roomTaskId);
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
		], $preview['previewVersion']);
		self::assertSame(
			$appliedDate,
			substr((string)$this->roomTaskRow($roomTaskId)['next_due'], 0, 10)
		);

		// 3. The undo restores the LEDGER's recorded pre-completion state —
		// it wins over the applied move (decision D8, ADR-011); the applied
		// assignment survives because assignments are not ledger state.
		$this->completionService->undo($houseId, $this->ownerActor($houseId), $roomTaskId);
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
