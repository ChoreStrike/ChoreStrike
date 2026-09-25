<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\DailyCapacityService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * DB-backed WP2 pause lifecycle proofs (CS-PLAN-073 §3.4):
 * - an acknowledged personal pause NEUTRALLY SUSPENDS the covered open
 *   occurrence (state + evidence persisted, idempotent re-application);
 * - a pending (child) request has NO execution effect until the owner
 *   decides it;
 * - a helper-only pause releases the helper without suspending the
 *   occurrence (Q-073-01);
 * - the selection skips the suspended obligation (no completable target)
 *   while the occurrence keeps its frozen effort snapshot;
 * - shortening to today resumes FUTURE duties while today's recognized
 *   excuse is preserved (the retained old revision keeps covering it);
 * - the owner role gates and the revision-staleness conflict.
 *
 * @group DB
 *
 * @covers \OCA\ChoreStrike\Service\PauseService
 * @covers \OCA\ChoreStrike\Service\PauseProjectionService
 */
class PauseAgreementTest extends IntegrationTestCase {
	private PauseService $pauseService;
	private OccurrenceService $occurrenceService;
	private CompletionService $completionService;
	private string $todayKey;
	private int $houseId;

	/** @var array<int> */
	private array $cleanupRoomTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->pauseService = \OC::$server->get(PauseService::class);
		$this->occurrenceService = \OC::$server->get(OccurrenceService::class);
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');

