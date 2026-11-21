<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\CycleMisses;
use OCA\ChoreStrike\Service\CycleProjection;
use OCA\ChoreStrike\Service\CycleSleep;
use PHPUnit\Framework\TestCase;

/**
 * The rules the collaborators split off CycleService keep (RF-091-10):
 * which room tasks have cycles at all, which can miss one, and when a
 * sleeping task wakes (ADR-021 decisions 5, 7 and 8).
 */
class CycleSleepRulesTest extends TestCase {
	private function roomTask(?string $sleepingSince = null, ?string $wakeOn = null): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setSleepingSince($sleepingSince === null ? null : new \DateTime($sleepingSince));
		$roomTask->setWakeOn($wakeOn === null ? null : new \DateTime($wakeOn));
		return $roomTask;
	}

	private function task(bool $bonus = false, string $mode = Task::SCHEDULING_DYNAMIC, string $recurrence = Task::RECURRENCE_RECURRING): Task {
		$task = new Task();
		$task->setIsVoluntary($bonus);
		$task->setSchedulingMode($mode);
		$task->setRecurrenceMode($recurrence);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setFlexDays(0);
		return $task;
	}

	public function testHasCycles_NeitherABonusTaskNorASleepingOne(): void {
		$this->assertTrue(CycleProjection::hasCycles($this->roomTask(), $this->task()));
		$this->assertFalse(CycleProjection::hasCycles($this->roomTask(), $this->task(true)), 'a bonus task is never due');
		$this->assertFalse(CycleProjection::hasCycles($this->roomTask('2026-11-01'), $this->task()), 'a sleeping task has no window');
	}

	public function testCanMiss_OnlyARecurringFixedRule(): void {
		$this->assertTrue(CycleMisses::canMiss($this->task(false, Task::SCHEDULING_STATIC)));
		$this->assertFalse(CycleMisses::canMiss($this->task()), 'an after-completion cycle stays the red entry');
		$this->assertFalse(CycleMisses::canMiss($this->task(false, Task::SCHEDULING_STATIC, Task::RECURRENCE_ONCE)), 'a one-time task is never missed');
		$this->assertFalse(CycleMisses::canMiss($this->task(true, Task::SCHEDULING_STATIC)), 'a bonus task is never missed');
	}

	public function testWakesBy_TheWakeDateHasCome(): void {
		$this->assertFalse(CycleSleep::wakesBy($this->roomTask(), '2026-11-20'), 'an awake task does not wake');
		$this->assertFalse(CycleSleep::wakesBy($this->roomTask('2026-11-01'), '2026-11-20'), 'without a wake date it sleeps on');
		$this->assertFalse(CycleSleep::wakesBy($this->roomTask('2026-11-01', '2026-11-21'), '2026-11-20'));
		$this->assertTrue(CycleSleep::wakesBy($this->roomTask('2026-11-01', '2026-11-20'), '2026-11-20'));
		$this->assertTrue(CycleSleep::wakesBy($this->roomTask('2026-11-01', '2026-11-10'), '2026-11-20'), 'a late job pass still wakes it');
	}
}
