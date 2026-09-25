<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Service\PauseProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * The frozen WP2 pause-projection rules (CS-PLAN-073 Standard A 2026-09-23):
 * civil-date coverage (Q-073-02 rule 4), personal/house excusal with the
 * departure stop (Q-073-03), the sole/joint/partial/helper suspension
 * decision (Q-073-01) and the cross-zone day-interval mapping
 * (Q-073-02 rules 1-2). Deterministic — no host clock.
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

	private function member(int $walletId, string $role = 'responsible', bool $obligated = true, int $sharePct = 100, string $disposition = 'active'): array {
		return [
			'walletId' => $walletId,
			'role' => $role,
			'obligated' => $obligated,
			'sharePct' => $sharePct,
			'disposition' => $disposition,
		];
	}

	public function testDeriveSuspension_SoleResponsibilityFullyCovered_SuspendsWithEvidence(): void {
		$members = [$this->member(11)];
		$excused = [11 => [['pauseGroupId' => 4, 'revision' => 2, 'reasonCode' => 'illness']]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended']);
		self::assertSame([['pauseGroupId' => 4, 'revision' => 2]], $decision['evidence']);
		self::assertSame([11], $decision['excusedWalletIds']);
	}

	public function testDeriveSuspension_JointAllCovered_SuspendsWithUnionEvidence(): void {
		$members = [$this->member(11, sharePct: 50), $this->member(12, sharePct: 50)];
		$excused = [
			11 => [['pauseGroupId' => 4, 'revision' => 2, 'reasonCode' => 'travel']],
			12 => [['pauseGroupId' => 5, 'revision' => 1, 'reasonCode' => 'travel']],
		];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended']);
		self::assertCount(2, $decision['evidence'], 'overlapping pause provenance composes as a union');
	}

	public function testDeriveSuspension_OneWalletUnderTwoPauses_KeepsBothProvenanceEntries(): void {
		// RF-073-WP2-03: the evidence of a suspended sole responsibility
		// lists EVERY covering pause revision of the covered wallet — a
		// personal and a house pause both stay visible.
		$members = [$this->member(11)];
		$excused = [11 => [
			['pauseGroupId' => 4, 'revision' => 2, 'reasonCode' => 'illness'],
			['pauseGroupId' => 9, 'revision' => 1, 'reasonCode' => 'travel'],
		]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended']);
		self::assertSame([
			['pauseGroupId' => 4, 'revision' => 2],
			['pauseGroupId' => 9, 'revision' => 1],
		], $decision['evidence']);
	}

	public function testDeriveSuspension_OnePausedJointParticipant_SuspendsTheWholeOccurrence(): void {
		// RF-073-WP2-24 (R6, plan §3.4 row 7): explicit joint 50/50 work is
		// suspended until an explicit feasible agreement resumes it — the
		// paused partner's share is NEVER silently redistributed to the
		// remaining one, and the suspension carries the covering pause's
		// provenance.
		$members = [$this->member(11, role: 'joint', sharePct: 50), $this->member(12, role: 'joint', sharePct: 50)];
		$excused = [11 => [['pauseGroupId' => 4, 'revision' => 2, 'reasonCode' => 'travel']]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended'], 'pausing ANY obligated joint participant suspends the occurrence');
		self::assertSame([11], $decision['excusedWalletIds']);
		self::assertSame([['pauseGroupId' => 4, 'revision' => 2]], $decision['evidence']);
		self::assertSame([], $decision['releasedHelperWalletIds']);
	}

	public function testDeriveSuspension_PausedResponsibleWithHelper_SuspendsAndReleasesHelperOnlyWhenCovered(): void {
		// A responsible+helper arrangement suspends when the RESPONSIBLE
		// is paused; the untouched helper commitment stays out of the
		// released list.
		$members = [
			$this->member(11, role: 'responsible'),
			$this->member(12, role: 'helper', obligated: false, sharePct: 30),
		];
		$excused = [11 => [['pauseGroupId' => 4, 'revision' => 1, 'reasonCode' => 'travel']]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended']);
		self::assertSame([11], $decision['excusedWalletIds']);
		self::assertSame([], $decision['releasedHelperWalletIds'], 'the unpaused helper is not released');
	}

	public function testDeriveSuspension_HelperPause_ReleasesOnlyTheHelperCommitment(): void {
		$members = [
			$this->member(11, role: 'responsible'),
			$this->member(12, role: 'helper', obligated: false, sharePct: 50),
		];
		$excused = [12 => [['pauseGroupId' => 4, 'revision' => 1, 'reasonCode' => 'travel']]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertFalse($decision['suspended'], 'a helper-only pause never suspends the occurrence (Q-073-01)');
		self::assertSame([12], $decision['releasedHelperWalletIds']);
		self::assertSame([12], $decision['excusedWalletIds']);
	}

	public function testDeriveSuspension_ReleasedFormerMember_IsNotLiveDuty(): void {
		// RF-073-WP2-02 interaction: a persisted RELEASED former
		// responsible row is neutral history — it never keeps the
		// occurrence from suspending when the ACTIVE arrangement is fully
		// covered, and it is never counted as an obligated member.
		$members = [
			$this->member(11, role: 'responsible', disposition: 'released'),
			$this->member(12, role: 'responsible', sharePct: 100),
		];
		$excused = [12 => [['pauseGroupId' => 4, 'revision' => 1, 'reasonCode' => 'travel']]];

		$decision = PauseProjectionService::deriveSuspension($members, $excused);

		self::assertTrue($decision['suspended'], 'the released row does not hold the occurrence open');
		self::assertSame([12], $decision['excusedWalletIds']);
	}

	public function testDeriveSuspension_NoPause_Nothing(): void {
		$members = [$this->member(11), $this->member(12, sharePct: 0)];

		$decision = PauseProjectionService::deriveSuspension($members, []);

		self::assertFalse($decision['suspended']);
		self::assertSame([], $decision['excusedWalletIds']);
	}

	// -----------------------------------------------------------------
	// Frozen Q-073-02 day-interval mapping (rules 1-2)
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

	public function testAppointmentIntersects_ExactDateWithinOneZone_ConservativeAcrossZones(): void {
		self::assertTrue(PauseProjectionService::appointmentIntersects('2026-10-01', 'UTC', '2026-10-01', 'UTC'));
		self::assertFalse(PauseProjectionService::appointmentIntersects('2026-10-02', 'UTC', '2026-10-01', 'UTC'));
		// Berlin evening vs the UTC same label: the two day intervals
		// overlap (the appointment counts conservatively on both boundary
		// days).
		self::assertTrue(PauseProjectionService::appointmentIntersects('2026-10-01', 'Europe/Berlin', '2026-10-01', 'UTC'));
		self::assertTrue(PauseProjectionService::appointmentIntersects('2026-10-02', 'Europe/Berlin', '2026-10-01', 'UTC'), 'the 2026-10-01 UTC day reaches into the Berlin Oct 2 morning');
	}

	// -----------------------------------------------------------------
	// Stamping: deadline eligibility and virtual/persisted parity
	// (R3/R4, RF-073-WP2-25/26)
	// ------------------------------------------------------------------

	private function occurrence(?int $id, string $planned = '2026-10-05', ?string $windowEnd = '2026-10-08 00:00:00'): Occurrence {
		$occurrence = new Occurrence();
		if ($id !== null) {
			$occurrence->setId($id);
		}
		$occurrence->setScheduleRevId(3);
		$occurrence->setOrdinal(1);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime($planned));
		$occurrence->setTimezone('UTC');
		if ($windowEnd !== null) {
			$occurrence->setWindowEnd(new \DateTime($windowEnd));
		}
		return $occurrence;
	}

	private function revisionDefaults(): ScheduleRevision {
		$revision = new ScheduleRevision();
		$revision->setId(3);
		$revision->setRevision(1);
		$revision->setDefaultMembers([
			['walletId' => 11, 'userId' => 'kid', 'role' => OccurrenceMember::ROLE_RESPONSIBLE, 'obligated' => true, 'sharePct' => 100],
		]);
		return $revision;
	}

	private function memberRow(int $walletId, string $role = OccurrenceMember::ROLE_RESPONSIBLE, bool $obligated = true, int $sharePct = 100, string $disposition = OccurrenceMember::DISPOSITION_ACTIVE): OccurrenceMember {
		$member = new OccurrenceMember();
		$member->setWalletId($walletId);
		$member->setRole($role);
		$member->setObligated($obligated);
		$member->setSharePct($sharePct);
		$member->setDisposition($disposition);
		return $member;
	}

	private function projectionService(array $pauses, array $walletIds, array $membersByOccurrence): PauseProjectionService {
		$pauseMapper = $this->createMock(PauseMapper::class);
		$pauseMapper->method('findByHouseId')->willReturn($pauses);
		$memberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$memberMapper->method('findByOccurrenceIds')->willReturn($membersByOccurrence);
		$houseUsers = [];
		foreach ($walletIds as $walletId) {
			$member = new HouseUser();
			$member->setWalletId($walletId);
			$houseUsers[] = $member;
		}
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$houseUserMapper->method('findByHouseId')->willReturn($houseUsers);
		return new PauseProjectionService($pauseMapper, $houseUserMapper, $memberMapper);
	}

	public function testStampSuspension_LateOrdinaryAcknowledgment_LeavesExpiredMissUnsuspended(): void {
		// RF-073-WP2-25: the occurrence's ORIGINAL exclusive deadline
		// (window end October 6 00:00) had passed when the ordinary pause
		// was acknowledged (October 6 12:00) — the expired miss stays.
		$service = $this->projectionService(
			[$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00')],
			[11],
			[]
		);
		$occurrence = $this->occurrence(5, windowEnd: '2026-10-06 00:00:00');

		$service->stampSuspension(
			$occurrence,
			[$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00')],
			[['walletId' => 11, 'role' => OccurrenceMember::ROLE_RESPONSIBLE, 'obligated' => true]],
			[11 => true]
		);

		self::assertSame(Occurrence::SUSPENSION_NONE, $occurrence->getSuspensionState());

		// The same pause acknowledged BEFORE the deadline suspends.
		$timely = $this->occurrence(5, windowEnd: '2026-10-08 00:00:00');
		$service->stampSuspension(
			$timely,
			[$this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', decidedAt: '2026-10-06 12:00:00')],
			[['walletId' => 11, 'role' => OccurrenceMember::ROLE_RESPONSIBLE, 'obligated' => true]],
			[11 => true]
		);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $timely->getSuspensionState());
	}

	public function testStampSuspensionOnEntries_VirtualEntry_UsesSavedRevisionParticipants(): void {
		// RF-073-WP2-26: a genuinely virtual occurrence (cron stopped, no
		// materialized row, no member rows) derives its suspension from the
		// associated saved revision's default snapshot — identical to its
		// materialized twin.
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09');
		$service = $this->projectionService([$pause], [11], []);
		$virtual = $this->occurrence(null);

		$service->stampSuspensionOnEntries(7, [['occurrence' => $virtual, 'revision' => $this->revisionDefaults()]]);

		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $virtual->getSuspensionState());
		self::assertSame([['pauseGroupId' => 1, 'revision' => 1]], $virtual->getSuspensionEvidence());
	}

	public function testStampSuspensionOnEntries_MaterializedEntry_UsesConcreteRowsNeverRevisionDefaults(): void {
		// R4: after a concrete handover the materialized rows are
		// authoritative — wallet 11 (the revision default) is released,
		// wallet 12 is the actual responsible. The pause covers 12, so the
		// occurrence IS suspended even though the defaults name 11.
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09', walletId: 12);
		$service = $this->projectionService([$pause], [11, 12], [
			5 => [
				$this->memberRow(11, disposition: OccurrenceMember::DISPOSITION_RELEASED),
				$this->memberRow(12),
			],
		]);
		$occurrence = $this->occurrence(5);

		$service->stampSuspensionOnEntries(7, [['occurrence' => $occurrence, 'revision' => $this->revisionDefaults()]]);

		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $occurrence->getSuspensionState(), 'the concrete arrangement decides, not the replaced defaults');
	}

	public function testStampSuspensionOnEntries_VirtualAndMaterializedTwins_Agree(): void {
		// R4 exit: the same occurrence before/after materialization derives
		// the identical effective suspension and appointment facts.
		$pause = $this->pause(Pause::STATE_ACKNOWLEDGED, '2026-10-05', '2026-10-09');
		$service = $this->projectionService([$pause], [11], [
			5 => [$this->memberRow(11)],
		]);
		$virtual = $this->occurrence(null);
		$materialized = $this->occurrence(5);

		$appointments = $service->stampSuspensionOnEntries(7, [
			['occurrence' => $virtual, 'revision' => $this->revisionDefaults()],
			['occurrence' => $materialized, 'revision' => $this->revisionDefaults()],
		]);

		self::assertSame($virtual->getSuspensionState(), $materialized->getSuspensionState());
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $materialized->getSuspensionState());
		self::assertSame($virtual->getSuspensionEvidence(), $materialized->getSuspensionEvidence());
		self::assertSame($virtual->toPublicKey(), $materialized->toPublicKey());
		self::assertSame(['date' => '2026-10-05', 'zone' => 'UTC'], $appointments['3:1']);
	}
}
