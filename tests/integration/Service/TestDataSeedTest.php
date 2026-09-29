<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Service\TestDataService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Real-DB proofs for the four seed profiles (CS-PLAN-068 §3.5, CR-053-22).
 *
 * Expected profile contents are derived from the CURRENT seed code in
 * TestDataService — not from stale docblock counts: every declared
 * structure number below is asserted against what the service actually
 * persists today, so a seed drift fails here first.
 *
 * The process environment is test-owned for the duration of each test and
 * restored afterwards (the destructive entry points are mode-guarded).
 */
class TestDataSeedTest extends IntegrationTestCase {
	private TestDataService $service;
	private string $previousEnv = '';
	private bool $hadPreviousEnv = false;

	protected function setUp(): void {
		parent::setUp();
		$this->service = \OC::$server->get(TestDataService::class);
		$this->hadPreviousEnv = getenv('NEXTCLOUD_TEST_MODE') !== false;
		if ($this->hadPreviousEnv) {
			$this->previousEnv = (string)getenv('NEXTCLOUD_TEST_MODE');
		}
		putenv('NEXTCLOUD_TEST_MODE=true');
		$_ENV['NEXTCLOUD_TEST_MODE'] = 'true';
	}

	protected function tearDown(): void {
		if ($this->hadPreviousEnv) {
			putenv('NEXTCLOUD_TEST_MODE=' . $this->previousEnv);
			$_ENV['NEXTCLOUD_TEST_MODE'] = $this->previousEnv;
		} else {
			putenv('NEXTCLOUD_TEST_MODE');
			unset($_ENV['NEXTCLOUD_TEST_MODE']);
		}
		parent::tearDown();
	}

