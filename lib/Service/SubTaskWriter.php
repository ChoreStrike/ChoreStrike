<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCP\IL10N;

/**
 * Writes the sub-tasks of a definition for the one form (CS-PLAN-091
 * WP-B6): the list it sends replaces the definition's list in one save.
 * Every method runs UNDER LOCK inside the caller's house-first
 * transaction and never begins one.
 */
class SubTaskWriter {
	public function __construct(
		private TaskChecklistItemMapper $items,
		private IL10N $l10n,
	) {
	}

	/**
	 * The sub-tasks of a new definition: every entry is new.
	 *
	 * @throws \InvalidArgumentException an entry names a sub-task (400)
	 */
	public function createUnderLock(int $taskId, SubTaskList $list): void {
		if ($list->keptIds() !== []) {
			throw new \InvalidArgumentException($this->l10n->t('A new task has no sub-tasks to keep'));
		}
		foreach ($list->entries as $order => $entry) {
			$this->items->create($taskId, $entry['label'], $order);
		}
	}

	/**
	 * The definition's sub-tasks by id, in their order: the one read of a
	 * save, handed on to {@see differs()}, {@see syncUnderLock()} and
	 * {@see copyUnderLock()}. Every id the list keeps must be one of them.
	 *
	 * @param SubTaskList|null $list Null: the form left the sub-tasks as they are
	 * @return array<int, TaskChecklistItem>
	 * @throws \InvalidArgumentException a kept id that is not the definition's (400)
	 */
	public function itemsOf(int $taskId, ?SubTaskList $list): array {
		$current = [];
		foreach ($this->items->findByTaskId($taskId) as $item) {
			$current[(int)$item->getId()] = $item;
		}
		foreach ($list?->keptIds() ?? [] as $id) {
			if (!isset($current[$id])) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown sub-task'));
			}
		}
		return $current;
	}

	/**
	 * Whether the list says something else than the definition holds: a
	 * label, the order, or the set of sub-tasks.
	 *
	 * @param array<int, TaskChecklistItem> $current {@see itemsOf()}
	 */
	public function differs(array $current, SubTaskList $list): bool {
		if (count($current) !== count($list->entries)) {
			return true;
		}
		foreach (array_values($current) as $order => $item) {
			$entry = $list->entries[$order];
			if ($entry['id'] !== (int)$item->getId() || $entry['label'] !== $item->getLabel()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace the definition's list: kept sub-tasks are relabelled and
	 * reordered, the others go, new entries are added. Ticks of a removed
	 * sub-task stay as facts; the progress reads no longer count them.
	 *
	 * @param array<int, TaskChecklistItem> $current {@see itemsOf()}
	 */
	public function syncUnderLock(int $taskId, array $current, SubTaskList $list): void {
		$kept = array_flip($list->keptIds());
		foreach ($current as $id => $item) {
			if (!isset($kept[$id])) {
				$this->items->delete($item);
			}
		}
		foreach ($list->entries as $order => $entry) {
			$item = $entry['id'] !== null ? $current[$entry['id']] : null;
			if ($item === null) {
				$this->items->create($taskId, $entry['label'], $order);
			} elseif ($item->getLabel() !== $entry['label'] || (int)$item->getSortOrder() !== $order) {
				$item->setLabel($entry['label']);
				$item->setSortOrder($order);
				$this->items->update($item);
			}
		}
	}

	/**
	 * The sub-tasks of a definition copy (a split): the list as sent, or
	 * the source's own when the form left them as they were.
	 *
	 * @param array<int, TaskChecklistItem> $source The source's sub-tasks ({@see itemsOf()})
	 * @return array<int, int> Source sub-task id => its copy
	 */
	public function copyUnderLock(array $source, int $copyTaskId, ?SubTaskList $list): array {
		$entries = $list?->entries ?? array_map(
			static fn (TaskChecklistItem $item): array => ['id' => (int)$item->getId(), 'label' => (string)$item->getLabel()],
			array_values($source),
		);
		$map = [];
		foreach (array_values($entries) as $order => $entry) {
			$copy = $this->items->create($copyTaskId, $entry['label'], $order);
			if ($entry['id'] !== null) {
				$map[$entry['id']] = (int)$copy->getId();
			}
		}
		return $map;
	}
}
