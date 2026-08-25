<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Applies the first-run house setup wizard payload in a single transaction:
 * floors → rooms (with roomType) → tasks from the leveled catalog → room-task
 * links with per-room assignees.
 *
 * Floor/house-wide chores need no new scope machinery (decision D2): floor-wide
 * tasks live in one `hallway` room per floor, house-wide tasks in one
 * `household` room — both materialized as ordinary rooms.
 *
 * Bulk materialization never swallows failures silently (BUG-TEMPLATE-001):
 * every template that cannot be materialized for the chosen level is logged
 * with its id and skipped, and the response reports the count of created tasks
 * so partial results are visible.
 */
class HouseSetupService {
	/** Hard cap on the number of floors a wizard payload may create. */
	public const MAX_FLOORS = 6;
	/** Hard cap on rooms per floor in a wizard payload. */
	public const MAX_ROOMS_PER_FLOOR = 12;
	/** Max length for a room or floor name. */
	public const MAX_NAME_LENGTH = 255;

	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private HouseUserMapper $houseUserMapper;
	private TemplateService $templateService;
	private IDBConnection $db;
	private IL10N $l10n;
	private ?AppLogger $appLogger;

	public function __construct(
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		TaskMapper $taskMapper,
		RoomTaskMapper $roomTaskMapper,
		HouseUserMapper $houseUserMapper,
		TemplateService $templateService,
		IDBConnection $db,
		IL10N $l10n,
		?AppLogger $appLogger = null,
	) {
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->taskMapper = $taskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->templateService = $templateService;
		$this->db = $db;
		$this->l10n = $l10n;
		$this->appLogger = $appLogger;
	}

