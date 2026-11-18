<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * BUG-UX-013: the daily limit never hides what a child owes. Runs the real
 * creation and assignment paths: neither refuses a task for a child on a
 * full day (the capacity refusals went with ADR-021), so the limit only
 * ever trims what nobody owns.
 */
class ChildDailyLimitTest extends IntegrationTestCase {
	private const OWNER = 'cdl_owner';
	private const KID = 'cdl_kid';

	private TaskCreationService $creation;
	private RoomTaskReadService $readService;
	private HouseUserMapper $houseUserMapper;
	private int $houseId;
	private int $roomId;
	private HouseUser $actor;

	protected function setUp(): void {
		parent::setUp();
		$this->creation = \OC::$server->get(TaskCreationService::class);
		$this->readService = \OC::$server->get(RoomTaskReadService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);

		$this->houseId = $this->createHouse(self::OWNER, 'Limit House');
		$this->addHouseMember($this->houseId, self::KID, 'Kid', 'child');
		$this->roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId, 'Ground', 1), 'Kitchen');
		$this->actor = $this->houseUserMapper->findByHouseAndUser($this->houseId, self::OWNER);
	}

	public function testTaskCreatedAtTheLimit_StaysOnTheChildsMyDay(): void {
		$this->setChildLimit(2);
		$this->createTaskDueToday('First', [self::KID]);
		$this->createTaskDueToday('Second', [self::KID]);
		$this->createTaskDueToday('Created at the limit', [self::KID]);

		self::assertSame(
			['First', 'Second', 'Created at the limit'],
			$this->myDayNames(),
			'a task the child owes is never hidden by the limit'
		);
	}

	public function testUnassignedTask_DoesNotTakeASlotFromAnOwnTask(): void {
		$this->setChildLimit(1);
		$this->createTaskDueToday('Unassigned, sorts first', []);
		$this->createTaskDueToday('Own', [self::KID]);

		self::assertSame(['Own'], $this->myDayNames());
	}

	public function testUnassignedTask_FillsAFreeSlot(): void {
		$this->setChildLimit(2);
		$this->createTaskDueToday('Own', [self::KID]);
		$this->createTaskDueToday('Unassigned', []);
		$this->createTaskDueToday('Over the limit', []);

		self::assertSame(['Own', 'Unassigned'], $this->myDayNames());
	}

	public function testLaterAssignmentOnAFullDay_IsAcceptedAndShown(): void {
		$this->setChildLimit(1);
		$this->createTaskDueToday('Own', [self::KID]);
		$unassigned = $this->createTaskDueToday('Assigned later', []);

		\OC::$server->get(RoomTaskService::class)->setAssignees($unassigned, [self::KID], $this->houseId, $this->actor);

		self::assertSame(['Own', 'Assigned later'], $this->myDayNames(), 'an assignment on a full day is accepted and never hidden');
	}

	/**
	 * @param string[] $assignees
	 */
	private function createTaskDueToday(string $name, array $assignees): int {
		$result = $this->creation->create($this->houseId, $this->actor, [
			'roomId' => $this->roomId,
			'task' => ['name' => $name, 'frequencyType' => 'weekly', 'frequencyValue' => 1],
			'assigneeUserIds' => $assignees,
			'firstDate' => (new \DateTime())->format('Y-m-d'),
		]);
		$this->registerCreatedTask((int)$result['task']->getId());
		return (int)$result['roomTask']->getId();
	}

	private function setChildLimit(int $limit): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode(['maxDailyTasks' => $limit])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter(self::KID)))
			->executeStatement();
	}

	/**
	 * @return string[]
	 */
	private function myDayNames(): array {
		$kid = $this->houseUserMapper->findByHouseAndUser($this->houseId, self::KID);
		return array_map(
			static fn (array $row): string => $row['task']->getName(),
			$this->readService->getFilteredDailyTasksForUser($this->houseId, $kid, new \DateTime())
		);
	}
}
