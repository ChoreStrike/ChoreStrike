<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCP\IDBConnection;

// The cutover to derived due windows in the P4 app-upgrade proof
// (CS-PLAN-091 WP-B1, ADR-021 decision 12). Functions only, loaded by
// `upgrade-fixture.php` after its target guard; it runs nothing itself.
// `verifyCutover` reads stored rows and so works on both builds;
// `postcheckCycles` calls services the candidate alone has.

/**
 * The live schedule revision, the valid completions and the earliest open
 * appointment of every room task, as the source build stored them.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, true>, 2: array<int, string>}
 */
function storedTimeline(array $before): array {
	$revisions = [];
	foreach ($before['chorestrike_schedule_revs'] as $revision) {
		$id = (int)$revision['room_task_id'];
		if ($revision['closed_at'] === null && (int)$revision['revision'] >= (int)($revisions[$id]['revision'] ?? 0)) {
			$revisions[$id] = $revision;
		}
	}
	$completed = [];
	foreach ($before['chorestrike_completed_tasks'] as $completion) {
		if ($completion['undone_at'] === null) {
			$completed[(int)$completion['room_task_id']] = true;
		}
	}
	// An appointment a restart replaced is not the open work (its successor is).
	$replaced = [];
	foreach ($before['chorestrike_occurrences'] as $occurrence) {
		if ($occurrence['restart_of_id'] !== null) {
			$replaced[(int)$occurrence['restart_of_id']] = true;
		}
	}
	$firstOpen = [];
	foreach ($before['chorestrike_occurrences'] as $occurrence) {
		if ($occurrence['completion_id'] === null && $occurrence['covered_by_completion_id'] === null && $occurrence['kind'] === 'scheduled'
			&& !isset($replaced[(int)$occurrence['id']])) {
			$id = (int)$occurrence['room_task_id'];
			$firstOpen[$id] = min($firstOpen[$id] ?? '9999-12-31', day($occurrence['planned_date']));
		}
	}
	return [$revisions, $completed, $firstOpen];
}

/**
 * The cutover values of the columns the update added (ADR-021 decision
 * 12), worked out here from the rows the source build stored: where the
 * derived model starts, the streak bases, the anchor of every room task
 * and the day of a fixed monthly rule. Nothing else is filled.
 */
function verifyCutover(array $before, array $after): string {
	foreach ($after['chorestrike_houses'] as $house) {
		$zone = new DateTimeZone((string)$house['timezone']);
		// The day of the update (D-091-41); "yesterday" only once a house-local midnight has passed since.
		$days = [(new DateTime('today', $zone))->format('Y-m-d'), (new DateTime('yesterday', $zone))->format('Y-m-d')];
		check(in_array(day($house['cycles_from']), $days, true), 'Derived windows do not start on the day of the update');
	}
	foreach ($after['chorestrike_house_users'] as $member) {
		check($member['streak_base'] !== null && (int)$member['streak_base'] === (int)$member['current_streak']
			&& $member['longest_streak_base'] !== null && (int)$member['longest_streak_base'] === (int)$member['longest_streak'],
			'The streak base is not the stored streak of member ' . $member['id']);
	}
	foreach ($after['chorestrike_completed_tasks'] as $completion) {
		check($completion['cycle_key'] === null && $completion['window_start_on'] === null && $completion['window_end_on'] === null,
			'Stored completion ' . $completion['id'] . ' got cycle facts');
	}

	[$revisions, $completed, $firstOpen] = storedTimeline($before);
	$roomTasks = array_column($after['chorestrike_room_tasks'], null, 'id');
	ksort($roomTasks);
	$cases = ['fixed' => 0, 'completed' => 0, 'open' => 0, 'none' => 0];
	$monthDays = [];
	foreach ($roomTasks as $id => $roomTask) {
		check($roomTask['cycle_key'] === null && $roomTask['sleeping_since'] === null && $roomTask['wake_on'] === null
			&& (int)$roomTask['postpone_count'] === 0 && (int)$roomTask['postpone_days'] === 0 && (int)$roomTask['cycle_version'] === 0,
			'Room task ' . $id . ' does not start from the defaults');
		$revision = $revisions[$id] ?? null;
		$fixed = $revision !== null && $revision['cadence_mode'] === 'fixed' && $revision['lifetime'] !== 'once';
		if ($fixed && $revision['cadence_unit'] === 'month') {
			// The lowest room task of a shared definition decides.
			$monthDays[(int)$roomTask['task_id']] ??= (int)substr((string)day($revision['anchor_date']), 8, 2);
		}
		$case = $revision === null ? 'none' : ($fixed ? 'fixed' : (isset($completed[$id]) ? 'completed' : 'open'));
		$cases[$case]++;
		$expected = match ($case) {
			'none', 'completed' => null,
			'fixed' => day($revision['anchor_date']),
			'open' => $firstOpen[$id] ?? day($revision['anchor_date']),
		};
		check(day($roomTask['anchor_on']) === $expected, 'Wrong anchor for room task ' . $id . ' (' . $case . ')');
	}
	check($cases['completed'] > 0 && $cases['open'] > 0 && $cases['fixed'] >= 2 && count($monthDays) === 1,
		'The populated source must hold completed and open room tasks, fixed rules and one fixed monthly rule');
	foreach ($after['chorestrike_tasks'] as $task) {
		$day = $task['fixed_month_day'] === null ? null : (int)$task['fixed_month_day'];
		check($day === ($monthDays[(int)$task['id']] ?? null), 'Wrong monthly day for definition ' . $task['id']);
	}
	return count($roomTasks) . ' room tasks anchored by rule (' . $cases['fixed'] . ' fixed, ' . $cases['open'] . ' from the open appointment, '
		. $cases['completed'] . ' on their last completion, ' . $cases['none'] . ' without a schedule), ' . count($monthDays) . ' monthly days';
}

