<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RankingService;
use OCA\ChoreStrike\Service\RecognitionFact;
use OCA\ChoreStrike\Service\RecognitionFactsService;
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
	private RecognitionFactsService&MockObject $facts;
	private RankingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->facts = $this->createMock(RecognitionFactsService::class);

		// Identity executor: run() executes the aggregate in-place.
		$transactions = $this->createMock(TransactionExecutor::class);
		$transactions->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$this->service = new RankingService(
			$this->houseMapper,
			$this->houseUserMapper,
			$this->prefMapper,
			$this->facts,
			$this->createMock(WalletMapper::class),
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
	 * Seed the week's facts ({@see RecognitionFactsService}): each row =
	 * [roomTaskId, isPublic, windowClosed, completionId, responsible
	 * wallets]. A row is kept when its completion id is in `$completions`,
	 * by the wallets `$sharesByCompletion` names; visibility comes from the
	 * definition, as for a cycle.
	 */
	private function seedWeek(array $rows, array $completions, array $sharesByCompletion): void {
		$facts = [];
		$public = [];
		foreach ($rows as [$id, $isPublic, $closed, $completionId, $walletIds]) {
			$kept = $completionId !== null && in_array($completionId, $completions, true);
			$facts[] = new RecognitionFact(
				'2026-09-22',
				'2026-09-21',
				$kept ? RecognitionFact::KEPT : ($closed ? RecognitionFact::MISSED : RecognitionFact::OPEN),
				$walletIds,
				$kept ? array_fill_keys($sharesByCompletion[$completionId] ?? [], true) : [],
				[],
				false,
				!$closed,
				$id,
			);
			if ($isPublic) {
				$public[$id] = true;
			}
		}
		$this->facts->method('factsForWeek')->willReturn($facts);
		$this->facts->method('publicRoomTaskIds')->willReturn($public);
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
		$this->seedWeek([], [], []);

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
	public function testGetRankingView_PrivateWork_NeverEntersTheRatio(): void {
		$this->house();
		$alice = $this->member(1, 'Alice');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$alice]);
		$this->prefMapper->method('findByHouseId')->willReturn([1 => $this->pref(1, '2026-09-14')]);

		// One PUBLIC kept cycle (1/1) and one PRIVATE kept cycle that must
		// be ignored (otherwise the ratio were 2/2).
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
	 * ADR-021 decision 5: an absence makes unkept work neutral — it leaves
	 * the ratio — while kept work still counts, and work a coworker kept
	 * is not the responsible member's.
	 */
	public function testGetRankingView_AbsenceIsNeutral_AndOnlyOwnSharesCount(): void {
		$this->house();
		$alice = $this->member(1, 'Alice');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$alice]);
		$this->prefMapper->method('findByHouseId')->willReturn([1 => $this->pref(1, '2026-09-14')]);
		$fact = static fn (string $state, array $workers, array $neutralFor): RecognitionFact => new RecognitionFact(
			'2026-09-22', '2026-09-21', $state, [901], $workers, $neutralFor, false, false, 7
		);
		$this->facts->method('factsForWeek')->willReturn([
			$fact(RecognitionFact::MISSED, [], [901 => true]),
			$fact(RecognitionFact::KEPT, [901 => true], [901 => true]),
			$fact(RecognitionFact::KEPT, [902 => true], []),
			$fact(RecognitionFact::LATE, [901 => true], []),
		]);
		$this->facts->method('publicRoomTaskIds')->willReturn([7 => true]);

		$view = $this->service->getRankingView(1, $alice, new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame(3, $view['entries'][0]['eligible'], 'the missed work inside the absence is left out');
		$this->assertSame(1, $view['entries'][0]['onTime'], 'only work kept on time with an own share');
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

		// Per-member outcome rows: (roomTaskId, public, closed, completionId, wallets)
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
		$shares = [];
		foreach ($rows as [, , , $completionId, $walletIds]) {
			if ($completionId !== null) {
				$shares[$completionId] = $walletIds;
			}
		}
		$this->seedWeek($rows, $completions, $shares);

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
		$created = null;
		$this->prefMapper->method('insertOrGet')->willReturnCallback(static function (MemberPreference $pref) use (&$created): MemberPreference {
			$created = clone $pref;
			$pref->setId(77);
			return $pref;
		});

		$state = $this->service->consent(1, $actor, new \DateTime('2026-09-23 12:00:00 UTC'));

		// A row created by consent carries the delivery defaults in its
		// INSERT (D-090-20): the animation stays on, sound stays off.
		$this->assertSame(1, $created->getVersion());
		$this->assertTrue($created->getAnimationEnabled());
		$this->assertFalse($created->getSoundEnabled());
		$written = array_keys($created->getUpdatedFields());
		foreach (array_keys(MemberPreference::DELIVERY_DEFAULTS) as $field) {
			$this->assertContains($field, $written, $field . ' is part of the INSERT');
		}

		// Wednesday → next Monday is 2026-09-28, generation 3 from the
		// house-lock-protected watermark (never reused after withdrawal).
		$this->assertSame('pending', $state['state']);
		$this->assertSame(3, $state['generation']);
		$this->assertSame('2026-09-28', $state['effectiveMonday']);
	}

	/**
	 * Consent_UpdatesTheExistingRowInPlace (Q-076-03 A)
	 *
	 * First consent and re-consent update the membership's preference row
	 * in place (or insert it when absent) — delivery preferences and the
	 * shared version chain survive every consent transition.
	 */
	public function testConsent_UpdatesTheExistingRowInPlace(): void {
		$house = $this->house();
		$house->setRankingGenerationSeq(0);
		$actor = $this->member(3, 'Carol');
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		$this->houseMapper->method('update')->willReturnArgument(0);
		$this->houseUserMapper->method('findForUpdate')->willReturn($actor);

		$existing = new MemberPreference();
		$existing->setId(77);
		$existing->setHouseId(1);
		$existing->setHouseUserId(3);
		$existing->setVersion(4);
		$existing->setTaskDigestEnabled(true);
		$existing->setUpdatedAt(new \DateTime('2026-09-01 10:00:00 UTC'));
		$this->prefMapper->method('findByHouseAndUser')->willReturn($existing);
		$this->prefMapper->expects($this->never())->method('insertOrGet');
		$this->prefMapper->expects($this->once())->method('update')
			->willReturnCallback(static function (MemberPreference $pref): MemberPreference {
				return $pref;
			});

		$state = $this->service->consent(1, $actor, new \DateTime('2026-09-23 12:00:00 UTC'));

		$this->assertSame(1, $state['generation']);
		$this->assertSame('2026-09-28', $state['effectiveMonday']);
		// Delivery preferences and the shared version chain survive: the
		// in-place write advances the row version and keeps the digest
		// opt-in.
		$this->assertSame(5, $existing->getVersion());
		$this->assertTrue($existing->getTaskDigestEnabled());
		$this->assertSame(1, $existing->getRankingConsentGeneration());
	}

	/**
	 * Withdraw_ResetsOnlyTheConsentColumns (Q-076-03 A)
	 *
	 * Withdrawal invalidates the generation by resetting ONLY the consent
	 * columns — the delivery preferences survive (no row deletion).
	 */
	public function testWithdraw_ResetsOnlyTheConsentColumns(): void {
		$this->house();
		$actor = $this->member(3, 'Carol');
		$this->houseUserMapper->method('findForUpdate')->willReturn($actor);
		$this->prefMapper->expects($this->once())
			->method('clearRankingConsentByHouseAndUser')
			->with(1, 3, $this->anything());
		$this->prefMapper->expects($this->never())
			->method('deleteByHouseAndUser');

		$this->service->withdraw(1, $actor);
	}
}
