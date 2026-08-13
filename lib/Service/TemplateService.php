<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * Service for the v2 room-type-based task template catalog.
 *
 * The catalog is two JSON files in lib/Templates/:
 *  - RoomTypes.json     room-type registry (id, defaultName, icon, templateIds)
 *  - TaskTemplates.json leveled task templates (v2 schema)
 *
 * A template applies to a room type when the type is present in the
 * template's roomTypes[] array. House-wide chores live under the
 * `household` room type, floor-wide chores under `hallway`, so the
 * materialization stays 100% room-based (no floor/house scope columns).
 *
 * Catalog strings are English source text; they are localized at runtime
 * via IL10N::t() so the German (l10n/de.json) and other translations are
 * resolved by Nextcloud's standard mechanism.
 */
class TemplateService {
	/** Thorough level: all tasks, standard frequencies. */
	public const LEVEL_PUTZTEUFEL = 'putzteufel';
	/** Relaxed level: fewer tasks, lower frequencies, shorter times. */
	public const LEVEL_PUTZMUFFEL = 'putzmuffel';

	/** All supported cleaning levels. */
	public const LEVELS = [
		self::LEVEL_PUTZTEUFEL,
		self::LEVEL_PUTZMUFFEL,
	];

	/**
	 * Template fields a caller may override when materializing a task.
	 * Whitelisted to prevent mass-assignment of catalog internals.
	 */
	private const MATERIALIZE_OVERRIDE_FIELDS = [
		'name',
		'frequencyType',
		'frequencyValue',
		'estimatedMinutes',
		'energyCost',
		'priority',
	];

	private IL10N $l10n;
	private ?array $templatesCache = null;
	private ?array $templatesById = null;
	private ?array $roomTypesCache = null;

	public function __construct(IL10N $l10n) {
		$this->l10n = $l10n;
	}

	/**
	 * Load the raw (un-localized) templates from the v2 JSON catalog.
	 *
	 * @return array<int, array>
	 */
	private function loadTemplates(): array {
		if ($this->templatesCache !== null) {
			return $this->templatesCache;
		}

		$this->templatesCache = $this->loadJsonFile(
			__DIR__ . '/../Templates/TaskTemplates.json',
			'templates'
		);
		return $this->templatesCache;
	}

	/**
	 * Load the raw room-type registry from JSON.
	 *
	 * @return array<int, array>
	 */
	private function loadRoomTypes(): array {
		if ($this->roomTypesCache !== null) {
			return $this->roomTypesCache;
		}

		$this->roomTypesCache = $this->loadJsonFile(
			__DIR__ . '/../Templates/RoomTypes.json',
			'roomTypes'
		);
		return $this->roomTypesCache;
	}

	/**
	 * Decode a JSON file and return the keyed section, or [] on any failure.
	 *
	 * @param string $path Absolute path to the JSON catalog file
	 * @param string $section Top-level key to extract
	 * @return array<int, array>
	 */
	private function loadJsonFile(string $path, string $section): array {
		if (!is_file($path)) {
			return [];
		}

		$content = file_get_contents($path);
		if (!is_string($content) || $content === '') {
			return [];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
			return [];
		}

		$section = $data[$section] ?? [];
		return is_array($section) ? array_values($section) : [];
	}

	/**
	 * Index templates by id (lazy, memoized).
	 *
	 * @return array<string, array>
	 */
	private function getTemplatesById(): array {
		if ($this->templatesById === null) {
			$this->templatesById = [];
			foreach ($this->loadTemplates() as $template) {
				if (isset($template['id'])) {
					$this->templatesById[$template['id']] = $template;
				}
			}
		}

		return $this->templatesById;
	}

	/**
	 * Build the localized catalog payload for the wizard and the per-room
	 * template picker.
	 *
	 * @return array{levels: array<int, array>, roomTypes: array<int, array>, templates: array<int, array>}
	 */
	public function getCatalog(): array {
		return [
			'levels' => $this->getLevelDefinitions(),
			'roomTypes' => $this->getRoomTypes(),
			'templates' => array_map(fn ($t) => $this->localizeTemplate($t), $this->loadTemplates()),
		];
	}

