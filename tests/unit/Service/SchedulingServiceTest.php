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
class SchedulingServiceTest extends TestCase {
	private SchedulingService $service;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->service = new SchedulingService($this->houseUserMapper, $this->completedTaskMapper);
	}

	/**
	 * GetUserPreferences_ReturnsRealConsumedEnergy
	 *
	 * CR-SVC-28 regression: the variant without a Nextcloud user ID reports
	 * the real consumed energy, not a hardcoded 0.
	 */
	public function testGetUserPreferences_ReturnsRealConsumedEnergy(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(7);
		$houseUser->setSchedulingPreferences([]);

		$this->houseUserMapper
			->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($houseUser);

		$this->completedTaskMapper
			->expects($this->once())
			->method('getConsumedEnergyForUserOnDate')
			->with($this->equalTo(7), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn(9);

		$preferences = $this->service->getUserPreferences(7);

		$this->assertSame(9, $preferences['todayConsumedEnergy']);
	}

	/**
	 * GetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences
	 */
	public function testGetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences(): void {
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
	 * UpdatePreferencesByNextcloudUserId_ProvidedSections_ReplacedWholesale
	 *
	 * Section-granular merge: provided sections replace the stored ones,
	 * absent sections and sibling keys (maxDailyTasks) are preserved.
	 */
	public function testUpdatePreferencesByNextcloudUserId_ProvidedSections_ReplacedAndSiblingsPreserved(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(13);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-d');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10],
			'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
			'maxDailyTasks' => 5,
		]);

		// Scoped lookup by houseId + nextcloud uid enforces membership.
		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(3, 'member-d')
			->willReturn($houseUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($houseUser);

		// The response is built from the in-memory entity — no second find.
		$this->houseUserMapper
			->expects($this->never())
			->method('find');

		$energyBudget = ['monday' => 20, 'tuesday' => 20, 'wednesday' => 20, 'thursday' => 20, 'friday' => 20, 'saturday' => 20, 'sunday' => 20];
		$result = $this->service->updatePreferencesByNextcloudUserId(3, 'member-d', null, $energyBudget);

		$preferences = $houseUser->getSchedulingPreferences();
		$this->assertSame($energyBudget, $preferences['energyBudget']);
		// Absent section unchanged.
		$this->assertTrue($preferences['weekdayAvailability']['monday']);
		// Sibling key preserved.
		$this->assertSame(5, $preferences['maxDailyTasks']);
		// The returned array is the full persisted preferences shape.
		$this->assertSame(13, $result['userId']);
		$this->assertSame($energyBudget, $result['energyBudget']);
	}

	/**
	 * UpdatePreferencesByNextcloudUserId_AvailabilityOnly_ReplacesWeekdaySection
	 */
	public function testUpdatePreferencesByNextcloudUserId_AvailabilityOnly_ReplacesWeekdaySection(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(14);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-e');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 7],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(3, 'member-e')
			->willReturn($houseUser);

		// The response is built from the in-memory entity — no second find.
		$this->houseUserMapper
			->expects($this->never())
			->method('find');

		$weekdayAvailability = ['monday' => false, 'tuesday' => false, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true];
		$this->service->updatePreferencesByNextcloudUserId(3, 'member-e', $weekdayAvailability, null);

		$preferences = $houseUser->getSchedulingPreferences();
		$this->assertSame($weekdayAvailability, $preferences['weekdayAvailability']);
		$this->assertSame(7, $preferences['energyBudget']['monday']);
	}

	/**
	 * Out-of-range energy in a bulk save is rejected and nothing is persisted.
	 */
	public function testUpdatePreferencesByNextcloudUserId_RejectsOutOfRangeEnergy(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(15);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-f');
		$houseUser->setSchedulingPreferences([]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(3, 'member-f')
			->willReturn($houseUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Energy budget must be between 1 and 20');

		$this->service->updatePreferencesByNextcloudUserId(
			3,
			'member-f',
			['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
			['monday' => 25, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10]
		);
	}

	/**
	 * GetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId
	 */
	public function testGetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId(): void {
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
	 * UpdateMemberAvailabilityByNextcloudUserId_DelegatesToScopedLookupAndAppliesUpdates
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_AppliesUpdatesViaScopedLookup(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		// Scoped lookup by houseId + nextcloud uid enforces membership.
		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($targetUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['weekdayAvailability' => ['monday' => false], 'energyBudget' => ['monday' => 15]]
		);

		$preferences = $targetUser->getSchedulingPreferences();
		$this->assertFalse($preferences['weekdayAvailability']['monday']);
		$this->assertSame(15, $preferences['energyBudget']['monday']);
	}

	/**
	 * Out-of-range energy values in a member update are rejected (not silently
	 * dropped) and nothing is persisted.
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_RejectsOutOfRangeEnergy(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Energy budget must be between 1 and 20');

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['energyBudget' => ['monday' => 25]]
		);
	}

	/**
	 * Non-integer energy values in a member update are rejected.
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_RejectsNonIntegerEnergy(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['energyBudget' => ['monday' => '15']]
		);
	}
}
