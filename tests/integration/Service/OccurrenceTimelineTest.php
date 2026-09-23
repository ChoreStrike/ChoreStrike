<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Occurrence timeline integration suite (CS-PLAN-072 WP1; parent
 * CS-PLAN-070 T1-B/T1-C/T1-D/T1-E): durable identity, virtual agreed
 * resolution, deterministic selection, replay authorization, catch-up
 * coverage, revision closure on definition edits and zone changes —
 * all against the real Docker database with the real services.
 */
class OccurrenceTimelineTest extends IntegrationTestCase {
	private OccurrenceService $occurrenceService;
	private OccurrenceMapper $occurrenceMapper;
	private ScheduleRevisionMapper $revisionMapper;
	private CompletionService $completionService;
	private TaskService $taskService;

	protected function setUp(): void {
		parent::setUp();
		$this->occurrenceService = \OC::$server->get(OccurrenceService::class);
		$this->occurrenceMapper = \OC::$server->get(OccurrenceMapper::class);
		$this->revisionMapper = \OC::$server->get(ScheduleRevisionMapper::class);
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->taskService = \OC::$server->get(TaskService::class);
	}

	/**
	 * Full fixture: house + owner + room + (optional recurrence) task with
	 * an initialized schedule. The optional anchor seeds the room task's
	 * next_due (the schedule's first date).
	 */
	private function fixture(array $taskOverrides = [], ?string $anchor = null): array {
		$houseId = $this->createHouse('ot_owner', 'Occurrence House');
		$this->addHouseMember($houseId, 'ot_helper', 'Helper', 'member');
		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 3, 30);
		$qb = $this->db->getQueryBuilder();
		$taskOverrides += [
			'frequency_type' => 'daily',
			'frequency_value' => 1,
			'scheduling_mode' => 'dynamic',
			'recurrence_mode' => 'recurring',
		];
		foreach ($taskOverrides as $column => $value) {
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ot_owner', $anchor);
		return [
			'houseId' => $houseId,
			'roomId' => $roomId,
			'taskId' => $taskId,
			'roomTaskId' => $roomTaskId,
		];
	}

	/**
	 * The next Monday on/after the given date (Y-m-d).
	 */
	private function nextMonday(string $fromDate): string {
		$date = new \DateTime($fromDate);
		while ((int)$date->format('N') !== 1) {
			$date->modify('+1 day');
		}
		return $date->format('Y-m-d');
	}