	/**
	 * Row counts of every app table a reset may touch.
	 *
	 * @return array<string, int>
	 */
	private function appRowCounts(): array {
		$tables = [
			'chorestrike_task_assignees',
			'chorestrike_chk_progress',
			'chorestrike_completed_tasks',
			'chorestrike_room_tasks',
			'chorestrike_chk_items',
			'chorestrike_tasks',
			'chorestrike_rooms',
			'chorestrike_floors',
			'chorestrike_house_users',
			'chorestrike_houses',
			'chorestrike_medals',
			'chorestrike_minigame_scores',
		];
		$counts = [];
		foreach ($tables as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))->from($table);
			$counts[$table] = (int)$qb->executeQuery()->fetchOne();
		}
		return $counts;
	}

	/**
	 * EmptyProfiles_LeaveZeroAppRows_AndKeepNextcloudAccounts
	 *
	 * @dataProvider emptyProfiles
	 */
	public function testEmptyProfiles_LeaveZeroAppRows_AndKeepNextcloudAccounts(string $profile): void {
		// Arrange real app state + an unrelated app's config value.
		$this->service->seed('family');
		$config = \OC::$server->get(IConfig::class);
		$config->setAppValue('another_app_probe', 'survival_key', 'intact');

		$result = $this->service->seed($profile);

		self::assertSame($profile, $result['profile']);
		foreach ($this->appRowCounts() as $table => $count) {
			self::assertSame(0, $count, "{$profile} must leave {$table} empty");
		}
		// Unrelated app config survives.
		self::assertSame('intact', $config->getAppValue('another_app_probe', 'survival_key', ''));
		$config->deleteAppValue('another_app_probe', 'survival_key');
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function emptyProfiles(): array {
		return [
			'empty' => ['empty'],
			'emptyhouse (wizard journey reset)' => ['emptyhouse'],
		];
	}

	/**
	 * MinimalProfile_PersistsDeclaredStructure_WithInvitedAdmin
	 */
	public function testMinimalProfile_PersistsDeclaredStructure_WithInvitedAdmin(): void {
		$result = $this->service->seed('minimal');

		self::assertSame('minimal', $result['profile']);
		$counts = $this->appRowCounts();
		self::assertSame(1, $counts['chorestrike_houses']);
		self::assertSame(1, $counts['chorestrike_floors']);
		self::assertSame(1, $counts['chorestrike_rooms']);
		self::assertSame(0, $counts['chorestrike_tasks'], 'minimal seeds no task definitions');
		self::assertSame(0, $counts['chorestrike_room_tasks']);

		$users = $result['data']['users'];
		self::assertCount(2, $users);
		$byId = [];
		foreach ($users as $user) {
			$byId[$user['nextcloudUserId']] = $user;
		}
		self::assertSame('owner', $byId['adult']['role']);
		self::assertSame('child', $byId['kid']['role']);

		// The NC admin account is an INVITED member (ADR-010), never an
		// accidental owner — and excluded from the returned payload.
		$houseId = (int)$result['data']['house']['id'];
		$admin = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByHouseAndUser($houseId, 'admin');
		self::assertNotNull($admin, 'the default E2E account must be an invited member');
		self::assertSame('member', $admin->getRole());
		self::assertArrayNotHasKey('admin', $byId);
	}

	/**
	 * FamilyProfile_PersistsDeclaredStructure_RolesAndAssignments
	 */
	public function testFamilyProfile_PersistsDeclaredStructure_RolesAndAssignments(): void {
		$result = $this->service->seed('family');

		self::assertSame('family', $result['profile']);
		$counts = $this->appRowCounts();
		self::assertSame(1, $counts['chorestrike_houses']);
		self::assertSame(3, $counts['chorestrike_floors']);
		self::assertSame(6, $counts['chorestrike_rooms']);
		self::assertSame(12, $counts['chorestrike_tasks']);
		self::assertSame(12, $counts['chorestrike_room_tasks']);

		$users = $result['data']['users'];
		self::assertCount(6, $users);
		$roles = [];
		foreach ($users as $user) {
			$roles[$user['nextcloudUserId']] = $user['role'];
		}
		self::assertSame([
			'mom' => 'owner',
			'dad' => 'owner',
			'teen' => 'child',
			'kid' => 'child',
			'grandma' => 'member',
			'friend' => 'member',
		], $roles);

		// mom's time-travel streak state and the admin invite membership.
		$houseId = (int)$result['data']['house']['id'];
		$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		self::assertSame(5, $mapper->findByHouseAndUser($houseId, 'mom')->getCurrentStreak());
		self::assertSame('member', $mapper->findByHouseAndUser($houseId, 'admin')->getRole());
		self::assertCount(7, $mapper->findByHouseId($houseId), 'six family members plus the invited admin');

		// Assignments persist through the canonical join table.
		$assignees = $this->assigneeUserIds($houseId);
		self::assertContains('mom', $assignees, 'the dishes room task is assigned to mom');
		self::assertNotContains('admin', $assignees, 'the admin invite must not grab an assignment');

		// WP4 pin: no reward-config knob is seeded — the weekly-goal target
		// is fixed policy.
		$config = \OC::$server->get(IConfig::class);
		self::assertSame('', $config->getAppValue('chorestrike', 'weekly_goal_target_percent', ''));
	}

	/**
	 * RepeatedSeedAndReset_HasNoDuplicatesOrOrphans
	 */
	public function testRepeatedSeedAndReset_HasNoDuplicatesOrOrphans(): void {
		$this->service->seed('family');
		$first = $this->appRowCounts();

		// A repeated seed replaces — never duplicates — the previous state.
		$this->service->seed('family');
		self::assertSame($first, $this->appRowCounts(), 'a repeated seed must not duplicate rows');

		// No orphaned assignees: every assignee row points at a live room
		// task after the reseed.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('chorestrike_task_assignees', 'ta')
			->leftJoin('ta', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ta.room_task_id', 'rt.id'))
			->where($qb->expr()->isNull('rt.id'));
		self::assertSame(0, (int)$qb->executeQuery()->fetchOne(), 'no orphaned assignee rows after reseeding');

		$this->service->reset();
		foreach ($this->appRowCounts() as $table => $count) {
			self::assertSame(0, $count, "reset must clear {$table}");
		}
	}

	/**
	 * SeedCycle_NextcloudAccountsAndForeignDataSurvive
	 */
	public function testSeedCycle_NextcloudAccountsAndForeignDataSurvive(): void {
		$userManager = \OC::$server->get(IUserManager::class);
		$config = \OC::$server->get(IConfig::class);
		$probeExisted = $userManager->get('admin') !== null;
		$config->setAppValue('foreign_app', 'cycle_key', 'untouched');

		$this->service->seed('family');
		$this->service->seed('emptyhouse');

		if ($probeExisted) {
			self::assertNotNull($userManager->get('admin'), 'Nextcloud accounts survive the app-data reset');
		}
		self::assertSame('untouched', $config->getAppValue('foreign_app', 'cycle_key', ''), 'other applications\' data survives');

		$config->deleteAppValue('foreign_app', 'cycle_key');
	}

	/**
	 * All assignee user ids of the seeded house.
	 *
	 * @return list<string>
	 */
	private function assigneeUserIds(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('ta.user_id')
			->from('chorestrike_task_assignees', 'ta')
			->innerJoin('ta', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ta.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn (array $row): string => (string)$row['user_id'], $rows);
	}
}
