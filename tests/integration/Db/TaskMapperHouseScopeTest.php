<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * House-scoped task-definition visibility (CS-PLAN-060 WP1 §3.4, D11):
 * `findVisibleByHouse` scopes through the real chain (room_tasks → rooms →
 * floors → houses). Unlinked definitions stay visible (CR-053-01
 * convention); only tasks linked to OTHER houses are rejected.
 */
class TaskMapperHouseScopeTest extends IntegrationTestCase {
	private TaskMapper $taskMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->taskMapper = \OC::$server->get(TaskMapper::class);
	}

	/**
	 * FindVisibleByHouse_ScopesLinkedTasksByHouse_KeepsUnlinkedVisible
	 */
	public function testFindVisibleByHouse_ScopesLinkedTasksByHouse_KeepsUnlinkedVisible(): void {
		$houseAId = $this->createHouse('scope_owner_a', 'Scope House A');
		$houseBId = $this->createHouse('scope_owner_b', 'Scope House B');

		$floorAId = $this->createFloor($houseAId, 'Ground Floor', 1);
		$roomAId = $this->createRoom($houseAId, $floorAId, 'Kitchen A');
		$floorBId = $this->createFloor($houseBId, 'Ground Floor', 1);
		$roomBId = $this->createRoom($houseBId, $floorBId, 'Kitchen B');

		// Task linked to house A only.
		$taskLinkedA = $this->createTask($houseAId, 'Linked A');
		$this->assignTaskToRoom($taskLinkedA, $roomAId);

		// Task linked to house B only.
		$taskLinkedB = $this->createTask($houseBId, 'Linked B');
		$this->assignTaskToRoom($taskLinkedB, $roomBId);

		// Task with no room-task link at all (fresh create-then-link flow).
		$taskUnlinked = $this->createTask($houseAId, 'Unlinked');

		// Private task owned by the viewer but linked to house B.
		$taskPrivateB = $this->createTask($houseBId, 'Private B');
		$this->setTaskOwnerVisibility($taskPrivateB, 'private', 'scope_owner_a');
		$this->assignTaskToRoom($taskPrivateB, $roomBId);

		// Viewer: owner of house A.
		$this->actingAs('scope_owner_a');
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($houseAId, 'scope_owner_a');

		$visibleIds = array_map(
			static fn ($task) => $task->getId(),
			$this->taskMapper->findVisibleByHouse($houseAId, $viewer)
		);

		self::assertContains($taskLinkedA, $visibleIds, 'house A must see its own linked task');
		self::assertContains($taskUnlinked, $visibleIds, 'unlinked definitions stay visible (D11)');
		self::assertNotContains($taskLinkedB, $visibleIds, 'a task linked only to house B must be rejected for house A');
		self::assertNotContains($taskPrivateB, $visibleIds, 'even the viewer\'s own private task is house-scoped when linked elsewhere');

		// Symmetric check from house B's owner perspective.
		$this->actingAs('scope_owner_b');
		$viewerB = $houseUserMapper->findByNextcloudUserId($houseBId, 'scope_owner_b');
		$visibleBIds = array_map(
			static fn ($task) => $task->getId(),
			$this->taskMapper->findVisibleByHouse($houseBId, $viewerB)
		);
		self::assertContains($taskLinkedB, $visibleBIds);
		self::assertContains($taskUnlinked, $visibleBIds, 'unlinked definitions are visible from every house');
		self::assertNotContains($taskLinkedA, $visibleBIds, 'house B must not see house A\'s linked task');
	}

	private function setTaskOwnerVisibility(int $taskId, string $visibilityType, ?string $ownerUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibilityType))
			->set('owner_user_id', $qb->createNamedParameter($ownerUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
