<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AccountErasureService;
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\CompletionReadModel;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;

/**
 * The two coin multipliers against the real database (CS-PLAN-091 WP-B5,
 * ADR-021 decision 11, D-091-15): the owner's coin factor per member and
 * the bonus periods of the house. Both reach coins only, are frozen on the
 * completion, and an undo or a change of people reverses exactly what was
 * paid.
 */
class CoinMultiplierTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'cmt_owner';
	protected const HELPER = 'cmt_helper';
	private const KID = 'cmt_kid';

	private CompletionService $completionService;
	private CoinMultiplierService $multipliers;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->multipliers = \OC::$server->get(CoinMultiplierService::class);
		$this->initToday();
	}

	/** House with an owner, a helper and a child; the task (energy 2) is the owner's. */
	private function house(array $taskOverrides = []): array {
		$f = $this->fixture($taskOverrides);
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');
		$this->actingAs(self::OWNER);
		return $f;
	}

	private function bookedMinor(int $houseId, string $userId): int {
		return (int)\OC::$server->get(WalletMapper::class)->findByHouseAndUser($houseId, $userId)?->getBookedMinor();
	}

	/** @param array<string, int> $shares userId => sharePct */
	private function completeWith(array $f, string $recorder, array $shares): array {
		$participants = [];
		foreach ($shares as $userId => $sharePct) {
			$participants[] = ['userId' => $userId, 'sharePct' => $sharePct];
		}
		return $this->completeOccurrenceWithParticipants($this->completionService, $f['houseId'], $recorder, $f['roomTaskId'], $participants);
	}

	/** @return array<string, mixed> */
	private function ledger(int $completionId): array {
		return $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0];
	}

	private function walletId(int $houseId, string $userId): int {
		return (int)$this->member($houseId, $userId)->getWalletId();
	}

	// ------------------------------------------------------------------
	// Coin factor
	// ------------------------------------------------------------------

	public function testCoinFactor_OnlyTheOwnerSetsIt_ForAMemberOfTheHouse(): void {
		$f = $this->house();

		$kid = $this->multipliers->setFactor($f['houseId'], self::KID, 3, $this->newCommandId());
		self::assertSame(3, $kid->getCoinFactor());
		self::assertSame(3, $kid->jsonSerialize()['coinFactor'], 'the member list carries it');
		self::assertSame(1, $this->member($f['houseId'], self::HELPER)->getCoinFactor(), 'everyone else keeps × 1');

		// A repeated command replays; the factor stays what the first one set.
		$commandId = $this->newCommandId();
		$this->multipliers->setFactor($f['houseId'], self::KID, 2, $commandId);
		self::assertSame(2, $this->multipliers->setFactor($f['houseId'], self::KID, 2, $commandId)->getCoinFactor());

		foreach ([0, 4, -1] as $refused) {
			try {
				$this->multipliers->setFactor($f['houseId'], self::KID, $refused, $this->newCommandId());
				self::fail('refused factor: ' . $refused);
			} catch (\InvalidArgumentException $e) {
				self::assertSame(2, $this->member($f['houseId'], self::KID)->getCoinFactor());
			}
		}
		try {
			$this->multipliers->setFactor($f['houseId'], 'cmt_stranger', 2, $this->newCommandId());
			self::fail('a non-member has no factor');
		} catch (NotFoundException $e) {
			// 404, as the API reference says.
		}

		$this->actingAs(self::HELPER);
		$this->expectException(ForbiddenException::class);
		$this->multipliers->setFactor($f['houseId'], self::KID, 1, $this->newCommandId());
	}

	public function testCoinFactor_MultipliesCoinsOnly_AndIsFrozenWithTheCompletion(): void {
		$f = $this->house(['flex_days' => 0]);
		$this->multipliers->setFactor($f['houseId'], self::KID, 3, $this->newCommandId());
		$this->multipliers->setFactor($f['houseId'], self::HELPER, 2, $this->newCommandId());

		// Energy 2: 2000 coin minor at × 1. Half each: 1000 × 3 and 1000 × 2.
		$result = $this->completeWith($f, self::OWNER, [self::KID => 50, self::HELPER => 50]);

		self::assertSame([3000, 2000, 0], [$this->bookedMinor($f['houseId'], self::KID), $this->bookedMinor($f['houseId'], self::HELPER), $this->bookedMinor($f['houseId'], self::OWNER)]);
		$shares = $this->rows('chorestrike_work_shares', 'completion_id', $result['completionId']);
		self::assertSame([100, 100], array_map('intval', array_column($shares, 'energy_minor')), 'energy never sees a factor');
		self::assertEqualsCanonicalizing([3000, 2000], array_map('intval', array_column($shares, 'coin_minor')));
		$ledger = $this->ledger($result['completionId']);
		self::assertSame(100, (int)$ledger['bonus_percent']);
		self::assertEquals(
			[$this->walletId($f['houseId'], self::OWNER) => 1, $this->walletId($f['houseId'], self::HELPER) => 2, $this->walletId($f['houseId'], self::KID) => 3],
			json_decode((string)$ledger['coin_factors'], true),
			'the factor of every member, frozen with the work'
		);
		self::assertSame(200, (int)$this->member($f['houseId'], self::KID)->getContributionMinor() + (int)$this->member($f['houseId'], self::HELPER)->getContributionMinor(), 'the contribution counts energy');

		// The read model names the frozen factor of each share.
		$shown = \OC::$server->get(CompletionReadModel::class)->show($f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId']);
		self::assertEqualsCanonicalizing([2, 3], array_column($shown['allocation'], 'coinFactor'));
		self::assertSame(100, $shown['bonusPercent']);

		// A later change of the factor does not reach what was paid: the
		// owner moves the work to the child alone, priced with the frozen × 3.
		$this->multipliers->setFactor($f['houseId'], self::KID, 1, $this->newCommandId());
		$this->completionService->attribute(
			$f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId'], $this->newCommandId(), 0,
			[['userId' => self::KID, 'sharePct' => 100]], true, null
		);
		self::assertSame([6000, 0], [$this->bookedMinor($f['houseId'], self::KID), $this->bookedMinor($f['houseId'], self::HELPER)]);

		// An undo reverses exactly what the selected shares paid.
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId'], $this->newCommandId(), 1, null);
		self::assertSame([0, 0, 0], [$this->bookedMinor($f['houseId'], self::KID), $this->bookedMinor($f['houseId'], self::HELPER), $this->bookedMinor($f['houseId'], self::OWNER)]);
	}

	public function testCoinFactor_LeavesTheRecognitionsAsTheyAre(): void {
		// Weekly: the cycle that follows the completion ends in another week.
		$f = $this->house(['frequency_type' => 'weekly']);
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$owner = $this->member($f['houseId'], self::OWNER);
		$recognitions = function () use ($f, $house, $owner): array {
			$goal = \OC::$server->get(WeeklyGoalService::class)->progressForWeek($house, new \DateTime());
			$rhythm = \OC::$server->get(RhythmService::class)->evaluateForMembers($f['houseId'], [$this->member($f['houseId'], self::OWNER)], new \DateTime())[(int)$owner->getId()];
			return [$goal['eligible'], $goal['onTime'], $rhythm['currentStreak']];
		};
		$this->multipliers->setFactor($f['houseId'], self::OWNER, 3, $this->newCommandId());

		$this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId']);

		self::assertSame(6000, $this->bookedMinor($f['houseId'], self::OWNER));
		self::assertSame([1, 1, 1], $recognitions(), 'one kept window, whatever the coins');
	}

	// ------------------------------------------------------------------
	// Bonus periods
	// ------------------------------------------------------------------

	public function testBonusPeriod_CountsOneAndAHalf_OnTopOfTheFactor_AndStaysFrozen(): void {
		$f = $this->house();
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$this->multipliers->setFactor($f['houseId'], self::KID, 3, $this->newCommandId());

		$period = $this->multipliers->startPeriod($f['houseId'], $this->today, 2, $this->newCommandId());
		self::assertSame(['id' => (int)$period->getId(), 'startsOn' => $this->today, 'endsOn' => $this->day(1)], $period->toPayload());
		self::assertSame((int)$period->getId(), (int)$this->multipliers->runningOrNext($house)?->getId(), 'the house shows the running period');

		// Energy 2, a third for the child at × 3, the rest for the owner:
		// 10 × 2 × 34 × 3 × 150 / 100 = 3060 and 10 × 2 × 66 × 150 / 100 = 1980.
		$result = $this->completeWith($f, self::OWNER, [self::KID => 34, self::OWNER => 66]);

		self::assertSame([3060, 1980], [$this->bookedMinor($f['houseId'], self::KID), $this->bookedMinor($f['houseId'], self::OWNER)]);
		self::assertSame(150, (int)$this->ledger($result['completionId'])['bonus_percent']);
		self::assertSame(150, $result['rewards']['completion']['bonusPercent']);

		// Cancelling the running period keeps what its days paid.
		$cancelled = $this->multipliers->cancelPeriod($f['houseId'], (int)$period->getId(), $this->newCommandId());
		self::assertNotNull($cancelled->getCancelledAt());
		self::assertNull($this->multipliers->runningOrNext($house));
		self::assertSame(3060, $this->bookedMinor($f['houseId'], self::KID));
		// Cancelling it again changes nothing.
		$this->multipliers->cancelPeriod($f['houseId'], (int)$period->getId(), $this->newCommandId());

		// The undo reverses the frozen amounts, not today's rate.
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId'], $this->newCommandId(), 0, null);
		self::assertSame([0, 0], [$this->bookedMinor($f['houseId'], self::KID), $this->bookedMinor($f['houseId'], self::OWNER)]);

		// Without a period the same work pays the plain rate again.
		$plain = $this->completeWith($f, self::OWNER, [self::OWNER => 100]);
		self::assertSame([100, 2000], [(int)$this->ledger($plain['completionId'])['bonus_percent'], $this->bookedMinor($f['houseId'], self::OWNER)]);
	}

	public function testBonusPeriod_OneToThreeDaysFromToday_WithoutOverlap(): void {
		$f = $this->house();
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);

		$next = $this->multipliers->startPeriod($f['houseId'], $this->day(5), 3, $this->newCommandId());
		self::assertSame($this->day(7), $next->getEndsOn()->format('Y-m-d'));
		self::assertSame((int)$next->getId(), (int)$this->multipliers->runningOrNext($house)?->getId(), 'no period runs: the house shows the next one');

		// A planned period does not count today.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId']);
		self::assertSame(100, (int)$this->ledger($result['completionId'])['bonus_percent']);

		foreach ([[$this->day(7), 1], [$this->day(3), 3], [$this->day(5), 1]] as [$startsOn, $days]) {
			try {
				$this->multipliers->startPeriod($f['houseId'], $startsOn, $days, $this->newCommandId());
				self::fail('an overlapping period must conflict: ' . $startsOn);
			} catch (ConflictException $e) {
				self::assertCount(1, $this->rows('chorestrike_bonus_periods', 'house_id', $f['houseId']));
			}
		}
		foreach ([[$this->day(-1), 1], [$this->day(1), 0], [$this->day(1), 4], [$this->day(400), 1], ['soon', 1]] as [$startsOn, $days]) {
			try {
				$this->multipliers->startPeriod($f['houseId'], $startsOn, $days, $this->newCommandId());
				self::fail('refused period: ' . $startsOn . ' × ' . $days);
			} catch (\InvalidArgumentException $e) {
				self::assertCount(1, $this->rows('chorestrike_bonus_periods', 'house_id', $f['houseId']));
			}
		}

		// Right next to it is no overlap; a cancelled period frees its days.
		$this->multipliers->startPeriod($f['houseId'], $this->day(8), 1, $this->newCommandId());
		$this->multipliers->cancelPeriod($f['houseId'], (int)$next->getId(), $this->newCommandId());
		$this->multipliers->startPeriod($f['houseId'], $this->day(6), 2, $this->newCommandId());
		self::assertCount(3, $this->rows('chorestrike_bonus_periods', 'house_id', $f['houseId']));

		try {
			$this->multipliers->cancelPeriod($f['houseId'], 999999999, $this->newCommandId());
			self::fail('an unknown period is not found');
		} catch (NotFoundException $e) {
			// 404.
		}
		$this->actingAs(self::HELPER);
		$this->expectException(ForbiddenException::class);
		$this->multipliers->startPeriod($f['houseId'], $this->day(20), 1, $this->newCommandId());
	}

	public function testBonusPeriod_AnEndedOneCannotBeCancelled(): void {
		$f = $this->house();
		// A period of last week, as its days left it.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_bonus_periods')->values([
			'house_id' => $qb->createNamedParameter($f['houseId'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			'starts_on' => $qb->createNamedParameter($this->day(-7)),
			'ends_on' => $qb->createNamedParameter($this->day(-6)),
			'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
		])->executeStatement();
		$periodId = (int)$this->rows('chorestrike_bonus_periods', 'house_id', $f['houseId'])[0]['id'];

		$this->expectException(ConflictException::class);
		$this->multipliers->cancelPeriod($f['houseId'], $periodId, $this->newCommandId());
	}

	// ------------------------------------------------------------------
	// Erasure
	// ------------------------------------------------------------------

	public function testErasure_TheFrozenFactorsStopNamingTheErasedWallet(): void {
		$f = $this->house();
		$this->multipliers->setFactor($f['houseId'], self::HELPER, 2, $this->newCommandId());
		$result = $this->completeWith($f, self::OWNER, [self::OWNER => 100]);
		$helperWallet = $this->walletId($f['houseId'], self::HELPER);
		self::assertArrayHasKey($helperWallet, json_decode((string)$this->ledger($result['completionId'])['coin_factors'], true));

		\OC::$server->get(AccountErasureService::class)->eraseAccount(self::HELPER);

		$factors = json_decode((string)$this->ledger($result['completionId'])['coin_factors'], true);
		self::assertArrayNotHasKey($helperWallet, $factors);
		self::assertSame(1, $factors[$this->walletId($f['houseId'], self::OWNER)], 'the other members keep their frozen factor');
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::OWNER), 'what was paid stays');
	}

	/** D-091-73 for an account that left the house first: the final erasure sweep reaches the frozen factors too. */
	public function testErasure_ADepartedAccount_LeavesTheFrozenFactorsAsWell(): void {
		$f = $this->house();
		$this->multipliers->setFactor($f['houseId'], self::HELPER, 2, $this->newCommandId());
		$result = $this->completeWith($f, self::OWNER, [self::OWNER => 100]);
		$helperWallet = $this->walletId($f['houseId'], self::HELPER);

		// Departure keeps the wallet as financial identity; the frozen map still names it.
		\OC::$server->get(HouseService::class)->removeHouseMember((int)$this->member($f['houseId'], self::HELPER)->getId());
		self::assertArrayHasKey($helperWallet, json_decode((string)$this->ledger($result['completionId'])['coin_factors'], true));

		\OC::$server->get(AccountErasureService::class)->eraseAccount(self::HELPER);

		$factors = json_decode((string)$this->ledger($result['completionId'])['coin_factors'], true);
		self::assertArrayNotHasKey($helperWallet, $factors, 'the swept wallet is gone from the frozen factors');
		self::assertSame(1, $factors[$this->walletId($f['houseId'], self::OWNER)]);
	}

	/** D-091-70: a change of people prices a member the frozen map does not know at × 1, not at today's factor. */
	public function testAttribution_AMemberMissingFromTheFrozenMap_CountsOnce(): void {
		$f = $this->house();
		$result = $this->completeWith($f, self::OWNER, [self::OWNER => 100]);
		// Joins after the work was recorded, at × 3.
		$this->addHouseMember($f['houseId'], 'cmt_late', 'Late', 'member');
		$this->actingAs(self::OWNER);
		$this->multipliers->setFactor($f['houseId'], 'cmt_late', 3, $this->newCommandId());

		$this->completionService->attribute(
			$f['houseId'], $this->member($f['houseId'], self::OWNER), $result['completionId'], $this->newCommandId(), 0,
			[['userId' => 'cmt_late', 'sharePct' => 100]], true, null
		);

		self::assertSame([2000, 0], [$this->bookedMinor($f['houseId'], 'cmt_late'), $this->bookedMinor($f['houseId'], self::OWNER)], 'energy 2 at × 1');
		self::assertArrayNotHasKey($this->walletId($f['houseId'], 'cmt_late'), json_decode((string)$this->ledger($result['completionId'])['coin_factors'], true), 'the frozen map stays as recorded');
	}
}
