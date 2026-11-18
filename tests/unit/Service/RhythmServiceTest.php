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
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\RecognitionFact;
use OCA\ChoreStrike\Service\RecognitionFactsService;
use OCA\ChoreStrike\Service\RhythmService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The rhythm over the recognition facts (CS-PLAN-091 WP-B1, ADR-021
 * decisions 5 and 12): the streak continues from what a member held at
 * the cutover, steps once per day whose cycles were all kept, holds
 * behind an open window, breaks on a miss and ignores absences.
 */
class RhythmServiceTest extends TestCase {
	private const WALLET = 500;

	private RecognitionFactsService&MockObject $facts;
	private HouseUserMapper&MockObject $houseUsers;
	private HouseMapper&MockObject $houses;
	private RhythmService $service;
	/** @var list<RecognitionFact> Closed cycles in window-end order. */
	private array $closed = [];
	/** @var list<RecognitionFact> */
	private array $open = [];

	protected function setUp(): void {
		$this->facts = $this->createMock(RecognitionFactsService::class);
		$this->houseUsers = $this->createMock(HouseUserMapper::class);
		$this->houses = $this->createMock(HouseMapper::class);
		$transactions = $this->createMock(TransactionExecutor::class);
		$transactions->method('run')->willReturnCallback(static fn (callable $fn): mixed => $fn());
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');
		$this->houses->method('find')->willReturn($house);
		$this->houses->method('findOneForJobs')->willReturn($house);
		$this->houses->method('findForUpdate')->willReturn($house);

		$this->closed = [];
		$this->open = [];
		// The cursor: pages of the service's size, keyed by the row index.
		$this->facts->method('closedFactsPage')->willReturnCallback(function (House $house, ?string $afterDay, int $afterId, int $limit): array {
			$page = array_slice($this->closed, $afterId, $limit);
			$last = $page === [] ? null : $page[count($page) - 1];
			return ['facts' => $page, 'lastDay' => $last?->day, 'lastId' => $afterId + count($page), 'count' => count($page)];
		});
		$this->facts->method('openFacts')->willReturnCallback(fn (): array => $this->open);
		$this->service = new RhythmService($this->facts, $this->houses, $this->houseUsers, $transactions);
	}

