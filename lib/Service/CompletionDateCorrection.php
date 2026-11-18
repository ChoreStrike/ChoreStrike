<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCP\IL10N;

/**
 * The day rule of a date correction (ADR-021 decision 9, D-091-26): which
 * house-local day an entry may take, and the work facts it then carries.
 *
 * An entry never moves past its neighbours: its day stays after the day
 * of the room task's previous valid entry and before the day of the next
 * one, so the latest valid completion remains the one with the highest id
 * and no day ever holds two entries of one task. The work instant keeps
 * its clock time on the corrected day; the classification is decided
 * again against the frozen window of the cycle the entry fulfilled.
 */
class CompletionDateCorrection {
	public function __construct(
		private CompletedTaskMapper $completedTaskMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * Validate the day and set the corrected work facts on the entity; the
	 * caller persists it inside its transaction.
	 *
	 * @param string $dateKey The corrected house-local day (`Y-m-d`)
	 * @return string The entry's work day before the correction
	 * @throws \InvalidArgumentException a day the entry cannot take
	 */
	public function apply(CompletedTask $completion, string $dateKey, \DateTimeZone $timeZone, \DateTime $now): string {
		$previousDay = $this->workDay($completion, $timeZone);
		if (!$this->calendar->isDayKey($dateKey)) {
			throw new \InvalidArgumentException($this->l10n->t('A valid date (Y-m-d) is required'));
		}
		if ($dateKey > $this->calendar->dayKey($now, $timeZone)) {
			throw new \InvalidArgumentException($this->l10n->t('The date cannot be in the future'));
		}
		if ($dateKey === $previousDay) {
			throw new \InvalidArgumentException($this->l10n->t('The entry already has this date'));
		}
		$neighbours = $this->completedTaskMapper->findValidNeighbours((int)$completion->getRoomTaskId(), (int)$completion->getId());
		if ($neighbours['previous'] !== null && $dateKey <= $this->workDay($neighbours['previous'], $timeZone)) {
			throw new \InvalidArgumentException($this->l10n->t('The date must be after the previous entry of this task'));
		}
		if ($neighbours['next'] !== null && $dateKey >= $this->workDay($neighbours['next'], $timeZone)) {
			throw new \InvalidArgumentException($this->l10n->t('The date must be before the next entry of this task'));
		}

		// The clock time of the work stays; on today it never lies ahead.
		$clock = \DateTime::createFromInterface($completion->getCompletedAt())->setTimezone($timeZone)->format('H:i:s');
		$instant = new \DateTime($dateKey . ' ' . $clock, $timeZone);
		$instant = $instant > $now ? clone $now : $instant;
		$completion->setCompletedAt($instant->setTimezone(new \DateTimeZone('UTC')));
		$completion->setWorkDate(RecurrenceCalculator::dayKeyToDate($dateKey));
		$completion->setWorkZone($timeZone->getName());
		$completion->setCompletionClass($completion->classOn($dateKey));
		return $previousDay;
	}

	/** The house-local day an entry counts on; a row from before work dates falls back to its instant. */
	private function workDay(CompletedTask $completion, \DateTimeZone $timeZone): string {
		return $completion->getWorkDate()?->format('Y-m-d') ?? $this->calendar->dayKey($completion->getCompletedAt(), $timeZone);
	}
}
