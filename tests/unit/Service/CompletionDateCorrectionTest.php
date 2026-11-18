<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionDateCorrection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The day rule of a date correction (ADR-021 decision 9, D-091-26): never
 * in the future, never past a neighbouring entry of the task, the clock
 * time kept and the classification decided again.
 */
class CompletionDateCorrectionTest extends TestCase {
	private CompletedTaskMapper $mapper;
	private CompletionDateCorrection $correction;
	private \DateTimeZone $berlin;
	private array $neighbours = ['previous' => null, 'next' => null];

	protected function setUp(): void {
		$this->mapper = $this->createMock(CompletedTaskMapper::class);
		$this->mapper->method('findValidNeighbours')->willReturnCallback(fn (): array => $this->neighbours);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->correction = new CompletionDateCorrection($this->mapper, new CalendarService(), $l10n);
		$this->berlin = new \DateTimeZone('Europe/Berlin');
	}

	/** An entry done at 21:30 Berlin time on the given day, in a window that ended the same day. */
	private function entry(int $id, string $workDay): CompletedTask {
		$entry = new CompletedTask();
		$entry->setId($id);
		$entry->setRoomTaskId(11);
		$entry->setCompletedAt((new \DateTime($workDay . ' 21:30:00', $this->berlin))->setTimezone(new \DateTimeZone('UTC')));
		$entry->setWorkDate(new \DateTime($workDay));
		$entry->setWorkZone('Europe/Berlin');
		$entry->setCycleKey('c1');
		$entry->setWindowStartOn(new \DateTime($workDay));
		$entry->setWindowEndOn(new \DateTime($workDay));
		$entry->setCompletionClass(CompletedTask::CLASS_ON_TIME);
		return $entry;
	}

	private function now(string $local = '2026-11-18 12:00:00'): \DateTime {
		return new \DateTime($local, $this->berlin);
	}

	public function testApply_MovesTheDayAndKeepsTheClockTime(): void {
		$entry = $this->entry(9, '2026-11-16');

		$previous = $this->correction->apply($entry, '2026-11-14', $this->berlin, $this->now());

		$this->assertSame('2026-11-16', $previous);
		$this->assertSame('2026-11-14', $entry->getWorkDate()->format('Y-m-d'));
		$this->assertSame('2026-11-14 21:30:00', \DateTime::createFromInterface($entry->getCompletedAt())->setTimezone($this->berlin)->format('Y-m-d H:i:s'));
		$this->assertSame('UTC', $entry->getCompletedAt()->getTimezone()->getName(), 'instants are stored in UTC');
		$this->assertSame(CompletedTask::CLASS_ON_TIME, $entry->getCompletionClass(), 'before the window is on time');
	}

	public function testApply_AfterItsWindow_IsLate(): void {
		$entry = $this->entry(9, '2026-11-16');

		$this->correction->apply($entry, '2026-11-17', $this->berlin, $this->now());

		$this->assertSame(CompletedTask::CLASS_LATE, $entry->getCompletionClass());
	}

	public function testApply_OnToday_NeverLiesAhead(): void {
		// Recorded yesterday evening, moved to today at noon: 21:30 has not come yet.
		$entry = $this->entry(9, '2026-11-17');
		$now = $this->now();

		$this->correction->apply($entry, '2026-11-18', $this->berlin, $now);

		$this->assertEquals($now, $entry->getCompletedAt());
	}

	public function testApply_BonusAndStoredRows_KeepTheirClass(): void {
		$bonus = $this->entry(9, '2026-11-16');
		$bonus->setCompletionClass(CompletedTask::CLASS_BONUS);
		$stored = $this->entry(8, '2026-11-16');
		$stored->setWindowEndOn(null);
		$stored->setCompletionClass(CompletedTask::CLASS_AGREED);

		$this->correction->apply($bonus, '2026-11-17', $this->berlin, $this->now());
		$this->correction->apply($stored, '2026-11-17', $this->berlin, $this->now());

		$this->assertSame(CompletedTask::CLASS_BONUS, $bonus->getCompletionClass());
		$this->assertSame(CompletedTask::CLASS_AGREED, $stored->getCompletionClass(), 'a row from before the derived model is read as stored');
	}

	public function testApply_ARowWithoutWorkDate_CountsOnTheHouseLocalDayOfItsInstant(): void {
		$entry = $this->entry(9, '2026-11-16');
		$entry->setWorkDate(null);
		// 23:30 UTC on the 15th is the 16th in Berlin.
		$entry->setCompletedAt(new \DateTime('2026-11-15 23:30:00', new \DateTimeZone('UTC')));

		$this->assertSame('2026-11-16', $this->correction->apply($entry, '2026-11-14', $this->berlin, $this->now()));
	}

	/** @return array<string, array{string, ?string, ?string}> */
	public static function refusedDays(): array {
		return [
			// date, previous entry's day, next entry's day
			'tomorrow' => ['2026-11-19', null, null],
			'the day it already has' => ['2026-11-16', null, null],
			'the day of the previous entry' => ['2026-11-12', '2026-11-12', null],
			'before the previous entry' => ['2026-11-11', '2026-11-12', null],
			'the day of the next entry' => ['2026-11-17', null, '2026-11-17'],
			'after the next entry' => ['2026-11-18', null, '2026-11-17'],
			'no calendar label' => ['16.11.2026', null, null],
		];
	}

	/** @dataProvider refusedDays */
	public function testApply_ADayTheEntryCannotTake_LeavesItUntouched(string $date, ?string $previousDay, ?string $nextDay): void {
		$entry = $this->entry(9, '2026-11-16');
		$this->neighbours = [
			'previous' => $previousDay !== null ? $this->entry(8, $previousDay) : null,
			'next' => $nextDay !== null ? $this->entry(10, $nextDay) : null,
		];

		try {
			$this->correction->apply($entry, $date, $this->berlin, $this->now());
			$this->fail('the day must be refused');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('2026-11-16', $entry->getWorkDate()->format('Y-m-d'));
		}
	}

	public function testApply_BetweenItsNeighbours_IsAccepted(): void {
		$entry = $this->entry(9, '2026-11-16');
		$this->neighbours = ['previous' => $this->entry(8, '2026-11-12'), 'next' => $this->entry(10, '2026-11-17')];

		$this->correction->apply($entry, '2026-11-13', $this->berlin, $this->now());

		$this->assertSame('2026-11-13', $entry->getWorkDate()->format('Y-m-d'));
	}
}