	private function user(int $id = 7, ?int $walletId = self::WALLET, ?int $base = null, ?int $longestBase = null): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setWalletId($walletId);
		$user->setCurrentStreak(0);
		$user->setLongestStreak(0);
		$user->setStreakBase($base);
		$user->setLongestStreakBase($longestBase);
		return $user;
	}

	/**
	 * @param list<int> $workers Wallets with a share of the completion
	 * @param list<int> $neutralFor Wallets absent throughout the window
	 */
	private function fact(string $day, string $state, array $workers = [], array $neutralFor = [], array $responsible = [self::WALLET]): RecognitionFact {
		return new RecognitionFact(
			$day,
			'2026-11-16',
			$state,
			$responsible,
			array_fill_keys($workers, true),
			array_fill_keys($neutralFor, true),
			false,
			in_array($state, [RecognitionFact::OPEN, RecognitionFact::UPCOMING], true),
			11,
		);
	}

	private function kept(string $day): RecognitionFact {
		return $this->fact($day, RecognitionFact::KEPT, [self::WALLET]);
	}

	private function evaluate(HouseUser $user): array {
		return $this->service->evaluateForMembers(1, [$user], new \DateTime('2026-11-20 12:00:00 UTC'))[(int)$user->getId()];
	}

	public function testEvaluate_ContinuesFromTheStreakHeldAtTheCutover(): void {
		$this->closed = [$this->kept('2026-11-17'), $this->kept('2026-11-18')];

		self::assertSame(
			['currentStreak' => 6, 'longestStreak' => 9, 'pending' => false],
			$this->evaluate($this->user(base: 4, longestBase: 9)),
			'no week from before the derived model is evaluated again (D-091-04)'
		);
		self::assertSame(['currentStreak' => 2, 'longestStreak' => 2, 'pending' => false], $this->evaluate($this->user()), 'a member without a base starts at zero');
	}

	public function testEvaluate_AMissBreaksTheRunAndALaterKeptDayStartsANewOne(): void {
		$this->closed = [
			$this->kept('2026-11-16'),
			$this->kept('2026-11-17'),
			$this->fact('2026-11-18', RecognitionFact::MISSED),
			$this->kept('2026-11-19'),
		];

		self::assertSame(['currentStreak' => 1, 'longestStreak' => 4, 'pending' => false], $this->evaluate($this->user(base: 2, longestBase: 2)));
	}

	public function testEvaluate_LateWorkAndWorkKeptBySomeoneElse_AreNotTheMembersSuccess(): void {
		$this->closed = [$this->kept('2026-11-16'), $this->fact('2026-11-17', RecognitionFact::LATE, [self::WALLET])];
		self::assertSame(0, $this->evaluate($this->user())['currentStreak'], 'done late stays not kept');

		$this->closed = [$this->kept('2026-11-16'), $this->fact('2026-11-17', RecognitionFact::KEPT, [501])];
		self::assertSame(0, $this->evaluate($this->user())['currentStreak'], 'a coworker\'s share is not the responsible member\'s');
	}

	public function testEvaluate_AnOpenWindowHoldsTheDayAndEverythingAfterIt(): void {
		$this->closed = [$this->kept('2026-11-18'), $this->kept('2026-11-20')];
		$this->open = [$this->fact('2026-11-19', RecognitionFact::OPEN)];

		self::assertSame(
			['currentStreak' => 1, 'longestStreak' => 1, 'pending' => true],
			$this->evaluate($this->user()),
			'the open cycle is merged in by its day: the kept day after it waits'
		);
	}

	public function testEvaluate_AnOpenRedCycleIsAMissWhileItIsOpen(): void {
		$this->closed = [$this->kept('2026-11-17')];
		$this->open = [$this->fact('2026-11-18', RecognitionFact::MISSED)];

		self::assertSame(['currentStreak' => 0, 'longestStreak' => 1, 'pending' => false], $this->evaluate($this->user()));
	}

	public function testEvaluate_AbsenceAndWindowsNotYetOpenAreNeutral(): void {
		$this->closed = [
			$this->kept('2026-11-16'),
			$this->fact('2026-11-17', RecognitionFact::MISSED, [], [self::WALLET]),
			$this->kept('2026-11-18'),
		];
		$this->open = [$this->fact('2026-11-25', RecognitionFact::UPCOMING)];

		self::assertSame(['currentStreak' => 2, 'longestStreak' => 2, 'pending' => false], $this->evaluate($this->user()), 'neither a step nor a break');
	}

	public function testEvaluate_WorkKeptDuringAnAbsenceStillCounts(): void {
		$this->closed = [$this->fact('2026-11-17', RecognitionFact::KEPT, [self::WALLET], [self::WALLET])];

		self::assertSame(1, $this->evaluate($this->user())['currentStreak']);
	}

	public function testEvaluate_SeveralCyclesOnOneDay_StepOnceAndBreakOnAnyMiss(): void {
		$this->closed = [$this->kept('2026-11-17'), $this->kept('2026-11-17'), $this->kept('2026-11-18'), $this->fact('2026-11-18', RecognitionFact::MISSED)];

		self::assertSame(['currentStreak' => 0, 'longestStreak' => 1, 'pending' => false], $this->evaluate($this->user()));
	}

	public function testEvaluate_SomeoneElsesAndUnassignedCycles_DoNotTouchTheMember(): void {
		$this->closed = [
			$this->kept('2026-11-17'),
			$this->fact('2026-11-18', RecognitionFact::MISSED, [], [], [501]),
			$this->fact('2026-11-18', RecognitionFact::MISSED, [], [], []),
		];

		self::assertSame(1, $this->evaluate($this->user())['currentStreak']);
	}

	public function testEvaluate_HistoryLongerThanOnePage_IsReadToItsEnd(): void {
		$day = new \DateTimeImmutable('2025-01-01');
		for ($i = 0; $i < RhythmService::HISTORY_PAGE_SIZE + 5; $i++) {
			$this->closed[] = $this->kept($day->modify('+' . $i . ' days')->format('Y-m-d'));
		}

		self::assertSame(RhythmService::HISTORY_PAGE_SIZE + 5, $this->evaluate($this->user())['currentStreak']);
	}

	public function testEvaluate_MemberWithoutAWallet_KeepsOnlyTheBase(): void {
		$this->facts->expects(self::never())->method('closedFactsPage');

		self::assertSame(['currentStreak' => 3, 'longestStreak' => 5, 'pending' => false], $this->evaluate($this->user(walletId: null, base: 3, longestBase: 5)));
	}

	public function testRecomputeForMembers_PersistsOnlyAChangedProjection(): void {
		$this->closed = [$this->kept('2026-11-17')];
		$changed = $this->user(7);
		$unchanged = $this->user(8, 501);
		$this->houseUsers->expects(self::once())->method('update')->with($changed);

		$this->service->recomputeForMembers(1, [$changed, $unchanged], new \DateTime('2026-11-20 12:00:00 UTC'));

		self::assertSame([1, 1], [$changed->getCurrentStreak(), $changed->getLongestStreak()]);
	}

	public function testRefreshForJobs_AnExpiredWindowWithoutAnyCommand_ResetsThePersistedStreak(): void {
		$user = $this->user();
		$user->setCurrentStreak(4);
		$user->setLongestStreak(4);
		$user->setStreakBase(4);
		$user->setLongestStreakBase(4);
		$this->houseUsers->method('findByHouseId')->willReturn([$user]);
		$this->houseUsers->method('findForUpdate')->willReturn($user);
		$this->open = [$this->fact('2026-11-19', RecognitionFact::MISSED)];
		$this->houseUsers->expects(self::once())->method('update')->with($user);

		$this->service->refreshForJobs(new \DateTime('2026-11-20 00:05:00 UTC'));

		self::assertSame([0, 4], [$user->getCurrentStreak(), $user->getLongestStreak()]);
	}
}
