<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * The household's task categories (CS-PLAN-090 WP-I, FEAT-UX-053, D-090-65).
 *
 * One ordered list in typed app config; there is one house per instance, so
 * it needs no schema (the ADR-019 pattern). An instance that never saved a
 * list reads the six keys the task form always offered, so retained tasks
 * keep their meaning without a write. Keys are stable; `label` is null for
 * a built-in key that keeps its translated name. *Other* is always last and
 * keeps its name. Tasks store only the key, and a key that is no longer
 * listed reads as *Other* on the client — nothing rewrites stored rows.
 */
class TaskCategoryService {
	public const OTHER = 'other';
	public const DEFAULT_KEYS = ['cleaning', 'kitchen', 'bathroom', 'organizing', 'maintenance', self::OTHER];
	public const MAX_CATEGORIES = 20;
	public const LABEL_MAX = 40;

	private const APP_NAME = 'chorestrike';
	private const CONFIG_KEY = 'task_categories';

	public function __construct(
		private IAppConfig $config,
		private AuthorizationService $authService,
		private HouseMapper $houseMapper,
		private TransactionExecutor $transactions,
		private IL10N $l10n,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * The current list with its revision (0 until the first save).
	 *
	 * @return array{revision: int, items: list<array{key: string, label: ?string}>}
	 */
	public function getList(): array {
		$stored = $this->config->getValueArray(self::APP_NAME, self::CONFIG_KEY, []);
		$items = $stored['items'] ?? null;
		if (!is_array($items) || $items === []) {
			$items = array_map(static fn (string $key): array => ['key' => $key, 'label' => null], self::DEFAULT_KEYS);
		}
		return [
			'revision' => (int)($stored['revision'] ?? 0),
			'items' => array_values(array_map(static fn (array $item): array => [
				'key' => (string)$item['key'],
				'label' => isset($item['label']) ? (string)$item['label'] : null,
			], $items)),
		];
	}

	/** Whether a task may be written with this key. */
	public function isKnown(string $key): bool {
		return in_array($key, array_column($this->getList()['items'], 'key'), true);
	}

	/** A template's key while it is listed, otherwise *Other*. */
	public function keyOrOther(string $key): string {
		return $this->isKnown($key) ? $key : self::OTHER;
	}

	/**
	 * Owner-only save of the whole ordered list against the revision the
	 * client read. Compare and write run under the house-row lock against a
	 * value read past the request cache, so two concurrent saves cannot both
	 * pass (RF-090-27).
	 *
	 * @param list<mixed> $items `{key?: ?string, label?: ?string}`; no key adds a category
	 * @return array{revision: int, items: list<array{key: string, label: ?string}>}
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException for a non-owner
	 * @throws ConflictException when the list changed since `$expectedRevision`
	 * @throws ValidationException for a list that breaks a rule
	 */
	public function save(int $houseId, int $expectedRevision, array $items): array {
		$owner = $this->authService->requireHouseOwner($houseId);
		$saved = $this->transactions->run(function () use ($houseId, $expectedRevision, $items): array {
			$this->houseMapper->findForUpdate($houseId);
			$this->config->clearCache(true);
			$current = $this->getList();
			if ($current['revision'] !== $expectedRevision) {
				throw new ConflictException($this->l10n->t('The categories changed in the meantime. Please check them and save again.'));
			}
			$next = ['revision' => $current['revision'] + 1, 'items' => $this->validated($items, $current['items'])];
			$this->config->setValueArray(self::APP_NAME, self::CONFIG_KEY, $next);
			return $next;
		});

		$this->appLogger->audit('Task categories saved', [
			'event' => 'task-categories.saved',
			'service' => 'TaskCategoryService',
			'houseId' => $houseId,
			'actorId' => (int)$owner->getId(),
			'revision' => $saved['revision'],
			'count' => count($saved['items']),
		]);
		return $saved;
	}

	/** Drop the stored list, so the defaults apply again (test-mode reset only). */
	public function resetToDefaults(): void {
		$this->config->deleteKey(self::APP_NAME, self::CONFIG_KEY);
	}

	/**
	 * @param list<mixed> $items
	 * @param list<array{key: string, label: ?string}> $current
	 * @return list<array{key: string, label: ?string}>
	 */
	private function validated(array $items, array $current): array {
		// Before the walk, so an oversized list costs nothing (RF-090-35).
		if (count($items) > self::MAX_CATEGORIES) {
			throw $this->invalid($this->l10n->t('A household can have at most %1$s categories.', [self::MAX_CATEGORIES]));
		}
		$listed = array_column($current, 'key');
		$result = [];
		foreach ($items as $item) {
			$label = $this->label($item);
			$key = is_array($item) ? ($item['key'] ?? null) : null;
			if ($key === null) {
				if ($label === null) {
					throw $this->invalid($this->l10n->t('Each category needs a name of 1 to %1$s characters.', [self::LABEL_MAX]));
				}
				$key = $this->newKey(array_merge($listed, array_keys($result)));
			} elseif (!is_string($key) || !in_array($key, $listed, true) || isset($result[$key])) {
				throw $this->invalid($this->l10n->t('The list names a category that does not exist.'));
			} elseif ($label === null && !in_array($key, self::DEFAULT_KEYS, true)) {
				throw $this->invalid($this->l10n->t('Each category needs a name of 1 to %1$s characters.', [self::LABEL_MAX]));
			}
			if ($key === self::OTHER && $label !== null) {
				throw $this->invalid($this->l10n->t('"Other" stays the last category and keeps its name.'));
			}
			$result[$key] = ['key' => $key, 'label' => $label];
		}
		$this->assertUniqueNames($result);
		return $this->checkedShape(array_values($result));
	}

	/**
	 * Refuse two categories that read the same regardless of case, a kept
	 * built-in name included, compared in the language of the saving owner.
	 *
	 * @param array<string, array{key: string, label: ?string}> $items
	 */
	private function assertUniqueNames(array $items): void {
		$names = [];
		foreach ($items as $item) {
			$folded = mb_strtolower($item['label'] ?? $this->builtInName($item['key']));
			if (isset($names[$folded])) {
				throw $this->invalid($this->l10n->t('Two categories cannot have the same name.'));
			}
			$names[$folded] = true;
		}
	}

	/** The translated name of a built-in key; only these keep a null label. */
	private function builtInName(string $key): string {
		return match ($key) {
			'cleaning' => $this->l10n->t('Cleaning'),
			'kitchen' => $this->l10n->t('Kitchen'),
			'bathroom' => $this->l10n->t('Bathroom'),
			'organizing' => $this->l10n->t('Organizing'),
			'maintenance' => $this->l10n->t('Maintenance'),
			default => $this->l10n->t('Other'),
		};
	}

	/** The trimmed label of one item, null for a built-in name. */
	private function label(mixed $item): ?string {
		if (!is_array($item) || !array_key_exists('label', $item) || $item['label'] === null) {
			return null;
		}
		$label = is_string($item['label']) ? trim($item['label']) : '';
		$length = mb_strlen($label);
		if ($length < 1 || $length > self::LABEL_MAX) {
			throw $this->invalid($this->l10n->t('Each category needs a name of 1 to %1$s characters.', [self::LABEL_MAX]));
		}
		return $label;
	}

	/**
	 * @param list<array{key: string, label: ?string}> $list
	 * @return list<array{key: string, label: ?string}>
	 */
	private function checkedShape(array $list): array {
		if ($list === [] || $list[count($list) - 1]['key'] !== self::OTHER) {
			throw $this->invalid($this->l10n->t('"Other" stays the last category and keeps its name.'));
		}
		return $list;
	}

	/**
	 * A short server-generated key; it fits the 50 characters of
	 * `tasks.category` (RF-090-30).
	 *
	 * @param list<string> $taken
	 */
	private function newKey(array $taken): string {
		do {
			$key = 'c' . bin2hex(random_bytes(4));
		} while (in_array($key, $taken, true));
		return $key;
	}

	private function invalid(string $message): ValidationException {
		return new ValidationException($message);
	}
}
