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
use OCA\ChoreStrike\Db\HouseUser;

/**
 * @template-extends QBMapper<Task>
 */
class TaskMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_tasks', Task::class);
    }

    /**
     * Find task by ID
     *
     * @param int $id The task ID
     * @throws DoesNotExistException if not found
     */
    public function find(int $id): Task
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Get all tasks
     *
     * @return Task[]
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('task_tier', 'DESC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all task templates
     *
     * @return Task[]
     */
    public function findTemplates(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_template', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('category', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get tasks by category
     *
     * @param string $category The task category
     * @return Task[]
     */
    public function findByCategory(string $category): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category', $qb->createNamedParameter($category)))
            ->orderBy('task_tier', 'DESC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Search tasks by name
     *
     * @param string $query The search query
     * @return Task[]
     */
    public function searchByName(string $query): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->iLike('name', $qb->createNamedParameter('%' . $query . '%')))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
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
     * @param string $recurrenceMode Recurrence mode (recurring, once, bounded)
     * @param \DateTime|null $activeFrom Optional start date
     * @param \DateTime|null $activeUntil Optional end date
     * @param bool $isVoluntary Whether task is voluntary
     * @param bool $isTemplate Whether this is a template
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
        bool $isTemplate = false,
        string $schedulingMode = 'dynamic',
        ?\DateTime $fixedScheduleTime = null,
        ?string $fixedScheduleDays = null,
        ?string $instructions = null,
        ?string $tips = null,
        ?int $estimatedMinutesMin = null,
        ?int $estimatedMinutesMax = null,
        bool $isPrivate = false,
        string $visibilityType = 'public',
        ?string $ownerUserId = null
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
        $task->setIsTemplate($isTemplate);
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
     * Create task from template
     *
     * @param int $templateId The template ID
     * @return Task The new task (not a template)
     */
    public function createFromTemplate(int $templateId): Task
    {
        $template = $this->find($templateId);

        return $this->create(
            $template->getName(),
            $template->getDescription(),
            $template->getEnergyCost(),
            $template->getPriority(),
            $template->getFrequencyType(),
            $template->getFrequencyValue(),
            $template->getEstimatedMinutes(),
            $template->getCategory(),
            $template->getScopeType(),
            $template->getScopeLabel(),
            $template->getRecurrenceMode(),
            $template->getActiveFrom(),
            $template->getActiveUntil(),
            $template->getIsVoluntary(),
            false, // Not a template
            $template->getSchedulingMode(),
            $template->getFixedScheduleTime(),
            $template->getFixedScheduleDays(),
            $template->getInstructions(),
            $template->getTips(),
            $template->getEstimatedMinutesMin(),
            $template->getEstimatedMinutesMax(),
            $template->isPrivate(),
            $template->getVisibilityType(),
            $template->getOwnerUserId()
        );
    }

    /**
     * Get all categories
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('category')
            ->from($this->getTableName())
            ->orderBy('category', 'ASC');

        $result = $qb->executeQuery();
        $categories = [];

        while ($row = $result->fetch()) {
            $categories[] = $row['category'];
        }

        return $categories;
    }

    /**
     * Find daily tasks for a house
     *
     * @param int $houseId The house ID
     * @param DateTime $date The date to find tasks for
     * @return Task[]
     */
    public function findDailyTasks(int $houseId, \DateTime $date): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('scheduled_date', $qb->createNamedParameter($date->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'completed', 'skipped'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('task_tier', 'DESC')
            ->addOrderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find tasks visible to a viewer based on the visibility matrix.
     *
     * Visibility rules:
     *   - public: visible to all roles
     *   - private: visible only to the owner (ownerUserId matches viewer)
     *   - admin-only: visible only to house owners
     *
     * @param int $houseId The house ID (reserved for future house-scoped tasks)
     * @param HouseUser $viewer The viewing house user
     * @return Task[]
     */
    public function findVisibleByHouse(int $houseId, HouseUser $viewer): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        if ($viewer->isOwner()) {
            // Owners see public + admin-only + their own private
            // TODO: flip to see ALL private tasks if admin oversight is desired (CS-PLAN-005 §7)
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PUBLIC)),
                    $qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_ADMIN_ONLY)),
                    $qb->expr()->andX(
                        $qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE)),
                        $qb->expr()->eq('owner_user_id', $qb->createNamedParameter($viewer->getNextcloudUserId()))
                    )
                )
            );
        } else {
            // Members and children see public + their own private only
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PUBLIC)),
                    $qb->expr()->andX(
                        $qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE)),
                        $qb->expr()->eq('owner_user_id', $qb->createNamedParameter($viewer->getNextcloudUserId()))
                    )
                )
            );
        }

        $qb->orderBy('task_tier', 'DESC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find private tasks owned by a specific user.
     *
     * @param string $ownerUserId The Nextcloud user ID
     * @return Task[]
     */
    public function findPrivateByOwner(string $ownerUserId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE)))
            ->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }
}