	/**
	 * Validate and transactionally apply a wizard payload.
	 *
	 * @param int $houseId The caller's house ID
	 * @param array $payload {intensityLevel: string, options: {createHouseholdRoom?: bool, createHallways?: bool}, floors: [{name: string, rooms: [{name: string, roomType: string, icon?: string, assigneeUserId?: string}]}]}
	 * @return array{floors: int, rooms: int, tasks: int, roomTasks: int, skipped: array<int, string>}
	 *
	 * @throws \InvalidArgumentException on any validation failure
	 */
	public function applySetup(int $houseId, array $payload): array {
		$level = $this->validateBasics($payload);
		$options = $this->validateOptions($payload['options'] ?? []);
		$floors = $this->validateFloors($payload['floors'] ?? [], $houseId);

		// Pre-resolve the auto rooms so they participate in the same room
		// list / task-materialization pass as the user-defined rooms.
		$this->injectAutoRooms($floors, $options);

		$createdTasks = [];
		$skipped = [];
		$taskCount = 0;
		$roomTaskCount = 0;
		$roomCount = 0;
		$nextDue = new \DateTime();
		$nextDue->add(new \DateInterval('P1D'));

		$this->db->beginTransaction();
		try {
			foreach ($floors as $floorIndex => $floor) {
				$floorEntity = $this->floorMapper->create($houseId, $floor['name'], $floorIndex);

				foreach ($floor['rooms'] as $room) {
					$roomEntity = $this->roomMapper->create(
						$floorEntity->getId(),
						$room['name'],
						$room['icon'] ?? null,
						$room['roomType']
					);
					$roomCount++;

					$applicable = $this->templateService->getTemplatesForRoomType($room['roomType']);
					foreach ($applicable as $template) {
						if (($template['levels'][$level] ?? null) === null) {
							// Template is level-exclusive (omitted from this plan).
							// Report it for transparency without logging — this is
							// expected behaviour, not the swallow-on-exception case.
							if (!in_array($template['id'], $skipped, true)) {
								$skipped[] = $template['id'];
							}
							continue;
						}

						$taskId = $createdTasks[$template['id']] ?? null;
						if ($taskId === null) {
							try {
								$materialized = $this->templateService->materializeTemplate($template['id'], $level);
							} catch (\InvalidArgumentException $e) {
								// BUG-TEMPLATE-001: log every skip with context, never swallow silently.
								$this->appLogger?->warning('Setup wizard skipped template', [
									'service' => 'HouseSetupService',
									'event' => 'setup.skip-template',
									'templateId' => $template['id'],
									'level' => $level,
									'reason' => $e->getMessage(),
								]);
								$skipped[] = $template['id'];
								continue;
							}

							$task = $this->taskMapper->create(
								name: $materialized['name'],
								description: $materialized['description'],
								energyCost: $materialized['energyCost'],
								priority: $materialized['priority'],
								frequencyType: $materialized['frequencyType'],
								frequencyValue: $materialized['frequencyValue'],
								estimatedMinutes: $materialized['estimatedMinutes'],
								category: $materialized['category'],
								scopeType: $materialized['scopeType'],
								schedulingMode: $materialized['schedulingMode'],
								instructions: $materialized['instructions'],
							);
							$taskId = $task->getId();
							$createdTasks[$template['id']] = $taskId;
							$taskCount++;
						}

						$this->roomTaskMapper->create(
							$roomEntity->getId(),
							$taskId,
							$nextDue,
							$room['assigneeUserId'] ?? null
						);
						$roomTaskCount++;
					}
				}
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'floors' => count($floors),
			'rooms' => $roomCount,
			'tasks' => $taskCount,
			'roomTasks' => $roomTaskCount,
			'skipped' => $skipped,
		];
	}

	/**
	 * Validate the top-level intensity level.
	 *
	 * @param array $payload
	 * @return string The validated level
	 * @throws \InvalidArgumentException
	 */
	private function validateBasics(array $payload): string {
		$level = $payload['intensityLevel'] ?? null;
		if (!is_string($level) || !in_array($level, TemplateService::LEVELS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid or missing intensity level'));
		}

		return $level;
	}

	/**
	 * Normalize and validate the options block.
	 *
	 * @param mixed $options
	 * @return array{createHouseholdRoom: bool, createHallways: bool}
	 */
	private function validateOptions($options): array {
		if (!is_array($options)) {
			$options = [];
		}

		return [
			'createHouseholdRoom' => (bool)($options['createHouseholdRoom'] ?? true),
			'createHallways' => (bool)($options['createHallways'] ?? true),
		];
	}

	/**
	 * Validate the floors/rooms structure and every assignee's house membership.
	 *
	 * @param mixed $floors
	 * @param int $houseId
	 * @return array<int, array{name: string, rooms: array<int, array{name: string, roomType: string, icon?: string, assigneeUserId?: string}>}>
	 * @throws \InvalidArgumentException
	 */
	private function validateFloors($floors, int $houseId): array {
		if (!is_array($floors) || $floors === []) {
			throw new \InvalidArgumentException($this->l10n->t('At least one floor is required'));
		}

		if (count($floors) > self::MAX_FLOORS) {
			throw new \InvalidArgumentException($this->l10n->t('A maximum of %1$d floors is allowed', [self::MAX_FLOORS]));
		}

		$validated = [];
		foreach ($floors as $floor) {
			if (!is_array($floor)) {
				throw new \InvalidArgumentException($this->l10n->t('Invalid floor definition'));
			}

			$name = $this->validateName($floor['name'] ?? null, 'floor');
			$rooms = $floor['rooms'] ?? [];
			if (!is_array($rooms) || $rooms === []) {
				throw new \InvalidArgumentException($this->l10n->t('At least one room is required per floor'));
			}
			if (count($rooms) > self::MAX_ROOMS_PER_FLOOR) {
				throw new \InvalidArgumentException(
					$this->l10n->t('A maximum of %1$d rooms per floor is allowed', [self::MAX_ROOMS_PER_FLOOR])
				);
			}

			$validatedRooms = [];
			foreach ($rooms as $room) {
				if (!is_array($room)) {
					throw new \InvalidArgumentException($this->l10n->t('Invalid room definition'));
				}

				$roomName = $this->validateName($room['name'] ?? null, 'room');
				$roomType = $room['roomType'] ?? null;
				if (!is_string($roomType) || !$this->templateService->isValidRoomType($roomType)) {
					throw new \InvalidArgumentException($this->l10n->t('Invalid room type: %1$s', [(string)$roomType]));
				}

				$assigneeUserId = $room['assigneeUserId'] ?? null;
				if (is_string($assigneeUserId) && trim($assigneeUserId) !== '') {
					if ($this->houseUserMapper->findByHouseAndUser($houseId, $assigneeUserId) === null) {
						throw new \InvalidArgumentException(
							$this->l10n->t('Assignee is not a member of this house: %1$s', [$assigneeUserId])
						);
					}
				} else {
					$assigneeUserId = null;
				}

				$validatedRooms[] = [
					'name' => $roomName,
					'roomType' => $roomType,
					'icon' => $this->extractIcon($room, $roomType),
					'assigneeUserId' => $assigneeUserId,
				];
			}

			$validated[] = ['name' => $name, 'rooms' => $validatedRooms];
		}

		return $validated;
	}

	/**
	 * Validate a room/floor name.
	 *
	 * @param mixed $name
	 * @param string $context Used in the error message
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	private function validateName($name, string $context): string {
		if (!is_string($name) || trim($name) === '') {
			throw new \InvalidArgumentException($this->l10n->t('A %1$s name is required', [$context]));
		}

		$trimmed = trim($name);
		if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
			throw new \InvalidArgumentException($this->l10n->t('A %1$s name must not exceed %2$d characters', [$context, self::MAX_NAME_LENGTH]));
		}

		return $trimmed;
	}

	/**
	 * Resolve the room icon: explicit override, else the room-type default.
	 *
	 * @param array $room
	 * @param string $roomType
	 * @return string|null
	 */
	private function extractIcon(array $room, string $roomType): ?string {
		if (isset($room['icon']) && is_string($room['icon']) && trim($room['icon']) !== '') {
			return trim($room['icon']);
		}

		$rt = $this->templateService->getRoomType($roomType);
		return $rt['icon'] ?? null;
	}

	/**
	 * Append the toggleable auto rooms (house-wide + per-floor hallway) to the
	 * floor list so they are materialized like ordinary rooms.
	 *
	 * @param array $floors
	 * @param array{createHouseholdRoom: bool, createHallways: bool} $options
	 */
	private function injectAutoRooms(array &$floors, array $options): void {
		if ($options['createHallways']) {
			$hallwayType = $this->templateService->getRoomType('hallway');
			foreach ($floors as &$floor) {
				$floor['rooms'][] = [
					'name' => $hallwayType['defaultName'] ?? 'Hallway & Stairs',
					'roomType' => 'hallway',
					'icon' => $hallwayType['icon'] ?? null,
					'assigneeUserId' => null,
				];
			}
			unset($floor);
		}

		if ($options['createHouseholdRoom'] && $floors !== []) {
			// House-wide chores land on the ground floor (or the basement floor
			// when one is present), since they are not tied to a specific level.
			$householdFloorIndex = 0;
			$lastFloor = $floors[count($floors) - 1];
			$lastFloorRoomTypes = array_column($lastFloor['rooms'], 'roomType');
			if (in_array('basement', $lastFloorRoomTypes, true)) {
				$householdFloorIndex = count($floors) - 1;
			}

			$householdType = $this->templateService->getRoomType('household');
			$floors[$householdFloorIndex]['rooms'][] = [
				'name' => $householdType['defaultName'] ?? 'Household & Co.',
				'roomType' => 'household',
				'icon' => $householdType['icon'] ?? null,
				'assigneeUserId' => null,
			];
		}
	}
}