function countRows(string $table): int {
	$qb = \OC::$server->get(IDBConnection::class)->getQueryBuilder();
	return (int)$qb->select($qb->func()->count('*'))->from($table)->executeQuery()->fetchOne();
}

/**
 * Derived windows on the upgraded rows, through the candidate's services:
 * every room task that had a schedule reads a first window, the week that
 * holds the cutover evaluates from stored appointments and cycles
 * together, and one completion closes exactly one cycle while the stored
 * timeline keeps every row.
 */
function postcheckCycles(int $houseId): string {
	$before = json_decode((string)file_get_contents(BEFORE), true, 512, JSON_THROW_ON_ERROR);
	[$revisions, $completed] = storedTimeline($before);
	$mom = member($houseId, 'mom');
	$read = \OC::$server->get(RoomTaskReadService::class);
	$roomTasks = $read->getHouseRoomTasks($houseId, $mom);
	$facts = $read->cycleFacts($houseId, $roomTasks);
	$windows = 0;
	$target = null;
	foreach ($roomTasks as $roomTask) {
		$id = (int)$roomTask->getId();
		$block = $facts[$id]['block'] ?? null;
		$spent = ($revisions[$id]['lifetime'] ?? null) === 'once' && isset($completed[$id]);
		check($block !== null || !isset($revisions[$id]) || $spent, 'Scheduled room task ' . $id . ' reads no cycle');
		if (!isset($block['cycleKey'])) {
			continue;
		}
		check($block['windowStartOn'] <= $block['windowEndOn'], 'Room task ' . $id . ' reads no sound window');
		$windows++;
		// Never completed, so no same-day rule can refuse the completion below.
		$target ??= isset($completed[$id]) ? null : [$id, $block];
	}
	check($windows > 0 && $target !== null, 'No upgraded room task reads a first window that can be completed');

	$goal = \OC::$server->get(WeeklyGoalService::class)->getWeeklyGoalProgress($houseId);
	check($goal['eligible'] >= $goal['onTime'] && $goal['targetPercent'] === 80, 'The week of the cutover does not evaluate');

	[$roomTaskId, $block] = $target;
	$result = \OC::$server->get(CompletionService::class)->complete($houseId, 'mom', $roomTaskId, uuid(),
		$block['cycleKey'], (int)$block['expectedVersion'], [['userId' => 'mom', 'sharePct' => 100]], true);
	$db = \OC::$server->get(IDBConnection::class);
	$qb = $db->getQueryBuilder();
	$closed = $qb->select('*')->from('chorestrike_cycles')->executeQuery()->fetchAll();
	check(count($closed) === 1 && (int)$closed[0]['room_task_id'] === $roomTaskId && $closed[0]['cycle_key'] === $block['cycleKey']
		&& $closed[0]['outcome'] === 'done' && (int)$closed[0]['completion_id'] === (int)$result['completionId'],
		'The completion did not close exactly its cycle');
	$qb = $db->getQueryBuilder();
	$stored = $qb->select('cycle_key', 'window_start_on', 'window_end_on')->from('chorestrike_completed_tasks')
		->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$result['completionId'])))->executeQuery()->fetch();
	check($stored['cycle_key'] === $block['cycleKey'] && day($stored['window_end_on']) !== null, 'The completion did not freeze its cycle');
	$fresh = $read->cycleFacts($houseId, [\OC::$server->get(RoomTaskMapper::class)->find($roomTaskId)])[$roomTaskId]['block'] ?? null;
	check(($fresh['cycleKey'] ?? null) !== $block['cycleKey'], 'The completed cycle is still the current one');
	foreach (['chorestrike_occurrences', 'chorestrike_occ_members', 'chorestrike_agreements', 'chorestrike_schedule_revs', 'chorestrike_chk_progress'] as $table) {
		check(countRows($table) === count($before[$table]), 'The completion changed the stored timeline in ' . $table);
	}
	return $windows . ' first windows read; a completion closed cycle ' . $block['cycleKey'] . ' and left the stored timeline alone';
}
