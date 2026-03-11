<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\SchedulingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingService
 */
class SchedulingServiceTest extends TestCase
{
    private SchedulingService $service;
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->service = new SchedulingService($this->houseUserMapper, $this->completedTaskMapper);
    }

    /**
     * GetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences
     */
    public function testGetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setId(7);
        $houseUser->setHouseId(2);
        $houseUser->setNextcloudUserId('member-a');
        $houseUser->setSchedulingPreferences([
            'energyBudget' => ['monday' => 12],
            'weekdayAvailability' => ['monday' => false],
        ]);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with(2, 'member-a')
            ->willReturn($houseUser);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('find')
            ->with(7)
            ->willReturn($houseUser);

        $this->completedTaskMapper
            ->expects($this->once())
            ->method('getConsumedEnergyForUserOnDate')
            ->with($this->equalTo(7), $this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(6);

        $preferences = $this->service->getUserPreferencesByNextcloudUserId(2, 'member-a');

        $this->assertSame(7, $preferences['userId']);
        $this->assertSame(12, $preferences['energyBudget']['monday']);
        $this->assertFalse($preferences['weekdayAvailability']['monday']);
        $this->assertSame(6, $preferences['todayConsumedEnergy']);
    }

    /**
     * UpdateAvailabilityByNextcloudUserId_ValidUser_UpdatesResolvedHouseUser
     */
    public function testUpdateAvailabilityByNextcloudUserId_ValidUser_UpdatesResolvedHouseUser(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setId(11);
        $houseUser->setHouseId(3);
        $houseUser->setNextcloudUserId('member-b');
        $houseUser->setSchedulingPreferences([]);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with(3, 'member-b')
            ->willReturn($houseUser);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('find')
            ->with(11)
            ->willReturn($houseUser);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (HouseUser $updatedUser): bool {
                $preferences = $updatedUser->getSchedulingPreferences();
                return isset($preferences['weekdayAvailability']['friday'])
                    && $preferences['weekdayAvailability']['friday'] === true;
            }));

        $this->service->updateAvailabilityByNextcloudUserId(3, 'member-b', 'friday', true);
    }

    /**
     * UpdateEnergyBudgetByNextcloudUserId_ValidUser_UpdatesResolvedHouseUser
     */
    public function testUpdateEnergyBudgetByNextcloudUserId_ValidUser_UpdatesResolvedHouseUser(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setId(12);
        $houseUser->setHouseId(3);
        $houseUser->setNextcloudUserId('member-c');
        $houseUser->setSchedulingPreferences([]);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByNextcloudUserId')
            ->with(3, 'member-c')
            ->willReturn($houseUser);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('find')
            ->with(12)
            ->willReturn($houseUser);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (HouseUser $updatedUser): bool {
                $preferences = $updatedUser->getSchedulingPreferences();
                return isset($preferences['energyBudget']['wednesday'])
                    && $preferences['energyBudget']['wednesday'] === 15;
            }));

        $this->service->updateEnergyBudgetByNextcloudUserId(3, 'member-c', 'wednesday', 15);
    }

    /**
     * GetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId
     */
    public function testGetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setId(42);
        $houseUser->setHouseId(5);
        $houseUser->setNextcloudUserId('member-z');
        $houseUser->setDisplayName('Member Z');
        $houseUser->setSchedulingPreferences([
            'energyBudget' => ['monday' => 14],
            'weekdayAvailability' => ['monday' => true],
        ]);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('findByHouseId')
            ->with(5)
            ->willReturn([$houseUser]);

        $availability = $this->service->getMemberAvailability(5);

        $this->assertCount(1, $availability);
        $this->assertSame('member-z', $availability[0]['userId']);
        $this->assertSame(42, $availability[0]['houseUserId']);
        $this->assertSame('Member Z', $availability[0]['userName']);
    }

    /**
     * UpdateMemberAvailability_TargetUserOutsideHouse_ThrowsInvalidArgumentException
     */
    public function testUpdateMemberAvailability_TargetUserOutsideHouse_ThrowsInvalidArgumentException(): void
    {
        $targetUser = new HouseUser();
        $targetUser->setId(22);
        $targetUser->setHouseId(99);

        $this->houseUserMapper
            ->expects($this->once())
            ->method('find')
            ->with(22)
            ->willReturn($targetUser);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target user does not belong to this house');

        $this->service->updateMemberAvailability(1, 22, ['weekdayAvailability' => ['monday' => true]]);
    }
}
