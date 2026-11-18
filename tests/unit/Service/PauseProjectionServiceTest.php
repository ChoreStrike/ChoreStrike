<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Service\PauseProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * The frozen WP2 pause-projection rules (CS-PLAN-073 Standard A 2026-09-23):
 * civil-date coverage (Q-073-02 rule 4), personal/house excusal with the
 * departure stop (Q-073-03) and the day-interval mapping (Q-073-02 rule 1),
 * plus the read-time decision of ADR-021 whether a window lay entirely
 * inside an absence. Deterministic — no host clock.
 *
 * @covers \OCA\ChoreStrike\Service\PauseProjectionService
 */
class PauseProjectionServiceTest extends TestCase {
	// -----------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------

	private function pause(
		string $state,
		string $start,
		string $end,
		int $groupId = 1,
		int $revision = 1,
		?int $walletId = 11,
		string $scope = Pause::SCOPE_PERSONAL,
		string $decidedAt = '2026-10-01 09:00:00',
		bool $retrospective = false,
		string $timezone = 'UTC',
	): Pause {
		$pause = new Pause();
		$pause->setHouseId(7);
		$pause->setPauseGroupId($groupId);
		$pause->setRevision($revision);
		$pause->setScope($scope);
		$pause->setSubjectWalletId($walletId);
		$pause->setState($state);
		$pause->setReasonCode(Pause::REASON_TRAVEL);
		$pause->setIsRetrospectiveIllness($retrospective);
		$pause->setStartDate(new \DateTime($start));
		$pause->setEndDate(new \DateTime($end));
		$pause->setTimezone($timezone);
		// The decision fact (R2/R3): `updated_at` is the acknowledgment
		// time of an acknowledged revision; `created_at` is the (possibly
		// earlier) request time.
		$pause->setCreatedAt(new \DateTime($decidedAt));
		$pause->setUpdatedAt(new \DateTime($decidedAt));
		return $pause;
	}

	/**
	 * A pending revision that was decided later: the request time stays
	 * `created_at`, the ACTUAL recognition decision is `updated_at`.
	 */
	private function pauseDecidedLater(string $state, string $start, string $end, string $requestedAt, string $decidedAt, int $groupId = 1, int $revision = 1, ?int $walletId = 11, bool $retrospective = false): Pause {
		$pause = $this->pause($state, $start, $end, $groupId, $revision, $walletId, Pause::SCOPE_PERSONAL, $requestedAt, $retrospective);
		$pause->setUpdatedAt(new \DateTime($decidedAt));
		return $pause;
	}

	// -----------------------------------------------------------------
	// Coverage
	// ------------------------------------------------------------------

	public function testCoversDate_IsInclusiveCivilComparisonWithoutZoneTranslation(): void {
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09');

		self::assertFalse($pause->coversDate('2026-10-04'), 'the day before the start is uncovered');
		self::assertTrue($pause->coversDate('2026-10-05'), 'the start day is covered (inclusive)');
		self::assertTrue($pause->coversDate('2026-10-09'), 'the end day is covered (inclusive)');
		self::assertFalse($pause->coversDate('2026-10-10'), 'the day after the end is uncovered');
	}

	public function testExcusedWallets_OnlyRecognizedRevisionsExcuse(): void {
		$current = [11 => true, 12 => true];
		$recognized = [
			$this->pause(Pause::STATE_REQUESTED, '2026-10-05', '2026-10-06', walletId: 11),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 2, walletId: 12),
			$this->pause(Pause::STATE_REJECTED, '2026-10-05', '2026-10-06', groupId: 3, walletId: 11),
		];

		$excused = PauseProjectionService::excusedWalletsOn($recognized, '2026-10-05', $current);

