<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB integration pins for the per-house timezone day bucketing
 * (CS-PLAN-057 §4): a completion at 22:30 UTC (00:30 Berlin) belongs to the
 * NEXT Berlin day — the duplicate-completion guard and the streak/freeze
 * day-window queries must count it there, a Berlin house's weekly window
 * starts at Berlin Monday midnight (≠ UTC Monday midnight), and the
 * minigame weekly leaderboard window follows the house week (D3 fix
 * CR-P2-11: correct Monday semantics instead of 'monday this week').
 */
class TimezoneDayBucketingTest extends IntegrationTestCase {
	private CompletedTaskMapper $completedTaskMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private CalendarService $calendar;
	private MinigameScoreMapper $minigameScoreMapper;
	private WeeklyGoalService $weeklyGoalService;
	private MinigameService $minigameService;

	/** @var array<int> Minigame score rows created by this test. */
	private array $createdScoreIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
		$this->houseMapper = \OC::$server->get(HouseMapper::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->calendar = new CalendarService();
		$this->minigameScoreMapper = \OC::$server->get(MinigameScoreMapper::class);
		$this->weeklyGoalService = \OC::$server->get(WeeklyGoalService::class);
		$this->minigameService = \OC::$server->get(MinigameService::class);
	}

	protected function tearDown(): void {
		if (!empty($this->createdScoreIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdScoreIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$this->createdScoreIds = [];
		}
		parent::tearDown();
	}

	/**
	 * Fixture house with an explicit IANA timezone.
	 */
	private function createHouseIn(string $ownerUserId, string $timezone): House {
		$houseId = $this->createHouse($ownerUserId, $timezone . ' House');
		$house = $this->houseMapper->find($houseId);
		$house->setTimezone($timezone);
		$house->touch();
		$this->houseMapper->update($house);
		return $this->houseMapper->find($houseId);
	}

	private function houseUserId(int $houseId, string $ncUserId): int {
		return $this->houseUserMapper->findByNextcloudUserId($houseId, $ncUserId)->getId();
	}

	/**
	 * Insert a completed_tasks row with an explicit UTC wall-clock timestamp.
	 */
	private function insertCompletion(int $roomTaskId, int $houseUserId, string $completedAtUtc): void {
		$this->insertCompletionRow($roomTaskId, $houseUserId, $completedAtUtc);
	}

	/**
	 * Completion at 22:30 UTC (00:30 Berlin next day) counts for the NEXT
	 * Berlin day in the duplicate guard, while a UTC-day interpretation of
	 * the same attempt would not see it.
	 */
	public function testDuplicateGuardDayWindow_FollowsHouseTimezone(): void {
		$house = $this->createHouseIn('tzday_owner', 'Europe/Berlin');
		$floorId = $this->createFloor($house->getId(), 'Ground Floor', 1);
		$roomId = $this->createRoom($house->getId(), $floorId, 'Kitchen');
		$roomTaskId = $this->assignTaskToRoom($this->createTask($house->getId(), 'Dishes', '', 5, 3), $roomId);
		$houseUserId = $this->houseUserId($house->getId(), 'tzday_owner');

		// Completed 22:30 UTC on July 14 = 00:30 Berlin on July 15.
		$this->insertCompletion($roomTaskId, $houseUserId, '2026-07-14 22:30:00');

		// A second completion attempt on July 15 at 00:30 UTC is still the
		// SAME Berlin day → the guard must see the first completion.
		$nowUtc = new \DateTimeImmutable('2026-07-15 00:30:00', new \DateTimeZone('UTC'));
		$tz = $this->calendar->getTimeZone($house);
		[$dayStart, $dayEnd] = $this->calendar->dayBounds($nowUtc, $tz);

		self::assertSame(
			1,
			$this->completedTaskMapper->countByRoomTaskAndUserOnDate($roomTaskId, $houseUserId, $dayStart, $dayEnd),
			'The 22:30 UTC completion must count for the NEXT Berlin day (duplicate guard)'
		);

		// Control: the UTC calendar-day bucket of the same attempt must NOT
		// contain the completion — the guard is genuinely TZ-sensitive.
		self::assertSame(
			0,
			$this->completedTaskMapper->countByRoomTaskAndUserOnDate(
				$roomTaskId,
				$houseUserId,
				new \DateTimeImmutable('2026-07-15 00:00:00', new \DateTimeZone('UTC')),
				new \DateTimeImmutable('2026-07-16 00:00:00', new \DateTimeZone('UTC'))
			),
			'A UTC-day interpretation would let the duplicate through'
		);

		// The streak/freeze day-window query (grouped counts) sees the same
		// completion on the Berlin day.
		$grouped = $this->completedTaskMapper->countByUserOnDateGrouped($dayStart, $dayEnd);
		self::assertSame(1, $grouped[$houseUserId] ?? 0);
	}

	/**
	 * A Berlin house's weekly window starts at Berlin Monday midnight —
	 * each zone independently selects the week containing the same instant.
	 *
	 * @dataProvider weeklyGoalInstants
	 */
	public function testWeeklyGoalWindow_FollowsHouseTimezone(string $instant, string $berlinStart, string $utcStart): void {
		$berlinHouse = $this->createHouseIn('tzweek_berlin', 'Europe/Berlin');
		$utcHouse = $this->createHouseIn('tzweek_utc', 'UTC');

		$now = new \DateTime($instant, new \DateTimeZone('UTC'));
		$berlin = $this->weeklyGoalService->progressForWeek($berlinHouse, clone $now);
		$utc = $this->weeklyGoalService->progressForWeek($utcHouse, clone $now);

		$berlinWeekStart = new \DateTimeImmutable($berlin['weekStart']);
		$utcWeekStart = new \DateTimeImmutable($utc['weekStart']);

		// Berlin's week start is Monday 00:00 in the house timezone.
		self::assertSame(
			'1',
			$berlinWeekStart->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('N'),
			'Berlin week start must be a Monday in Berlin'
		);
		self::assertSame(
			'00:00:00',
			$berlinWeekStart->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H:i:s')
		);
		self::assertSame(
			'1',
			$utcWeekStart->setTimezone(new \DateTimeZone('UTC'))->format('N'),
			'UTC week start must be a Monday in UTC'
		);

		// On Sunday evening Berlin can already be in NEXT week's window.
		self::assertSame($berlinStart, $berlin['weekStart']);
		self::assertSame($utcStart, $utc['weekStart']);
	}

	/** @return array<string, array{string, string, string}> */
	public static function weeklyGoalInstants(): array {
		return [
			'summer same week' => ['2026-07-15 12:00:00', '2026-07-12T22:00:00+00:00', '2026-07-13T00:00:00+00:00'],
			'winter same week' => ['2026-01-14 12:00:00', '2026-01-11T23:00:00+00:00', '2026-01-12T00:00:00+00:00'],
			'summer Sunday crosses Berlin week' => ['2026-10-04 22:30:00', '2026-10-04T22:00:00+00:00', '2026-09-28T00:00:00+00:00'],
			'winter Sunday crosses Berlin week' => ['2026-01-18 23:30:00', '2026-01-18T23:00:00+00:00', '2026-01-12T00:00:00+00:00'],
		];
	}

	/**
	 * The minigame weekly leaderboard window follows the house week (D3:
	 * correct Monday semantics — a score from before the house-local
	 * Monday midnight is excluded, one inside the week is included). The
	 * fresh house isolates the rows; the game id must be a catalog game
	 * since reads reject unknown ids (CS-PLAN-086).
	 */
	public function testWeeklyLeaderboard_FollowsHouseWeek(): void {
		$house = $this->createHouseIn('tzlb_owner', 'Europe/Berlin');
		$tz = $this->calendar->getTimeZone($house);

		$weekStart = $this->calendar->weekStart(new \DateTime(), $tz);
		$inWeek = (clone $weekStart)->modify('+1 hour');
		$beforeWeek = (clone $weekStart)->modify('-1 hour');

		foreach (['in-week' => $inWeek, 'before-week' => $beforeWeek] as $key => $createdAt) {
			$score = new MinigameScore();
			$score->setHouseId($house->getId());
			$score->setUserId('tzlb_player-' . $key);
			$score->setGameId('snake');
			$score->setScore(100);
			$score->setCreatedAt($createdAt);
			$this->createdScoreIds[] = $this->minigameScoreMapper->insert($score)->getId();
		}

		$leaderboard = $this->minigameService->getWeeklyLeaderboard($house->getId(), 'snake', 10);
		$userIds = array_map(static fn (array $entry) => $entry['userId'], $leaderboard);

		self::assertContains('tzlb_player-in-week', $userIds, 'A score inside the house week must be listed');
		self::assertNotContains('tzlb_player-before-week', $userIds, 'A score before the house-local Monday midnight must be excluded');
	}

	/**
	 * LocalTimeResolution_FollowsDstGapAndFoldRules (CS-PLAN-070 WP5,
	 * C5b): the digest schedule resolves house-local wall times against
	 * the real tz database — a nonexistent spring-gap time resolves to
	 * the first valid LATER instant (the transition itself), an autumn
	 * fold time to its FIRST occurrence, and ordinary times exactly.
	 */
	public function testLocalTimeResolution_FollowsDstGapAndFoldRules(): void {
		$house = $this->createHouseIn('tzdg_owner', 'Europe/Berlin');
		$tz = $this->calendar->getTimeZone($house);

		// Spring gap 2026-03-29: 02:00 CET → 03:00 CEST. '02:30' does not
		// exist — the first valid later instant is the transition itself
		// (03:00 CEST = 01:00 UTC).
		$gap = $this->calendar->localTimeToInstant('2026-03-29', '02:30', $tz);
		self::assertSame('2026-03-29T01:00:00+00:00', $gap->format('c'), 'gap → first valid later instant');

		// Ordinary CEST time.
		$summer = $this->calendar->localTimeToInstant('2026-07-15', '18:00', $tz);
		self::assertSame('2026-07-15T16:00:00+00:00', $summer->format('c'));

		// Autumn fold 2026-10-25: 02:30 occurs twice — the FIRST
		// occurrence (CEST = 00:30 UTC) wins.
		$fold = $this->calendar->localTimeToInstant('2026-10-25', '02:30', $tz);
		self::assertSame('2026-10-25T00:30:00+00:00', $fold->format('c'), 'fold → first occurrence');

		// The gap resolution renders AFTER the requested wall time.
		self::assertGreaterThan('02:30', $gap->setTimezone($tz)->format('H:i'));
	}
}
