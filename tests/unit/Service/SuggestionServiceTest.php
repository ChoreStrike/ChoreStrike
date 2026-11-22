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
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\SuggestionDay;
use OCA\ChoreStrike\Db\SuggestionDayMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\SuggestionContext;
use OCA\ChoreStrike\Service\SuggestionInputs;
use OCA\ChoreStrike\Service\SuggestionJob;
use OCA\ChoreStrike\Service\SuggestionMember;
use OCA\ChoreStrike\Service\SuggestionPlanner;
use OCA\ChoreStrike\Service\SuggestionService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\WindowService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The live suggestion's own wiring (CS-PLAN-091 RF-091-14): its rules are
 * pinned in SuggestionPlannerTest and LiveSuggestionTest.
 *
 * @covers \OCA\ChoreStrike\Service\SuggestionService
 */
class SuggestionServiceTest extends TestCase {
	private const TODAY = '2026-03-07';

	/**
	 * D-091-150: the planner asks for the cycle after every cycle it
	 * places, but the rule and the anchor of a room task are read once —
	 * across both runs of the day's first read.
	 */
	public function testView_ReadsTheRuleOfARoomTaskOnce_HoweverManyCyclesItPlaces(): void {
		$asked = 0;
		$windows = $this->getMockBuilder(WindowService::class)->disableOriginalConstructor()->onlyMethods(['successorOf'])->getMock();
		$windows->expects($this->once())->method('successorOf')->willReturn(
			static function (CurrentCycle $cycle, string $doneOn) use (&$asked): ?CurrentCycle {
				$asked++;
				$next = RecurrenceCalculator::dayAfter($doneOn);
				return new CurrentCycle('p' . $next, $next, $next, $next);
			}
		);
		$dayMapper = $this->createMock(SuggestionDayMapper::class);
		$dayMapper->method('findDay')->willReturn(null);
		$dayMapper->method('storeOnce')->willReturnCallback(static function (int $houseId, string $dayKey, array $placements, \DateTimeInterface $now): SuggestionDay {
			$day = new SuggestionDay();
			$day->setPlacements($placements);
			$day->setCreatedAt(\DateTime::createFromInterface($now));
			return $day;
		});
		$inputs = $this->createMock(SuggestionInputs::class);
		$inputs->method('read')->willReturn($this->dailyTaskContext());

		$service = new SuggestionService(
			$inputs,
			new SuggestionPlanner(),
			$dayMapper,
			$windows,
			$this->createMock(TaskAccessPolicy::class),
			$this->createMock(RoomMapper::class),
			$this->createMock(HouseMapper::class),
			new CalendarService(),
			$this->createMock(IL10N::class),
			$this->createMock(AppLogger::class),
		);
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');

		$service->view($house, new \DateTime(self::TODAY . ' 09:00:00 UTC'));

		// Two runs over a fourteen-day horizon: many asks, one read of the rule.
		$this->assertGreaterThan(14, $asked);
	}

	/** One member and one daily room task, due today. */
	private function dailyTaskContext(): SuggestionContext {
		$member = new HouseUser();
		$member->setId(1);
		$member->setNextcloudUserId('mom');
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(11);
		$task = new Task();
		$task->setId(11);
		$cycle = new CurrentCycle('d' . self::TODAY, self::TODAY, self::TODAY, self::TODAY);
		return new SuggestionContext(
			1,
			new \DateTimeZone('UTC'),
			self::TODAY,
			[1 => $member],
			[new SuggestionMember(1, 'mom', array_fill_keys(TaskService::WEEKDAYS, 6), [], [], null)],
			[],
			[],
			[1 => $roomTask],
			[11 => $task],
			[1 => []],
			[1 => new SuggestionJob(1, 1, [1], false, false, $cycle)],
		);
	}
}
