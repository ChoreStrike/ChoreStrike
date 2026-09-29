<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RankingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RankingService (CS-PLAN-070 WP4, C5i/C5l/D10b,
 * Q-075-01 Option A): the consent lifecycle facts and the exact-rational
 * ranking semantics over the mapper seams (the full persistence/erasure
 * coverage lives in the integration RankingConsentTest).
 */
class RankingServiceTest extends TestCase {
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private MemberPreferenceMapper&MockObject $prefMapper;
	private OccurrenceMapper&MockObject $occurrenceMapper;
	private OccurrenceMemberMapper&MockObject $occMemberMapper;
	private CompletedTaskMapper&MockObject $completedTaskMapper;
	private WorkShareMapper&MockObject $workShareMapper;
	private RankingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->workShareMapper = $this->createMock(WorkShareMapper::class);

		// Identity executor: run() executes the aggregate in-place.
		$transactions = $this->createMock(TransactionExecutor::class);
		$transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$this->service = new RankingService(
			$this->houseMapper,
			$this->houseUserMapper,
			$this->prefMapper,
			$this->occurrenceMapper,
			$this->occMemberMapper,
			$this->completedTaskMapper,
			$this->createMock(WalletMapper::class),
			$this->workShareMapper,
			new CalendarService(),
			$transactions
		);
	}

	private function house(bool $rankingEnabled = true): House {
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');
		$house->setRankingEnabled($rankingEnabled);
		$this->houseMapper->method('find')->willReturn($house);
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		return $house;
	}

	private function member(int $id, string $name): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setNextcloudUserId('nc-' . $id);
		$member->setDisplayName($name);
		$member->setWalletId(900 + $id);
		return $member;
	}

	private function pref(int $houseUserId, string $effectiveMonday, int $generation = 1): MemberPreference {
		$pref = new MemberPreference();
		$pref->setId($houseUserId);
		$pref->setHouseId(1);
		$pref->setHouseUserId($houseUserId);
		$pref->setRankingConsentGeneration($generation);
		$pref->setRankingEffectiveMonday(new \DateTime($effectiveMonday));
		$pref->setRankingSavedZone('UTC');
		return $pref;
	}

	/**
	 * Seed the occurrence seam: each row = [occurrenceId, isPublic,
	 * windowClosed] with one obligated active participant per wallet in
	 * `$wallets`, fulfilled when `$completionIds[occurrenceId]` is set.
	 */
	private function seedWeek(array $rows, array $completions, array $sharesByCompletion): void {
		$occurrences = [];
		$membersByOccurrence = [];
		foreach ($rows as [$id, $isPublic, $closed, $completionId, $walletIds]) {
			$occ = new Occurrence();
			$occ->setId($id);
			$occ->setHouseId(1);
			$occ->setPlannedDate(new \DateTime('2026-09-22'));
			$occ->setWeekStart('2026-09-21');
			$occ->setWindowStart(new \DateTime('2026-09-22 00:00:00 UTC'));
			// A closed window ends before the evaluation instant but still
			// CONTAINS the 09-22 12:00 completion (on-time facts).
			$occ->setWindowEnd($closed
				? new \DateTime('2026-09-23 00:00:00 UTC')
				: new \DateTime('2026-09-30 00:00:00 UTC'));
			$occ->setIsPublic($isPublic);
			$occ->setCompletionId($completionId);
			$occ->setSuspensionState(Occurrence::SUSPENSION_NONE);
			$occurrences[] = $occ;

			$members = [];
			foreach ($walletIds as $walletId) {
				$m = new OccurrenceMember();
				$m->setId($walletId * 10 + $id);
				$m->setOccurrenceId($id);
				$m->setWalletId($walletId);
				$m->setObligated(true);
				$m->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
				$members[] = $m;
			}
			$membersByOccurrence[$id] = $members;
		}
		$this->occurrenceMapper->method('findByHousePlannedBetween')->willReturn($occurrences);
		$this->occMemberMapper->method('findByOccurrenceIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($membersByOccurrence, array_flip($ids))
		);

		$valid = [];
		foreach ($completions as $id) {
			$c = new CompletedTask();
			$c->setId($id);
			$c->setCompletionClass(CompletedTask::CLASS_AGREED);
			$c->setCompletedAt(new \DateTime('2026-09-22 12:00:00 UTC'));
			$valid[$id] = $c;
		}
		$this->completedTaskMapper->method('findValidByIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($valid, array_flip($ids))
		);

		$shares = [];
		foreach ($sharesByCompletion as $completionId => $walletIds) {
			$shares[$completionId] = array_map(static function (int $walletId): WorkShare {
				$s = new WorkShare();
				$s->setWalletId($walletId);
				return $s;
			}, $walletIds);
		}
		$this->workShareMapper->method('findSelectedByCompletionIds')->willReturn($shares);
	}

	public function testGetRankingView_DisabledFlag_ShowsNoEntriesAndNoConsentStates(): void {
		$this->house(false);
		$viewer = $this->member(1, 'Alice');

		$view = $this->service->getRankingView(1, $viewer, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertFalse($view['rankingEnabled']);
		$this->assertSame([], $view['entries']);
		$this->assertSame([], $view['pending']);
		$this->assertSame('none', $view['me']['state']);
	}

	public function testGetRankingView_WeekFacts_AreHouseLocalAndProvisional(): void {
		$this->house();
		$viewer = $this->member(1, 'Alice');
		$this->prefMapper->method('findByHouseId')->willReturn([]);
		$this->occurrenceMapper->method('findByHousePlannedBetween')->willReturn([]);

		$view = $this->service->getRankingView(1, $viewer, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertSame('2026-09-21', $view['week']['weekKey']);
		$this->assertTrue($view['week']['provisional']);
	}

	public function testGetRankingView_NonConsentingMembers_NeverAppear(): void {
		$this->house();
		$alice = $this->member(1, 'Alice');
		$bob = $this->member(2, 'Bob');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$alice, $bob]);
		// Only Alice consented (effective since the previous Monday).
		$this->prefMapper->method('findByHouseId')->willReturn([1 => $this->pref(1, '2026-09-14')]);
		$this->seedWeek([], [], []);

		$view = $this->service->getRankingView(1, $alice, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertCount(1, $view['entries']);
		$this->assertSame('Alice', $view['entries'][0]['displayName']);
		// Zero denominator → neutral and unranked.
		$this->assertTrue($view['entries'][0]['neutral']);
		$this->assertNull($view['entries'][0]['rank']);
		// Alice's own effective consent is visible to herself only.
		$this->assertSame('effective', $view['me']['state']);
	}

	public function testGetRankingView_PendingConsent_AppearsWithoutRank(): void {
		$this->house();
		$alice = $this->member(1, 'Alice');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$alice]);
		// Consented but not yet effective (activates the coming Monday).
		$this->prefMapper->method('findByHouseId')->willReturn([1 => $this->pref(1, '2026-09-28', 4)]);
		$this->seedWeek([], [], []);

		$view = $this->service->getRankingView(1, $alice, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertSame([], $view['entries']);
		$this->assertCount(1, $view['pending']);
		$this->assertSame('Alice', $view['pending'][0]['displayName']);
		$this->assertSame('2026-09-28', $view['pending'][0]['effectiveMonday']);
		$this->assertSame('pending', $view['me']['state']);
		$this->assertSame(4, $view['me']['generation']);
	}

	/**
	 * C5i scoring facts: on-time ratios over PUBLIC occurrences only, and
	 * private-task work never leaks into a ratio (the viewer sees only the
	 * consenting member's public counts).
	 */
	public function testGetRankingView_PrivateOccurrences_NeverEnterTheRatio(): void {
		$this->house();
		$alice = $this->member(1, 'Alice');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$alice]);
		$this->prefMapper->method('findByHouseId')->willReturn([1 => $this->pref(1, '2026-09-14')]);

		// One PUBLIC fulfilled occurrence (1/1) and one PRIVATE fulfilled
		// occurrence that must be ignored (otherwise the ratio were 2/2).
		$this->seedWeek(
			[
				[11, true, true, 51, [901]],
				[12, false, true, 52, [901]],
			],
			[51, 52],
			[51 => [901], 52 => [901]]
		);

		$view = $this->service->getRankingView(1, $alice, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertCount(1, $view['entries']);
		$this->assertSame(1, $view['entries'][0]['eligible']);
		$this->assertSame(1, $view['entries'][0]['onTime']);
		// No private-task payloads and no monetary fields anywhere.
		$json = json_encode($view);
		$this->assertStringNotContainsString('private', $json);
		$this->assertStringNotContainsString('contribution', $json);
		$this->assertStringNotContainsString('wallet', $json);
	}

	/**
	 * Exact-rational ordering + standard competition ties via the public
	 * seam: 2/3 beats 3/5 beats 1/2 = 4/8 beats 2/4.
	 */
	public function testGetRankingView_ExactRatios_TiesAndOrdering(): void {
		$this->house();
		$members = [
			$this->member(1, 'Two Thirds'),
			$this->member(2, 'Three Fifths'),
			$this->member(9, 'Half A'),
			$this->member(4, 'Quarter'),
			$this->member(5, 'Half B'),
		];
		$this->houseUserMapper->method('findByHouseId')->willReturn($members);
		$prefs = [];
		foreach ($members as $member) {
			$prefs[(int)$member->getId()] = $this->pref((int)$member->getId(), '2026-09-14');
		}
		$this->prefMapper->method('findByHouseId')->willReturn($prefs);

		// Per-member outcome rows: (occurrenceId, public, closed, completionId, wallets)
		$rows = [
			// 1: 2/3 → two public fulfilled of three public due.
			[101, true, true, 201, [901]],
			[102, true, true, 202, [901]],
			[103, true, true, null, [901]],
			// 2: 3/5.
			[104, true, true, 204, [902]],
			[105, true, true, 205, [902]],
			[106, true, true, 206, [902]],
			[107, true, true, null, [902]],
			[108, true, true, null, [902]],
			// 9: 1/2.
			[109, true, true, 209, [909]],
			[110, true, true, null, [909]],
			// 4: 1/4.
			[111, true, true, 211, [904]],
			[112, true, true, null, [904]],
			[113, true, true, null, [904]],
			[114, true, true, null, [904]],
			// 5: 4/8 (same ratio as 9's 1/2 → tie).
			[115, true, true, 215, [905]],
			[116, true, true, 216, [905]],
			[117, true, true, 217, [905]],
			[118, true, true, 218, [905]],
			[119, true, true, null, [905]],
			[120, true, true, null, [905]],
			[121, true, true, null, [905]],
			[122, true, true, null, [905]],
		];
		$completions = [201, 202, 204, 205, 206, 209, 211, 215, 216, 217, 218];
		$occMembers = [];
		$shares = [];
		$occurrences = [];
		foreach ($rows as [$id, $isPublic, $closed, $completionId, $walletIds]) {
			$occ = new Occurrence();
			$occ->setId($id);
			$occ->setHouseId(1);
			$occ->setPlannedDate(new \DateTime('2026-09-22'));
			$occ->setWeekStart('2026-09-21');
			$occ->setWindowStart(new \DateTime('2026-09-22 00:00:00 UTC'));
			$occ->setWindowEnd(new \DateTime('2026-09-23 00:00:00 UTC'));
			$occ->setIsPublic($isPublic);
			$occ->setCompletionId($completionId);
			$occ->setSuspensionState(Occurrence::SUSPENSION_NONE);
			$occurrences[] = $occ;

			$walletId = $walletIds[0];
			$m = new OccurrenceMember();
			$m->setId($id);
			$m->setOccurrenceId($id);
			$m->setWalletId($walletId);
			$m->setObligated(true);
			$m->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
			$occMembers[$id] = [$m];

			if ($completionId !== null) {
				$c = new CompletedTask();
				$c->setId($completionId);
				$c->setCompletionClass(CompletedTask::CLASS_AGREED);
				$c->setCompletedAt(new \DateTime('2026-09-22 12:00:00 UTC'));
				$valid[$completionId] = $c;
				$s = new WorkShare();
				$s->setWalletId($walletId);
				$shares[$completionId] = [$s];
			}
		}
		$this->occurrenceMapper->method('findByHousePlannedBetween')->willReturn($occurrences);
		$this->occMemberMapper->method('findByOccurrenceIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($occMembers, array_flip($ids))
		);
		$this->completedTaskMapper->method('findValidByIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($valid, array_flip($ids))
		);
		$this->workShareMapper->method('findSelectedByCompletionIds')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key($shares, array_flip($ids))
		);

		$view = $this->service->getRankingView(1, $members[2], new \DateTime('2026-09-23 12:00:00 UTC'));

		// 2/3 > 3/5 > 1/2 = 4/8 > 2/4: competition ranks 1, 2, 3, 3, 5.
		$this->assertSame(
			['Two Thirds', 'Three Fifths', 'Half A', 'Half B', 'Quarter'],
			array_column($view['entries'], 'displayName')
		);
		$this->assertSame([1, 2, 3, 3, 5], array_column($view['entries'], 'rank'));
		$tied = array_column($view['entries'], 'tied', 'houseUserId');
		$this->assertTrue($tied[9], 'the first member of a tied rank carries the tie flag');
		$this->assertTrue($tied[5], 'the second member of the tied rank carries the tie flag');
		$this->assertFalse($tied[4]);
		$this->assertFalse($tied[1]);
	}

	public function testConsent_CreatesFreshGeneration_EffectiveNextMonday(): void {
		$house = $this->house();
		$house->setRankingGenerationSeq(2);
		$actor = $this->member(3, 'Carol');
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$this->houseMapper->method('update')->willReturnArgument(0);
		$this->houseUserMapper->method('findForUpdate')->willReturn($actor);
		$this->prefMapper->method('insertOrGet')->willReturnCallback(static function (MemberPreference $pref): MemberPreference {
			$pref->setId(77);
			return $pref;
		});

		$state = $this->service->consent(1, $actor, new \DateTime('2026-09-23 12:00:00 UTC'));

		// Wednesday → next Monday is 2026-09-28, generation 3 from the
		// house-lock-protected watermark (never reused after withdrawal).
		$this->assertSame('pending', $state['state']);
		$this->assertSame(3, $state['generation']);
		$this->assertSame('2026-09-28', $state['effectiveMonday']);
	}

	public function testWithdraw_DeletesTheMembershipRow(): void {
		$this->house();
		$actor = $this->member(3, 'Carol');
		$this->houseUserMapper->method('findForUpdate')->willReturn($actor);
		$this->prefMapper->expects($this->once())
			->method('deleteByHouseAndUser')
			->with(1, 3);

		$this->service->withdraw(1, $actor);
	}
}
