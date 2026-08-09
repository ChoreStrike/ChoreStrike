<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for StreakFreezeService idempotency (CR-SVC-03).
 *
 * Re-running the daily freeze pass for the same injected day must be a no-op:
 * no double freeze consumption and no reset of an already-preserved streak.
 * Uses the CS-PLAN-023 injectable-time seam — process() takes $yesterday —
 * so the regression is provable against the real DB without a multi-day wait.
 */
class StreakFreezeIdempotencyTest extends IntegrationTestCase
{
	private int $houseId;
	private string $userId = 'freeze_member';

	protected function setUp(): void
	{
		parent::setUp();

		$this->houseId = $this->createHouse('freeze_owner', 'Freeze House');
		$this->addHouseMember($this->houseId, $this->userId, 'Freeze Member', 'member');
	}

	/**
	 * Reprocessing the same day consumes at most one freeze and never resets a
	 * preserved streak.
	 */
	public function testReprocessingSameDayIsNoOp(): void
	{
		$mapper = \OC::$server->get(HouseUserMapper::class);

		// Seed a live streak with two freezes to spend.
		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		$user->setCurrentStreak(5);
		$user->setStreakFreezes(2);
		$mapper->update($user);

		$yesterday = new \DateTime('2026-07-29'); // a Wednesday (no availability set ⇒ available)
		$service = \OC::$server->get(StreakFreezeService::class);

		// First run: one freeze is consumed to preserve the streak.
		$first = $service->process($yesterday);
		self::assertGreaterThan(0, $first['processed']);

		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		self::assertSame(1, $user->getStreakFreezes(), 'first run consumes exactly one freeze');
		self::assertSame(5, $user->getCurrentStreak(), 'streak is preserved by the freeze');

		// Second run for the SAME day (job retry / occ trigger-jobs) — no-op.
		$second = $service->process($yesterday);
		self::assertSame(0, $second['frozen'], 're-run does not consume a second freeze');
		self::assertSame(0, $second['reset'], 're-run does not reset the preserved streak');

		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		self::assertSame(1, $user->getStreakFreezes(), 'freeze balance unchanged after re-run');
		self::assertSame(5, $user->getCurrentStreak(), 'streak unchanged after re-run');
	}

	/**
	 * The guard is day-scoped: after a run for day N, a run for day N+1 still
	 * processes the user (the freeze balance drops again).
	 */
	public function testDifferentDayIsProcessedAfterEarlierRun(): void
	{
		$mapper = \OC::$server->get(HouseUserMapper::class);

		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		$user->setCurrentStreak(5);
		$user->setStreakFreezes(2);
		$mapper->update($user);

		$service = \OC::$server->get(StreakFreezeService::class);

		$service->process(new \DateTime('2026-07-29')); // Wednesday
		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		self::assertSame(1, $user->getStreakFreezes());

		// A genuinely different day is not guarded ⇒ processed again.
		$service->process(new \DateTime('2026-07-30')); // Thursday
		$user = $mapper->findByNextcloudUserId($this->houseId, $this->userId);
		self::assertSame(0, $user->getStreakFreezes(), 'second distinct day consumes the last freeze');
		self::assertSame(5, $user->getCurrentStreak(), 'streak still preserved while freezes remain');
	}
}
