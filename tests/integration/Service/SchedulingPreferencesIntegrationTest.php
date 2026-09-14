<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for the bulk scheduling-preferences save (CS-PLAN-033,
 * CR-016-02) against the real Docker DB.
 *
 * Verifies that SchedulingService::updatePreferencesByNextcloudUserId
 * persists full weeks, merges section-granular, overwrites cleanly, and
 * rejects invalid energy without partial persistence.
 */
class SchedulingPreferencesIntegrationTest extends IntegrationTestCase {
	private SchedulingService $schedulingService;

	/** @var array<string, bool> */
	private array $fullAvailability;

	/** @var array<string, int> */
	private array $fullEnergy;

	protected function setUp(): void {
		parent::setUp();
		$this->schedulingService = \OC::$server->get(SchedulingService::class);

		$this->fullAvailability = [
			'monday' => true,
			'tuesday' => false,
			'wednesday' => true,
			'thursday' => true,
			'friday' => false,
			'saturday' => true,
			'sunday' => true,
		];
		$this->fullEnergy = [
			'monday' => 10,
			'tuesday' => 12,
			'wednesday' => 14,
			'thursday' => 16,
			'friday' => 18,
			'saturday' => 20,
			'sunday' => 5,
		];
	}

	/**
	 * Create a fresh house with one member and return
	 * [houseId, nextcloudUserId, ownerUserId].
	 *
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function houseWithMember(): array {
		$ownerId = 'sched-owner-' . uniqid();
		$houseId = $this->createHouse($ownerId);
		$userId = 'sched-member-' . uniqid();
		$this->addHouseMember($houseId, $userId, 'Sched Member', 'member');

		return [$houseId, $userId, $ownerId];
	}

	public function testBulkSave_PersistsFullWeek_AndRereadsExactValues(): void {
		[$houseId, $userId] = $this->houseWithMember();

		$this->schedulingService->updatePreferencesByNextcloudUserId(
			$houseId,
			$userId,
			$this->fullAvailability,
			$this->fullEnergy
		);

		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertSame($this->fullAvailability, $preferences['weekdayAvailability']);
		$this->assertSame($this->fullEnergy, $preferences['energyBudget']);
	}

	public function testGetPreferences_FreshMemberWithoutStoredPreferences_ReturnsDefaults(): void {
		[$houseId, $userId] = $this->houseWithMember();

		// Regression for the CS-PLAN-033 review: a fresh house_user row has
		// NULL schedulingPreferences — the payload builder must fall back to
		// the defaults instead of crashing on the null.
		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);

		$this->assertSame(10, $preferences['energyBudget']['monday']);
		$this->assertSame(15, $preferences['energyBudget']['saturday']);
		$this->assertSame(15, $preferences['energyBudget']['sunday']);
		$this->assertTrue($preferences['weekdayAvailability']['monday']);
		$this->assertTrue($preferences['weekdayAvailability']['sunday']);
		// CS-PLAN-065 (decision D5): a missing limit serializes as null.
		$this->assertNull($preferences['maxDailyTasks']);
		// CS-PLAN-065 (decision D7): the reader-less energy field is gone.
		$this->assertArrayNotHasKey('todayConsumedEnergy', $preferences);
	}

	/**
	 * CS-PLAN-065 (decision D5): the owner member path persists a finite
	 * child limit and a later explicit null clears it again.
	 */
	public function testMemberAvailability_MaxDailyTasks_SetAndCleared(): void {
		[$houseId, $userId, $ownerId] = $this->houseWithMember();

		// RF-058-05: the member-availability owner path gates service-side —
		// act as the house owner.
		$this->actingAs($ownerId);
		$this->schedulingService->updateMemberAvailabilityByNextcloudUserId(
			$houseId,
			$userId,
			['maxDailyTasks' => 4]
		);

		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertSame(4, $preferences['maxDailyTasks']);

		// Omitted key leaves the bound untouched.
		$this->schedulingService->updateMemberAvailabilityByNextcloudUserId(
			$houseId,
			$userId,
			['energyBudget' => $this->fullEnergy]
		);
		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertSame(4, $preferences['maxDailyTasks']);

		// Explicit null clears the bound (unlimited).
		$this->schedulingService->updateMemberAvailabilityByNextcloudUserId(
			$houseId,
			$userId,
			['maxDailyTasks' => null]
		);
		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertNull($preferences['maxDailyTasks']);
	}

	/**
	 * CS-PLAN-065 (decision D5): the owner member payload carries the limit.
	 */
	public function testGetMemberAvailability_CarriesMaxDailyTasks(): void {
		[$houseId, $userId, $ownerId] = $this->houseWithMember();

		// RF-058-05: owner-only read — act as the house owner.
		$this->actingAs($ownerId);
		$this->schedulingService->updateMemberAvailabilityByNextcloudUserId(
			$houseId,
			$userId,
			['maxDailyTasks' => 2]
		);

		$availability = $this->schedulingService->getMemberAvailability($houseId);
		$member = array_values(array_filter(
			$availability,
			static fn (array $entry): bool => $entry['userId'] === $userId
		))[0] ?? null;

		$this->assertNotNull($member);
		$this->assertSame(2, $member['maxDailyTasks']);
	}

	public function testEnergyOnlySave_LeavesAvailabilityUntouched(): void {
		[$houseId, $userId] = $this->houseWithMember();

		$this->schedulingService->updatePreferencesByNextcloudUserId(
			$houseId,
			$userId,
			$this->fullAvailability,
			$this->fullEnergy
		);

		$energyOnly = array_map(fn () => 7, $this->fullEnergy);
		$this->schedulingService->updatePreferencesByNextcloudUserId($houseId, $userId, null, $energyOnly);

		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertSame($energyOnly, $preferences['energyBudget']);
		$this->assertSame($this->fullAvailability, $preferences['weekdayAvailability']);
	}

	public function testSecondBulkSave_OverwritesCleanly_NoDayMergeResidue(): void {
		[$houseId, $userId] = $this->houseWithMember();

		$this->schedulingService->updatePreferencesByNextcloudUserId(
			$houseId,
			$userId,
			$this->fullAvailability,
			$this->fullEnergy
		);

		$weekB = array_map(fn () => 3, $this->fullEnergy);
		$this->schedulingService->updatePreferencesByNextcloudUserId($houseId, $userId, null, $weekB);

		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		// Exactly the seven canonical weekdays — no day-merge residue from week A.
		$this->assertSame($weekB, $preferences['energyBudget']);
		$this->assertSame(
			['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
			array_keys($preferences['energyBudget'])
		);
	}

	public function testInvalidEnergy_RejectedWithoutPartialPersistence(): void {
		[$houseId, $userId] = $this->houseWithMember();

		$this->schedulingService->updatePreferencesByNextcloudUserId(
			$houseId,
			$userId,
			$this->fullAvailability,
			$this->fullEnergy
		);

		$invalidEnergy = $this->fullEnergy;
		$invalidEnergy['wednesday'] = 25;

		try {
			$this->schedulingService->updatePreferencesByNextcloudUserId($houseId, $userId, null, $invalidEnergy);
			$this->fail('Expected InvalidArgumentException for out-of-range energy');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('Energy budget must be between 1 and 20', $e->getMessage());
		}

		// Row unchanged: no partial persistence of the invalid section.
		$preferences = $this->schedulingService->getUserPreferencesByNextcloudUserId($houseId, $userId);
		$this->assertSame($this->fullEnergy, $preferences['energyBudget']);
		$this->assertSame($this->fullAvailability, $preferences['weekdayAvailability']);
	}
}
