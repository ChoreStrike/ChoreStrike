<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;

/**
 * The daily rows of *Today* (ADR-022, CS-PLAN-091 WP-C): every room task
 * a viewer may see whose window is open today, with what the live
 * suggestion decided about it, and the viewer's own load of the day.
 *
 * A row the suggestion placed on today carries `suggestedFor`, the
 * members it is suggested to, and `overload` when it fits no day. Every
 * other open row carries `openWindow: true`; *Today* lists those as also
 * possible (BUG-UX-005), and they never consume a child's slot.
 *
 * The rows are built from what the suggestion already read — the room
 * tasks, definitions, assignees and current cycles of the house — so the
 * day's work costs only the rooms and the sub-task counts on top. A
 * child's availability and daily limit are the ones the plan was placed
 * with: both come from its budgets.
 */
class TodayRows {
	public function __construct(
		private SuggestionService $suggestions,
		private RoomTaskReadService $roomTaskRead,
		private WindowService $windows,
	) {
	}

	/**
	 * The daily read of a viewer.
	 *
	 * @return array{rows: list<array<string, mixed>>, load: array<string, mixed>|null} The rows of {@see rowsOf()} and
	 *                                                                                  the viewer's load of today
	 *                                                                                  ({@see SuggestionView::loadOf()})
	 */
	public function forViewer(House $house, HouseUser $viewer, \DateTimeInterface $now): array {
		$view = $this->suggestions->view($house, $now);
		return [
			'rows' => $this->rowsOf($view, $viewer),
			'load' => $view->loadOf((int)$viewer->getId(), $view->context->todayKey),
		];
	}

	/**
	 * Today's open rows of a viewer from one suggestion read. A member
	 * who is no child receives every visible row; a child is offered its
	 * own and the unassigned work only (CS-PLAN-065, decision D4), and
	 * nothing on a day it is not available.
	 *
	 * @return list<array{roomTask: RoomTask, task: \OCA\ChoreStrike\Db\Task, room: \OCA\ChoreStrike\Db\Room, assignees: string[], cycle: array<string, mixed>, checklistItemCount: int, suggestedFor?: list<string>, overload?: bool, openWindow?: true}> Suggested rows carry `suggestedFor` and `overload`, the others `openWindow`
	 */
	private function rowsOf(SuggestionView $view, HouseUser $viewer): array {
		if ($viewer->isChild() && !$view->isAvailableToday((int)$viewer->getId())) {
			return [];
		}
		$suggested = $view->today();
		$dueRows = [];
		$openWindow = [];
		foreach ($this->openRows($view->context, $viewer) as $row) {
			$entry = $suggested[(int)$row['roomTask']->getId()] ?? null;
			if ($entry !== null && $entry['cycleKey'] === $row['cycle']['cycleKey']) {
				$dueRows[] = $row + ['suggestedFor' => $entry['userIds'], 'overload' => $entry['overload']];
			} else {
				$openWindow[] = $row + ['openWindow' => true];
			}
		}
		$rows = $viewer->isChild() ? $this->childRows($view, $viewer, $dueRows, $openWindow) : [...$dueRows, ...$openWindow];
		return $this->roomTaskRead->withChecklistCounts($this->roomTaskRead->withAssignees($rows, $view->context->assignees));
	}

	/**
	 * The viewer-visible rows whose window is open today, in the canonical
	 * `next_due, created_at, id` order of the house read. Visibility first:
	 * only rows the viewer may see get a `cycle` block.
	 *
	 * @return list<array<string, mixed>> Rows with their `cycle` block
	 */
	private function openRows(SuggestionContext $context, HouseUser $viewer): array {
		$withCycle = array_values(array_filter(
			$this->suggestions->visibleRoomTasks($context, $viewer),
			static fn (RoomTask $roomTask): bool => isset($context->jobs[(int)$roomTask->getId()])
		));
		$rows = [];
		foreach ($this->roomTaskRead->rowsOf(new RoomTaskList($withCycle, $context->tasks)) as $row) {
			$block = $this->windows->block($row['roomTask'], $row['task'], $context->jobs[(int)$row['roomTask']->getId()]->cycle, $context->todayKey);
			if (($block['state'] ?? null) === WindowService::STATE_OPEN) {
				$rows[] = $row + ['cycle' => $block];
			}
		}
		return $rows;
	}

	/**
	 * A child's rows of *Today* on a day it is available: the work
	 * suggested to the child, at most the owner-set daily limit (D5,
	 * ADR-022 decision 2 rule 5), and as also possible the open rows that
	 * are not today's work and are the child's or unassigned (D4).
	 * Unassigned work that today suggests to someone else is not offered
	 * to the child. The limit caps what *Today* suggests, never what the
	 * child owes (BUG-UX-013): the child's own work above it stays visible
	 * as also possible.
	 *
	 * @param list<array<string, mixed>> $dueRows The rows suggested for today, to anyone
	 * @param list<array<string, mixed>> $openWindow The other open rows
	 * @return list<array<string, mixed>>
	 */
	private function childRows(SuggestionView $view, HouseUser $viewer, array $dueRows, array $openWindow): array {
		[$suggested, $others] = self::suggestedTo($viewer, $dueRows, $view->limitOf((int)$viewer->getId()));
		$assignees = $view->context->assignees;
		$possible = [
			...array_filter($others, static fn (array $row): bool => RoomTaskReadService::isOwn($row, $viewer, $assignees)),
			...array_filter($openWindow, static fn (array $row): bool => RoomTaskReadService::isChildEligible($row, $viewer, $assignees)),
		];
		return [...$suggested, ...array_map(
			static fn (array $row): array => array_diff_key($row, ['suggestedFor' => true, 'overload' => true]) + ['openWindow' => true],
			$possible
		)];
	}

	/**
	 * Today's rows split into the ones suggested to the child, at most
	 * the limit, and every other one. Work that fits the day comes before
	 * overload; without a limit nothing is cut.
	 *
	 * @param list<array<string, mixed>> $dueRows
	 * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
	 */
	private static function suggestedTo(HouseUser $viewer, array $dueRows, ?int $limit): array {
		$suggested = [];
		$others = [];
		foreach ($dueRows as $row) {
			if (in_array($viewer->getNextcloudUserId(), $row['suggestedFor'], true)) {
				$suggested[] = $row;
			} else {
				$others[] = $row;
			}
		}
		if ($limit !== null && count($suggested) > $limit) {
			usort($suggested, static fn (array $a, array $b): int => $a['overload'] <=> $b['overload']);
			$others = [...array_slice($suggested, $limit), ...$others];
			$suggested = array_slice($suggested, 0, $limit);
		}
		return [$suggested, $others];
	}
}
