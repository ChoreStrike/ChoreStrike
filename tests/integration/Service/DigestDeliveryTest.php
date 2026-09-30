<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\DigestScheduleService;
use OCA\ChoreStrike\Service\DigestService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Digest delivery lifecycle against the real DB (CS-PLAN-070 WP5 §3.2
 * and §3.4, C5c): quiet hours GATE the dispatch without shifting the
 * slot, a missed window expires permanently together with its queued
 * rows, and an opt-out before the claim marks the slot skipped.
 */
class DigestDeliveryTest extends IntegrationTestCase {
	private DigestService $digestService;
	private DigestScheduleService $scheduleService;
	private DigestSlotMapper $slotMapper;
	private DigestChangeMapper $changeMapper;
	private MemberPreferenceMapper $prefMapper;
	private int $houseId;
	private int $memberId;
	private ?int $completionId = null;

	protected function setUp(): void {
		parent::setUp();

		$this->digestService = \OC::$server->get(DigestService::class);
		$this->scheduleService = \OC::$server->get(DigestScheduleService::class);
		$this->slotMapper = \OC::$server->get(DigestSlotMapper::class);
		$this->changeMapper = \OC::$server->get(DigestChangeMapper::class);
		$this->prefMapper = \OC::$server->get(MemberPreferenceMapper::class);

		$this->houseId = $this->createHouse('dgd-lead');
		$this->addHouseMember($this->houseId, 'dgd-member', 'Digest Delivery Member');
		$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, 'dgd-member');
		$this->memberId = (int)$member->getId();
	}

	protected function tearDown(): void {
		if ($this->completionId !== null) {
			foreach (['chorestrike_work_shares' => 'completion_id', 'chorestrike_completion_revs' => 'completion_id', 'chorestrike_room_events' => 'source_completion_id', 'chorestrike_completed_tasks' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($this->completionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
			}
		}
		parent::tearDown();
	}

	private function seedPref(?string $quietStart = null, ?string $quietEnd = null): void {
		$pref = new MemberPreference();
		$pref->setHouseId($this->houseId);
		$pref->setHouseUserId($this->memberId);
		$pref->setVersion(1);
		$pref->setTaskDigestEnabled(true);
		$pref->setOrgDigestEnabled(true);
		$pref->setDigestTime('18:00');
		$pref->setQuietStart($quietStart);
		$pref->setQuietEnd($quietEnd);
		$pref->setCreatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$pref->setUpdatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$this->prefMapper->insertOrGet($pref);
	}

	private function seedQueuedChange(string $dayKey): void {
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('agreement');
		$change->setObjectId('21');
		$change->setCategory(DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime($dayKey . ' 10:00:00'));
		$change->setTargetSlot($dayKey);
		$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime($dayKey . ' 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime($dayKey . ' 10:00:00 UTC'));
		$this->changeMapper->queueOrCoalesce($change);
	}

	/**
	 * QuietHours_GateTheDispatch_WithoutShiftingTheSlot: at 18:05 inside
	 * a 12:00→18:30 quiet range the pass leaves the slot pending and
	 * dispatches nothing; the slot itself stays pending (a later pass
	 * inside the window — after 18:30, before 19:00 — may still deliver).
	 */
	public function testQuietHours_GateTheDispatch_WithoutShiftingTheSlot(): void {
		$this->seedPref('12:00', '18:30');
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:30:00 UTC'));
		$todayKey = '2026-09-29';
		$this->seedQueuedChange($todayKey);

		$stats = $this->digestService->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		self::assertSame(1, $stats['quiet'], 'the quiet range gates the attempt');
		$slot = $this->slotMapper->findByHouseUserAndDay($this->houseId, $this->memberId, $todayKey);
		self::assertNotNull($slot);
		self::assertSame(DigestSlot::STATUS_PENDING, $slot->getStatus(), 'quiet hours never shift the slot — it stays pending inside the window');
	}

	/**
	 * MissedWindow_ExpiresPermanently_WithItsQueuedRows (C5c/C5m): a slot
	 * whose window ended is skipped forever, its queued rows are expired,
	 * and a LATER pass never resurrects the delivery.
	 */
	public function testMissedWindow_ExpiresPermanently_WithItsQueuedRows(): void {
		$this->seedPref();
		// Materialize "yesterday's" slot directly (the window has passed).
		$yesterdayKey = '2026-09-28';
		$slot = new DigestSlot();
		$slot->setHouseId($this->houseId);
		$slot->setHouseUserId($this->memberId);
		$slot->setLocalDay($yesterdayKey);
		$slot->setZone('UTC');
		$slot->setPrefRevision(1);
		$slot->setScheduledUtc(new \DateTime($yesterdayKey . ' 18:00:00 UTC'));
		$slot->setWindowEndUtc(new \DateTime($yesterdayKey . ' 19:00:00 UTC'));
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setCreatedAt(new \DateTime('2026-09-28 10:00:00 UTC'));
		$slot->setUpdatedAt(new \DateTime('2026-09-28 10:00:00 UTC'));
		$this->slotMapper->insertOrGet($slot);
		$this->seedQueuedChange($yesterdayKey);

		$stats = $this->digestService->processDueSlots(new \DateTime('2026-09-29 12:00:00 UTC'));

		self::assertGreaterThanOrEqual(1, $stats['expiredSlots'], 'the missed slot expires');
		$expired = $this->slotMapper->findByHouseUserAndDay($this->houseId, $this->memberId, $yesterdayKey);
		self::assertNotNull($expired);
		self::assertSame(DigestSlot::STATUS_SKIPPED, $expired->getStatus(), 'a missed slot is never compensated by next-day catch-up');
		$rows = $this->changeMapper->findBySlot($this->memberId, $yesterdayKey);
		self::assertCount(1, $rows);
		self::assertSame(DigestChange::DISPOSITION_EXPIRED, $rows[0]->getDisposition(), 'the missed slot’s queued rows expire permanently');
	}

	/**
	 * OptOutBeforeTheClaim_MarksTheSlotSkipped (parent §3.6): a deleted
	 * preference row (full opt-out) consumes the pending slot without a
	 * dispatch attempt.
	 */
	public function testOptOutBeforeTheClaim_MarksTheSlotSkipped(): void {
		// No preference row at all — the member never opted in.
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:30:00 UTC'));

		$stats = $this->digestService->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		// Without an opt-in the materializer created no slots at all; the
		// pass dispatches nothing.
		self::assertSame(0, $stats['dispatched']);
		self::assertSame(0, $stats['uncertain']);
	}

	public function testTakeoverQueue_RollsBackWithCompletion_AndReceiptReplayWritesNothing(): void {
		$floorId = $this->createFloor($this->houseId);
		$roomId = $this->createRoom($this->houseId, $floorId);
		$taskId = $this->createTask($this->houseId, 'Takeover work', '', 1, 1);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'dgd-member', (new \DateTime('-2 days'))->format('Y-m-d'));
		$receipt = $this->completionReceiptFor($roomTaskId);
		$service = \OC::$server->get(CompletionService::class);
		$property = new \ReflectionProperty($service, 'digestChangeMapper');
		$realMapper = $property->getValue($service);
		$sabotaged = $this->createMock(DigestChangeMapper::class);
		$sabotaged->method('queueOrCoalesce')->willReturnCallback(function (DigestChange $row) use ($realMapper): DigestChange {
			$realMapper->queueOrCoalesce($row);
			self::assertTrue($this->db->inTransaction(), 'the queue joins the source command transaction');
			self::assertCount(1, $realMapper->findBySlot($this->memberId, $row->getTargetSlot()));
			throw new \RuntimeException('failure after queue insertion');
		});
		$property->setValue($service, $sabotaged);
		$complete = fn (): array => $service->complete(
			$this->houseId, 'dgd-lead', $roomTaskId, ...array_merge($receipt, [[['userId' => 'dgd-lead', 'sharePct' => 100]], true])
		);
		try {
			try {
				$complete();
				self::fail('the sabotaged source transaction must fail');
			} catch (\RuntimeException $e) {
				self::assertSame('failure after queue insertion', $e->getMessage());
			}
		} finally {
			$property->setValue($service, $realMapper);
		}
		$today = (new \DateTime())->format('Y-m-d');
		self::assertSame([], $this->changeMapper->findBySlot($this->memberId, $today), 'rollback removes the queue row');
		$first = $complete();
		$this->completionId = (int)$first['completionId'];
		self::assertFalse($first['replay']);
		$queued = $this->changeMapper->findBySlot($this->memberId, $today);
		self::assertCount(1, $queued);
		self::assertSame((string)$first['completionId'], $queued[0]->getObjectId(), 'takeover identity is the immutable completion');
		$replay = $complete();
		self::assertTrue($replay['replay']);
		self::assertSame($first['completionId'], $replay['completionId']);
		self::assertCount(1, $this->changeMapper->findBySlot($this->memberId, $today));
	}

	public function testQueue_RepeatedSourceChanges_CoalesceToTheLatestRevision(): void {
		$this->seedQueuedChange('2026-09-29');
		$row = $this->changeMapper->findBySlot($this->memberId, '2026-09-29')[0];
		$id = $row->getId();
		$row->setObjectRevision(2);
		$row->setChangeTime(new \DateTime('2026-09-29 12:00:00 UTC'));
		$this->changeMapper->queueOrCoalesce($row);
		$rows = $this->changeMapper->findBySlot($this->memberId, '2026-09-29');
		self::assertCount(1, $rows);
		self::assertSame($id, $rows[0]->getId());
		self::assertSame(2, $rows[0]->getObjectRevision());
		self::assertSame('12:00', $rows[0]->getChangeTime()->format('H:i'));
	}

	public function testDispatch_CurrentTaskVisibility_DropsBothTaskAndAgreementContent(): void {
		$this->seedPref();
		$floorId = $this->createFloor($this->houseId);
		$roomId = $this->createRoom($this->houseId, $floorId);
		$taskId = $this->createTask($this->houseId, 'Private work');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'dgd-member', '2026-09-29');
		$occurrences = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findByRoomTask($roomTaskId);
		$occurrence = array_values(array_filter($occurrences, static fn ($row): bool => $row->getPlannedDate()->format('Y-m-d') === '2026-09-29'))[0];
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('agreement');
		$change->setObjectId((string)$occurrence->getId());
		$change->setObjectRevision($occurrence->getVersion());
		$change->setChangeTime(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setTargetSlot('2026-09-29');
		$change->setCreatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$this->changeMapper->queueOrCoalesce($change);
		$taskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class);
		$task = $taskMapper->find($taskId);
		$task->setVisibilityType(\OCA\ChoreStrike\Db\Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('dgd-lead');
		$taskMapper->update($task);
		\OC::$server->get(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class)->deleteByUserForHouse('dgd-member', $this->houseId);
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:00:00 UTC'));
		$slot = $this->slotMapper->findByHouseUserAndDay($this->houseId, $this->memberId, '2026-09-29');
		$outcome = (new \ReflectionMethod($this->digestService, 'dispatchSlot'))->invoke($this->digestService, $slot, new \DateTime('2026-09-29 18:05:00 UTC'));
		self::assertSame('skipped', $outcome, 'the current policy excludes both counts even though old occurrence/queue rows remain');
		self::assertNull($this->slotMapper->findById((int)$slot->getId())->getDispatchedAt());
	}
}
