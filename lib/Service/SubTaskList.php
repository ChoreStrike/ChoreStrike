<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * The sub-tasks the one form sends (CS-PLAN-091 WP-B6): the whole list in
 * its order, each entry `{id?, label}`. An entry with an id keeps that
 * sub-task (and its ticks), one without is new. One boundary validation
 * for the creation and the edit aggregate.
 */
final class SubTaskList {
	/** Most sub-tasks one task takes. */
	public const MAX_ITEMS = 50;

	/** The label column's length. */
	public const MAX_LABEL_LENGTH = 255;

	/**
	 * @param list<array{id: ?int, label: string}> $entries
	 */
	private function __construct(
		public readonly array $entries,
	) {
	}

	/**
	 * @param mixed $raw The `checklistItems` field of a form payload
	 * @throws \InvalidArgumentException with a translated message (400)
	 */
	public static function fromPayload(mixed $raw, IL10N $l10n): self {
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new \InvalidArgumentException($l10n->t('Invalid value type for field: %1$s', ['checklistItems']));
		}
		if (count($raw) > self::MAX_ITEMS) {
			throw new \InvalidArgumentException($l10n->t('A task takes at most %1$d sub-tasks', [self::MAX_ITEMS]));
		}
		$entries = [];
		$ids = [];
		foreach ($raw as $entry) {
			[$id, $label] = self::entry($entry, $l10n);
			if ($id !== null) {
				if (isset($ids[$id])) {
					throw new \InvalidArgumentException($l10n->t('Invalid value type for field: %1$s', ['checklistItems']));
				}
				$ids[$id] = true;
			}
			$entries[] = ['id' => $id, 'label' => $label];
		}
		return new self($entries);
	}

	/** @return int[] The ids of the sub-tasks the list keeps */
	public function keptIds(): array {
		return array_values(array_filter(array_column($this->entries, 'id'), static fn ($id): bool => $id !== null));
	}

	/**
	 * @return array{0: ?int, 1: string}
	 * @throws \InvalidArgumentException
	 */
	private static function entry(mixed $entry, IL10N $l10n): array {
		$unknown = is_array($entry) ? array_diff(array_keys($entry), ['id', 'label']) : ['entry'];
		$id = is_array($entry) ? ($entry['id'] ?? null) : null;
		$label = is_array($entry) ? ($entry['label'] ?? null) : null;
		if ($unknown !== [] || ($id !== null && (!is_int($id) || $id < 1)) || !is_string($label)) {
			throw new \InvalidArgumentException($l10n->t('Invalid value type for field: %1$s', ['checklistItems']));
		}
		$label = trim($label);
		if ($label === '') {
			throw new \InvalidArgumentException($l10n->t('A sub-task needs a label'));
		}
		if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			throw new \InvalidArgumentException($l10n->t('A sub-task has at most %1$d characters', [self::MAX_LABEL_LENGTH]));
		}
		return [$id, $label];
	}
}
