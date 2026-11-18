<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Cycle;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CycleService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PauseProjectionService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\RecognitionFact;
use OCA\ChoreStrike\Service\RecognitionFactsService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * DB-backed absence lifecycle proofs (CS-PLAN-073 §3.4, ADR-020) and what
 * an absence means for the work (ADR-021 decision 5, D-091-03):
 * - a window that lies inside an acknowledged absence is NEUTRAL for the
 *   absent member — neither kept nor missed — decided on read, so nothing
 *   is stored on the work and the work stays possible;
 * - a pending request from before ADR-020 has no effect until the owner
 *   decides it;
 * - shortening or deleting frees the following days while today's
 *   recognized excuse is preserved;
 * - an absence entered after a window ended is honoured;
 * - the owner role gates and the revision-staleness conflict.
 *
 * @group DB
 *
 * @covers \OCA\ChoreStrike\Service\PauseService
 * @covers \OCA\ChoreStrike\Service\PauseProjectionService
 * @covers \OCA\ChoreStrike\Service\RecognitionFactsService
 */
class PauseAbsenceTest extends IntegrationTestCase {
	private PauseService $pauseService;
	private HouseUserMapper $houseUserMapper;
	private string $todayKey;
	private int $houseId;
	private int $roomTaskId;

	protected function setUp(): void {
		parent::setUp();
		$this->pauseService = \OC::$server->get(PauseService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');

		$houseId = $this->createHouse('pa_owner', 'Pause House');
		$this->addHouseMember($houseId, 'pa_mom', 'Mom', 'member');
		$this->addHouseMember($houseId, 'pa_kid', 'Kid', 'child');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Pause dishes', '', 5, 2);
		$this->roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'pa_mom');
		$this->houseId = $houseId;
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	private function range(): array {
		return ['startDate' => $this->todayKey, 'endDate' => $this->dayKey(4), 'reasonCode' => Pause::REASON_TRAVEL];
	}

	private function dayKey(int $offset): string {
		return (new \DateTime($this->todayKey))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
	}

	private function walletId(string $userId): int {
		return (int)$this->houseUserMapper->findByNextcloudUserId($this->houseId, $userId)->getWalletId();
	}

	/**
	 * The fact of the room task's cycle whose window ends on the day, read
	 * as the recognitions read it: the current cycle or, for a fixed rule,
	 * one of the cycles that follow it.
	 */
	private function factOn(int $roomTaskId, string $dayKey): RecognitionFact {
		$house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		$facts = \OC::$server->get(RecognitionFactsService::class)->openFacts($house, new \DateTime(), $dayKey, $dayKey, true);
		foreach ($facts as $fact) {
			if ($fact->roomTaskId === $roomTaskId) {
				return $fact;
			}
		}
		self::fail('no cycle of the room task ends on ' . $dayKey);
	}

	/** Whether the room task's cycle of that day is neutral for the member. */
	private function isNeutral(int $roomTaskId, string $dayKey, string $userId): bool {
		return isset($this->factOn($roomTaskId, $dayKey)->neutralFor[$this->walletId($userId)]);
	}

	/** Whether a recognized absence covers the member on that day, whatever they are responsible for. */
	private function isAbsentOn(string $userId, string $dayKey): bool {
		$recognized = \OC::$server->get(PauseProjectionService::class)->recognizedForHouse($this->houseId);
		$walletId = $this->walletId($userId);
		return isset(PauseProjectionService::walletsAbsentThroughout($recognized, $dayKey, $dayKey, [$walletId => true])[$walletId]);
	}

	/** A task the CHILD owes: the shared fixture is assigned to pa_mom. */
	private function kidTask(): int {
		$floorId = $this->createFloor($this->houseId, 'Upper Floor', 2);
		$roomId = $this->createRoom($this->houseId, $floorId, 'Playroom');
		return $this->assignTaskToRoom($this->createTask($this->houseId, 'Kid chores', '', 5, 2), $roomId, 'pa_kid');
	}

	/** A fixed daily task from the given first day: one cycle per day. */
	private function dailyFixedTask(string $assignee, string $firstDayKey, string $name): int {
		$floorId = $this->createFloor($this->houseId, 'Floor ' . $name, 4);
		$roomId = $this->createRoom($this->houseId, $floorId, 'Room ' . $name);
		$taskId = $this->createTask($this->houseId, $name, '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->set('frequency_value', $qb->createNamedParameter(1))
			->set('scheduling_mode', $qb->createNamedParameter('static'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $this->assignTaskToRoom($taskId, $roomId, $assignee, $firstDayKey . ' 00:00:00');
	}

	/** A request that was still pending when ADR-020 arrived. */
	private function pendingKidRequest(?int $pauseGroupId = null, ?string $endDate = null): array {
		$range = $this->range();
		return $this->seedPendingPauseRequest($this->houseId, 'pa_kid', $range['startDate'], $endDate ?? $range['endDate'], $pauseGroupId);
	}

	private function selfAbsence(string $userId, ?array $range = null): array {
		return $this->pauseService->requestForSelf($this->houseId, $userId, array_merge(['commandId' => $this->uuid()], $range ?? $this->range()));
	}

	// ------------------------------------------------------------------
	// Neutral windows
	// ------------------------------------------------------------------

	public function testAcknowledgedPersonalAbsence_MakesTheCoveredWindowNeutral(): void {
		$block = fn (): array => \OC::$server->get(RoomTaskReadService::class)->cycleFacts(
			$this->houseId,
			[\OC::$server->get(RoomTaskMapper::class)->find($this->roomTaskId)]
		)[$this->roomTaskId]['block'];
		$before = $block();

		$result = $this->selfAbsence('pa_mom');
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state'], 'an adult self-absence is acknowledged directly');

		$fact = $this->factOn($this->roomTaskId, $this->todayKey);
		self::assertSame([$this->walletId('pa_mom') => true], $fact->neutralFor, 'the covered window is neutral for the absent member');
		self::assertTrue($fact->houseNeutral, 'and for the household: nobody else is responsible');
		self::assertSame(RecognitionFact::OPEN, $fact->state, 'neutrality never fabricates a completion');

		// Nothing is stored on the work: the cycle stays as it was and the
		// work stays possible.
		self::assertSame(WindowService::STATE_OPEN, $before['state']);
		self::assertSame($before, $block(), 'an absence changes no window and no cycle version');
	}

	/** ADR-020: a child's absence needs no request and counts at once. */
	public function testChildAbsence_IsNeutralAtOnceAndVisibleToEveryMember(): void {
		$kidTaskId = $this->kidTask();

		$result = $this->selfAbsence('pa_kid');

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state']);
		self::assertTrue($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'));
		foreach (['pa_owner', 'pa_mom', 'pa_kid'] as $viewer) {
			self::assertContains(
				$result['pauseGroupId'],
				array_column($this->pauseService->listForViewer($this->houseId, $viewer), 'pauseGroupId'),
				"$viewer sees the absence"
			);
		}
	}

	public function testPendingRequestFromBeforeTheUpdate_HasNoEffectUntilOwnerDecides(): void {
		$kidTaskId = $this->kidTask();

		$result = $this->pendingKidRequest();
		self::assertFalse($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'), 'a pending request changes no duty');

		// A non-owner cannot decide the request.
		try {
			$this->pauseService->approve($this->houseId, 'pa_kid', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision']]);
			self::fail('a child cannot approve its own request');
		} catch (ForbiddenException $e) {
			self::assertSame('Owner role required', $e->getMessage());
		}

		$approved = $this->pauseService->approve($this->houseId, 'pa_owner', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision']]);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $approved['state']);

		self::assertTrue($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'), 'the acknowledgment makes the covered window neutral');
	}

	public function testRejectedRequest_NeverExcuses(): void {
		$kidTaskId = $this->kidTask();
		$created = $this->pendingKidRequest();
		$this->pauseService->reject($this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $created['revision']]);

		self::assertFalse($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'), 'a rejected request has no effect');
	}

	public function testHouseholdAbsence_IsNeutralForEveryResponsibleMember(): void {
		$kidTaskId = $this->kidTask();
		$result = $this->pauseService->createHousePause($this->houseId, 'pa_owner', array_merge(['commandId' => $this->uuid()], $this->range()));

		self::assertTrue($this->isNeutral($this->roomTaskId, $this->todayKey, 'pa_mom'));
		self::assertTrue($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'));

		// The viewer-safe list shows the house absence without a subject.
		$entry = null;
		foreach ($this->pauseService->listForViewer($this->houseId, 'pa_kid') as $item) {
			if ($item['pauseGroupId'] === $result['pauseGroupId']) {
				$entry = $item;
			}
		}
		self::assertNotNull($entry, 'the house absence is visible to every member');
		self::assertNull($entry['subjectWalletId']);
	}

	/**
	 * A task two members share stays a duty of the one who is there: it is
	 * neutral for the absent member only, never for the household.
	 */
	public function testSharedTask_IsNeutralForTheAbsentMemberOnly(): void {
		$actor = $this->houseUserMapper->findByNextcloudUserId($this->houseId, 'pa_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($this->roomTaskId, ['pa_mom', 'pa_owner'], $this->houseId, $actor);

		$this->selfAbsence('pa_mom');

		$fact = $this->factOn($this->roomTaskId, $this->todayKey);
		self::assertSame([$this->walletId('pa_mom') => true], $fact->neutralFor);
		self::assertFalse($fact->houseNeutral, 'the member who is there still owes it');
		self::assertTrue($fact->countsFor($this->walletId('pa_owner')));
		self::assertFalse($fact->countsFor($this->walletId('pa_mom')));
	}

	/**
	 * D-091-03: neutrality is decided on read. An absence entered after a
	 * window ended is honoured — the missed cycle stays a fact, it just
	 * does not count against the member who was away.
	 */
	public function testAbsenceEnteredAfterTheWindow_IsHonoured(): void {
		$roomTaskId = $this->dailyFixedTask('pa_mom', $this->dayKey(-2), 'Missed dishes');
		$house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		\OC::$server->get(CycleService::class)->advanceHouse($house, new \DateTime());
		$closed = fn (): array => array_values(array_filter(
			\OC::$server->get(RecognitionFactsService::class)->closedFactsPage($house, null, 0, 50)['facts'],
			static fn (RecognitionFact $fact): bool => $fact->roomTaskId === $roomTaskId
		));
		self::assertSame([$this->dayKey(-2), $this->dayKey(-1)], array_map(static fn (RecognitionFact $fact): string => $fact->day, $closed()), 'the fixture owns two missed days');
		self::assertSame([[], []], array_map(static fn (RecognitionFact $fact): array => $fact->neutralFor, $closed()));

		$this->pauseService->recognizeIllness($this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'subjectUserId' => 'pa_mom',
			'startDate' => $this->dayKey(-2),
			'endDate' => $this->dayKey(-2),
			'reasonCode' => Pause::REASON_ILLNESS,
		]);

		[$illDay, $dayAfter] = $closed();
		self::assertSame([$this->walletId('pa_mom') => true], $illDay->neutralFor, 'the day she was ill no longer counts against her');
		self::assertSame(RecognitionFact::MISSED, $illDay->state, 'no completion is fabricated');
		self::assertSame([], $dayAfter->neutralFor, 'the day after stays a miss');
		$rows = array_values(array_filter(
			\OC::$server->get(\OCA\ChoreStrike\Db\CycleMapper::class)->findByHouseEndingBetween($this->houseId, $this->dayKey(-2), $this->dayKey(-1)),
			static fn (Cycle $row): bool => $row->getRoomTaskId() === $roomTaskId
		));
		self::assertSame([Cycle::OUTCOME_MISSED, Cycle::OUTCOME_MISSED], array_map(static fn (Cycle $row): string => $row->getOutcome(), $rows), 'the stored rows are untouched');
	}

	/**
	 * D-091-33 (WP-B1 interim review): a closed cycle keeps its neutrality
	 * when the absent member later leaves the house.
	 */
	public function testClosedCycle_StaysNeutralAfterTheAbsentMemberLeaves(): void {
		$roomTaskId = $this->dailyFixedTask('pa_mom', $this->dayKey(-2), 'Leaving dishes');
		$this->pauseService->recognizeIllness($this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'subjectUserId' => 'pa_mom',
			'startDate' => $this->dayKey(-2),
			'endDate' => $this->dayKey(-2),
			'reasonCode' => Pause::REASON_ILLNESS,
		]);
		$house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		\OC::$server->get(CycleService::class)->advanceHouse($house, new \DateTime());
		$momWallet = $this->walletId('pa_mom');
		$illDay = fn (): RecognitionFact => array_values(array_filter(
			\OC::$server->get(RecognitionFactsService::class)->closedFactsPage($house, null, 0, 50)['facts'],
			fn (RecognitionFact $fact): bool => $fact->roomTaskId === $roomTaskId && $fact->day === $this->dayKey(-2)
		))[0];
		self::assertSame([$momWallet => true], $illDay()->neutralFor, 'neutral while she is a member');

		$this->actingAs('pa_owner');
		\OC::$server->get(HouseService::class)->removeHouseMember((int)$this->houseUserMapper->findByNextcloudUserId($this->houseId, 'pa_mom')->getId());

		self::assertSame([$momWallet => true], $illDay()->neutralFor, 'and still neutral after she left');
	}

	// ------------------------------------------------------------------
	// Decisions and shortening
	// ------------------------------------------------------------------

	public function testStaleRevisionDecision_Conflicts(): void {
		$result = $this->pendingKidRequest();

		$this->expectException(ConflictException::class);
		$this->pauseService->approve($this->houseId, 'pa_owner', $result['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $result['revision'] + 3]);
	}

	public function testShorteningByNonOwnerForeignSubject_Forbidden(): void {
		$created = $this->selfAbsence('pa_mom');

		$this->expectException(ForbiddenException::class);
		$this->pauseService->shorten(
			$this->houseId,
			'pa_kid',
			$created['pauseGroupId'],
			['commandId' => $this->uuid(), 'expectedRevision' => $created['revision'], 'endDate' => $this->todayKey]
		);
	}

	/**
	 * RF-073-WP2-18: shortening is scoped to ACKNOWLEDGED absences (plan
	 * §3.4 row 6) — a pending request carries no recognized effect to
	 * shorten and is decided (approve/reject) instead.
	 */
	public function testShorten_PendingRequest_Conflicts(): void {
		$kidTaskId = $this->kidTask();
		$created = $this->pendingKidRequest();

		try {
			$this->pauseService->shorten(
				$this->houseId,
				'pa_owner',
				$created['pauseGroupId'],
				['commandId' => $this->uuid(), 'expectedRevision' => $created['revision'], 'endDate' => $this->todayKey]
			);
			self::fail('shortening a pending request must conflict');
		} catch (ConflictException $e) {
			self::assertStringContainsString('acknowledged', $e->getMessage());
		}

		self::assertCount(1, $this->fetchPauseRows($created['pauseGroupId']), 'no revision was appended');
		self::assertFalse($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'), 'the pending request kept zero effect');
	}

	/**
	 * RF-073-WP2-18: a foreign/unknown illness subject is the §3.5 404
	 * class (invisible or foreign target) — never a 409 confirming the
	 * account exists.
	 */
	public function testRecognizeIllness_ForeignSubject_NotFound(): void {
		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->pauseService->recognizeIllness($this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(),
			'subjectUserId' => 'pa_outsider',
			'startDate' => $this->todayKey,
			'endDate' => $this->dayKey(2),
			'reasonCode' => Pause::REASON_ILLNESS,
		]);
	}

	/**
	 * R2 / RF-073-WP2-23: a shortening removes the SUPERSEDED FUTURE
	 * coverage with the full persisted revision history in place — the
	 * retained old revision must not keep the shortened-away dates alive,
	 * while today's recognized excuse survives.
	 */
	public function testShortening_RemovesSupersededFutureCoverage_FullPersistedHistory(): void {
		$roomTaskId = $this->dailyFixedTask('pa_mom', $this->todayKey, 'Shorten dishes');
		$created = $this->selfAbsence('pa_mom');
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $created['state']);
		for ($offset = 0; $offset <= 4; $offset++) {
			self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey($offset), 'pa_mom'), 'the acknowledged range covers every day');
		}
		self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey(5), 'pa_mom'));

		$shortened = $this->pauseService->shorten($this->houseId, 'pa_mom', $created['pauseGroupId'], [
			'commandId' => $this->uuid(),
			'expectedRevision' => $created['revision'],
			'endDate' => $this->todayKey,
		]);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $shortened['state'], 'an adult self-shortening appends an acknowledged revision');
		self::assertSame(2, $shortened['revision']);

		// Today (the decision day in the saved zone) keeps its recognized
		// excuse; every superseded future date actually loses coverage —
		// with BOTH revisions still persisted in the history.
		self::assertCount(2, $this->fetchPauseRows($created['pauseGroupId']));
		self::assertTrue($this->isNeutral($roomTaskId, $this->todayKey, 'pa_mom'), "today's recognized excuse survives the shortening");
		for ($offset = 1; $offset <= 4; $offset++) {
			self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey($offset), 'pa_mom'), "the superseded future day +$offset is a duty again");
		}
	}

	/**
	 * ADR-020: a child's shortening is acknowledged directly and removes
	 * the superseded future coverage without an owner decision.
	 */
	public function testChildShortening_TakesEffectWithoutOwnerDecision(): void {
		$roomTaskId = $this->dailyFixedTask('pa_kid', $this->todayKey, 'Kid shorten');
		$created = $this->selfAbsence('pa_kid');

		$shortened = $this->pauseService->shorten($this->houseId, 'pa_kid', $created['pauseGroupId'], [
			'commandId' => $this->uuid(),
			'expectedRevision' => 1,
			'endDate' => $this->todayKey,
		]);

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $shortened['state']);
		self::assertTrue($this->isNeutral($roomTaskId, $this->todayKey, 'pa_kid'), 'today stays recognized');
		self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'));
	}

	/**
	 * R2 / plan §3.4 row 6 for rows stored before ADR-020: a pending
	 * shortening leaves the acknowledged coverage unchanged until the
	 * owner explicitly acknowledges it — and only then does the future
	 * coverage shrink.
	 */
	public function testPendingShorteningFromBeforeTheUpdate_LeavesCoverageUnchanged_UntilOwnerAcknowledges(): void {
		$roomTaskId = $this->dailyFixedTask('pa_kid', $this->todayKey, 'Kid pending shorten');
		$created = $this->selfAbsence('pa_kid');
		self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'));

		$pendingShorten = $this->pendingKidRequest($created['pauseGroupId'], $this->todayKey);
		self::assertSame(2, $pendingShorten['revision']);
		self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'), 'the undecided shortening leaves the acknowledged coverage unchanged');

		$this->pauseService->approve($this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => 2]);
		self::assertTrue($this->isNeutral($roomTaskId, $this->todayKey, 'pa_kid'), 'today stays recognized');
		self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'), 'the acknowledged shortening removes the superseded future coverage');
	}

	/**
	 * A child's shortening that the owner declined before ADR-020 leaves
	 * the absence in force: everyone still sees it with its own range, and
	 * its subject or the owner can still delete it (D-090-22).
	 */
	public function testDeclinedShorteningFromBeforeTheUpdate_AbsenceStaysListedAndDeletable(): void {
		$roomTaskId = $this->dailyFixedTask('pa_kid', $this->todayKey, 'Kid declined shorten');
		$created = $this->selfAbsence('pa_kid');
		$declined = $this->pendingKidRequest($created['pauseGroupId'], $this->todayKey);
		$this->pauseService->reject($this->houseId, 'pa_owner', $created['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $declined['revision']]);
		self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'), 'the declined shortening changes nothing');

		foreach (['pa_owner' => true, 'pa_mom' => false, 'pa_kid' => true] as $viewer => $deletable) {
			$entries = array_values(array_filter(
				$this->pauseService->listForViewer($this->houseId, $viewer),
				static fn (array $row): bool => $row['pauseGroupId'] === $created['pauseGroupId']
			));
			self::assertCount(1, $entries, "$viewer sees the absence that stands");
			self::assertSame(Pause::STATE_ACKNOWLEDGED, $entries[0]['state']);
			self::assertSame($this->range()['endDate'], $entries[0]['endDate'], 'its own range, not the declined one');
			self::assertSame(2, $entries[0]['revision'], 'the next command expects the latest revision');
			self::assertSame($deletable, $entries[0]['deletable'], "deletable for $viewer");
		}

		$deleted = $this->deletePause('pa_kid', ['pauseGroupId' => $created['pauseGroupId'], 'revision' => 2]);

		self::assertSame(Pause::STATE_CANCELLED, $deleted['state']);
		self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'), 'tomorrow is a duty again');
	}

	// ------------------------------------------------------------------
	// Deletion (ADR-020, D-090-07, D-090-22)
	// ------------------------------------------------------------------

	private function deletePause(string $actor, array $pause): array {
		return $this->pauseService->delete($this->houseId, $actor, $pause['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $pause['revision']]);
	}

	public function testDeleteRunningAbsence_KeepsTodayFreesTheNextDay(): void {
		$roomTaskId = $this->dailyFixedTask('pa_kid', $this->todayKey, 'Kid delete');
		$created = $this->selfAbsence('pa_kid');
		self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'));

		$deleted = $this->deletePause('pa_kid', $created);

		self::assertSame(['pauseGroupId' => $created['pauseGroupId'], 'revision' => 2, 'state' => Pause::STATE_CANCELLED], $deleted);
		self::assertTrue($this->isNeutral($roomTaskId, $this->todayKey, 'pa_kid'), 'what today was excused stays excused');
		self::assertFalse($this->isNeutral($roomTaskId, $this->dayKey(1), 'pa_kid'), 'tomorrow is a duty again');
		$rows = $this->fetchPauseRows($created['pauseGroupId']);
		self::assertSame([Pause::STATE_ACKNOWLEDGED, Pause::STATE_CANCELLED], array_column($rows, 'state'), 'the deletion is a new revision, the first one is untouched');
		foreach (['pa_owner', 'pa_mom', 'pa_kid'] as $viewer) {
			self::assertNotContains(
				$created['pauseGroupId'],
				array_column($this->pauseService->listForViewer($this->houseId, $viewer), 'pauseGroupId'),
				"the deleted absence is gone for $viewer"
			);
		}
	}

	public function testDeleteFutureAbsence_LeavesNoTraceInGoalRhythmOrNeutrality(): void {
		$roomTaskId = $this->dailyFixedTask('pa_kid', $this->todayKey, 'Kid future');
		$days = [$this->todayKey, $this->dayKey(2), $this->dayKey(3)];
		$kid = $this->houseUserMapper->findByNextcloudUserId($this->houseId, 'pa_kid');
		$goalAndRhythm = fn (): array => [
			\OC::$server->get(\OCA\ChoreStrike\Service\WeeklyGoalService::class)->getWeeklyGoalProgress($this->houseId),
			\OC::$server->get(\OCA\ChoreStrike\Service\RhythmService::class)->evaluateForMembers($this->houseId, [$kid], new \DateTime($this->dayKey(4) . ' 12:00:00')),
		];
		$goalAndRhythmBefore = $goalAndRhythm();

		$created = $this->selfAbsence('pa_kid', ['startDate' => $this->dayKey(2), 'endDate' => $this->dayKey(3), 'reasonCode' => Pause::REASON_OTHER]);
		self::assertTrue($this->isNeutral($roomTaskId, $this->dayKey(2), 'pa_kid'));

		$this->deletePause('pa_kid', $created);

		self::assertEquals($goalAndRhythmBefore, $goalAndRhythm(), 'the weekly goal and the rhythm, read after the freed days, show no trace');
		foreach ($days as $day) {
			self::assertFalse($this->isNeutral($roomTaskId, $day, 'pa_kid'), "$day is an ordinary duty");
		}
	}

	public function testDelete_OwnerDeletesAnyAbsence_OthersOnlyTheirOwn(): void {
		$momPause = $this->selfAbsence('pa_mom');
		$housePause = $this->pauseService->createHousePause($this->houseId, 'pa_owner', array_merge(['commandId' => $this->uuid()], $this->range()));

		foreach ([['pa_kid', $momPause], ['pa_kid', $housePause], ['pa_mom', $housePause]] as [$actor, $pause]) {
			try {
				$this->deletePause($actor, $pause);
				self::fail("$actor may not delete this absence");
			} catch (ForbiddenException $e) {
				self::assertSame('Owner role required', $e->getMessage());
			}
		}
		self::assertCount(1, $this->fetchPauseRows($momPause['pauseGroupId']), 'a denied deletion appends nothing');

		self::assertSame(Pause::STATE_CANCELLED, $this->deletePause('pa_owner', $momPause)['state']);
		self::assertSame(Pause::STATE_CANCELLED, $this->deletePause('pa_owner', $housePause)['state']);
	}

	public function testDelete_StaleRevisionEndedAndAlreadyDeleted_Conflict(): void {
		$created = $this->selfAbsence('pa_mom');
		$ended = $this->pauseService->recognizeIllness($this->houseId, 'pa_owner', [
			'commandId' => $this->uuid(), 'subjectUserId' => 'pa_mom', 'startDate' => $this->dayKey(-5), 'endDate' => $this->dayKey(-3),
		]);

		$attempts = [
			'stale revision' => ['pauseGroupId' => $created['pauseGroupId'], 'revision' => 7],
			'ended absence' => $ended,
		];
		foreach ($attempts as $label => $pause) {
			try {
				$this->deletePause('pa_owner', $pause);
				self::fail("$label must conflict");
			} catch (ConflictException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
		self::assertCount(1, $this->fetchPauseRows($ended['pauseGroupId']), 'the ended absence keeps its single revision');
		self::assertContains(
			$ended['pauseGroupId'],
			array_column($this->pauseService->listForViewer($this->houseId, 'pa_kid'), 'pauseGroupId'),
			'an ended absence stays in the shared list'
		);

		$deleted = $this->deletePause('pa_mom', $created);
		try {
			$this->deletePause('pa_mom', $deleted);
			self::fail('a deleted absence cannot be deleted again');
		} catch (ConflictException $e) {
			self::assertCount(2, $this->fetchPauseRows($created['pauseGroupId']));
		}
	}

	public function testDelete_ReplayedCommand_ReturnsTheSameResultOnce(): void {
		$created = $this->selfAbsence('pa_mom');
		$payload = ['commandId' => $this->uuid(), 'expectedRevision' => $created['revision']];

		$first = $this->pauseService->delete($this->houseId, 'pa_mom', $created['pauseGroupId'], $payload);
		$replay = $this->pauseService->delete($this->houseId, 'pa_mom', $created['pauseGroupId'], $payload);

		self::assertSame($first, $replay);
		self::assertCount(2, $this->fetchPauseRows($created['pauseGroupId']), 'the replay appends no second cancellation');
	}

	public function testDelete_PendingRequestFromBeforeTheUpdate_IsWithdrawn(): void {
		$kidTaskId = $this->kidTask();
		$pending = $this->pendingKidRequest();

		$deleted = $this->deletePause('pa_kid', $pending);

		self::assertSame(Pause::STATE_CANCELLED, $deleted['state']);
		self::assertFalse($this->isNeutral($kidTaskId, $this->todayKey, 'pa_kid'));
		self::assertSame([], $this->pauseService->listForViewer($this->houseId, 'pa_owner'), 'nothing is left to decide');
		$this->expectException(ConflictException::class);
		$this->pauseService->approve($this->houseId, 'pa_owner', $pending['pauseGroupId'], ['commandId' => $this->uuid(), 'expectedRevision' => $deleted['revision']]);
	}

	public function testDelete_OwnerDeletesSomeoneElses_QueuesOneDigestChangeForTheSubject(): void {
		$created = $this->selfAbsence('pa_kid');
		self::assertSame([], $this->digestRecipients($created['pauseGroupId']), 'entering an own absence notifies nobody');

		$this->deletePause('pa_owner', $created);

		$kid = $this->houseUserMapper->findByNextcloudUserId($this->houseId, 'pa_kid');
		self::assertSame([(int)$kid->getId()], $this->digestRecipients($created['pauseGroupId']));
	}

	public function testDelete_HouseAbsence_QueuesDigestChangeForEveryOtherMember(): void {
		$created = $this->pauseService->createHousePause($this->houseId, 'pa_owner', array_merge(['commandId' => $this->uuid()], $this->range()));

		$this->deletePause('pa_owner', $created);

		$others = [];
		foreach (['pa_mom', 'pa_kid'] as $userId) {
			$others[] = (int)$this->houseUserMapper->findByNextcloudUserId($this->houseId, $userId)->getId();
		}
		sort($others);
		self::assertSame($others, $this->digestRecipients($created['pauseGroupId']), 'mom and kid, never the deciding owner');
	}

	/**
	 * D-090-21: one reading for every cancelled revision. A pending
	 * shortening that a departure cancelled ends its group's coverage
	 * from the next local day — the member who rejoins with the retained
	 * wallet is not covered again by the old absence.
	 */
	public function testRejoinAfterDepartureWithPendingShortening_IsNotCoveredAgain(): void {
		$created = $this->selfAbsence('pa_kid');
		$this->pendingKidRequest($created['pauseGroupId'], $this->dayKey(1));
		$kid = $this->houseUserMapper->findByNextcloudUserId($this->houseId, 'pa_kid');
		$walletId = (int)$kid->getWalletId();

		$this->actingAs('pa_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class)->removeHouseMember((int)$kid->getId());
		self::assertSame([Pause::STATE_ACKNOWLEDGED, Pause::STATE_CANCELLED], array_column($this->fetchPauseRows($created['pauseGroupId']), 'state'));

		$this->addHouseMember($this->houseId, 'pa_kid', 'Kid', 'child');
		self::assertSame($walletId, $this->walletId('pa_kid'), 'the rejoin reattaches the retained wallet');

		self::assertTrue($this->isAbsentOn('pa_kid', $this->todayKey), 'the departure day stays excused');
		self::assertFalse($this->isAbsentOn('pa_kid', $this->dayKey(1)), 'the old absence covers nothing after the departure');
		self::assertFalse($this->isAbsentOn('pa_kid', $this->dayKey(3)));
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * The memberships of THIS house with a queued pause change (group ids
	 * are reused across test houses).
	 *
	 * @return list<int>
	 */
	private function digestRecipients(int $pauseGroupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('house_user_id')->from('chorestrike_digest_changes')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('pause')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$pauseGroupId)));
		$ids = array_map('intval', array_column($qb->executeQuery()->fetchAll(), 'house_user_id'));
		sort($ids);
		return $ids;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchPauseRows(int $pauseGroupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_pauses')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pause_group_id', $qb->createNamedParameter($pauseGroupId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		return $qb->executeQuery()->fetchAll();
	}
}