	/**
	 * Static level metadata (keys + localized labels/descriptions).
	 *
	 * @return array<int, array{id: string, label: string, description: string}>
	 */
	public function getLevelDefinitions(): array {
		return [
			[
				'id' => self::LEVEL_PUTZTEUFEL,
				'label' => $this->l10n->t('Putzteufel'),
				'description' => $this->l10n->t('Everything shines! Lots of tasks, but everything is sparkling clean in no time.'),
			],
			[
				'id' => self::LEVEL_PUTZMUFFEL,
				'label' => $this->l10n->t('Putzmuffel'),
				'description' => $this->l10n->t('Keep it relaxed! Only the essentials, with shorter times.'),
			],
		];
	}

	/**
	 * Localized room types (id, defaultName, icon, templateIds).
	 *
	 * @return array<int, array{id: string, defaultName: string, icon: string, templateIds: array<int, string>}>
	 */
	public function getRoomTypes(): array {
		return array_map(function ($rt): array {
			$templateIds = isset($rt['templateIds']) && is_array($rt['templateIds'])
				? array_values(array_map('strval', $rt['templateIds']))
				: [];
			return [
				'id' => (string)$rt['id'],
				'defaultName' => $this->l10n->t((string)$rt['defaultName']),
				'icon' => (string)($rt['icon'] ?? 'Home'),
				'templateIds' => $templateIds,
			];
		}, $this->loadRoomTypes());
	}

	/**
	 * Get a single room-type definition by id (localized), or null.
	 *
	 * @param string $roomType The room-type id
	 * @return array|null
	 */
	public function getRoomType(string $roomType): ?array {
		foreach ($this->getRoomTypes() as $rt) {
			if ($rt['id'] === $roomType) {
				return $rt;
			}
		}

		return null;
	}

	/**
	 * Whether a room-type id is part of the registry.
	 *
	 * @param string $roomType The room-type id
	 */
	public function isValidRoomType(string $roomType): bool {
		return $this->getRoomType($roomType) !== null;
	}

	/**
	 * Get templates applicable to a room type (multi-valued).
	 *
	 * Generalizes the old single-valued getTemplatesByRoomType(): a template
	 * matches when ANY of its roomTypes[] equals the requested type. House-wide
	 * chores (roomTypes includes 'household') are NOT auto-added for every
	 * room type — they are surfaced explicitly via the 'household' room type
	 * and the picker adds them separately.
	 *
	 * @param string $roomType The room-type id
	 * @return array<int, array> Localized templates whose roomTypes[] include $roomType
	 */
	public function getTemplatesForRoomType(string $roomType): array {
		$matched = array_filter(
			$this->loadTemplates(),
			fn ($t) => is_array($t['roomTypes'] ?? null) && in_array($roomType, $t['roomTypes'], true)
		);

		return array_map(fn ($t) => $this->localizeTemplate($t), array_values($matched));
	}

	/**
	 * Search templates by name, description, category or tags (localized view).
	 *
	 * @param string $query Search text (case-insensitive)
	 * @return array<int, array>
	 */
	public function searchTemplates(string $query): array {
		$lower = strtolower(trim($query));
		if ($lower === '') {
			return array_map(fn ($t) => $this->localizeTemplate($t), $this->loadTemplates());
		}

		$matched = array_filter(
			$this->loadTemplates(),
			function ($t) use ($lower): bool {
				if (stripos((string)($t['name'] ?? ''), $lower) !== false) {
					return true;
				}
				if (stripos((string)($t['description'] ?? ''), $lower) !== false) {
					return true;
				}
				if (stripos((string)($t['category'] ?? ''), $lower) !== false) {
					return true;
				}
				$tags = $t['tags'] ?? [];
				if (is_array($tags)) {
					foreach ($tags as $tag) {
						if (stripos((string)$tag, $lower) !== false) {
							return true;
						}
					}
				}
				return false;
			}
		);

		return array_map(fn ($t) => $this->localizeTemplate($t), array_values($matched));
	}

