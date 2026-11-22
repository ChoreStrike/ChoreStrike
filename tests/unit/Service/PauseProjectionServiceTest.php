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
 * The pause-projection rules (CS-PLAN-073 Standard A 2026-09-23, ADR-020,
 * ADR-021): civil-date coverage, the revision that governs a day within
 * its pause group, personal and household absences with the departure
 * stop (Q-073-03), and the read-time decision whether a day or a whole
 * window lay inside an absence. Deterministic — no host clock.
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
		$pause->setIsRetrospectiveIllness(false);
		$pause->setStartDate(new \DateTime($start));
		$pause->setEndDate(new \DateTime($end));
		$pause->setTimezone($timezone);
		// The decision fact (R2): `updated_at` is the acknowledgment
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
	private function pauseDecidedLater(string $state, string $start, string $end, string $requestedAt, string $decidedAt, int $groupId = 1, int $revision = 1, ?int $walletId = 11): Pause {
		$pause = $this->pause($state, $start, $end, $groupId, $revision, $walletId, Pause::SCOPE_PERSONAL, $requestedAt);
		$pause->setUpdatedAt(new \DateTime($decidedAt));
		return $pause;
	}

	/**
	 * The wallets absent on one day.
	 *
	 * @param Pause[] $revisions
	 * @param array<int, bool> $wallets
	 * @return array<int, true>
	 */
	private static function absentOn(array $revisions, string $day, array $wallets): array {
		return PauseProjectionService::walletsAbsentThroughout($revisions, $day, $day, $wallets);
	}

	/**
	 * The revisions that cover a day: per pause group its governing
	 * revision, when that one is recognized and covers the day.
	 *
	 * @param Pause[] $revisions
	 * @return array<int, int> Pause group id to revision
	 */
	private static function covering(array $revisions, string $day): array {
		$covering = [];
		foreach (PauseProjectionService::governingRevisionsOn($revisions, $day) as $pause) {
			if ($pause->isRecognized() && $pause->coversDate($day)) {
				$covering[$pause->getPauseGroupId()] = $pause->getRevision();
			}
		}
		return $covering;
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

	public function testAbsentOn_OnlyRecognizedRevisionsExcuse(): void {
		$current = [11 => true, 12 => true];
		$recognized = [
			$this->pause(Pause::STATE_REQUESTED, '2026-10-05', '2026-10-06', walletId: 11),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 2, walletId: 12),
			$this->pause(Pause::STATE_REJECTED, '2026-10-05', '2026-10-06', groupId: 3, walletId: 11),
		];

		// Pending and rejected requests have NO execution effect (plan §3.4).
		self::assertSame([12 => true], self::absentOn($recognized, '2026-10-05', $current));
		self::assertSame([2 => 1], self::covering($recognized, '2026-10-05'));
	}

	public function testAbsentOn_OverlappingPauses_ComposeAsUnionProvenanceList(): void {
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

		self::assertSame([1 => 1, 2 => 1, 3 => 1], self::covering($recognized, '2026-10-06'), 'all three covering pauses stay in the union');
		self::assertEquals([11 => true, 12 => true], self::absentOn($recognized, '2026-10-06', $current));

		// Outside the house-pause window only the personal groups remain.
		self::assertSame([1 => 1, 2 => 1], self::covering($recognized, '2026-10-07'), 'the expired house pause drops out; both personal ones stay');
		self::assertSame([11 => true], self::absentOn($recognized, '2026-10-07', $current));
	}

	public function testAbsentOn_FuturePersonalEffectsStopAtDepartureHousePausesCoverCurrentMembers(): void {
		$recognized = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, walletId: 99),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 2, walletId: null, scope: Pause::SCOPE_HOUSE),
		];
		// Wallet 99 departed; 11/12 are the current members.
		$current = [11 => true, 12 => true];

		self::assertSame([11, 12], array_keys(self::absentOn($recognized, '2026-10-05', $current)), 'house scope covers every current member, a departed subject none');
	}

	public function testAbsentOn_ShorteningRemovesSupersededFutureDatesWithFullHistory(): void {
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

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-05', $current), 'the preserved past date stays covered');
		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-06', $current), 'the decision day itself stays covered (next-day boundary)');
		self::assertSame([], self::absentOn($revisions, '2026-10-07', $current), 'the first superseded future date loses coverage');
		self::assertSame([], self::absentOn($revisions, '2026-10-08', $current));
		self::assertSame([], self::absentOn($revisions, '2026-10-09', $current));

		// The preserved dates stay governed by the revision that recognized
		// them; the boundary is the day AFTER the decision day.
		self::assertSame([1 => 1], self::covering($revisions, '2026-10-06'));
		self::assertSame('2026-10-07', PauseProjectionService::effectiveBoundaryKey($revisions[1]));
	}

	public function testAbsentOn_MultipleShortenings_EachDecisionGovernsFromItsOwnBoundary(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-07', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, revision: 3, decidedAt: '2026-10-07 08:00:00'),
		];

		// Revision 2 governs from October 7 (its decision day + 1) and
		// still covers it; revision 3 governs from October 8 and dropped it.
		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-07', $current));
		self::assertSame([1 => 2], self::covering($revisions, '2026-10-07'), 'the revision that governs the date covers it');
		self::assertSame([], self::absentOn($revisions, '2026-10-08', $current));
		// A date before revision 2's boundary stays governed by revision 1.
		self::assertSame([1 => 1], self::covering($revisions, '2026-10-06'));
	}

	public function testAbsentOn_PendingOrRejectedShortening_LeavesAcknowledgedCoverageUnchanged(): void {
		$current = [11 => true];
		$acknowledged = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1);
		$pending = $this->pause(Pause::STATE_REQUESTED, '2026-10-05', '2026-10-06', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00');
		$rejected = $this->pause(Pause::STATE_REJECTED, '2026-10-05', '2026-10-06', groupId: 1, revision: 3, decidedAt: '2026-10-06 13:00:00');

		$revisions = [$acknowledged, $pending, $rejected];

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-08', $current), 'an undecided or rejected child shortening never governs');
		self::assertSame([1 => 1], self::covering($revisions, '2026-10-08'), 'the acknowledged revision stays authoritative');
	}

	// -----------------------------------------------------------------
	// Cancelled revisions (ADR-020, D-090-07, D-090-21)
	// ------------------------------------------------------------------

	public function testAbsentOn_DeletedRunningAbsence_KeepsTodayAndEarlierFreesLaterDays(): void {
		// October 5-9, deleted ON October 6: the range is kept, the state ends it.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
		];

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-05', $current), 'what was excused stays excused');
		self::assertSame([1 => 1], self::covering($revisions, '2026-10-06'), 'the day of the deletion stays covered by the revision that recognized it');
		foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $freed) {
			self::assertSame([], self::absentOn($revisions, $freed, $current), "$freed is free again");
		}
	}

	public function testAbsentOn_DeletedFutureAbsence_CoversNothing(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-12', '2026-10-16', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-12', '2026-10-16', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
		];

		foreach (['2026-10-11', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-17'] as $day) {
			self::assertSame([], self::absentOn($revisions, $day, $current), "$day is uncovered");
		}
	}

	public function testAbsentOn_EnteredAndDeletedOnTheSameDay_CoversOnlyThatDay(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-06', '2026-10-09', groupId: 1, revision: 1, decidedAt: '2026-10-06 08:00:00'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-06', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 09:00:00'),
		];

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-06', $current), 'today is never cut');
		self::assertSame([], self::absentOn($revisions, '2026-10-07', $current), 'the later revision wins at a shared boundary');
	}

	public function testAbsentOn_DeletionBoundary_FollowsTheSavedZone(): void {
		// 23:30 UTC on October 6 is already October 7 in Berlin: the
		// deletion frees the days from October 8 there.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1, timezone: 'Europe/Berlin'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 23:30:00', timezone: 'Europe/Berlin'),
		];

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-07', $current));
		self::assertSame([], self::absentOn($revisions, '2026-10-08', $current));
	}

	public function testAbsentOn_DeletedAfterShortening_TheShorteningStillGovernsItsDays(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-16', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-12', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-12', groupId: 1, revision: 3, decidedAt: '2026-10-08 12:00:00'),
		];

		self::assertSame([1 => 2], self::covering($revisions, '2026-10-08'));
		self::assertSame([], self::absentOn($revisions, '2026-10-09', $current));
	}

	public function testAbsentOn_DeletingOneGroup_NeverRemovesAnotherGroupsExcuse(): void {
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 2, revision: 1, scope: Pause::SCOPE_HOUSE, walletId: null),
		];

		self::assertSame([2 => 1], self::covering($revisions, '2026-10-08'));
		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-08', $current));
	}

	public function testAbsentOn_DepartureCancelledFirstRequest_CoversNothing(): void {
		// Q-073-03: the pending request was stamped `cancelled` in place.
		$current = [11 => true];
		$revisions = [
			$this->pauseDecidedLater(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', '2026-10-01 09:00:00', '2026-10-06 12:00:00'),
		];

		foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-09'] as $day) {
			self::assertSame([], self::absentOn($revisions, $day, $current), "$day was never covered");
		}
	}

	public function testAbsentOn_DepartureCancelledPendingShortening_EndsCoverageFromTheNextLocalDay(): void {
		// D-090-21: one reading for every cancelled revision. The member
		// left on October 6 with a pending shortening and rejoined with the
		// retained wallet — the old absence does not cover them again.
		$current = [11 => true];
		$revisions = [
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pauseDecidedLater(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-06', '2026-10-05 10:00:00', '2026-10-06 12:00:00', groupId: 1, revision: 2),
		];

		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-06', $current), 'the departure day and earlier stay excused');
		self::assertSame([], self::absentOn($revisions, '2026-10-07', $current));
		self::assertSame([], self::absentOn($revisions, '2026-10-09', $current));
	}

	public function testGoverningRevisionsOn_GroupWithoutAcknowledgedRevision_HasNoGoverningRevisionBeforeItsBoundary(): void {
		$cancelled = $this->pause(Pause::STATE_CANCELLED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00');

		self::assertSame([], PauseProjectionService::governingRevisionsOn([$cancelled], '2026-10-05'));
		self::assertSame([$cancelled], PauseProjectionService::governingRevisionsOn([$cancelled], '2026-10-07'));
	}

	public function testAbsentOn_ShorteningOneGroup_NeverRemovesAnotherGroupsExcuse(): void {
		$current = [11 => true];
		$revisions = [
			// Group 1: shortened on October 6 to October 6.
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 1, revision: 1),
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-06', groupId: 1, revision: 2, decidedAt: '2026-10-06 12:00:00'),
			// Group 2: an independent overlapping pause, never shortened.
			$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', groupId: 2, revision: 1),
		];

		self::assertSame([2 => 1], self::covering($revisions, '2026-10-08'), 'group 1 lost its superseded coverage; group 2\'s independent excuse survives');
		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-08', $current));
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
		self::assertArrayHasKey(11, self::absentOn($revisions, '2026-10-08', $current), 'Berlin today stays covered by the governing revision 1');
		self::assertSame([], self::absentOn($revisions, '2026-10-09', $current), 'from the boundary the shortened revision governs');
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
