<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for the teamwork bonus reward on multi-assign task completion.
 *
 * Verifies the CS-PLAN-005 contract:
 * - Single-assignee completion awards no teamwork bonus
 * - Multi-assignee completion awards a positive teamwork bonus
 * - The bonus is additive on top of the base coin reward
 * - The bonus is capped at the configured max percentage of the base reward
 * - The bonus is zero when teamwork_bonus_enabled is false
 *
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 2
 */
class TeamworkRewardTest extends IntegrationTestCase
{
    private int $houseId;
    private int $floorId;
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->houseId = $this->createHouse('team_owner', 'Team House');
        $this->addHouseMember($this->houseId, 'team_member_a', 'Member A', 'member');
        $this->addHouseMember($this->houseId, 'team_member_b', 'Member B', 'member');

        $this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
        $this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Living Room');
    }

    // ========== Single Assignee ==========

    public function testSingleAssigneeGetsNoTeamworkBonus(): void
    {
        $taskId = $this->createTask($this->houseId, 'Solo Sweep', 'Sweep alone', 5, 10);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');

        $this->actingAs('team_owner');
        $roomService = \OC::$server->get(RoomService::class);

        $result = $roomService->completeRoomTask($roomTaskId, 'team_owner');

        self::assertSame(0, $result['rewards']['teamworkBonus']);
    }

    // ========== Multi Assignee ==========

    public function testMultiAssigneeCompletionAwardsTeamworkBonus(): void
    {
        $taskId = $this->createTask($this->houseId, 'Team Clean', 'Clean together', 10, 20);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');
        $this->addAssignee($roomTaskId, 'team_member_a');

        $this->actingAs('team_owner');
        $roomService = \OC::$server->get(RoomService::class);

        $result = $roomService->completeRoomTask($roomTaskId, 'team_owner');

        self::assertGreaterThan(0, $result['rewards']['teamworkBonus']);
    }

    public function testTeamworkBonusIsAdditiveToBaseCoins(): void
    {
        $taskId = $this->createTask($this->houseId, 'Team Dishes', 'Dishes together', 10, 20);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');
        $this->addAssignee($roomTaskId, 'team_member_a');

        $this->actingAs('team_owner');
        $roomService = \OC::$server->get(RoomService::class);
        $houseUserMapper = \OC::$server->get(HouseUserMapper::class);

        $before = $houseUserMapper->findByNextcloudUserId($this->houseId, 'team_owner');
        $coinsBefore = $before->getCoins();

        $result = $roomService->completeRoomTask($roomTaskId, 'team_owner');

        $after = $houseUserMapper->findByNextcloudUserId($this->houseId, 'team_owner');
        $totalEarned = $after->getCoins() - $coinsBefore;

        self::assertGreaterThan($result['rewards']['coins'], $totalEarned);
        self::assertSame(
            $result['rewards']['coins'] + $result['rewards']['teamworkBonus'],
            $totalEarned
        );
    }

    public function testThreeAssigneesGetBonus(): void
    {
        $taskId = $this->createTask($this->houseId, 'Group Mop', 'Mop together', 12, 25);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');
        $this->addAssignee($roomTaskId, 'team_member_a');
        $this->addAssignee($roomTaskId, 'team_member_b');

        $this->actingAs('team_owner');
        $roomService = \OC::$server->get(RoomService::class);

        $result = $roomService->completeRoomTask($roomTaskId, 'team_owner');

        self::assertGreaterThan(0, $result['rewards']['teamworkBonus']);
    }

    // ========== Bonus Cap ==========

    public function testTeamworkBonusIsCappedAtMaxPercentage(): void
    {
        $taskId = $this->createTask($this->houseId, 'Big Team Task', 'Large effort', 20, 40);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');
        $this->addAssignee($roomTaskId, 'team_member_a');
        $this->addAssignee($roomTaskId, 'team_member_b');

        $this->actingAs('team_owner');
        $gameLogicService = \OC::$server->get(GameLogicService::class);
        $configService = \OC::$server->get(\OCA\ChoreStrike\Service\RewardConfigService::class);
        $taskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class);
        $task = $taskMapper->find($taskId);

        $assigneeUserIds = ['team_owner', 'team_member_a', 'team_member_b'];

        // Lower the cap so it actually binds. With default maxPct=50 the raw
        // bonus is always below the cap, making the assertion tautological.
        $originalMaxPct = $configService->getTeamworkBonusMaxPct();
        $configService->setTeamworkBonusMaxPct(5);

        try {
            $bonus = $gameLogicService->calculateTeamworkBonus($task, $assigneeUserIds);

            $baseRate = $configService->getCoinBasePerEnergy();
            $baseReward = (int) round($task->getEnergyCost() * $baseRate * $task->getTaskTierMultiplier());
            $cap = (int) floor($baseReward * 5 / 100);

            // Assert equality, not <=, so the test fails if the min() cap is removed.
            self::assertSame($cap, $bonus);
        } finally {
            $configService->setTeamworkBonusMaxPct($originalMaxPct);
        }
    }

    // ========== Disabled Bonus ==========

    public function testTeamworkBonusIsZeroWhenDisabled(): void
    {
        $taskId = $this->createTask($this->houseId, 'Disabled Bonus Task', 'No bonus', 10, 20);
        $roomTaskId = $this->assignTaskToRoom($taskId, $this->roomId, 'team_owner');
        $this->addAssignee($roomTaskId, 'team_member_a');

        $this->actingAs('team_owner');
        $gameLogicService = \OC::$server->get(GameLogicService::class);
        $configService = \OC::$server->get(\OCA\ChoreStrike\Service\RewardConfigService::class);
        $taskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class);
        $task = $taskMapper->find($taskId);

        $originalEnabled = $configService->getTeamworkBonusEnabled();
        $configService->setTeamworkBonusEnabled(false);

        try {
            $bonus = $gameLogicService->calculateTeamworkBonus($task, ['team_owner', 'team_member_a']);
            self::assertSame(0, $bonus);
        } finally {
            $configService->setTeamworkBonusEnabled($originalEnabled);
        }
    }

    // ========== Helpers ==========

    private function addAssignee(int $roomTaskId, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('chorestrike_task_assignees')
            ->values([
                'room_task_id' => $qb->createNamedParameter($roomTaskId),
                'user_id' => $qb->createNamedParameter($userId),
                'assigned_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
            ]);
        $qb->executeStatement();
    }
}
