<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Service for managing test data profiles
 *
 * Provides four profiles:
 * - empty: Clean database (no data)
 * - emptyhouse: Clean ChoreStrike tables, reusing the seeded family Nextcloud
 *   accounts (CS-PLAN-062 §3.6 — the first-run setup wizard E2E journey)
 * - minimal: 1 House, 1 Floor, 1 Room, 2 Users
 * - family: Complete family (6 Users, 3 Floors, 6 Rooms, 12 Tasks)
 */
class TestDataService {
	/**
	 * Reward-config overrides (appconfig keys) applied by the family profile.
	 *
	 * `release-notifications` needs a trivially-crossed weekly-goal target so
	 * a single completion deterministically achieves it. The reward-config
	 * HTTP surface was pruned (CS-PLAN-055), so the knob is seeded instead;
	 * `reset()` deletes the overrides again when a non-family profile seeds,
	 * keeping `oc_appconfig` free of leaked test state. Keys reference the
	 * RewardConfigService registry consts (single source of truth).
	 *
	 * @var array<string, string>
	 */
	private const FAMILY_REWARD_CONFIG_OVERRIDES = [
		RewardConfigService::KEY_WEEKLY_GOAL_TARGET_PERCENT => '1',
	];

	/**
	 * Tables to clear during test-data reset.
	 *
	 * Canonical tables only, audited against the current migration chain
	 * (CR-SVC-42; table names re-verified against the real schema by
	 * CS-PLAN-037 — the checklist tables are chk_items/chk_progress as
	 * created by Version1005; the longer chorestrike_checklist_* names
	 * never existed). `chorestrike_task_assignees` is deleted before
	 * `chorestrike_room_tasks` to keep the logical dependency order (the
	 * schema declares indexes, not DB-level foreign keys).
	 *
	 * CS-PLAN-071 WP0: `wallet_entries` → `wallets` → `commands` are
	 * included in dependency order — this test-mode reset is the ONLY
	 * path allowed to clear the financial identity and receipt tables
	 * (production structural deletion tombstones and preserves evidence).
	 *
	 * @var list<string>
	 */
	private const RESET_TABLES = [
		'chorestrike_task_assignees',
		'chorestrike_chk_progress',
		'chorestrike_completed_tasks',
		'chorestrike_room_tasks',
		'chorestrike_chk_items',
		'chorestrike_tasks',
		'chorestrike_rooms',
		'chorestrike_floors',
		'chorestrike_wallet_entries',
		'chorestrike_wallets',
		'chorestrike_commands',
		'chorestrike_house_users',
		'chorestrike_houses',
		'chorestrike_medals',
		'chorestrike_minigame_scores',
	];

	private IDBConnection $db;
	private IConfig $config;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private WalletMapper $walletMapper;

	// CR-SVC-40 / CS-PLAN-060 WP1, decision D6 documented exemption: this
	// service is NON-PRODUCTION test tooling (every entry point is guarded by
	// assertTestMode()). It keeps its IDBConnection injection purely for
	// query-builder access in reset()/seeding — it never opens transactions
	// or runs raw SQL, and a mapper-based QB access does not exist.

	public function __construct(
		IDBConnection $db,
		IConfig $config,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		TaskMapper $taskMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		WalletMapper $walletMapper,
	) {
		$this->db = $db;
		$this->config = $config;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->taskMapper = $taskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->walletMapper = $walletMapper;
	}

	/**
	 * Create the persistent wallet for a seeded member and return its id
	 * (CS-PLAN-071 WP0: seeds mirror the production membership paths —
	 * every membership row carries its wallet binding).
	 */
	private function seedWallet(int $houseId, string $nextcloudUserId): int {
		return $this->walletMapper->createOrGet($houseId, $nextcloudUserId)->getId();
	}

	/**
	 * Get the current Nextcloud instance ID
	 */
	private function getInstanceId(): string {
		return $this->config->getSystemValue('instanceid', 'default');
	}

	/**
	 * Guard every destructive entry point against a production run.
	 *
	 * Defense-in-depth backstop: callers (e.g. TestDataController) already
	 * gate on isTestMode(), but a future caller that forgets the preamble must
	 * not be able to truncate a production database. Throws unless the process
	 * is explicitly in test mode.
	 *
	 * @throws \RuntimeException when not running in test mode
	 */
	private function assertTestMode(): void {
		if (!$this->isTestMode()) {
			throw new \RuntimeException(
				'TestDataService destructive operations require test mode (NEXTCLOUD_TEST_MODE=true)'
			);
		}
	}