	/**
	 * T1-B: creation materializes revision 1 + the interval pending at the
	 * anchor; the ledger row carries the frozen occurrence evidence.
	 */
	public function testIntervalCreation_MaterializesOnePending_WithFrozenEvidence(): void {
		$f = $this->fixture();
		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $revisions);
		self::assertSame(1, $revisions[0]->getRevision());
		self::assertNull($revisions[0]->getClosedAt());
		self::assertSame('interval', $revisions[0]->getCadenceMode());

		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $occurrences, 'an interval rule owns exactly one pending occurrence at creation');
		self::assertSame(1, $occurrences[0]->getOrdinal());
		self::assertSame(0, $occurrences[0]->getVersion(), 'a merely materialized occurrence keeps version 0');

		// Complete through the receipt contract; the ledger must freeze the
		// public key parts, work date/zone and the 'agreed' classification.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$ledgerRows = $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(1, $ledgerRows);
		self::assertNotNull($ledgerRows[0]['schedule_rev_id']);
		self::assertNotNull($ledgerRows[0]['occurrence_ordinal']);
		self::assertNotNull($ledgerRows[0]['work_date']);
		self::assertSame('UTC', $ledgerRows[0]['work_zone']);
		self::assertSame('agreed', $ledgerRows[0]['completion_class']);
		self::assertGreaterThan(0, $result['completionId']);
	}

	/**
	 * T1-B regression (CS-PLAN-072 WP1): a participant amendment on an
	 * interval task appends a new revision while the OLD revision's
	 * unfulfilled pending survives as the task's sole open obligation. The
	 * batched agreed derivation must NOT fabricate a second virtual pending
	 * for the row-less new revision — that double-counted the survivor in the
	 * calendar/range/selection reads (the scheduling-apply-flow exit journey
	 * saw the task twice on the applied day).
	 */
	public function testParticipantAmendment_DoesNotDuplicateIntervalPending(): void {
		$f = $this->fixture();
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$before = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		self::assertCount(1, $before, 'an interval rule owns exactly one pending occurrence');

		// Amend the participants (owner -> helper) through the public
		// agreement writer; this closes+appends a revision.
		$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByHouseAndUser($f['houseId'], 'ot_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($f['roomTaskId'], ['ot_helper'], $f['houseId'], $actor);

		$after = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		$open = array_values(array_filter(
			$after,
			static fn (array $entry): bool => $entry['occurrence']->getCompletionId() === null
				&& $entry['occurrence']->getCoveredByCompletionId() === null
		));
		self::assertCount(1, $open, 'the participant amendment keeps exactly one open interval pending (no virtual duplicate)');
	}

	/**
	 * T1-B: a fixed rule materializes the full horizon in the saved zone,
	 * and a completion fulfills exactly one occurrence while the rest stay
	 * agreed.
	 */
	public function testFixedRule_MaterializesHorizon_AndCompletionFulfillsOne(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertGreaterThanOrEqual(1, count($occurrences), 'fixed rules materialize through the horizon');
		foreach ($occurrences as $occurrence) {
			self::assertSame(
				(strtolower((new \DateTime())->format('l'))),
				strtolower($occurrence->getPlannedDate()->format('l')),
				'every occurrence lands on the configured weekday'
			);
		}

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$fulfilled = array_values(array_filter($after, static fn (Occurrence $o): bool => $o->getCompletionId() !== null));
		self::assertCount(1, $fulfilled, 'exactly one occurrence is fulfilled');
	}

	/**
	 * T1-B replay semantics: the same (commandId, payload) returns the
	 * ORIGINAL completion identity with fresh authorized projections and a
	 * replay marker — no second ledger row, no reward repeat. A NEW
	 * command on the fulfilled target conflicts.
	 */
	public function testCommandReplay_ReturnsOriginalIdentity_WithoutRepeatingEffects(): void {
		$f = $this->fixture();
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($f['roomTaskId']);

		$first = $this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);
		$replay = $this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);

		self::assertFalse($first['replay']);
		self::assertTrue($replay['replay']);
		self::assertSame($first['completionId'], $replay['completionId'], 'the receipt returns the original completion identity');
		self::assertCount(1, $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));

		// A new command on the fulfilled target conflicts.
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $occurrenceId, $expectedVersion, $selectionToken);
			self::fail('a new command on a fulfilled target must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('already completed', $e->getMessage());
		}
	}

	/**
	 * T1-B: stale selection inputs (wrong version, wrong token) conflict
	 * with zero writes.
	 */
	public function testStaleSelectionInputs_ConflictWithZeroWrites(): void {
		$f = $this->fixture();
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($f['roomTaskId']);

		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion + 5, $selectionToken);
			self::fail('a wrong expected version must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $occurrenceId, $expectedVersion, 'bogus');
			self::fail('a wrong selection token must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}

		self::assertSame([], $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));
	}

	/**
	 * T1-C: undo re-opens the recorded occurrence, deletes the open
	 * interval successor (later valid work survives), and re-completing
	 * the SAME day succeeds with a fresh ledger row.
	 */
	public function testUndo_ReleasesOccurrence_ReDerivesIntervalSuccessor(): void {
		$f = $this->fixture();

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$afterComplete = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $afterComplete, 'completion anchored one successor');

		$this->completionService->undo($f['houseId'], $this->houseUser($f['houseId'], 'ot_owner'), $f['roomTaskId']);
		$afterUndo = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $afterUndo, 'the open successor anchored on the undone completion is re-derived away');
		self::assertNull($afterUndo[0]->getCompletionId(), 'the completed occurrence is open again');
		self::assertGreaterThan(0, $afterUndo[0]->getVersion(), 're-opening advances the occurrence version');

		// Re-complete the same day: occurrence validity replaces the
		// per-person/day duplicate guard.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertCount(2, $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));
	}

	/**
	 * T1-C catch-up coverage: completing current work covers the OLDER
	 * missed occurrence (covered_by), never fulfills it; a late completion
	 * of the miss itself is classified 'late'.
	 */
	public function testCatchUpCompletion_CoversOlderMiss_WithoutFulfillingIt(): void {
		$f = $this->fixture();

		// Force a miss: move the pending occurrence's window into the past.
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$missed = $occurrences[0];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('planned_date', $qb->createNamedParameter((new \DateTime('-3 days'))->format('Y-m-d')))
			->set('day_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($missed->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Completing the catch-up card fulfills the miss late.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('late', $this->classificationOfCompletion($result['completionId']));

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$fulfilled = array_values(array_filter($after, static fn (Occurrence $o): bool => $o->getCompletionId() !== null));
		self::assertCount(1, $fulfilled);
		self::assertSame($missed->getId(), $fulfilled[0]->getId(), 'the missed occurrence itself is fulfilled late');
	}

	/**
	 * T1-D: a cadence edit closes the old revision and appends a new one;
	 * already agreed occurrences keep identity/energy; the new revision
	 * re-anchors beyond the old frontier.
	 */
	public function testDefinitionEdit_ClosesOldRevision_AppendsNew_BeyondOldFrontier(): void {
		$f = $this->fixture();
		$oldRevisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		$oldFrontier = $oldRevisions[0]->getAgreedThrough()->format('Y-m-d');
		$oldOccurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertNotSame([], $oldOccurrences);

		// Edit a rule-relevant input (cadence change).
		$this->actingAs('ot_owner');
		$this->taskService->updateTask($f['taskId'], ['frequencyType' => 'weekly'], $this->houseUser($f['houseId'], 'ot_owner'));

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions);
		self::assertNotNull($revisions[0]->getClosedAt(), 'the old revision is closed');
		self::assertNull($revisions[1]->getClosedAt(), 'the new revision is current');
		self::assertSame(2, $revisions[1]->getRevision());
		self::assertSame(
			$oldFrontier,
			$revisions[1]->getAgreedThrough()->format('Y-m-d'),
			'the new frontier starts at the old one — new agreements are prospective only'
		);

		// Already agreed occurrences retain identity and state.
		foreach ($oldOccurrences as $oldOccurrence) {
			$still = $this->occurrenceMapper->find((int)$oldOccurrence->getId());
			self::assertSame($oldOccurrence->toPublicKey(), $still->toPublicKey());
		}
	}

	/**
	 * T1-D regression (RF-072-WP1-02): a keep-anchor edit (energy) on a
	 * fixed rule closes the old revision and appends a new one with the
	 * ORIGINAL anchor. The new revision must not re-derive the predecessor's
	 * agreed history: a fulfilled planned day would reappear as an
	 * unfulfilled completable twin and reads would show the task twice.
	 */
	public function testKeepAnchorEdit_DoesNotDuplicateAgreedOccurrences(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$fulfilled = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getCompletionId() !== null) {
				$fulfilled = $occurrence;
			}
		}
		self::assertNotNull($fulfilled, 'the open occurrence was fulfilled');
		$agreedBefore = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);

		// Energy is rule-relevant but leaves the cadence shape unchanged —
		// a keep-anchor revision.
		$this->actingAs('ot_owner');
		$this->taskService->updateTask($f['taskId'], ['energyCost' => 5], $this->houseUser($f['houseId'], 'ot_owner'));

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions, 'the energy edit closed the old and appended a new revision');
		self::assertSame(
			$revisions[0]->getAnchorDate()->format('Y-m-d'),
			$revisions[1]->getAnchorDate()->format('Y-m-d'),
			'a keep-anchor edit preserves the original anchor'
		);

		$agreedAfter = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		self::assertCount(
			count($agreedBefore),
			$agreedAfter,
			'the keep-anchor revision must not duplicate the predecessor\'s agreed entries'
		);
		$fulfilledDateKey = $fulfilled->getPlannedDate()->format('Y-m-d');
		foreach ($agreedAfter as $entry) {
			$occurrence = $entry['occurrence'];
			if ($occurrence->getPlannedDate()->format('Y-m-d') === $fulfilledDateKey
				&& $occurrence->getCompletionId() === null
				&& $occurrence->getCoveredByCompletionId() === null) {
				self::fail('the fulfilled planned day reappeared as an unfulfilled twin under the new revision');
			}
		}

		// A stale twin key under the new revision must conflict, never
		// materialize a second completable occurrence for the owned date.
		$twinKey = $revisions[1]->getId() . ':' . $fulfilled->getOrdinal();
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $twinKey, 0, 'bogus');
			self::fail('a twin key of a predecessor-owned date must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('no longer valid', $e->getMessage());
		}
	}

	/**
	 * T1-C regression (RF-072-WP1-03): with default (flex=0) windows the
	 * yesterday miss and today's open occurrence share the boundary instant
	 * (miss.windowEnd == target.windowStart). Completing the current open
	 * target must COVER the adjacent older miss — a window-instant
	 * comparison never covers it, leaving a stale catch-up card that stays
	 * completable for extra coins.
	 */
	public function testCurrentCompletion_CoversAdjacentMiss_ForDefaultFlexWindows(): void {
		$yesterday = (new \DateTime('-1 days'))->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $yesterday . ' 00:00:00');

		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('agreed', $this->classificationOfCompletion($result['completionId']));

		$missed = null;
		$fulfilled = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getPlannedDate()->format('Y-m-d') === $yesterday) {
				$missed = $occurrence;
			} elseif ($occurrence->getCompletionId() !== null) {
				$fulfilled = $occurrence;
			}
		}
		self::assertNotNull($missed, 'the yesterday occurrence materialized as the miss');
		self::assertNotNull($fulfilled, "today's open occurrence was fulfilled");
		self::assertNull($missed->getCompletionId(), 'the miss is covered, never fulfilled');
		self::assertSame(
			$result['completionId'],
			$missed->getCoveredByCompletionId(),
			'the current work covers the adjacent older miss'
		);

		// With the miss covered and today fulfilled, no late catch-up card
		// remains selectable.
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$selection = $this->occurrenceService->selectionFor($roomTaskMapper->find($f['roomTaskId']), new \DateTime());
		self::assertTrue(
			$selection === null || $selection['classification'] !== 'late',
			'no stale catch-up card survives the coverage'
		);
	}

	/**
	 * T1-C zone changes: a house-zone edit appends zone-carrying
	 * revisions atomically; existing occurrences keep their saved zone.
	 */
	public function testHouseZoneChange_PropagatesToSchedules_SavedZoneSurvives(): void {
		$f = $this->fixture();
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertSame('UTC', $occurrences[0]->getTimezone());

		$houseService = \OC::$server->get(HouseService::class);
		$this->actingAs('ot_owner');
		$houseService->updateHouse($f['houseId'], 'Occurrence House', 'Europe/Berlin');

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions, 'the zone edit appended a new revision');
		self::assertSame('Europe/Berlin', $revisions[1]->getTimezone());

		// The already agreed occurrence keeps its saved zone.
		$saved = $this->occurrenceMapper->find((int)$occurrences[0]->getId());
		self::assertSame('UTC', $saved->getTimezone(), 'saved occurrences keep their zone across house-zone edits');
	}

	/**
	 * T1-E: checklist progress is occurrence-scoped — toggling under one
	 * occurrence never leaks into another.
	 */
	public function testChecklistProgress_IsOccurrenceScoped(): void {
		$f = $this->fixture();
		$itemId = $this->createChecklistItem($f['taskId'], 'Rinse');

		$checklistService = \OC::$server->get(\OCA\ChoreStrike\Service\ChecklistService::class);
		$viewer = $this->houseUser($f['houseId'], 'ot_owner');

		$progress = $checklistService->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $viewer);
		$occurrenceId = $progress['occurrence']['occurrenceId'];
		self::assertNotSame('', $occurrenceId);

		$checklistService->toggleItem($f['roomTaskId'], $occurrenceId, $itemId, 0, $f['houseId'], $viewer);
		$rows = $this->fetchRows('chorestrike_chk_progress', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(1, $rows);
		self::assertSame(1, (int)$rows[0]['is_checked']);

		// Complete + toggle under the NEXT occurrence: a separate row keyed
		// by the new occurrence, the old row untouched. The successor is a
		// FUTURE agreed occurrence (its window has not opened yet) — the
		// canonical selection is null then, so the toggle addresses it by
		// its explicit public key.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$successor = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getCompletionId() === null) {
				$successor = $occurrence;
				break;
			}
		}
		self::assertNotNull($successor, 'completion anchored an open successor');
		$nextOccurrenceId = $successor->toPublicKey();
		self::assertNotSame($occurrenceId, $nextOccurrenceId);
		$checklistService->toggleItem($f['roomTaskId'], $nextOccurrenceId, $itemId, 0, $f['houseId'], $viewer);

		$rows = $this->fetchRows('chorestrike_chk_progress', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(2, $rows, 'progress rows are scoped per occurrence');
	}

	/**
	 * The timeline job pass: the frontier advances and missing occurrences
	 * materialize idempotently (a repeated run writes nothing new).
	 */
	public function testTimelineJobPass_MaterializesIdempotently(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');

		// Regress the frontier to force materialization on the next pass
		// (the per-task reconcile is the same generator the house pass of
		// the timeline job runs per room task).
		$revision = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId']);
		$regressed = (new \DateTime('-30 days'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_schedule_revs')
			->set('agreed_through', $qb->createNamedParameter($regressed))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($revision->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$materialized = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertGreaterThan(0, $materialized);

		$afterFirst = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		$second = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertSame(0, $second, 'a repeated pass writes nothing new');
		self::assertSame($afterFirst, count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])));
	}

	/**
	 * RF-072-WP1-06 regression: undo AFTER a revision edit must release
	 * the open interval successor across ALL revisions. The completion
	 * anchors its successor under the THEN-current revision; a participant
	 * amendment afterwards appends a new revision — a current-revision-only
	 * successor match left BOTH pendings open (§3.2 rule 2: exactly one
	 * next pending).
	 */
	public function testUndoAfterParticipantAmendment_ReleasesCrossRevisionSuccessor(): void {
		$f = $this->fixture();

		// Complete: pending fulfilled + open successor anchored on the
		// completion, both under revision 1.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertCount(2, $this->occurrenceMapper->findByRoomTask($f['roomTaskId']));

		// Participant amendment: closes revision 1 and appends revision 2
		// while the revision-1 successor stays the open obligation.
		$actor = $this->houseUser($f['houseId'], 'ot_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($f['roomTaskId'], ['ot_helper'], $f['houseId'], $actor);
		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions);
		self::assertNotNull($revisions[0]->getClosedAt());

		// Undo: the revision-1 successor anchored on the undone completion
		// must be released; exactly ONE open interval pending remains.
		$this->completionService->undo($f['houseId'], $this->houseUser($f['houseId'], 'ot_owner'), $f['roomTaskId']);

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$open = array_values(array_filter(
			$after,
			static fn (Occurrence $o): bool => $o->getCompletionId() === null && $o->getCoveredByCompletionId() === null
		));
		self::assertCount(1, $open, 'undo leaves exactly ONE open interval pending, across revision boundaries');
	}

	/**
	 * RF-072-WP1-08 regression: a job pass materializes a large historical
	 * gap only within its per-pass budget — the frontier advances through
	 * what the pass actually reconciled and later passes converge without
	 * re-materializing.
	 */
	public function testReconciliationBudget_ConvergesOverPasses(): void {
		// Static daily rule anchored 400 days ago: creation materializes the
		// full anchor→horizon range (8-day creation horizon semantics kept
		// simple here by the fixture's 7-day window), so simulate a dead
		// cron by keeping only the creation week's rows and freezing the
		// frontier there — exactly the state a stopped job leaves behind.
		$todayKey = (new \DateTime())->format('Y-m-d');
		$anchorKey = (new \DateTime('-400 days'))->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $anchorKey . ' 00:00:00');

		// Shrink to the creation-week state: keep rows of the first 8 days,
		// freeze the frontier at the last kept day.
		$keepThrough = (new \DateTime($anchorKey))->modify('+7 days')->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occurrences')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($f['roomTaskId'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('planned_date', $qb->createNamedParameter($keepThrough, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->executeStatement();
		$revision = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId']);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_schedule_revs')
			->set('agreed_through', $qb->createNamedParameter($keepThrough))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($revision->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$rowsBefore = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		self::assertSame(8, $rowsBefore);

		// Pass 1: bounded by the per-pass budget — far fewer than the ~400
		// missing days are materialized, the frontier advances partway.
		$this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		$afterFirst = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertLessThan(
			400,
			count($afterFirst) - $rowsBefore,
			'a single pass must not materialize the whole historical gap (budget)'
		);
		self::assertCount(OccurrenceService::JOB_MATERIALIZE_BUDGET + $rowsBefore, $afterFirst);
		$frontierAfterFirst = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId'])
			->getAgreedThrough()->format('Y-m-d');
		self::assertGreaterThan($keepThrough, $frontierAfterFirst);
		self::assertLessThan($todayKey, $frontierAfterFirst, 'the truncated pass stops before the horizon');

		// Pass 2 converges to the full horizon; pass 3 writes nothing.
		$this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		$afterSecond = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		$frontierAfterSecond = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId'])
			->getAgreedThrough()->format('Y-m-d');
		self::assertGreaterThanOrEqual($todayKey, $frontierAfterSecond);

		$third = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertSame(0, $third, 'a converged pass writes nothing new');
		self::assertSame($afterSecond, count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])));
	}

	/**
	 * RF-072-WP1-09: the derived next_due projection follows the earliest
	 * OPEN scheduled occurrence — completion moves it to the successor,
	 * undo restores it.
	 */
	public function testNextDueProjection_TracksEarliestOpenOccurrence(): void {
		$f = $this->fixture();
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$before = $roomTaskMapper->find($f['roomTaskId'])->getNextDue();
		self::assertNotNull($before);

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$open = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($f['roomTaskId']),
			static fn (Occurrence $o): bool => $o->getCompletionId() === null
		));
		self::assertCount(1, $open);
		self::assertSame(
			$open[0]->getDayStart()->getTimestamp(),
			$roomTaskMapper->find($f['roomTaskId'])->getNextDue()->getTimestamp(),
			'the projection follows the successor occurrence'
		);

		$this->completionService->undo($f['houseId'], $this->houseUser($f['houseId'], 'ot_owner'), $f['roomTaskId']);
		self::assertSame(
			$before->getTimestamp(),
			$roomTaskMapper->find($f['roomTaskId'])->getNextDue()->getTimestamp(),
			'the undo restores the projection to the re-opened occurrence'
		);
	}

	/**
	 * RF-072-WP1-14: voluntary rows are neither catch-up misses nor
	 * coverage targets — the catch-up lookup filters by kind and recorded
	 * voluntary work never carries catch-up coverage marks.
	 */
	public function testVoluntaryOccurrence_IsNoCatchUpMiss_AndNeverCovered(): void {
		$f = $this->fixture();

		// Force the pending into an expired window and mark it voluntary
		// (the kind a voluntary task's rows carry).
		$pending = $this->occurrenceMapper->findByRoomTask($f['roomTaskId'])[0];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('kind', $qb->createNamedParameter('voluntary'))
			->set('planned_date', $qb->createNamedParameter((new \DateTime('-3 days'))->format('Y-m-d')))
			->set('day_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pending->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// (a) The catch-up lookup ignores voluntary rows entirely.
		self::assertNull(
			$this->occurrenceMapper->findLatestMissedByRoomTask($f['roomTaskId'], new \DateTime()),
			'an expired voluntary row is never the catch-up target'
		);

		// (b) The selection classifies it as recordable voluntary work, not
		// as a late catch-up obligation.
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$selection = $this->occurrenceService->selectionFor($roomTaskMapper->find($f['roomTaskId']), new \DateTime());
		self::assertNotNull($selection);
		self::assertSame('voluntary', $selection['classification']);

		// (c) Recording it fulfills the voluntary occurrence itself and
		// never writes catch-up coverage provenance.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('voluntary', $this->classificationOfCompletion($result['completionId']));
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			self::assertNull($occurrence->getCoveredByCompletionId(), 'no occurrence is covered by voluntary work');
		}
	}

	/**
	 * RF-072-WP1-11 regression: the C2h date-amendment gate counts OPEN
	 * occurrences on the target day — including agreed occurrences whose
	 * task's next_due still points at an earlier day (the next_due-equality
	 * count missed those) and never fulfilled rows.
	 */
	public function testDateAmendment_ChildCapacity_CountsOpenOccurrencesOnTargetDay(): void {
		$houseId = $this->createHouse('ot_cap_owner', 'Capacity House');
		$this->addHouseMember($houseId, 'ot_kid', 'Kid', 'child');

		// Child membership: wallet identity + a maxDailyTasks=2 limit.
		$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
		$wallet = $walletMapper->createOrGet($houseId, 'ot_kid');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('wallet_id', $qb->createNamedParameter($wallet->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode(['maxDailyTasks' => 2])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('ot_kid')))
			->executeStatement();

		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$todayKey = (new \DateTime())->format('Y-m-d');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');

		// A1: static daily — open occurrences on EVERY horizon day, with
		// next_due pinned to TODAY (its earliest open occurrence).
		$a1Task = $this->createTask($houseId, 'A1', '', 1, 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->set('frequency_value', $qb->createNamedParameter(1))
			->set('scheduling_mode', $qb->createNamedParameter('static'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($a1Task, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$a1 = $this->assignTaskToRoom($a1Task, $roomId, 'ot_cap_owner', $todayKey . ' 00:00:00');

		// A2: interval — its single pending is moved to an open
		// tomorrow window.
		$a2Task = $this->createTask($houseId, 'A2', '', 1, 1);
		$a2 = $this->assignTaskToRoom($a2Task, $roomId, 'ot_cap_owner');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('planned_date', $qb->createNamedParameter($tomorrowKey))
			->set('day_start', $qb->createNamedParameter((new \DateTime($tomorrowKey))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime($tomorrowKey))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('+2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($a2, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Assign kid to both — A1's open tomorrow occurrence and A2's
		// open tomorrow occurrence put the child's day load at 2.
		$actor = $this->houseUser($houseId, 'ot_cap_owner');
		$roomTaskService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$roomTaskService->setAssignees($a1, ['ot_kid'], $houseId, $actor);
		$roomTaskService->setAssignees($a2, ['ot_kid'], $houseId, $actor);

		// B: a third task for the owner — moving it to tomorrow would push
		// the child to load 2 + 1 > 2. Both open occurrences of A1/A2 count
		// against tomorrow even though A1's next_due says today.
		$bTask = $this->createTask($houseId, 'B', '', 1, 1);
		$b = $this->assignTaskToRoom($bTask, $roomId, 'ot_cap_owner');
		$roomTaskService->setAssignees($b, ['ot_kid'], $houseId, $actor);

		try {
			$roomTaskService->updateDueDate($b, $tomorrowKey, $actor);
			self::fail('moving B onto the full day must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('daily task limit', $e->getMessage());
		}

		// The same move succeeds once the child carries fewer open
		// occurrences on that day (A2's pending fulfilled → open load 1).
		$openA2 = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($a2),
			static fn (Occurrence $o): bool => $o->getCompletionId() === null && $o->getCoveredByCompletionId() === null
		));
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('completion_id', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($openA2[0]->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$roomTaskService->updateDueDate($b, $tomorrowKey, $actor);
		self::assertSame(
			$tomorrowKey,
			$this->occurrenceMapper->findByRoomTask($b)[0]->getPlannedDate()->format('Y-m-d'),
			'the amendment lands once the open occurrence load fits the limit'
		);
	}

	private function classificationOfCompletion(int $completionId): string {
		$rows = $this->fetchRows('chorestrike_completed_tasks', ['id' => $completionId]);
		return (string)$rows[0]['completion_class'];
	}

	private function houseUser(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		$houseUser = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByHouseAndUser($houseId, $userId);
		self::assertNotNull($houseUser);
		return $houseUser;
	}

	private function createChecklistItem(int $taskId, string $label): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_items')
			->values([
				'task_id' => $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'label' => $qb->createNamedParameter($label),
				'sort_order' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
		return (int)$this->db->lastInsertId('chorestrike_chk_items');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRows(string $table, array $criteria): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table);
		foreach ($criteria as $column => $value) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT : \OCP\DB\QueryBuilder::PARAM_STR)));
		}
		return $qb->executeQuery()->fetchAll();
	}

	private static function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}
}