	/**
	 * Get a raw (un-localized) template by id.
	 *
	 * @param string $templateId The template id
	 * @return array|null
	 */
	public function getTemplate(string $templateId): ?array {
		return $this->getTemplatesById()[$templateId] ?? null;
	}

	/**
	 * Resolve a template + level into a Task-ready payload, applying
	 * whitelisted overrides. Localizes the user-visible strings.
	 *
	 * @param string $templateId The template id
	 * @param string $level One of self::LEVELS
	 * @param array $overrides Optional overrides (name, frequencyType,
	 *                          frequencyValue, estimatedMinutes, energyCost, priority)
	 * @return array{name: string, description: ?string, instructions: ?string, category: string, energyCost: int, priority: int, frequencyType: string, frequencyValue: int, estimatedMinutes: int, schedulingMode: string, scopeType: string}
	 * @throws \InvalidArgumentException if the template or level is invalid,
	 *         or the template is not available for the requested level
	 */
	public function materializeTemplate(string $templateId, string $level, array $overrides = []): array {
		$template = $this->getTemplate($templateId);
		if ($template === null) {
			throw new \InvalidArgumentException('Template not found: ' . $templateId);
		}

		if (!in_array($level, self::LEVELS, true)) {
			throw new \InvalidArgumentException('Invalid cleaning level: ' . $level);
		}

		$levelData = $template['levels'][$level] ?? null;
		if (!is_array($levelData)) {
			throw new \InvalidArgumentException(
				'Template "' . $templateId . '" is not available for level "' . $level . '"'
			);
		}

		$payload = [
			'name' => $this->l10n->t((string)$template['name']),
			'description' => $this->l10n->t((string)($template['description'] ?? '')),
			'instructions' => $this->l10n->t((string)($template['instructions'] ?? '')),
			'category' => (string)$template['category'],
			'energyCost' => (int)$template['energyCost'],
			'priority' => (int)$template['priority'],
			'frequencyType' => (string)$levelData['frequencyType'],
			'frequencyValue' => (int)$levelData['frequencyValue'],
			'estimatedMinutes' => (int)$levelData['estimatedMinutes'],
			'schedulingMode' => 'dynamic',
			'scopeType' => 'room',
		];

		foreach (self::MATERIALIZE_OVERRIDE_FIELDS as $field) {
			if (array_key_exists($field, $overrides) && $overrides[$field] !== null) {
				$payload[$field] = $overrides[$field];
			}
		}

		return $payload;
	}

	/**
	 * Project a raw template into the localized public view (incl. level data).
	 *
	 * @param array $t Raw template from the catalog
	 * @return array
	 */
	private function localizeTemplate(array $t): array {
		return [
			'id' => (string)$t['id'],
			'name' => $this->l10n->t((string)$t['name']),
			'description' => $this->l10n->t((string)($t['description'] ?? '')),
			'instructions' => $this->l10n->t((string)($t['instructions'] ?? '')),
			'category' => (string)$t['category'],
			'energyCost' => (int)$t['energyCost'],
			'priority' => (int)$t['priority'],
			'roomTypes' => array_values(array_map('strval', $t['roomTypes'] ?? [])),
			'levels' => $this->normalizeLevels($t['levels'] ?? []),
			'tags' => array_values(array_map('strval', $t['tags'] ?? [])),
		];
	}

	/**
	 * Ensure each level entry carries its scheduling details; keep null
	 * entries explicit so the UI can mark a task as level-exclusive.
	 *
	 * @param array $levels Raw levels map
	 * @return array
	 */
	private function normalizeLevels(array $levels): array {
		$normalized = [];
		foreach (self::LEVELS as $level) {
			$data = $levels[$level] ?? null;
			if (!is_array($data)) {
				$normalized[$level] = null;
				continue;
			}

			$normalized[$level] = [
				'frequencyType' => (string)($data['frequencyType'] ?? 'weekly'),
				'frequencyValue' => (int)($data['frequencyValue'] ?? 1),
				'estimatedMinutes' => (int)($data['estimatedMinutes'] ?? 15),
			];
		}

		return $normalized;
	}
}