		// Pending and rejected requests have NO execution effect (plan §3.4).
		self::assertSame([12], array_keys($excused));
		self::assertSame([['pauseGroupId' => 2, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]], $excused[12]);
	}

	public function testExcusedWallets_OverlappingPauses_ComposeAsUnionProvenanceList(): void {
		// RF-073-WP2-03: a wallet covered by BOTH a personal and a house
		// pause (and by two personal groups) keeps EVERY provenance entry —
		// no last-wins overwrite; revoking one pause never removes another
		// pause's excuse.
		$current = [11 => true, 12 => true];
		$recognized = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1, walletId: 11),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-06', '2026-10-08', groupId: 2, revision: 1, walletId: 11),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 3, revision: 1, walletId: null, scope: Pause::SCOPE_HOUSE),
		];

		$excused = PauseProjectionService::excusedWalletsOn($recognized, '2026-10-06', $current);

		self::assertSame([
			['pauseGroupId' => 1, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL],
			['pauseGroupId' => 2, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL],
			['pauseGroupId' => 3, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL],
		], $excused[11], 'all three covering pauses stay in the union');
		self::assertSame([['pauseGroupId' => 3, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]], $excused[12]);

		// Outside the house-pause window only the personal groups remain.
		$after = PauseProjectionService::excusedWalletsOn($recognized, '2026-10-07', $current);
		self::assertCount(2, $after[11], 'the expired house pause drops out; both personal provenances stay');
		self::assertArrayNotHasKey(12, $after);
	}

	public function testExcusedWallets_FuturePersonalEffectsStopAtDepartureHousePausesCoverCurrentMembers(): void {
		$recognized = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, walletId: 99),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 2, walletId: null, scope: Pause::SCOPE_HOUSE),
		];
		// Wallet 99 departed; 11/12 are the current members.
		$current = [11 => true, 12 => true];

		$excused = PauseProjectionService::excusedWalletsOn($recognized, '2026-10-05', $current);

		self::assertSame([11, 12], array_keys($excused), 'house scope covers every current member, a departed subject none');
	}

	public function testExcusedWallets_ShorteningRemovesSupersededFutureDatesWithFullHistory(): void {
		// RF-073-WP2-23 (R2): October 5-9 acknowledged October 1, then
		// shortened ON October 6 to October 6. The FULL persisted history
		// stays in the input (no manual filtering): October 5/6 (today and
		// past in the saved zone) survive, October 7-9 lose their
		// superseded future coverage.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
		];

		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-05', $current), 'the preserved past date stays covered');
		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current), 'the decision day itself stays covered (next-day boundary)');
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current), 'the first superseded future date loses coverage');
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current));
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-09', $current));

		// The preserved dates keep the provenance of the revision that
		// recognized them; the boundary is the day AFTER the decision day.
		self::assertSame(
			[['pauseGroupId' => 1, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]],
			PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current)[11]
		);
		self::assertSame('2026-10-07', PauseProjectionService::effectiveBoundaryKey($revisions[1]));
	}

	public function testExcusedWallets_MultipleShortenings_EachDecisionGovernsFromItsOwnBoundary(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-07', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, revision: 3, decidedAt: '2026-10-07 08:00:00'),
		];

		// Revision 2 governs from October 7 (its decision day + 1) and
		// still covers it; revision 3 governs from October 8 and dropped it.
		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current));
		self::assertSame(
			[['pauseGroupId' => 1, 'revision' => 2, 'reasonCode' => Pause::REASON_TRAVEL]],
			PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current)[11],
			'the governing revision at the date is the provenance'
		);
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current));
		// A date before revision 2's boundary stays governed by revision 1.
		self::assertSame(
			[['pauseGroupId' => 1, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]],
			PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current)[11]
		);
	}

	public function testExcusedWallets_PendingOrRejectedShortening_LeavesAcknowledgedCoverageUnchanged(): void {
		$current = [11 => true];
		$acknowledged = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1);
		$pending = $this->pause(Pause::STATE_REQUESTED, '2026-10-05', '2026-10-06', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00');
		$rejected = $this->pause(Pause::STATE_REJECTED, '2026-10-05', '2026-10-06', groupId: 1, revision: 3, decidedAt: '2026-10-06 13:00:00');

		$excused = PauseProjectionService::excusedWalletsOn([$acknowledged, $pending, $rejected], '2026-10-08', $current);

		self::assertArrayHasKey(11, $excused, 'an undecided or rejected child shortening never governs');
		self::assertSame(1, $excused[11][0]['revision'], 'the acknowledged revision stays authoritative');
	}

	// -----------------------------------------------------------------
	// Cancelled revisions (ADR-020, D-090-07, D-090-21)
	// ------------------------------------------------------------------

	public function testExcusedWallets_DeletedRunningAbsence_KeepsTodayAndEarlierFreesLaterDays(): void {
		// October 5-9, deleted ON October 6: the range is kept, the state ends it.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
		];

		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-05', $current), 'what was excused stays excused');
		self::assertSame(
			[['pauseGroupId' => 1, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]],
			PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current)[11],
			'the day of the deletion stays covered by the revision that recognized it'
		);
		foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $freed) {
			self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, $freed, $current), "$freed is free again");
		}
	}

	public function testExcusedWallets_DeletedFutureAbsence_CoversNothing(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-12', '2026-10-16', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-12', '2026-10-16', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
		];

		foreach (['2026-10-11', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-17'] as $day) {
			self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, $day, $current), "$day is uncovered");
		}
	}

	public function testExcusedWallets_EnteredAndDeletedOnTheSameDay_CoversOnlyThatDay(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-06', '2026-10-09', groupId: 1, revision: 1, decidedAt: '2026-10-06 08:00:00'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-06', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 09:00:00'),
		];

		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current), 'today is never cut');
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current), 'the later revision wins at a shared boundary');
	}

	public function testExcusedWallets_DeletionBoundary_FollowsTheSavedZone(): void {
		// 23:30 UTC on October 6 is already October 7 in Berlin: the
		// deletion frees the days from October 8 there.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1, timezone: 'Europe/Berlin'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 23:30:00', timezone: 'Europe/Berlin'),
		];

		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current));
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current));
	}

	public function testExcusedWallets_DeletedAfterShortening_TheShorteningStillGovernsItsDays(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-16', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-12', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-12', groupId: 1, revision: 3, decidedAt: '2026-10-08 12:00:00'),
		];

		self::assertSame(2, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current)[11][0]['revision']);
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-09', $current));
	}

	public function testExcusedWallets_DeletingOneGroup_NeverRemovesAnotherGroupsExcuse(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 2, revision: 1, scope: Pause::SCOPE_HOUSE, walletId: null),
		];

		self::assertSame([2], array_column(PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current)[11], 'pauseGroupId'));
	}

	public function testExcusedWallets_DepartureCancelledFirstRequest_CoversNothing(): void {
		// Q-073-03: the pending request was stamped `cancelled` in place.
		$current = [11 => true];
		$revisions = [
			$this->pauseDecidedLater(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', '2026-10-01 09:00:00', '2026-10-06 12:00:00'),
		];

		foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-09'] as $day) {
			self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, $day, $current), "$day was never covered");
		}
	}

	public function testExcusedWallets_DepartureCancelledPendingShortening_EndsCoverageFromTheNextLocalDay(): void {
		// D-090-21: one reading for every cancelled revision. The member
		// left on October 6 with a pending shortening and rejoined with the
		// retained wallet — the old absence does not cover them again.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pauseDecidedLater(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-06', '2026-10-05 10:00:00', '2026-10-06 12:00:00', groupId: 1, revision: 2),
		];

		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-06', $current), 'the departure day and earlier stay excused');
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-07', $current));
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-09', $current));
	}

	public function testGoverningRevisionsOn_GroupWithoutAcknowledgedRevision_HasNoGoverningRevisionBeforeItsBoundary(): void {
		$cancelled = $this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00');

		self::assertSame([], PauseProjectionService::governingRevisionsOn([$cancelled], '2026-10-05'));
		self::assertSame([$cancelled], PauseProjectionService::governingRevisionsOn([$cancelled], '2026-10-07'));
	}

	public function testExcusedWallets_ShorteningOneGroup_NeverRemovesAnotherGroupsExcuse(): void {
		$current = [11 => true];
		$revisions = [
			// Group 1: shortened on October 6 to October 6.
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			// Group 2: an independent overlapping pause, never shortened.
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 2, revision: 1),
		];

		$excused = PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current);

		self::assertSame(
			[['pauseGroupId' => 2, 'revision' => 1, 'reasonCode' => Pause::REASON_TRAVEL]],
			$excused[11],
			'group 1 lost its superseded coverage; group 2\'s independent excuse survives'
		);
	}

	public function testEffectiveBoundaryKey_UsesSavedZoneMidnight_NotEvaluationZone(): void {
		// R2: the next-day boundary is reproducible in the pause's SAVED
		// zone. The decision 2026-10-07T22:30:00Z is already October 8 in
		// Berlin (CEST) — the shortened coverage therefore ends no earlier
		// than October 9 Berlin, and October 8 stays preserved.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1, timezone: 'Europe/Berlin'),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-07', groupId: 1, revision: 2, decidedAt: '2026-10-07 22:30:00', timezone: 'Europe/Berlin'),
		];

		self::assertSame('2026-10-09', PauseProjectionService::effectiveBoundaryKey($revisions[1]), 'the Berlin decision day is October 8, so the boundary is October 9');
		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn($revisions, '2026-10-08', $current), 'Berlin today stays covered by the governing revision 1');
		self::assertSame([], PauseProjectionService::excusedWalletsOn($revisions, '2026-10-09', $current), 'from the boundary the shortened revision governs');
	}

	// -----------------------------------------------------------------
	// Recognition eligibility (R3 / RF-073-WP2-25)
	// ------------------------------------------------------------------

	public function testExcusedWallets_LateOrdinaryAcknowledgment_DoesNotExcuseExpiredDeadline(): void {
		$current = [11 => true];
		// Acknowledged October 6 12:00 for a range covering October 5.
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00');

		// The occurrence's original exclusive deadline had already passed.
		self::assertSame(
			[],
			PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-06 00:00:00')),
			'an ordinary late approval never erases an expired miss'
		);
		// A deadline still ahead of the decision stays excusable.
		self::assertArrayHasKey(
			11,
			PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-08 00:00:00')),
			'a timely recognition (decision before the original deadline) excuses the unfulfilled obligation'
		);
	}

	public function testExcusedWallets_DecisionExactlyAtTheOriginalDeadline_IsNotTimely(): void {
		// R3: the original exclusive deadline is exclusive — a decision at
		// the very instant the window closes is already late (the gate is
		// decision < deadline), and the persisted facts make the verdict
		// stable for every later read and job.
		$current = [11 => true];
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 00:00:00');

		self::assertSame(
			[],
			PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-06 00:00:00')),
			'a decision exactly at the deadline is late'
		);
		self::assertArrayHasKey(
			11,
			PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-06 00:00:01')),
			'one second earlier the same decision is timely'
		);
	}

	public function testExcusedWallets_RetrospectiveIllnessMarker_ExcusesQualifyingOldMiss(): void {
		$current = [11 => true];
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00', retrospective: true);

		self::assertArrayHasKey(
			11,
			PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-06 00:00:00')),
			'only the explicit retrospective exception can neutralize a qualifying old miss'
		);
	}

	public function testExcusedWallets_RequestBeforeExpiryApprovedAfter_UsesTheActualDecisionTime(): void {
		$current = [11 => true];
		// Requested October 4 (before the October 6 deadline) but approved
		// only on October 7 — the DECISION time governs, not the request.
		$pause = $this->pauseDecidedLater(
			Pause::STATE_ACKNOWLEDGED,
			'2026-10-05',
			'2026-10-09',
			requestedAt: '2026-10-04 08:00:00',
			decidedAt: '2026-10-07 09:00:00'
		);

		self::assertSame([], PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current, new \DateTime('2026-10-06 00:00:00')));
	}

	public function testExcusedWallets_DayLevelRule_OrdinaryDecisionDoesNotReachBackIntoPastDays(): void {
		$current = [11 => true];
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00');

		// Without an explicit deadline (the capacity day flags) the
		// decision must predate the END of the candidate day.
		self::assertSame([], PauseProjectionService::excusedWalletsOn([$pause], '2026-10-05', $current), 'the expired day is not neutralized retroactively');
		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn([$pause], '2026-10-06', $current), 'the decision day itself counts');
		self::assertArrayHasKey(11, PauseProjectionService::excusedWalletsOn([$pause], '2026-10-07', $current));
	}

	// -----------------------------------------------------------------
	// Suspension decision (Q-073-01)

	// ------------------------------------------------------------------

	public function testCandidateDayInterval_HalfOpenUtcInterval(): void {
		[$start, $end] = PauseProjectionService::candidateDayInterval('2026-10-01', 'UTC');

		self::assertSame('2026-10-01T00:00:00+00:00', $start->format('c'));
		self::assertSame('2026-10-02T00:00:00+00:00', $end->format('c'));
		// The end is exclusive: it starts the NEXT candidate day.
		[$nextStart] = PauseProjectionService::candidateDayInterval('2026-10-02', 'UTC');
		self::assertTrue($start < $end && $end == $nextStart);
	}

	public function testCandidateDayInterval_CrossZoneCompletedInstantVector(): void {
		// Standard-A frozen vector: a completion at 2026-10-01T22:30:00Z;
		// the candidate day 2026-10-01 on a New York basis covers it
		// ([04:00Z, next-day 04:00Z)); on a Sydney basis it does not
		// ([Oct 1 00:00 AEDT = 2026-09-30T13:00Z .. 2026-10-01T13:00Z)).
		$instant = new \DateTime('2026-10-01T22:30:00+00:00');

		[$nyStart, $nyEnd] = PauseProjectionService::candidateDayInterval('2026-10-01', 'America/New_York');
		[$sydStart, $sydEnd] = PauseProjectionService::candidateDayInterval('2026-10-01', 'Australia/Sydney');

		self::assertTrue($instant >= $nyStart && $instant < $nyEnd, 'the instant consumes October 1 New York capacity');
		self::assertFalse($instant >= $sydStart && $instant < $sydEnd, 'the same instant does not consume October 1 Sydney capacity');
	}

	// -----------------------------------------------------------------
	// Windows inside an absence (ADR-021 decision 5, D-091-03)
	// ------------------------------------------------------------------

	public function testWalletsAbsentThroughout_WindowEntirelyInsideAnAbsence_IsNeutral(): void {
		$absence = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09');

		self::assertSame([11 => true], PauseProjectionService::walletsAbsentThroughout([$absence], '2026-10-06', '2026-10-08', [11 => true, 12 => true]));
		self::assertSame([11 => true], PauseProjectionService::walletsAbsentThroughout([$absence], '2026-10-05', '2026-10-09', [11 => true]), 'both ends are inclusive');
	}

	public function testWalletsAbsentThroughout_WindowReachingOutOfTheAbsence_Counts(): void {
		$absence = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09');

		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([$absence], '2026-10-04', '2026-10-06', [11 => true]), 'one day before the absence');
		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([$absence], '2026-10-08', '2026-10-10', [11 => true]), 'one day after the absence');
	}

	public function testWalletsAbsentThroughout_AbsenceEnteredAfterTheWindow_IsHonoured(): void {
		// Decided on read: the window ended on 8 October, the absence was
		// entered on the 20th — no timeliness rule applies to derived windows.
		$absence = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', 1, 1, 11, Pause::SCOPE_PERSONAL, '2026-10-20 09:00:00');

		self::assertSame([11 => true], PauseProjectionService::walletsAbsentThroughout([$absence], '2026-10-06', '2026-10-08', [11 => true]));
	}

	public function testWalletsAbsentThroughout_DeletedAbsence_FreesTheDaysItNoLongerCovers(): void {
		// Deleted on 6 October: it governs from the 7th on and covers nothing there.
		$entered = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', 1, 1, 11, Pause::SCOPE_PERSONAL, '2026-10-01 09:00:00');
		$deleted = $this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', 1, 2, 11, Pause::SCOPE_PERSONAL, '2026-10-06 09:00:00');

		self::assertSame([11 => true], PauseProjectionService::walletsAbsentThroughout([$entered, $deleted], '2026-10-05', '2026-10-06', [11 => true]), 'what it already excused stays');
		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([$entered, $deleted], '2026-10-06', '2026-10-08', [11 => true]));
	}

	public function testWalletsAbsentThroughout_TwoAbsencesBackToBack_CoverOneWindowTogether(): void {
		$first = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', 1);
		$second = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-07', '2026-10-09', 2);

		self::assertSame([11 => true], PauseProjectionService::walletsAbsentThroughout([$first, $second], '2026-10-06', '2026-10-08', [11 => true]));
	}

	public function testWalletsAbsentThroughout_HouseAbsenceCoversEveryWalletAskedAbout(): void {
		$house = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', 1, 1, null, Pause::SCOPE_HOUSE);
		$other = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', 2, 1, 99);

		$absent = PauseProjectionService::walletsAbsentThroughout([$house, $other], '2026-10-06', '2026-10-07', [11 => true, 12 => true]);

		ksort($absent);
		self::assertSame([11 => true, 12 => true], $absent, 'the absence of a wallet nobody asked about adds nothing');
	}

	public function testWalletsAbsentThroughout_NoResponsibleMember_IsNeverNeutral(): void {
		$house = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', 1, 1, null, Pause::SCOPE_HOUSE);

		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([$house], '2026-10-06', '2026-10-07', []));
	}

	public function testWalletsAbsentThroughout_PendingRequestOrNoAbsence_IsNeverNeutral(): void {
		$pending = $this->pause(Pause::STATE_REQUESTED, '2026-10-05', '2026-10-09');

		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([$pending], '2026-10-06', '2026-10-07', [11 => true]));
		self::assertSame([], PauseProjectionService::walletsAbsentThroughout([], '2026-10-06', '2026-10-07', [11 => true]));
	}
}
