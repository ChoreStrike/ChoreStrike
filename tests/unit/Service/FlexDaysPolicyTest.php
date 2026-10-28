<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\FlexDaysPolicy;
use PHPUnit\Framework\TestCase;

/**
 * FEAT-UX-039 (CS-PLAN-088 D-088-12): the flex cap is half the recurrence
 * period, rounded up; a fixed weekly rule on several weekdays uses the
 * smallest gap between them.
 *
 * @covers \OCA\ChoreStrike\Service\FlexDaysPolicy
 */
class FlexDaysPolicyTest extends TestCase {
	/**
	 * @dataProvider capProvider
	 * @param list<string> $weekdays
	 */
	public function testMaxFlexDays_PerRule(string $frequencyType, int $frequencyValue, string $recurrenceMode, array $weekdays, int $expected): void {
		self::assertSame($expected, FlexDaysPolicy::maxFlexDays($frequencyType, $frequencyValue, $recurrenceMode, $weekdays));
	}

	public static function capProvider(): array {
		$recurring = Task::RECURRENCE_RECURRING;
		return [
			'daily → 1' => ['daily', 1, $recurring, [], 1],
			'every 3 days → 2 (rounded up)' => ['daily', 3, $recurring, [], 2],
			'every 10 days → 5' => ['daily', 10, $recurring, [], 5],
			'weekly → 4' => ['weekly', 1, $recurring, [], 4],
			'every 2 weeks → 7' => ['weekly', 2, $recurring, [], 7],
			'monthly → 15' => ['monthly', 1, $recurring, [], 15],
			'every 6 months → 90' => ['monthly', 6, $recurring, [], 90],
			'legacy custom is one week → 4' => ['custom', 5, $recurring, [], 4],
			'Mon+Thu → smallest gap 3 → 2' => ['weekly', 1, $recurring, ['monday', 'thursday'], 2],
			'Fri+Mon wraps the week → gap 3 → 2' => ['weekly', 1, $recurring, ['friday', 'monday'], 2],
			'one weekday every 2 weeks → 7' => ['weekly', 2, $recurring, ['wednesday'], 7],
			'Mon+Thu every 2 weeks → gap 3 → 2' => ['weekly', 2, $recurring, ['monday', 'thursday'], 2],
			'one-time keeps the former bound' => ['weekly', 1, Task::RECURRENCE_ONCE, [], 7],
			'bounded recurrence follows the period' => ['monthly', 6, Task::RECURRENCE_BOUNDED, [], 90],
			'the sanity bound caps very long periods' => ['monthly', 100, $recurring, [], FlexDaysPolicy::SANITY_MAX],
			// Review fix: no int overflow (TypeError) for any repeat value.
			'every 731 days still saturates' => ['daily', 731, $recurring, [], FlexDaysPolicy::SANITY_MAX],
			'every 730 days stays below the bound' => ['daily', 730, $recurring, [], 365],
			'PHP_INT_MAX days' => ['daily', PHP_INT_MAX, $recurring, [], FlexDaysPolicy::SANITY_MAX],
			'PHP_INT_MAX weeks on one weekday' => ['weekly', PHP_INT_MAX, $recurring, ['monday'], FlexDaysPolicy::SANITY_MAX],
			'PHP_INT_MAX weeks keeps the weekday gap' => ['weekly', PHP_INT_MAX, $recurring, ['monday', 'thursday'], 2],
			'PHP_INT_MAX months' => ['monthly', PHP_INT_MAX, $recurring, [], FlexDaysPolicy::SANITY_MAX],
		];
	}

	public function testFixedWeekdays_OnlyAStaticRuleRunsOnItsWeekdays(): void {
		self::assertSame(['monday', 'thursday'], FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, '["monday","thursday"]'));
		self::assertSame(['monday'], FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, ['monday']));
		self::assertSame([], FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_DYNAMIC, '["monday","thursday"]'));
		self::assertSame([], FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, 'not-json'));
		self::assertSame([], FlexDaysPolicy::fixedWeekdays(Task::SCHEDULING_STATIC, null));
	}

	public function testMaxForTask_ReadsTheDefinitionsRule(): void {
		$task = new Task();
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setRecurrenceMode(Task::RECURRENCE_RECURRING);
		$task->setSchedulingMode(Task::SCHEDULING_STATIC);
		$task->setFixedScheduleDays('["monday","thursday"]');
		self::assertSame(2, FlexDaysPolicy::maxForTask($task));

		$task->setSchedulingMode(Task::SCHEDULING_DYNAMIC);
		self::assertSame(4, FlexDaysPolicy::maxForTask($task), 'a completion-relative rule ignores its weekdays');
	}
}
