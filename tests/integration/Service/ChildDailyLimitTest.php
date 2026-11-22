<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;

/**
 * BUG-UX-013: the daily limit never hides what a child owes. Runs the real
 * creation and assignment paths: neither refuses a task for a child on a
 * full day (the capacity refusals went with ADR-021). The limit is a second
 * budget of the live suggestion (ADR-022 decision 2 rule 5): it caps what
 * *Today* suggests to the child, and unassigned work beyond it goes to
 * someone else.
 */
class ChildDailyLimitTest extends IntegrationTestCase {
	use TaskEditing;

	private const OWNER = 'cdl_owner';
	private const KID = 'cdl_kid';

	private TaskCreationService $creation;
	private HouseUserMapper $houseUserMapper;
	private int $houseId;
	private int $roomId;
	private HouseUser $actor;

	protected function setUp(): void {
		parent::setUp();
		$this->creation = \OC::$server->get(TaskCreationService::class);
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
		self::assertSame(['First', 'Second'], $this->myDayNames(true), 'today suggests no more than the limit');
	}

	public function testUnassignedTask_DoesNotTakeASlotFromAnOwnTask(): void {
		$this->setChildLimit(1);
		$this->createTaskDueToday('Unassigned, sorts first', []);
		$this->createTaskDueToday('Own', [self::KID]);

		// The unassigned task goes to the owner; the child's one slot is its own task's.
		self::assertSame(['Own'], $this->myDayNames());
	}

	public function testUnassignedTask_FillsAFreeSlot(): void {
		// The child has the most energy, so unassigned work is suggested to it while a slot is free.
		$this->setChildLimit(2, 12);
		$this->createTaskDueToday('Own', [self::KID]);
		$this->createTaskDueToday('Unassigned', []);
		$this->createTaskDueToday('Over the limit', []);

		self::assertSame(['Own', 'Unassigned'], $this->myDayNames());
	}

	public function testLaterAssignmentOnAFullDay_IsAcceptedAndShown(): void {
		$this->setChildLimit(1);
		$this->createTaskDueToday('Own', [self::KID]);
		$unassigned = $this->createTaskDueToday('Assigned later', []);

		$this->assignTo($this->houseId, $this->actor, $unassigned, [self::KID]);

		self::assertSame(['Own', 'Assigned later'], $this->myDayNames(), 'an assignment on a full day is accepted and never hidden');
	}

	/**
	 * @param string[] $assignees
	 */
	private function createTaskDueToday(string $name, array $assignees): int {
		// Without flex days the window is today alone, so the suggestion cannot move the task to another day.
		$result = $this->creation->create($this->houseId, $this->actor, [
			'roomId' => $this->roomId,
			'task' => ['name' => $name, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'energyCost' => 1, 'flexDays' => 0],
			'assigneeUserIds' => $assignees,
			'firstDate' => (new \DateTime())->format('Y-m-d'),
		]);
		$this->registerCreatedTask((int)$result['task']->getId());
		return (int)$result['roomTask']->getId();
	}

	/** The child's limit, with the same energy on every day for the child and the owner. */
	private function setChildLimit(int $limit, int $childEnergy = 6): void {
		$week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		foreach ([self::KID => ['maxDailyTasks' => $limit, 'energyBudget' => array_fill_keys($week, $childEnergy)], self::OWNER => ['energyBudget' => array_fill_keys($week, 6)]] as $userId => $preferences) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_house_users')
				->set('scheduling_preferences', $qb->createNamedParameter(json_encode($preferences)))
				->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}
	}

	/**
	 * The names on the child's *Today*, in the order of the read.
	 *
	 * @param bool $suggestedOnly Leave out what is only also possible
	 * @return string[]
	 */
	private function myDayNames(bool $suggestedOnly = false): array {
		$kid = $this->houseUserMapper->findByHouseAndUser($this->houseId, self::KID);
		$rows = $this->todayRowsOf($this->houseId, $kid);
		if ($suggestedOnly) {
			$rows = array_values(array_filter($rows, static fn (array $row): bool => isset($row['suggestedFor'])));
		}
		return array_map(static fn (array $row): string => $row['task']->getName(), $rows);
	}
}
