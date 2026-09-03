<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_tasks', Task::class);
	}

	/**
	 * Find task by ID
	 *
	 * @param int $id The task ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): Task {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find multiple tasks by IDs in one query (CS-PLAN-036).
	 *
	 * IN-clause batch lookup returning a map id → Task. Missing ids are
	 * tolerated and simply absent from the map — callers skip rows whose
	 * key is missing (skip-on-missing semantics, e.g. a room task whose
	 * task definition vanished).
	 *
	 * @param int[] $ids The task IDs
	 * @return array<int, Task> Map of task ID → Task entity
	 */
	public function findByIds(array $ids): array {
		if (empty($ids)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$map = [];
		foreach ($this->findEntities($qb) as $task) {
			$map[$task->getId()] = $task;
		}

		return $map;
	}

	/**
	 * Create a new task
	 *
	 * @param string $name The task name
	 * @param string|null $description The task description
	 * @param int $energyCost The energy cost (1-5)
	 * @param int $priority The priority (1-3)
	 * @param string $frequencyType The frequency type
	 * @param int $frequencyValue The frequency value
	 * @param int $estimatedMinutes Estimated time in minutes
	 * @param string $category The task category
	 * @param string $scopeType The scope type (room, floor, house)
	 * @param string|null $scopeLabel Optional scope label
	 * @param string $recurrenceMode Recurrence mode (Task::RECURRENCE_*)
	 * @param \DateTime|null $activeFrom Optional start date
	 * @param \DateTime|null $activeUntil Optional end date
	 * @param bool $isVoluntary Whether task is voluntary
	 * @param string $schedulingMode Scheduling mode (dynamic, fixed)
	 * @param \DateTime|null $fixedScheduleTime Optional fixed schedule time
	 * @param string|null $fixedScheduleDays Optional fixed schedule days
	 */
	public function create(
		string $name,
		?string $description,
		int $energyCost,
		int $priority,
		string $frequencyType,
		int $frequencyValue,
		int $estimatedMinutes,
		string $category,
		string $scopeType = 'room',
		?string $scopeLabel = null,
		string $recurrenceMode = 'recurring',
		?\DateTime $activeFrom = null,
		?\DateTime $activeUntil = null,
		bool $isVoluntary = false,
		string $schedulingMode = 'dynamic',
		?\DateTime $fixedScheduleTime = null,
		?string $fixedScheduleDays = null,
		?string $instructions = null,
		?string $tips = null,
		?int $estimatedMinutesMin = null,
		?int $estimatedMinutesMax = null,
		string $visibilityType = 'public',
		?string $ownerUserId = null,
	): Task {
		$task = new Task();
		$task->setName($name);
		$task->setDescription($description);
		$task->setEnergyCost($energyCost);
		$task->setPriority($priority);
		$task->setFrequencyType($frequencyType);
		$task->setFrequencyValue($frequencyValue);
		$task->setEstimatedMinutes($estimatedMinutes);
		$task->setCategory($category);
		$task->setScopeType($scopeType);
		$task->setScopeLabel($scopeLabel);
		$task->setRecurrenceMode($recurrenceMode);
		$task->setActiveFrom($activeFrom);
		$task->setActiveUntil($activeUntil);
		$task->setIsVoluntary($isVoluntary);
		$task->setSchedulingMode($schedulingMode);
		$task->setFixedScheduleTime($fixedScheduleTime);
		$task->setFixedScheduleDays($fixedScheduleDays);
		$task->setInstructions($instructions);
		$task->setTips($tips);
		$task->setEstimatedMinutesMin($estimatedMinutesMin);
		$task->setEstimatedMinutesMax($estimatedMinutesMax);
		$task->setVisibilityType($visibilityType);
		$task->setOwnerUserId($ownerUserId);
		$task->setCreatedAt(new \DateTime());

		return $this->insert($task);
	}

	/**
	 * Find tasks visible to a viewer based on the visibility matrix.
	 *
	 * Visibility rules:
	 *   - public: visible to all roles
	 *   - private: visible only to the owner (ownerUserId matches viewer)
	 *   - admin-only: visible only to house owners
	 *
	 * House scoping (CS-PLAN-060 §3.4): the $houseId parameter is
	 * authoritative. A task definition counts as belonging to the house when
	 * at least one room task of that house links it (EXISTS over
	 * room_tasks → rooms → floors → houses). Unlinked definitions stay
	 * visible — the CR-053-01 convention (unlinked = linked to the
	 * instance's house) keeps the create-then-link flow working; only
	 * linked tasks of OTHER houses are rejected (D11).
	 *
	 * @param int $houseId The house ID
	 * @param HouseUser $viewer The viewing house user
	 * @return Task[]
	 */
	public function findVisibleByHouse(int $houseId, HouseUser $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$houseIdParam = $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT);

		// Correlated EXISTS: the task is linked to the viewer's house through
		// room_tasks → rooms → floors. Built with the query-builder subquery
		// pattern (the house parameter is bound on the OUTER builder and its
		// placeholder embedded into the subquery SQL — the parameter is bound
		// exactly once where execution happens). No raw SQL.
		$linkedToViewerHouse = $this->db->getQueryBuilder();
		$linkedToViewerHouse->select($linkedToViewerHouse->expr()->literal(1))
			->from('chorestrike_room_tasks', 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', $linkedToViewerHouse->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $linkedToViewerHouse->expr()->eq('r.floor_id', 'f.id'))
			->where(
				$linkedToViewerHouse->expr()->andX(
					$linkedToViewerHouse->expr()->eq('rt.task_id', 't.id'),
					$linkedToViewerHouse->expr()->eq('f.house_id', $houseIdParam)
				)
			);

		$anyLink = $this->db->getQueryBuilder();
		$anyLink->select($anyLink->expr()->literal(1))
			->from('chorestrike_room_tasks', 'rt2')
			->where($anyLink->expr()->eq('rt2.task_id', 't.id'));

		$visibilityRules = [
			// Public definitions are visible everywhere.
			$qb->expr()->eq('t.visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PUBLIC)),
			// Private definitions only for their owner (any role).
			$qb->expr()->andX(
				$qb->expr()->eq('t.visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE)),
				$qb->expr()->eq('t.owner_user_id', $qb->createNamedParameter($viewer->getNextcloudUserId()))
			),
		];

		if ($viewer->isOwner()) {
			// Owners additionally see admin-only definitions
			// TODO: flip to see ALL private tasks if admin oversight is desired (CS-PLAN-005 §7)
			$visibilityRules[] = $qb->expr()->eq('t.visibility_type', $qb->createNamedParameter(Task::VISIBILITY_ADMIN_ONLY));
		}

		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->where(
				$qb->expr()->andX(
					$qb->expr()->orX(...$visibilityRules),
					// House scope: linked to the viewer's house, or completely
					// unlinked (D11 — unlinked = linked to the instance's
					// house, keeps the create-then-link flow visible).
					$qb->expr()->orX(
						$qb->expr()->eq($qb->createFunction('EXISTS (' . $linkedToViewerHouse->getSQL() . ')'), $qb->createFunction('1')),
						$qb->expr()->eq($qb->createFunction('EXISTS (' . $anyLink->getSQL() . ')'), $qb->createFunction('0'))
					)
				)
			);

		$qb->orderBy('t.task_tier', 'DESC')
			->addOrderBy('t.name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find private tasks owned by a specific user.
	 *
	 * @param string $ownerUserId The Nextcloud user ID
	 * @return Task[]
	 */
	public function findPrivateByOwner(string $ownerUserId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}
}
