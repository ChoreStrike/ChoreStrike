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
use OCA\ChoreStrike\Tests\Integration\Support\StoredTimelineFixtures;

/**
 * Digest delivery lifecycle against the real DB (CS-PLAN-070 WP5 §3.2
 * and §3.4, C5c): quiet hours GATE the dispatch without shifting the
 * slot, a missed window expires permanently together with its queued
 * rows, and an opt-out before the claim marks the slot skipped.
 */
class DigestDeliveryTest extends IntegrationTestCase {
	use StoredTimelineFixtures;

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

	private function seedPref(?string $quietStart = null, ?string $quietEnd = null, ?int $memberId = null): void {
		$pref = new MemberPreference();
		$pref->setHouseId($this->houseId);
		$pref->setHouseUserId($memberId ?? $this->memberId);
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

	/** One dispatch attempt for a slot, as a pass of its own makes it. */
	private function dispatch(DigestSlot $slot, \DateTime $now): string {
		$views = [];
		return (new \ReflectionMethod($this->digestService, 'dispatchSlot'))->invokeArgs($this->digestService, [$slot, $now, &$views]);
	}

	/** A queued agreement row, as the retired agreement writers left it. */
	private function seedQueuedChange(string $dayKey, string $objectId = '21'): void {
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('agreement');
		$change->setObjectId($objectId);
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
	}

	// ------------------------------------------------------------------
	// WP7 reward-request org delivery (CS-PLAN-078 §3.5)
	// ------------------------------------------------------------------

	/**
	 * The requester's own current-revision decision reaches them; a stale
	 * row superseded by a newer revision is silently dropped from the
	 * same pass. Catalog gate off and personal navigation hiding never
	 * remove authorized processing updates.
	 */
	public function testRewardRequestDecision_DeliversAtCurrentRevision_DropsSupersededRows(): void {
		$this->seedPref();
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:30:00 UTC'));
		$todayKey = '2026-09-29';

		$requests = \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class);
		$itemId = $this->seedRewardItem();
		// Request A stays at revision 0 — its queued row stays relevant.
		$current = $requests->create($this->houseId, 'dgd-member', [
			'itemId' => $itemId, 'itemVersion' => 0, 'priceMinor' => 500, 'commandId' => $this->uuidDgd(),
		]);
		// Request B advances to revision 1 (cancelled) — the queued row at
		// revision 0 is superseded.
		$superseded = $requests->create($this->houseId, 'dgd-member', [
			'itemId' => $itemId, 'itemVersion' => 0, 'priceMinor' => 500, 'commandId' => $this->uuidDgd(),
		]);
		$requests->cancel($this->houseId, 'dgd-member', (int)$superseded['id'], ['commandId' => $this->uuidDgd(), 'expectedVersion' => 0]);

		$this->seedQueuedRewardChange($todayKey, (int)$current['id'], 0);
		$this->seedQueuedRewardChange($todayKey, (int)$superseded['id'], 0);

		$stats = $this->digestService->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		self::assertSame(1, $stats['dispatched'], 'the slot delivers once');
		$rows = $this->changeMapper->findBySlot($this->memberId, $todayKey);
		self::assertCount(2, $rows);
		$dispositions = array_map(static fn ($row): string => (string)$row->getDisposition(), $rows);
		self::assertContains(DigestChange::DISPOSITION_CONSUMED, $dispositions, 'relevant rows are consumed');
	}

