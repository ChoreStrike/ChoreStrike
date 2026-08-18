<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration tests for transactional house-member removal (CR-SVC-26).
 *
 * Removing a member must clean up every task reference scoped to that
 * house — the legacy `room_tasks.assigned_to_user_id` column AND the
 * `task_assignees` join rows — together with the `house_users` row, in
 * one transaction. Another house's assignments for the same Nextcloud
 * user must remain untouched.
 */
class HouseMemberRemovalIntegrationTest extends IntegrationTestCase {
	private int $houseAId = 0;
	private int $houseBId = 0;

	protected function tearDown(): void {
		// The base cleanup removes houses/floors/rooms/tasks/house_users by
		// tracked ids, but not task_assignees rows created here. Clear them
		// first (FK-free schema) so the shared Docker DB stays clean.
		foreach ([$this->houseAId, $this->houseBId] as $houseId) {
			if ($houseId === 0) {
				continue;
			}

			// Same subquery pattern as TaskAssigneeMapper: the house
			// parameter is bound on the OUTER builder (createNamedParameter
			// returns the placeholder string embedded into the subquery
			// SQL), never as a positional ? on the never-executed subquery
			// builder.
			$qb = $this->db->getQueryBuilder();
			$houseIdParam = $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT);

			$roomTasks = $this->db->getQueryBuilder();
			$roomTasks->select('rt.id')
				->from('chorestrike_room_tasks', 'rt')
				->innerJoin('rt', 'chorestrike_rooms', 'r', $roomTasks->expr()->eq('rt.room_id', 'r.id'))
				->innerJoin('r', 'chorestrike_floors', 'f', $roomTasks->expr()->eq('r.floor_id', 'f.id'))
				->where($roomTasks->expr()->eq('f.house_id', $houseIdParam));

			$qb->delete('chorestrike_task_assignees')
				->where($qb->expr()->in('room_task_id', $qb->createFunction($roomTasks->getSQL())));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * RemoveHouseMember_CleansUpHouseScopedReferences_AndDeletesMembership
	 */
	public function testRemoveHouseMember_CleansUpHouseScopedReferences_AndDeletesMembership(): void {
		$fixture = $this->seedFixture();

		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);
		$houseService->removeHouseMember($fixture['memberHouseUserIdA']);

		// Membership row gone.
		self::assertSame(0, $this->countHouseUsers($fixture['houseAId'], 'rmr_member'), 'the house_users row must be deleted');

		// House A: legacy assignee column nulled, join rows deleted.
		self::assertNull($this->fetchAssignedUser($fixture['roomTaskAId']), 'assigned_to_user_id must be NULL in house A');
		self::assertSame(0, $this->countAssignees($fixture['roomTaskAId'], 'rmr_member'), 'task_assignees rows must be deleted in house A');

		// House B (control): the same Nextcloud user's references survive.
		self::assertSame('rmr_member', $this->fetchAssignedUser($fixture['roomTaskBId']), 'another house\'s assignment must be untouched');
		self::assertSame(1, $this->countAssignees($fixture['roomTaskBId'], 'rmr_member'), 'another house\'s assignee rows must be untouched');
		self::assertSame(1, $this->countHouseUsers($fixture['houseBId'], 'rmr_member'), 'another house\'s membership must be untouched');
	}

	/**
	 * RemoveHouseMember_OwnerTarget_ThrowsForbidden
	 */
	public function testRemoveHouseMember_OwnerTarget_ThrowsForbidden(): void {
		$fixture = $this->seedFixture();

		$this->actingAs('rmr_owner_a');
		$houseService = \OC::$server->get(HouseService::class);

		try {
			$houseService->removeHouseMember($fixture['ownerHouseUserIdA']);
			self::fail('removing the house owner must be rejected');
		} catch (ForbiddenException $e) {
			self::assertStringContainsString('owner', $e->getMessage());
		}

		// Nothing was removed: owner membership and all references survive.
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_owner_a'));
		self::assertSame(1, $this->countHouseUsers($fixture['houseAId'], 'rmr_member'));
		self::assertSame('rmr_member', $this->fetchAssignedUser($fixture['roomTaskAId']));
		self::assertSame(1, $this->countAssignees($fixture['roomTaskAId'], 'rmr_member'));
	}

	/**
	 * Seed two houses, both with a room task assigned (legacy column +
	 * join table) to the same Nextcloud user 'rmr_member'.
	 *
	 * @return array{houseAId: int, houseBId: int, memberHouseUserIdA: int, ownerHouseUserIdA: int, roomTaskAId: int, roomTaskBId: int}
	 */
	private function seedFixture(): array {
		// House A: owner + the member under removal.
		$houseAId = $this->createHouse('rmr_owner_a', 'Removal House A');
		$this->addHouseMember($houseAId, 'rmr_member', 'Member A', 'member');
		$floorAId = $this->createFloor($houseAId, 'Ground Floor', 1);
		$roomIdA = $this->createRoom($houseAId, $floorAId, 'Kitchen');
		$taskIdA = $this->createTask($houseAId, 'Dishes', 'Do the dishes', 5, 3);
		$roomTaskAId = $this->assignTaskToRoom($taskIdA, $roomIdA, 'rmr_member');
		$this->addAssignee($roomTaskAId, 'rmr_member');

		// House B (control): same Nextcloud user, own membership + assignment.
		$houseBId = $this->createHouse('rmr_owner_b', 'Removal House B');
		$this->addHouseMember($houseBId, 'rmr_member', 'Member B', 'member');
		$floorBId = $this->createFloor($houseBId, 'Ground Floor', 1);
		$roomIdB = $this->createRoom($houseBId, $floorBId, 'Bathroom');
		$taskIdB = $this->createTask($houseBId, 'Tiles', 'Scrub the tiles', 5, 3);
		$roomTaskBId = $this->assignTaskToRoom($taskIdB, $roomIdB, 'rmr_member');
		$this->addAssignee($roomTaskBId, 'rmr_member');

		$this->houseAId = $houseAId;
		$this->houseBId = $houseBId;

		return [
			'houseAId' => $houseAId,
			'houseBId' => $houseBId,
			'memberHouseUserIdA' => $this->findHouseUserId($houseAId, 'rmr_member'),
			'ownerHouseUserIdA' => $this->findHouseUserId($houseAId, 'rmr_owner_a'),
			'roomTaskAId' => $roomTaskAId,
			'roomTaskBId' => $roomTaskBId,
		];
	}

	private function addAssignee(int $roomTaskId, string $ncUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_task_assignees')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($ncUserId),
				'assigned_by' => $qb->createNamedParameter(null),
				'assigned_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
	}

	private function findHouseUserId(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertGreaterThan(0, $id, "house user row for {$ncUserId} must exist");
		return $id;
	}

	private function countHouseUsers(int $houseId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	private function fetchAssignedUser(int $roomTaskId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('assigned_to_user_id')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false || $value === null ? null : (string)$value;
	}

	private function countAssignees(int $roomTaskId, string $ncUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($ncUserId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