	/**
	 * Reset all tables (truncate, preserve schema)
	 */
	public function reset(): void {
		$this->assertTestMode();

		foreach (array_keys(self::FAMILY_REWARD_CONFIG_OVERRIDES) as $key) {
			$this->config->deleteAppValue('chorestrike', $key);
		}

		foreach (self::RESET_TABLES as $table) {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->delete($table);
				$qb->executeStatement();
			} catch (\Throwable $exception) {
				if (!$this->isMissingTableException($exception)) {
					throw $exception;
				}
			}
		}
	}

	/**
	 * Determine whether an exception was caused by a missing table.
	 */
	private function isMissingTableException(\Throwable $exception): bool {
		$message = strtolower($exception->getMessage());

		return str_contains($message, 'no such table')
			|| str_contains($message, 'does not exist')
			|| str_contains($message, "doesn't exist")
			|| str_contains($message, 'unknown table')
			|| str_contains($message, 'undefined table')
			|| str_contains($message, 'table or view not found');
	}

	/**
	 * Seed the Nextcloud admin account as a plain household member of the
	 * given house (test-mode only, ADR-010 invite membership). The admin
	 * session is the default E2E session; the seeded membership makes its
	 * authorization state deterministic instead of racing the first-owner
	 * bootstrap after every reset. Idempotent via createOrGet semantics of a
	 * plain insert guarded by a lookup.
	 */
	private function seedAdminMembership(int $houseId): void {
		if ($this->houseUserMapper->findByHouseAndUser($houseId, 'admin') !== null) {
			return;
		}

		$admin = new HouseUser();
		$admin->setHouseId($houseId);
		$admin->setNextcloudUserId('admin');
		$admin->setDisplayName('Admin');
		$admin->setRole('member');
		$admin->setWalletId($this->seedWallet($houseId, 'admin'));
		$admin->setJoinedAt(new \DateTime());
		try {
			$this->houseUserMapper->insert($admin);
		} catch (\OCP\DB\Exception $e) {
			// A unique-constraint race (two seeds in parallel) is benign:
			// the winner's row satisfies the same purpose.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Seed empty profile (just reset)
	 *
	 * @return array Empty result
	 */
	public function seedEmpty(): array {
		$this->assertTestMode();
		$this->reset();
		return ['profile' => 'empty', 'data' => []];
	}

	/**
	 * Seed the empty-house wizard profile (CS-PLAN-062 §3.6, decision D6).
	 *
	 * Reset only: no ChoreStrike rows, no memberships — the first-run setup
	 * wizard journey (setup-wizard-journey.spec.ts) starts from a structurally
	 * empty house. The Nextcloud accounts (mom, dad, …) already exist via the
	 * auth-setup family seed and survive `reset()` (only `chorestrike_*`
	 * tables are truncated), so the journey authenticates real users whose
	 * first request bootstraps them to owner.
	 *
	 * @return array Empty result
	 */
	public function seedEmptyhouse(): array {
		$this->assertTestMode();
		$this->reset();
		return ['profile' => 'emptyhouse', 'data' => []];
	}

	/**
	 * Seed minimal profile
	 *
	 * Creates:
	 * - 1 House
	 * - 1 Floor
	 * - 1 Room (Kitchen)
	 * - 2 Users (adult, kid)
	 *
	 * @return array Seeded data with IDs
	 */
	public function seedMinimal(): array {
		$this->assertTestMode();
		$this->reset();

		// Create house with real instance ID
		$instanceId = $this->getInstanceId();
		$house = new House();
		$house->setNextcloudInstanceId($instanceId);
		$house->setName('Test House');
		$house->setHouseHealth(100);
		$house->setCreatedAt(new \DateTime());
		$house->setUpdatedAt(new \DateTime());
		$house = $this->houseMapper->insert($house);

		// Create floor
		$floor = $this->floorMapper->create($house->getId(), 'Ground Floor', 0);

		// Create room
		$room = $this->roomMapper->create($floor->getId(), 'Kitchen');

		// Create users
		$users = [];
		$userData = [
			['userId' => 'adult', 'displayName' => 'Adult User', 'role' => 'owner'],
			['userId' => 'kid', 'displayName' => 'Kid User', 'role' => 'child'],
		];

		foreach ($userData as $data) {
			$houseUser = new HouseUser();
			$houseUser->setHouseId($house->getId());
			$houseUser->setNextcloudUserId($data['userId']);
			$houseUser->setDisplayName($data['displayName']);
			$houseUser->setRole($data['role']);
			$houseUser->setWalletId($this->seedWallet($house->getId(), $data['userId']));
			$houseUser->setJoinedAt(new \DateTime());
			$houseUser = $this->houseUserMapper->insert($houseUser);
			$users[$data['userId']] = $houseUser;
		}

		// Seed the NC admin account as an invited household member
		// (ADR-010: membership is invite-only after the first owner). The
		// admin session is the DEFAULT E2E session, so without an explicit
		// membership every default-session spec would non-deterministically
		// race the first-owner bootstrap after a reset. Excluded from the
		// returned payload — specs pin the seeded family member count.
		$this->seedAdminMembership($house->getId());

		return [
			'profile' => 'minimal',
			'data' => [
				'house' => $house->jsonSerialize(),
				'floors' => [$floor->jsonSerialize()],
				'rooms' => [$room->jsonSerialize()],
				'users' => array_values(array_map(fn ($u) => $u->jsonSerialize(), $users)),
			],
		];
	}

	/**
	 * Seed family profile
	 *
	 * Creates complete family structure:
	 * - 6 Users: mom, dad, teen, kid, grandma, friend
	 * - 3 Floors: Ground, First, Second
	 * - 6 Rooms: Kitchen, Living Room, Master Bedroom, Kids Bedroom, Master Bathroom, Kids Bathroom
	 * - 12 Tasks with various categories
	 * - 12 RoomTasks with assignments
	 *
	 * @return array Seeded data with IDs
	 */
	public function seedFamily(): array {
		$this->assertTestMode();
		$this->reset();

		// Family-profile reward-config knobs (CS-PLAN-055): the
		// `release-notifications` spec completes a single task and expects the
		// weekly goal to be reached — a trivially-crossed target keeps that
		// deterministic without the (pruned) reward-config HTTP surface.
		foreach (self::FAMILY_REWARD_CONFIG_OVERRIDES as $key => $value) {
			$this->config->setAppValue('chorestrike', $key, $value);
		}

		// Create house with real instance ID
		$instanceId = $this->getInstanceId();
		$house = new House();
		$house->setNextcloudInstanceId($instanceId);
		$house->setName('Test Family Home');
		$house->setHouseHealth(100);
		$house->setCreatedAt(new \DateTime());
		$house->setUpdatedAt(new \DateTime());
		$house = $this->houseMapper->insert($house);

		// Create floors
		$floors = [];
		$floorData = [
			['name' => 'Ground Floor', 'level' => 0],
			['name' => 'First Floor', 'level' => 1],
			['name' => 'Second Floor', 'level' => 2],
		];
		foreach ($floorData as $i => $data) {
			$floors[] = $this->floorMapper->create($house->getId(), $data['name'], $data['level']);
		}

		// Create rooms
		$rooms = [];
		$roomData = [
			['floorIndex' => 0, 'name' => 'Kitchen', 'cleanliness' => 75],
			['floorIndex' => 0, 'name' => 'Living Room', 'cleanliness' => 60],
			['floorIndex' => 1, 'name' => 'Master Bedroom', 'cleanliness' => 80],
			['floorIndex' => 1, 'name' => 'Kids Bedroom', 'cleanliness' => 40],
			['floorIndex' => 0, 'name' => 'Master Bathroom', 'cleanliness' => 70],
			['floorIndex' => 1, 'name' => 'Kids Bathroom', 'cleanliness' => 50],
		];
		foreach ($roomData as $data) {
			$room = new Room();
			$room->setFloorId($floors[$data['floorIndex']]->getId());
			$room->setName($data['name']);
			$room->setCleanliness($data['cleanliness']);
			$room->setCreatedAt(new \DateTime());
			$room = $this->roomMapper->insert($room);
			$rooms[$data['name']] = $room;
		}

		// Create users
		$users = [];
		$userData = [
			// mom carries the time-travel streak state (CS-PLAN-023): a live
			// streak with zero freezes and no completions, so the seeded
			// family can exercise streak-freeze consumption / reset + the
			// at-risk notification via `occ chorestrike:trigger-jobs --as-of`
			// without any real multi-day wait. She is available every weekday,
			// so the fairness skip never interferes regardless of run day.
			['userId' => 'mom', 'displayName' => 'Mom', 'role' => 'owner', 'currentStreak' => 5, 'streakFreezes' => 0, 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['kitchen', 'laundry'],
				'maxDailyTasks' => 5,
			]],
			['userId' => 'dad', 'displayName' => 'Dad', 'role' => 'owner', 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['garage', 'yard'],
				'maxDailyTasks' => 4,
			]],
			['userId' => 'teen', 'displayName' => 'Teen', 'role' => 'child', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['bedroom', 'bathroom'],
				'maxDailyTasks' => 3,
			]],
			['userId' => 'kid', 'displayName' => 'Kid', 'role' => 'child', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['toys', 'pets'],
				'maxDailyTasks' => 2,
			]],
			['userId' => 'grandma', 'displayName' => 'Grandma', 'role' => 'member', 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['kitchen', 'living_room'],
				'maxDailyTasks' => 2,
			]],
			['userId' => 'friend', 'displayName' => 'Friend', 'role' => 'member', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => false],
				'preferredTasks' => ['living_room'],
				'maxDailyTasks' => 1,
			]],
		];

		foreach ($userData as $data) {
			$houseUser = new HouseUser();
			$houseUser->setHouseId($house->getId());
			$houseUser->setNextcloudUserId($data['userId']);
			$houseUser->setDisplayName($data['displayName']);
			$houseUser->setRole($data['role']);
			$houseUser->setSchedulingPreferences($data['preferences']);
			$houseUser->setWalletId($this->seedWallet($house->getId(), $data['userId']));
			$houseUser->setJoinedAt(new \DateTime());
			if (isset($data['currentStreak'])) {
				$houseUser->setCurrentStreak($data['currentStreak']);
			}
			if (isset($data['streakFreezes'])) {
				$houseUser->setStreakFreezes($data['streakFreezes']);
			}
			$houseUser = $this->houseUserMapper->insert($houseUser);
			$users[$data['userId']] = $houseUser;
		}

		// Seed the NC admin account as an invited household member
		// (ADR-010: membership is invite-only after the first owner). The
		// admin session is the DEFAULT E2E session, so without an explicit
		// membership every default-session spec would non-deterministically
		// race the first-owner bootstrap after a reset. Excluded from the
		// returned payload — specs pin the seeded family member count.
		$this->seedAdminMembership($house->getId());

		// Create tasks
		$tasks = [];
		$taskData = [
			['name' => 'Do the dishes', 'description' => 'Clean all dishes and load/unload dishwasher', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'daily', 'estimatedMinutes' => 30, 'category' => 'cleaning'],
			['name' => 'Wipe kitchen counters', 'description' => 'Clean and disinfect all kitchen surfaces', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'daily', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Take out trash', 'description' => 'Empty kitchen trash and take to outside bin', 'energyCost' => 2, 'priority' => 3, 'frequencyType' => 'daily', 'estimatedMinutes' => 10, 'category' => 'maintenance'],
			['name' => 'Vacuum living room', 'description' => 'Vacuum all carpets and floors', 'energyCost' => 4, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 20, 'category' => 'cleaning'],
			['name' => 'Dust furniture', 'description' => 'Dust all surfaces in living room', 'energyCost' => 2, 'priority' => 1, 'frequencyType' => 'weekly', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Tidy up toys', 'description' => 'Put away all toys and games', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'daily', 'estimatedMinutes' => 10, 'category' => 'organizing'],
			['name' => 'Make bed', 'description' => 'Make bed and arrange pillows', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'daily', 'estimatedMinutes' => 5, 'category' => 'organizing'],
			['name' => 'Clean bedroom', 'description' => 'Vacuum, dust, and organize bedroom', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 30, 'category' => 'cleaning'],
			['name' => 'Do laundry', 'description' => 'Wash, dry, and fold clothes', 'energyCost' => 4, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 60, 'category' => 'cleaning'],
			['name' => 'Clean toilet', 'description' => 'Clean and disinfect toilet', 'energyCost' => 3, 'priority' => 3, 'frequencyType' => 'weekly', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Clean bathroom sink', 'description' => 'Clean sink and mirror', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 10, 'category' => 'cleaning'],
			['name' => 'Restock bathroom supplies', 'description' => 'Refill toilet paper, soap, etc.', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'weekly', 'estimatedMinutes' => 5, 'category' => 'maintenance'],
		];

		foreach ($taskData as $data) {
			$task = $this->taskMapper->create(
				$data['name'],
				$data['description'],
				$data['energyCost'],
				$data['priority'],
				$data['frequencyType'],
				1, // frequencyValue
				$data['estimatedMinutes'],
				$data['category']
			);
			$tasks[$data['name']] = $task;
		}

		// Create room tasks with assignments
		$roomTasks = [];
		$seedAssignees = [];
		$today = new \DateTime();
		$tomorrow = (clone $today)->modify('+1 day');
		// Time-travel overdue seed (CS-PLAN-023): a due-date already in the
		// past, so the overdue flow (RoomTask::isOverdue / findOverdueByHouse)
		// is exercisable against the real clock with no multi-day wait.
		$threeDaysAgo = (clone $today)->modify('-3 days');

		$roomTaskData = [
			['room' => 'Kitchen', 'task' => 'Do the dishes', 'due' => $today, 'assignedTo' => 'mom'],
			['room' => 'Kitchen', 'task' => 'Wipe kitchen counters', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Kitchen', 'task' => 'Take out trash', 'due' => $threeDaysAgo, 'assignedTo' => 'dad'],
			['room' => 'Living Room', 'task' => 'Tidy up toys', 'due' => $today, 'assignedTo' => 'kid'],
			['room' => 'Living Room', 'task' => 'Vacuum living room', 'due' => $tomorrow, 'assignedTo' => null],
			['room' => 'Living Room', 'task' => 'Dust furniture', 'due' => $tomorrow, 'assignedTo' => 'grandma'],
			['room' => 'Kids Bedroom', 'task' => 'Make bed', 'due' => $today, 'assignedTo' => 'kid'],
			['room' => 'Master Bedroom', 'task' => 'Make bed', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Master Bedroom', 'task' => 'Clean bedroom', 'due' => $tomorrow, 'assignedTo' => null],
			['room' => 'Master Bedroom', 'task' => 'Do laundry', 'due' => $tomorrow, 'assignedTo' => 'mom'],
			['room' => 'Kids Bathroom', 'task' => 'Clean bathroom sink', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Master Bathroom', 'task' => 'Clean toilet', 'due' => $tomorrow, 'assignedTo' => null],
		];

		foreach ($roomTaskData as $data) {
			$roomTask = $this->roomTaskMapper->create(
				$rooms[$data['room']]->getId(),
				$tasks[$data['task']]->getId(),
				$data['due']
			);
			if ($data['assignedTo'] !== null) {
				$this->taskAssigneeMapper->addAssignee($roomTask->getId(), $data['assignedTo']);
			}
			$roomTasks[] = $roomTask;
			// Canonical assignee key for seed consumers (E2E specs); the
			// plain entity payload carries no assignee information.
			$seedAssignees[$roomTask->getId()] = $data['assignedTo'] !== null
				? [$data['assignedTo']]
				: [];
		}

		return [
			'profile' => 'family',
			'data' => [
				'house' => $house->jsonSerialize(),
				'floors' => array_values(array_map(fn ($f) => $f->jsonSerialize(), $floors)),
				'rooms' => array_values(array_map(fn ($r) => $r->jsonSerialize(), $rooms)),
				'users' => array_values(array_map(fn ($u) => $u->jsonSerialize(), $users)),
				'tasks' => array_values(array_map(fn ($t) => $t->jsonSerialize(), $tasks)),
				'roomTasks' => array_values(array_map(
					fn ($rt) => $rt->jsonSerialize() + ['assignees' => $seedAssignees[$rt->getId()] ?? []],
					$roomTasks
				)),
			],
		];
	}

	/**
	 * Seed by profile name
	 *
	 * @param string $profile Profile name (empty, emptyhouse, minimal, family)
	 * @return array Seeded data
	 */
	public function seed(string $profile): array {
		$this->assertTestMode();

		return match ($profile) {
			'empty' => $this->seedEmpty(),
			'emptyhouse' => $this->seedEmptyhouse(),
			'minimal' => $this->seedMinimal(),
			'family' => $this->seedFamily(),
			default => throw new \InvalidArgumentException("Unknown profile: {$profile}. Valid profiles: empty, emptyhouse, minimal, family"),
		};
	}

	/**
	 * Check if test mode is enabled
	 *
	 * @return bool
	 */
	public function isTestMode(): bool {
		return getenv('NEXTCLOUD_TEST_MODE') === 'true'
			|| ($_ENV['NEXTCLOUD_TEST_MODE'] ?? '') === 'true';
	}
}