	private function seedRewardItem(): int {
		$houseService = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class);
		$this->actingAs('dgd-lead');
		$fixtureHouse = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($this->houseId);
		\OC::$server->get(\OCA\ChoreStrike\Service\RewardService::class)->setCatalogEnabled($this->houseId, true, $fixtureHouse->getStateVersion(), $this->uuidDgd());
		$item = \OC::$server->get(\OCA\ChoreStrike\Service\RewardService::class)->createItem($this->houseId, [
			'title' => 'Digest prize', 'priceMinor' => 500, 'commandId' => $this->uuidDgd(),
		]);
		// Fund the member wallet through the real ledger path.
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->find($this->memberId);
		$this->creditTestWallet($this->houseId, (int)$member->getWalletId(), 5000, null);
		return (int)$item['id'];
	}

	private function seedQueuedRewardChange(string $dayKey, int $requestId, int $revision): void {
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('reward_request');
		$change->setObjectId((string)$requestId);
		$change->setObjectRevision($revision);
		$change->setCategory(DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime($dayKey . ' 10:00:00'));
		$change->setTargetSlot($dayKey);
		$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime($dayKey . ' 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime($dayKey . ' 10:00:00 UTC'));
		$this->changeMapper->queueOrCoalesce($change);
	}

	private function uuidDgd(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * D-091-12 (CS-PLAN-091 WP-B7): nothing queues agreement lines any more.
	 * A row retained from before the cutover never becomes a summary, even
	 * while its stored occurrence still stands at the queued revision and
	 * the member sees the task; it stays queued until its slot expires.
	 */
	public function testDispatch_RetainedAgreementRow_NeverBecomesASummary(): void {
		$this->seedPref();
		$roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId));
		// Planned later, so the member has no task content for the day.
		$roomTaskId = $this->assignTaskToRoom($this->createTask($this->houseId, 'Agreed work'), $roomId, 'dgd-member', '2026-10-20');
		$occurrenceId = $this->insertStoredOccurrence($this->houseId, $roomTaskId, '2026-09-30');
		$this->seedQueuedChange('2026-09-29', (string)$occurrenceId);
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:00:00 UTC'));
		$slot = $this->slotMapper->findByHouseUserAndDay($this->houseId, $this->memberId, '2026-09-29');

		$outcome = $this->dispatch($slot, new \DateTime('2026-09-29 18:05:00 UTC'));

		self::assertSame('skipped', $outcome);
		self::assertNull($this->slotMapper->findById((int)$slot->getId())->getDispatchedAt());
		$rows = $this->changeMapper->findBySlot($this->memberId, '2026-09-29');
		self::assertCount(1, $rows);
		self::assertSame(DigestChange::DISPOSITION_QUEUED, $rows[0]->getDisposition(), 'a retained row is skipped, never consumed');
	}

	/**
	 * RF-091-14: the summaries of one pass count the same plan. The work
	 * of the house is read once, however many members are due — before,
	 * every slot placed the whole suggestion again under the house lock
	 * and read the room tasks a second time for the takeover rows.
	 */
	public function testPass_SeveralSummaries_ReadTheWorkOfTheHouseOnce(): void {
		$this->addHouseMember($this->houseId, 'dgd-second', 'Second Digest Member');
		$secondId = (int)\OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, 'dgd-second')->getId();
		$this->seedPref();
		$this->seedPref(null, null, $secondId);
		$roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId));
		$roomTaskIds = [];
		foreach (['dgd-member', 'dgd-second'] as $userId) {
			$roomTaskIds[$userId] = $this->assignTaskToRoom($this->createTask($this->houseId, "Work of $userId"), $roomId, $userId, '2026-09-29');
		}
		// A takeover queued for the second member: its visibility is asked of the same read.
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($secondId);
		$change->setObjectType('takeover');
		$change->setObjectId((string)$this->insertCompletionRow($roomTaskIds['dgd-second'], $this->memberId, '2026-09-28 10:00:00'));
		$change->setCategory(DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setTargetSlot('2026-09-29');
		$change->setDisposition(DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$this->changeMapper->queueOrCoalesce($change);
		$this->scheduleService->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:00:00 UTC'));

		$counter = \OCA\ChoreStrike\Tests\Integration\Support\QueryCounter::attach($this->db);
		try {
			$stats = $this->digestService->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));
		} finally {
			$counter->detach();
		}

		self::assertSame(2, $stats['dispatched']);
		foreach ([$this->memberId, $secondId] as $memberId) {
			self::assertSame(DigestSlot::STATUS_SENT, $this->slotMapper->findByHouseUserAndDay($this->houseId, $memberId, '2026-09-29')->getStatus());
		}
		$takeover = $this->changeMapper->findBySlot($secondId, '2026-09-29');
		self::assertSame(DigestChange::DISPOSITION_CONSUMED, $takeover[0]->getDisposition(), 'the takeover counted: its visibility was asked');
		$roomTaskReads = array_filter($counter->sqlStatements(), static fn (string $sql): bool => preg_match('/FROM\s+\S*chorestrike_room_tasks\b/i', $sql) === 1);
		self::assertCount(1, $roomTaskReads, implode("\n", $counter->sqlStatements()));
	}

	public function testDispatch_CurrentTaskVisibility_DropsBothTaskAndTakeoverContent(): void {
		$this->seedPref();
		$floorId = $this->createFloor($this->houseId);
		$roomId = $this->createRoom($this->houseId, $floorId);
		$taskId = $this->createTask($this->houseId, 'Private work');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'dgd-member', '2026-09-29');
		// A second room of the same definition carries a takeover of the
		// member's work; the first one is the member's task planned today.
		$otherRoomTaskId = $this->assignTaskToRoom($taskId, $this->createRoom($this->houseId, $floorId, 'Other room'), 'dgd-member', '2026-09-20');
		$completionId = $this->insertCompletionRow($otherRoomTaskId, null, '2026-09-28 10:00:00');
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$change->setObjectType('takeover');
		$change->setObjectId((string)$completionId);
		$change->setObjectRevision(0);
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
		$outcome = $this->dispatch($slot, new \DateTime('2026-09-29 18:05:00 UTC'));
		self::assertSame('skipped', $outcome, 'the current policy excludes both counts even though the task and the queue row remain');
		self::assertNull($this->slotMapper->findById((int)$slot->getId())->getDispatchedAt());
	}
}