		$houseId = $this->createHouse('pa_owner', 'Pause House');
		$this->addHouseMember($houseId, 'pa_mom', 'Mom', 'member');
		$this->addHouseMember($houseId, 'pa_kid', 'Kid', 'child');
		// Production parity: every membership mints its persistent WP0
		// wallet (the pause lifecycle and the capacity evaluator resolve
		// effort per wallet).
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		foreach (['pa_mom' => 'Mom', 'pa_kid' => 'Kid'] as $memberUserId => $displayName) {
			\OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class)->ensureBinding(
				$houseId,
				$houseUserMapper->findByNextcloudUserId($houseId, $memberUserId)
			);
		}
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Pause dishes', '', 5, 2);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'pa_mom');
		$this->cleanupRoomTaskIds[] = $roomTaskId;
		$this->houseId = $houseId;
	}

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$this->deleteRoomTaskRows($roomTaskId);
		}
		$this->cleanupRoomTaskIds = [];
		parent::tearDown();
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	private function roomTaskId(): int {
		return $this->cleanupRoomTaskIds[0];
	}

	private function range(): array {
		return ['startDate' => $this->todayKey, 'endDate' => (new \DateTime($this->todayKey))->modify('+4 days')->format('Y-m-d'), 'reasonCode' => Pause::REASON_TRAVEL];
	}

	private function openOccurrence(?int $roomTaskId = null): Occurrence {
		$targetId = $roomTaskId ?? $this->roomTaskId();
		foreach (\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findOpenByHouseId($this->houseId) as $row) {
			if ($row->getRoomTaskId() === $targetId) {
				return $row;
			}
		}
		throw new \RuntimeException('no open occurrence for the pause fixture');
	}

	public function testAcknowledgedPersonalPause_SuspendsCoveredOccurrenceNeutrally(): void {
		$result = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state'], 'an adult self-pause is acknowledged directly');

		$occurrence = $this->openOccurrence();
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $occurrence->getSuspensionState(), 'the covered obligation is neutrally suspended');
		self::assertNotNull($occurrence->getSuspensionEvidence(), 'the suspension carries the covering pause provenance');
		self::assertSame(2, $occurrence->getEnergy(), 'the frozen effort snapshot is retained');
		self::assertNull($occurrence->getCompletionId(), 'suspension never fabricates a completion');

		// Idempotent: re-applying the effects writes nothing.
		$changed = $this->pauseService->applyPauseEffectsUnderLock((int)$this->houseId);
		self::assertSame(0, $changed);

		// The selection has no completable target while paused.
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($this->roomTaskId());
		self::assertNull($this->occurrenceService->selectionFor($roomTask, new \DateTime(), (int)$this->houseId));
	}

	public function testChildRequest_HasNoEffectUntilOwnerDecides(): void {
		// The shared fixture is assigned to pa_mom; the child's pause can
		// only neutralize an obligation the CHILD owes — create one.
		$floorId = $this->createFloor((int)$this->houseId, 'Upper Floor', 2);
		$roomId = $this->createRoom((int)$this->houseId, $floorId, 'Playroom');
		$taskId = $this->createTask((int)$this->houseId, 'Kid chores', '', 5, 2);
		$kidTaskId = $this->assignTaskToRoom($taskId, $roomId, 'pa_kid');
		$this->cleanupRoomTaskIds[] = $kidTaskId;

		$result = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Pause::STATE_REQUESTED, $result['state']);
		self::assertSame(Occurrence::SUSPENSION_NONE, $this->openOccurrence($kidTaskId)->getSuspensionState(), 'a pending request changes no duty');

		// A non-owner cannot decide the request.
		try {
			$this->pauseService->approve((int)$this->houseId, 'pa_kid', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision']]);
			self::fail('a child cannot approve its own request');
		} catch (ForbiddenException $e) {
			self::assertSame('Owner role required', $e->getMessage());
		}

		$approved = $this->pauseService->approve((int)$this->houseId, 'pa_owner', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision']]);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $approved['state']);

		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->openOccurrence($kidTaskId)->getSuspensionState(), 'the acknowledgment suspends the covered obligation');
	}

	public function testStaleRevisionDecision_Conflicts(): void {
		$result = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));

		$this->expectException(\OCA\ChoreStrike\Exception\ConflictException::class);
		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision'] + 3]);
	}

	public function testShortenToToday_ResumesFutureDuty_PreservesToday(): void {
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->openOccurrence()->getSuspensionState());

		$shortened = $this->pauseService->shorten(
			(int)$this->houseId,
			'pa_mom',
			$created['pauseGroupId'],
			['commandId' => $this->uuid(), 'expectedRevision' => $created['revision'], 'endDate' => $this->todayKey]
		);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $shortened['state'], 'an adult self-shortening appends an acknowledged revision');
		self::assertSame(2, $shortened['revision']);

		// Today stays recognized (the retained revision 1 keeps covering
		// it); the occurrence planned TODAY therefore stays suspended and
		// its neutral history is preserved — a resumed FUTURE duty is the
		// projection's next enumeration.
		$occurrence = $this->openOccurrence();
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $occurrence->getSuspensionState(), "today's recognized excuse is preserved");
	}

	public function testShorteningByNonOwnerForeignSubject_Forbidden(): void {
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', array_merge(['commandId' => $this->uuid()], $this->range()));

		$this->expectException(ForbiddenException::class);
		$this->pauseService->shorten(
			(int)$this->houseId,
			'pa_kid',
			$created['pauseGroupId'],
			['commandId' => $this->uuid(), 'expectedRevision' => $created['revision'], 'endDate' => $this->todayKey]
		);
	}

	public function testCapacityExcludesSuspendedPlannedEffort(): void {
		$before = \OC::$server->get(DailyCapacityService::class)->evaluateDays((int)$this->houseId, [$this->todayKey])[$this->todayKey]['pa_mom'];
		self::assertSame(200, $before['plannedEnergyMinor'], 'the open obligation plans its agreed share before the pause');

		$this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', array_merge(['commandId' => $this->uuid()], $this->range()));

		$after = \OC::$server->get(DailyCapacityService::class)->evaluateDays((int)$this->houseId, [$this->todayKey])[$this->todayKey]['pa_mom'];
		self::assertSame(0, $after['plannedEnergyMinor'], 'a suspended plan consumes no live planned capacity');
		self::assertTrue($after['paused'], 'the member-day carries the paused flag with a clarification reason');
		self::assertContains('PAUSED', $after['reasons']);
	}

	public function testSuspensionLiftsOnRejection(): void {
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		$this->pauseService->reject((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $created['revision']]);

		self::assertSame(Occurrence::SUSPENSION_NONE, $this->openOccurrence()->getSuspensionState(), 'a rejected request lifts any neutral suspension');
	}

	public function testHouseholdPause_SuspendsWithHouseScope(): void {
		$result = $this->pauseService->createHousePause((int)$this->houseId, 'pa_owner', array_merge(['commandId' => $this->uuid()], $this->range()));

		$occurrence = $this->openOccurrence();
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $occurrence->getSuspensionState());
		$evidence = $occurrence->getSuspensionEvidence();
		self::assertNotNull($evidence);
		self::assertSame($result['pauseGroupId'], (int)$evidence[0]['pauseGroupId']);

		// The viewer-safe list shows the house pause without a subject.
		$list = $this->pauseService->listForViewer((int)$this->houseId, 'pa_kid');
		$entry = null;
		foreach ($list as $item) {
			if ($item['pauseGroupId'] === $result['pauseGroupId']) {
				$entry = $item;
			}
		}
		self::assertNotNull($entry, 'the house pause is visible to every member');
		self::assertNull($entry['subjectWalletId']);
	}

	/**
	 * RF-073-WP2-03/18: overlapping acknowledged pauses (house +
	 * personal) compose as a UNION — the suspension evidence of a
	 * suspended occurrence retains EVERY covering provenance entry (the
	 * pre-fix projection overwrote the per-wallet provenance last-wins),
	 * and each covering pause is referenceable through its own evidence
	 * row with its own `pause_ref`.
	 */
	public function testOverlappingPauses_UnionProvenancePersistedPerPauseRef(): void {
		// A static daily task for mom so TODAY and TOMORROW both own a
		// materialized occurrence under the covering ranges.
		$floorId = $this->createFloor($this->houseId, 'Union Floor', 2);
		$roomId = $this->createRoom($this->houseId, $floorId, 'Union Kitchen');
		$taskId = $this->createTask($this->houseId, 'Union dishes', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->set('frequency_value', $qb->createNamedParameter(1))
			->set('scheduling_mode', $qb->createNamedParameter('static'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$unionTaskId = $this->assignTaskToRoom($taskId, $roomId, 'pa_mom', $this->todayKey . ' 00:00:00');
		$this->cleanupRoomTaskIds[] = $unionTaskId;

		$tomorrowKey = (new \DateTime($this->todayKey))->modify('+1 day')->format('Y-m-d');
		$house = $this->pauseService->createHousePause((int)$this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'startDate' => $this->todayKey,
			'endDate' => $tomorrowKey,
			'reasonCode' => Pause::REASON_TRAVEL,
		]);
		$personal = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', [
			'commandId' => $this->uuid(),
			'startDate' => $this->todayKey,
			'endDate' => $tomorrowKey,
			'reasonCode' => Pause::REASON_ILLNESS,
		]);

		$occurrenceMapper = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class);
		$todayRow = null;
		foreach ($occurrenceMapper->findByRoomTask($unionTaskId) as $row) {
			if ($row->getPlannedDate()->format('Y-m-d') === $this->todayKey) {
				$todayRow = $row;
			} elseif ($row->getPlannedDate()->format('Y-m-d') === $tomorrowKey) {
				$this->assertUnionEvidence($row, [(int)$house['pauseGroupId'], (int)$personal['pauseGroupId']], 'tomorrow is covered by both pauses');
			}
		}
		self::assertNotNull($todayRow);
		$this->assertUnionEvidence($todayRow, [(int)$house['pauseGroupId'], (int)$personal['pauseGroupId']], 'today is covered by both pauses');

		// Each covering pause owns an evidence row with its own pause_ref.
		$qb = $this->db->getQueryBuilder();
		$qb->select('pause_ref')
			->from('chorestrike_agreements')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter((int)$todayRow->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('pause')));
		$refs = array_map('intval', array_filter(array_column($qb->executeQuery()->fetchAll(), 'pause_ref'), static fn ($v): bool => $v !== null));
		self::assertContains((int)$house['pauseGroupId'], $refs, 'the house pause is referenceable by its own pause_ref');
		self::assertContains((int)$personal['pauseGroupId'], $refs, 'the personal pause is referenceable by its own pause_ref');
	}

	private function assertUnionEvidence(Occurrence $occurrence, array $expectedGroupIds, string $message): void {
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $occurrence->getSuspensionState(), $message);
		$evidence = $occurrence->getSuspensionEvidence();
		self::assertIsArray($evidence);
		$groupIds = array_map(static fn (array $entry): int => (int)$entry['pauseGroupId'], $evidence);
		sort($groupIds);
		$expected = $expectedGroupIds;
		sort($expected);
		self::assertSame($expected, $groupIds, $message . ' — the union keeps every provenance entry');
	}

	/**
	 * RF-073-WP2-18: shortening is scoped to ACKNOWLEDGED pauses (plan
	 * §3.4 row 6) — a pending request carries no recognized effect to
	 * shorten and is decided (approve/reject) instead.
	 */
	public function testShorten_PendingRequest_Conflicts(): void {
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Pause::STATE_REQUESTED, $created['state'], 'a child request stays pending');

		try {
			$this->pauseService->shorten(
				(int)$this->houseId,
				'pa_owner',
				$created['pauseGroupId'],
				['commandId' => $this->uuid(), 'expectedRevision' => $created['revision'], 'endDate' => $this->todayKey]
			);
			self::fail('shortening a pending request must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('acknowledged', $e->getMessage());
		}

		// No revision was appended.
		self::assertSame(1, $created['revision']);
		self::assertSame(Occurrence::SUSPENSION_NONE, $this->openOccurrence()->getSuspensionState(), 'the pending request kept zero execution effect');
	}

	/**
	 * RF-073-WP2-18: a foreign/unknown illness subject is the §3.5 404
	 * class (invisible or foreign target) — never a 409 confirming the
	 * account exists.
	 */
	public function testRecognizeIllness_ForeignSubject_NotFound(): void {
		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->pauseService->recognizeIllness((int)$this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'subjectUserId' => 'pa_outsider',
			'startDate' => $this->todayKey,
			'endDate' => (new \DateTime($this->todayKey))->modify('+2 days')->format('Y-m-d'),
			'reasonCode' => Pause::REASON_ILLNESS,
		]);
	}

	// ------------------------------------------------------------------
	// CS-PLAN-073 fix round R2/R3/R6: full-history shortening,
	// recognition-time eligibility and joint suspension/resumption
	// ------------------------------------------------------------------

	private function dailyStaticTask(string $assignee, string $anchorDayKey, string $name): int {
		$floorId = $this->createFloor((int)$this->houseId, 'Floor ' . $name, 4);
		$roomId = $this->createRoom((int)$this->houseId, $floorId, 'Room ' . $name);
		$taskId = $this->createTask((int)$this->houseId, $name, '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->set('frequency_value', $qb->createNamedParameter(1))
			->set('scheduling_mode', $qb->createNamedParameter('static'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, $assignee, $anchorDayKey . ' 00:00:00');
		$this->cleanupRoomTaskIds[] = $roomTaskId;
		return $roomTaskId;
	}

	private function occurrenceOn(int $roomTaskId, string $dayKey): Occurrence {
		foreach (\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findByRoomTask($roomTaskId) as $row) {
			if ($row->getPlannedDate()->format('Y-m-d') === $dayKey) {
				return $row;
			}
		}
		self::fail('no occurrence planned on ' . $dayKey);
	}

	private function dayKey(int $offset): string {
		return (new \DateTime($this->todayKey))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
	}

	private function reload(Occurrence $occurrence): Occurrence {
		return \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->find((int)$occurrence->getId());
	}

	/**
	 * R2 / RF-073-WP2-23: a shortening removes the SUPERSEDED FUTURE
	 * coverage with the full persisted revision history in place — the
	 * retained old revision must not keep the shortened-away dates alive,
	 * while today's recognized excuse survives and repeated reconciliation
	 * is inert.
	 */
	public function testShortening_RemovesSupersededFutureCoverage_FullPersistedHistory(): void {
		$roomTaskId = $this->dailyStaticTask('pa_mom', $this->todayKey, 'Shorten dishes');
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $created['state']);
		for ($offset = 0; $offset <= 4; $offset++) {
			self::assertSame(
				Occurrence::SUSPENSION_SUSPENDED,
				$this->occurrenceOn($roomTaskId, $this->dayKey($offset))->getSuspensionState(),
				'the acknowledged range suspends every covered day'
			);
		}
		self::assertSame(Occurrence::SUSPENSION_NONE, $this->occurrenceOn($roomTaskId, $this->dayKey(5))->getSuspensionState());

		$shortened = $this->pauseService->shorten((int)$this->houseId, 'pa_mom', $created['pauseGroupId'], [
			'commandId' => $this->uuid(),
			'expectedRevision' => $created['revision'],
			'endDate' => $this->todayKey,
		]);
		self::assertSame(2, $shortened['revision']);

		// Today (the decision day in the saved zone) keeps its recognized
		// excuse; every superseded future date actually loses coverage —
		// with BOTH revisions still persisted in the history.
		$today = $this->reload($this->occurrenceOn($roomTaskId, $this->todayKey));
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $today->getSuspensionState(), "today's recognized excuse survives the shortening");
		$evidence = $today->getSuspensionEvidence();
		self::assertIsArray($evidence);
		self::assertSame($created['pauseGroupId'], (int)$evidence[0]['pauseGroupId']);
		for ($offset = 1; $offset <= 4; $offset++) {
			self::assertSame(
				Occurrence::SUSPENSION_NONE,
				$this->reload($this->occurrenceOn($roomTaskId, $this->dayKey($offset)))->getSuspensionState(),
				"the superseded future day +$offset resumes duty"
			);
		}

		// The decision boundary is reproducible: repeated reconciliation
		// (job replay) is inert, and the resumed future day plans its
		// agreed share again.
		self::assertSame(0, $this->pauseService->applyPauseEffectsUnderLock((int)$this->houseId), 'repeated reconciliation writes nothing');
		$tomorrow = $this->dayKey(1);
		$report = \OC::$server->get(DailyCapacityService::class)->evaluateDays((int)$this->houseId, [$this->todayKey, $tomorrow]);
		self::assertSame(0, $report[$this->todayKey]['pa_mom']['plannedEnergyMinor'], 'the suspended today consumes no live capacity');
		self::assertSame(200, $report[$tomorrow]['pa_mom']['plannedEnergyMinor'], 'the resumed future duty charges its agreed share again');
	}

	/**
	 * R2 / plan §3.4 row 6: a child's shortening stays a PENDING revision
	 * (acknowledged coverage unchanged) until the owner explicitly
	 * acknowledges it — and only then does the future coverage shrink.
	 */
	public function testChildShortening_PendingLeavesCoverageUnchanged_UntilOwnerAcknowledges(): void {
		$roomTaskId = $this->dailyStaticTask('pa_kid', $this->todayKey, 'Kid shorten');
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		self::assertSame(Pause::STATE_REQUESTED, $created['state']);
		self::assertSame(Occurrence::SUSPENSION_NONE, $this->occurrenceOn($roomTaskId, $this->dayKey(1))->getSuspensionState(), 'pending has no effect');

		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 1]);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->reload($this->occurrenceOn($roomTaskId, $this->dayKey(1)))->getSuspensionState());

		$pendingShorten = $this->pauseService->shorten((int)$this->houseId, 'pa_kid', $created['pauseGroupId'], [
			'commandId' => $this->uuid(),
			'expectedRevision' => 1,
			'endDate' => $this->todayKey,
		]);
		self::assertSame(Pause::STATE_REQUESTED, $pendingShorten['state'], "a child's change needs owner acknowledgment");
		self::assertSame(2, $pendingShorten['revision']);
		self::assertSame(
			Occurrence::SUSPENSION_SUSPENDED,
			$this->reload($this->occurrenceOn($roomTaskId, $this->dayKey(1)))->getSuspensionState(),
			'the undecided shortening leaves the acknowledged coverage unchanged'
		);

		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 2]);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->reload($this->occurrenceOn($roomTaskId, $this->todayKey))->getSuspensionState(), 'today stays recognized');
		self::assertSame(
			Occurrence::SUSPENSION_NONE,
			$this->reload($this->occurrenceOn($roomTaskId, $this->dayKey(1)))->getSuspensionState(),
			'the acknowledged shortening removes the superseded future coverage'
		);
	}

	/**
	 * R3 / RF-073-WP2-25: an ORDINARY acknowledgment whose range reaches
	 * back over an already expired miss never neutralizes it — only the
	 * explicit retrospective-illness exception does, without fabricating a
	 * completion, and the selection keeps skipping neutralized misses.
	 */
	public function testLateOrdinaryAcknowledgment_DoesNotExcuseExpiredMiss_RetrospectiveIllnessDoes(): void {
		$missKey = $this->dayKey(-2);
		$roomTaskId = $this->dailyStaticTask('pa_mom', $missKey, 'Missed dishes');
		$miss = $this->occurrenceOn($roomTaskId, $missKey);
		self::assertLessThanOrEqual(new \DateTime(), $miss->getWindowEnd(), 'the fixture owns an expired miss');

		$this->pauseService->requestForSelf((int)$this->houseId, 'pa_mom', [
			'commandId' => $this->uuid(),
			'startDate' => $missKey,
			'endDate' => $this->dayKey(2),
			'reasonCode' => Pause::REASON_TRAVEL,
		]);

		self::assertSame(
			Occurrence::SUSPENSION_NONE,
			$this->reload($miss)->getSuspensionState(),
			'an ordinary late acknowledgment cannot erase an expired miss'
		);
		self::assertSame(
			Occurrence::SUSPENSION_SUSPENDED,
			$this->occurrenceOn($roomTaskId, $this->todayKey)->getSuspensionState(),
			'the still-open obligation of the same range is recognized'
		);

		$illness = $this->pauseService->recognizeIllness((int)$this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'subjectUserId' => 'pa_mom',
			'startDate' => $missKey,
			'endDate' => $missKey,
			'reasonCode' => Pause::REASON_ILLNESS,
		]);

		$neutralized = $this->reload($miss);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $neutralized->getSuspensionState(), 'the explicit retrospective exception neutralizes the qualifying old miss');
		self::assertNull($neutralized->getCompletionId(), 'no completion is fabricated');
		self::assertNull($neutralized->getCoveredByCompletionId());
		$evidence = $neutralized->getSuspensionEvidence();
		self::assertIsArray($evidence);
		self::assertSame($illness['pauseGroupId'], (int)$evidence[0]['pauseGroupId']);

		// The neutralized miss is no catch-up target anymore; the ordinary
		// late-acknowledged miss (yesterday) stays an open miss.
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);
		$selection = $this->occurrenceService->selectionFor($roomTask, new \DateTime(), (int)$this->houseId);
		self::assertNotNull($selection);
		self::assertSame('late', $selection['classification']);
		self::assertSame($this->dayKey(-1), $selection['occurrence']->getPlannedDate()->format('Y-m-d'), 'the untouched expired miss remains the catch-up target');
	}

	/**
	 * R3: the eligibility gate uses the ACTUAL recognition decision, not a
	 * child's earlier request time — a request made before the deadline
	 * but approved after it stays ineffective for the expired miss.
	 */
	public function testRequestBeforeExpiryApprovedAfterDeadline_UsesTheDecisionTime(): void {
		$missKey = $this->dayKey(-1);
		$roomTaskId = $this->dailyStaticTask('pa_kid', $missKey, 'Kid missed');
		$miss = $this->occurrenceOn($roomTaskId, $missKey);
		self::assertLessThanOrEqual(new \DateTime(), $miss->getWindowEnd(), 'the miss window already closed');

		// The child requests a pause covering the miss day while the row
		// is already expired; the pending request has no effect.
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', [
			'commandId' => $this->uuid(),
			'startDate' => $missKey,
			'endDate' => $this->dayKey(2),
			'reasonCode' => Pause::REASON_OVERLOAD,
		]);
		self::assertSame(Occurrence::SUSPENSION_NONE, $this->reload($miss)->getSuspensionState());

		// The owner approves AFTER the original deadline: the decision time
		// governs, so the expired miss is NOT neutralized — while today's
		// still-open obligation is recognized.
		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 1]);
		self::assertSame(
			Occurrence::SUSPENSION_NONE,
			$this->reload($miss)->getSuspensionState(),
			'a late approval of an early request cannot erase the expired miss'
		);
		self::assertSame(
			Occurrence::SUSPENSION_SUSPENDED,
			$this->occurrenceOn($roomTaskId, $this->todayKey)->getSuspensionState()
		);
	}

	/**
	 * R6 / RF-073-WP2-24: pausing ONE obligated participant of an explicit
	 * joint 50/50 arrangement suspends the whole occurrence (retained
	 * snapshot, zero live planned capacity, no automatic redistribution);
	 * an explicit owner-recorded feasible 100% agreement before the
	 * original deadline resumes it, while an over-budget proposal applies
	 * nothing.
	 */
	public function testJointPause_SuspendsWholeOccurrence_ExplicitFeasibleResumption(): void {
		$roomTaskId = $this->dailyStaticTask('pa_mom', $this->todayKey, 'Joint dishes');
		$agreements = \OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class);
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);

		// Baseline of mom's day BEFORE the joint arrangement (the setUp
		// weekly fixture task plus the static task's full 200 minor share)
		// — the joint assertions are relative to it.
		$baseline = \OC::$server->get(DailyCapacityService::class)
			->evaluateDays((int)$this->houseId, [$this->todayKey])[$this->todayKey]['pa_mom']['plannedEnergyMinor'];

		// Install the explicit joint 50/50 arrangement on today's target.
		$receipt = $this->renderedReceipt($roomTask, $this->todayKey);
		$agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $this->agreementPayload($receipt, [
			'participants' => [
				['userId' => 'pa_mom', 'role' => 'joint', 'sharePct' => 50],
				['userId' => 'pa_kid', 'role' => 'joint', 'sharePct' => 50],
			],
		]));
		$today = $this->occurrenceOn($roomTaskId, $this->todayKey);

		// The child's pause is acknowledged by the owner.
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 1]);

		$suspended = $this->reload($today);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $suspended->getSuspensionState(), 'one paused obligated joint participant suspends the occurrence');
		$evidence = $suspended->getSuspensionEvidence();
		self::assertIsArray($evidence);
		self::assertSame($created['pauseGroupId'], (int)$evidence[0]['pauseGroupId']);

		// The 50/50 snapshot is retained — NO automatic redistribution.
		$members = $this->memberRows((int)$today->getId());
		self::assertCount(2, $members);
		foreach ($members as $row) {
			self::assertSame('active', $row['disposition']);
			self::assertSame(50, (int)$row['share_pct'], 'the original shares are retained while suspended');
		}

		// Zero live planned capacity for BOTH partners while suspended
		// (mom's day loses exactly the static task's 200 minor share).
		$report = \OC::$server->get(DailyCapacityService::class)->evaluateDays((int)$this->houseId, [$this->todayKey])[$this->todayKey];
		self::assertSame($baseline - 200, $report['pa_mom']['plannedEnergyMinor'], 'the suspended joint work charges the unpaused partner nothing');
		self::assertSame(0, $report['pa_kid']['plannedEnergyMinor']);
		self::assertTrue($report['pa_kid']['paused']);
		self::assertFalse($report['pa_mom']['paused']);

		// A suspended obligation is no selection target.
		self::assertNull($this->occurrenceService->selectionFor($roomTask, new \DateTime(), (int)$this->houseId));

		// An over-budget resumption proposal applies NOTHING.
		$this->setEnergyBudget('pa_mom', 1);
		$suspendedReceipt = $this->renderedReceipt($roomTask, $this->todayKey);
		$agreementsBefore = count($this->fetchAgreementRows((int)$today->getId()));
		try {
			$agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $this->agreementPayload($suspendedReceipt, [
				'participants' => [['userId' => 'pa_mom', 'role' => 'responsible', 'sharePct' => 100]],
			]));
			self::fail('an over-capacity resumption must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('energy budget', $e->getMessage());
		}
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->reload($today)->getSuspensionState());
		self::assertCount($agreementsBefore, $this->fetchAgreementRows((int)$today->getId()), 'the rejected proposal wrote no evidence');
		self::assertSame($suspended->getVersion(), $this->reload($today)->getVersion(), 'the rejected proposal wrote no version bump');

		// The explicit feasible 100% agreement before the original deadline
		// resumes the occurrence.
		$this->setEnergyBudget('pa_mom', 10);
		$resumeReceipt = $this->renderedReceipt($roomTask, $this->todayKey);
		$refs = $agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $this->agreementPayload($resumeReceipt, [
			'participants' => [['userId' => 'pa_mom', 'role' => 'responsible', 'sharePct' => 100]],
		]));

		$resumed = $this->reload($today);
		self::assertSame(Occurrence::SUSPENSION_NONE, $resumed->getSuspensionState(), 'the accepted arrangement is persisted — the next GET/job agrees');
		self::assertSame($refs['expectedVersion'], $resumed->getVersion());
		$members = $this->memberRows((int)$today->getId());
		$byDisposition = [];
		foreach ($members as $row) {
			$byDisposition[$row['disposition']] = $row;
		}
		self::assertSame(100, (int)$byDisposition['active']['share_pct']);
		self::assertSame('released', $byDisposition['released']['disposition']);
		self::assertSame(50, (int)$byDisposition['released']['share_pct'], 'the paused partner is released with its retained snapshot');
		self::assertSame(0, (int)$byDisposition['released']['obligated']);

		// The pause itself is untouched (neutral), and the single-occurrence
		// agreement never changed the future defaults: day +1 still carries
		// the sole-responsible default (mom, unpaused) and stays open.
		$pauseRows = $this->fetchPauseRows($created['pauseGroupId']);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $pauseRows[count($pauseRows) - 1]['state']);
		self::assertSame(
			Occurrence::SUSPENSION_NONE,
			$this->reload($this->occurrenceOn($roomTaskId, $this->dayKey(1)))->getSuspensionState(),
			'the concrete joint agreement applied to the agreed occurrence only — future defaults are untouched'
		);
		$report = \OC::$server->get(DailyCapacityService::class)->evaluateDays((int)$this->houseId, [$this->todayKey])[$this->todayKey];
		self::assertSame($baseline, $report['pa_mom']['plannedEnergyMinor'], 'the resumed duty charges the full agreed 100% share again');

		// Voluntary actual work by the released partner stays possible —
		// the completion path is never blocked by capacity (proved in the
		// completion suites); here the paused flag remains a planning fact.
		self::assertTrue($report['pa_kid']['paused']);
	}

	/**
	 * R6: a resumption after the original deadline is rejected with zero
	 * writes, and the missing consent attestation stays a 400-class input
	 * rejection.
	 */
	public function testJointResumption_AfterDeadlineOrWithoutConsent_RejectedWithZeroWrites(): void {
		$roomTaskId = $this->dailyStaticTask('pa_mom', $this->todayKey, 'Joint deadline');
		$agreements = \OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class);
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);

		$receipt = $this->renderedReceipt($roomTask, $this->todayKey);
		$agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $this->agreementPayload($receipt, [
			'participants' => [
				['userId' => 'pa_mom', 'role' => 'joint', 'sharePct' => 50],
				['userId' => 'pa_kid', 'role' => 'joint', 'sharePct' => 50],
			],
		]));
		$today = $this->occurrenceOn($roomTaskId, $this->todayKey);
		$created = $this->pauseService->requestForSelf((int)$this->houseId, 'pa_kid', array_merge(['commandId' => $this->uuid()], $this->range()));
		$this->pauseService->approve((int)$this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 1]);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $this->reload($today)->getSuspensionState());

		// Force the original exclusive deadline into the past.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('window_end', $qb->createNamedParameter((new \DateTime('-1 hour'))->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$today->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$lateReceipt = $this->renderedReceipt($roomTask, $this->todayKey, 'late');
		$versionBefore = $this->reload($today)->getVersion();
		try {
			$agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $this->agreementPayload($lateReceipt, [
				'participants' => [['userId' => 'pa_mom', 'role' => 'responsible', 'sharePct' => 100]],
			]));
			self::fail('a resumption after the original deadline must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('deadline', $e->getMessage());
		}
		self::assertSame($versionBefore, $this->reload($today)->getVersion(), 'zero writes after the deadline rejection');

		// The missing consent attestation is the 400 class, zero writes.
		$payload = $this->agreementPayload($lateReceipt, [
			'participants' => [['userId' => 'pa_mom', 'role' => 'responsible', 'sharePct' => 100]],
		]);
		$payload['consent'] = false;
		try {
			$agreements->amend((int)$this->houseId, 'pa_owner', $roomTaskId, $payload);
			self::fail('a resumption without consent must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('consent', $e->getMessage());
		}
		self::assertSame($versionBefore, $this->reload($today)->getVersion());
	}

	// ------------------------------------------------------------------
	// Helpers for the agreement-driven pause scenarios
	// ------------------------------------------------------------------

	/**
	 * Render the occurrence block of one planned day through the real read
	 * path (stamped effective facts + authoritative selection token).
	 *
	 * @return array{occurrenceId: string, expectedVersion: int, selectionToken: string}
	 */
	private function renderedReceipt(\OCA\ChoreStrike\Db\RoomTask $roomTask, string $dayKey, string $classification = 'agreed'): array {
		$entries = $this->occurrenceService->agreedOccurrencesForHouseTasks((int)$this->houseId, [$roomTask], $dayKey, $dayKey);
		$entry = $entries[$roomTask->getId()][0] ?? null;
		self::assertNotNull($entry, 'the read path renders the target of ' . $dayKey);
		return [
			'occurrenceId' => $entry['occurrence']->toPublicKey(),
			'expectedVersion' => $entry['occurrence']->getVersion(),
			'selectionToken' => $this->occurrenceService->selectionToken(
				$entry['occurrence'],
				$entry['revision'],
				$classification,
				(int)$this->houseId,
				null,
				$entry['workAppointment']
			),
		];
	}

	private function agreementPayload(array $receipt, array $extra = []): array {
		return array_merge([
			'commandId' => $this->uuid(),
			'occurrenceId' => $receipt['occurrenceId'],
			'expectedVersion' => $receipt['expectedVersion'],
			'selectionToken' => $receipt['selectionToken'],
			'consent' => true,
		], $extra);
	}

	private function setEnergyBudget(string $userId, int $budget): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode([
				'energyBudget' => array_fill_keys(
					['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
					$budget
				),
			])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function memberRows(int $occurrenceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_occ_members')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrenceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchAgreementRows(int $occurrenceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_agreements')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrenceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchPauseRows(int $pauseGroupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_pauses')
			->where($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}

	private function deleteRoomTaskRows(int $roomTaskId): void {
		// Clear the completion ledger and the timeline tables (each test
		// runs in its own house, so the house-scoped wipe is bounded);
		// `agreements` references occurrences, not room tasks.
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*chorestrike_agreements WHERE occurrence_id IN (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
			[$this->houseId]
		);
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*chorestrike_pauses WHERE house_id = ?',
			[$this->houseId]
		);
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*chorestrike_occ_members WHERE occurrence_id IN (SELECT id FROM *PREFIX*chorestrike_occurrences WHERE house_id = ?)',
			[$this->houseId]
		);
		foreach (['chorestrike_completed_tasks', 'chorestrike_occurrences', 'chorestrike_schedule_revs'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
