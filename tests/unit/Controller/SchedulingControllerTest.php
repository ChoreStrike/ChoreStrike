<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SchedulingController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingController
 */
class SchedulingControllerTest extends TestCase {
	private SchedulingController $controller;
	private $schedulingService;
	private $houseService;
	private $authService;
	private $request;
	private $suggestionService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->schedulingService = $this->createMock(SchedulingService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->suggestionService = $this->createMock(\OCA\ChoreStrike\Service\SuggestionService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		// CR-016-15: the controller delegates the energy bounds to the
		// service; mirror the real pure-function behavior on the mock so the
		// section-validation tests pin the same stable message end-to-end.
		$this->schedulingService->method('assertValidEnergyBudget')
			->willReturnCallback(static function ($energy): void {
				if (!is_int($energy) || $energy < SchedulingService::ENERGY_BUDGET_MIN || $energy > SchedulingService::ENERGY_BUDGET_MAX) {
					throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
				}
			});
		// Decision D5: the limit bounds live in the services; mirror them.
		$this->schedulingService->method('assertValidMaxDailyTasks')
			->willReturnCallback(static function ($limit): void {
				if (!is_int($limit) || $limit < 1 || $limit > 100) {
					throw new \InvalidArgumentException('Max daily tasks must be an integer between 1 and 100');
				}
			});

		$this->controller = new SchedulingController(
			$this->request,
			$this->schedulingService,
			$this->houseService,
			$this->authService,
			$l10n,
			$this->createMock(AppLogger::class),
			$this->suggestionService,
			new \OCA\ChoreStrike\Service\CalendarService(),
		);
	}

	private function mockHouse(int $id = 1): House {
		$house = new House();
		$house->setId($id);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		return $house;
	}

	/**
	 * RF-065-05: the member endpoint reads its closed payload from the
	 * request parameters (top-level JSON body keys merged over the route
	 * internals), so the unit tests mock exactly that dispatcher source.
	 *
	 * @param array<string, mixed> $updates Top-level body keys
	 */
	private function mockMemberRequest(array $updates, string $userId = 'alice'): void {
		$this->request->method('getParams')->willReturn(array_merge(
			['userId' => $userId, '_route' => 'chorestrike.scheduling.updatememberavailability'],
			$updates
		));
	}

	public function testGetPreferencesReturnsPreferences(): void {
		$preferences = [
			'userId' => 'user1',
			'houseId' => 1,
			'weekdayAvailability' => ['monday' => true, 'tuesday' => false],
			'energyBudget' => ['monday' => 10, 'tuesday' => 15],
		];

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('getUserPreferencesByNextcloudUserId')
			->with(1, 'user1')
			->willReturn($preferences);

		$response = $this->controller->getPreferences();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($preferences, $data['data']);
	}

	public function testGetPreferencesReturnsForbiddenForNonMember(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->authService->method('assertHouseMember')
			->willThrowException(new ForbiddenException('Not a house member'));

		$response = $this->controller->getPreferences();

		$this->assertEquals(403, $response->getStatus());
	}

	/**
	 * CS-PLAN-061 §3.5: an anonymous caller (requireCurrentUserId throws)
	 * gets a clean 403 — not a 500.
	 */
	public function testGetPreferencesReturnsForbiddenForAnonymousUser(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')
			->willThrowException(new ForbiddenException('Not authenticated'));

		$this->schedulingService->expects($this->never())
			->method('getUserPreferencesByNextcloudUserId');

		$response = $this->controller->getPreferences();

		$this->assertEquals(403, $response->getStatus());
	}

	private function fullWeek(callable $valueFor): array {
		$week = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
			$week[$day] = $valueFor($day);
		}
		return $week;
	}

	public function testUpdatePreferences_PersistsFullWeek_SuccessResponse(): void {
		$preferences = [
			'userId' => 7,
			'weekdayAvailability' => $this->fullWeek(fn () => true),
			'energyBudget' => $this->fullWeek(fn () => 10),
		];
		$weekdayAvailability = $this->fullWeek(fn (string $day) => $day !== 'sunday');
		$energyBudget = $this->fullWeek(fn (string $day) => $day === 'saturday' ? 15 : 10);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', $weekdayAvailability, $energyBudget)
			->willReturn($preferences);

		$response = $this->controller->updatePreferences($weekdayAvailability, $energyBudget);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($preferences, $data['data']);
	}

	public function testUpdatePreferences_EnergyOnly_Success(): void {
		$energyBudget = $this->fullWeek(fn () => 12);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', null, $energyBudget);

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdatePreferences_AvailabilityOnly_Success(): void {
		$weekdayAvailability = $this->fullWeek(fn () => false);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', $weekdayAvailability, null);

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdatePreferences_ReturnsBadRequestWhenNeitherSectionProvided(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->never())
			->method('updatePreferencesByNextcloudUserId');

		$response = $this->controller->updatePreferences(null, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForPartialWeek(): void {
		$weekdayAvailability = $this->fullWeek(fn () => true);
		unset($weekdayAvailability['friday']);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('must contain all seven weekdays', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForUnknownDayKey(): void {
		$energyBudget = $this->fullWeek(fn () => 10);
		$energyBudget['funday'] = 10;

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Invalid weekday: %1$s', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForNonIntegerEnergy(): void {
		$energyBudget = $this->fullWeek(fn () => 10);
		$energyBudget['monday'] = '15';

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		// CR-SVC-49: the curated bounds message must surface verbatim.
		$this->assertEquals('Energy budget must be between 1 and 20', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForOutOfRangeEnergy(): void {
		$energyBudget = $this->fullWeek(fn () => 25);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Energy budget must be between 1 and 20', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForNonBoolAvailability(): void {
		$weekdayAvailability = $this->fullWeek(fn () => true);
		$weekdayAvailability['monday'] = 'yes';

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Availability values must be booleans', $response->getData()['error']['message']);
	}

	/**
	 * CS-PLAN-061 §3.7: weekday keys are validated case-insensitively
	 * ('Monday' must be accepted exactly like 'monday').
	 */
	public function testUpdatePreferences_AcceptsUpperCaseDayKeys(): void {
		$weekdayAvailability = array_combine(
			array_map('ucfirst', array_keys($this->fullWeek(fn () => true))),
			array_fill(0, 7, true)
		);

		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', $weekdayAvailability, null);

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdatePreferences_ReturnsForbiddenForNonMember(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->authService->method('assertHouseMember')
			->willThrowException(new ForbiddenException('Not a house member'));

		$response = $this->controller->updatePreferences($this->fullWeek(fn () => true), null);

		$this->assertEquals(403, $response->getStatus());
	}

	public function testGetMemberAvailabilityReturnsListForOwner(): void {
		$members = [
			['userId' => 'user1', 'houseId' => 1],
			['userId' => 'user2', 'houseId' => 1],
		];

		$this->mockHouse();

		$this->schedulingService->expects($this->once())
			->method('getMemberAvailability')
			->with(1)
			->willReturn($members);

		$response = $this->controller->getMemberAvailability();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	public function testGetMemberAvailabilityReturnsForbiddenForNonOwner(): void {
		$this->mockHouse();
		$this->authService->method('requireHouseOwner')
			->willThrowException(new ForbiddenException('Not the house owner'));

		$response = $this->controller->getMemberAvailability();

		$this->assertEquals(403, $response->getStatus());
	}

	public function testUpdateMemberAvailabilityUpdatesForOwner(): void {
		$this->mockHouse();
		$updates = ['weekdayAvailability' => ['monday' => false]];
		$this->mockMemberRequest($updates);

		$this->schedulingService->expects($this->once())
			->method('updateMemberAvailabilityByNextcloudUserId')
			->with(1, 'alice', $updates);

		$response = $this->controller->updateMemberAvailability('alice');

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdateMemberAvailabilityReturnsForbiddenForNonOwner(): void {
		$this->mockHouse();
		$this->authService->method('requireHouseOwner')
			->willThrowException(new ForbiddenException('Not the house owner'));

		$response = $this->controller->updateMemberAvailability('alice');

		$this->assertEquals(403, $response->getStatus());
	}

	public function testUpdateMemberAvailabilityReturnsBadRequestForInvalidWeekday(): void {
		$this->mockHouse();
		$this->mockMemberRequest(['weekdayAvailability' => ['funday' => true]]);

		$response = $this->controller->updateMemberAvailability('alice');

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		// CR-SVC-49: the curated weekday message must surface verbatim.
		$this->assertEquals('Invalid weekday: %1$s', $response->getData()['error']['message']);
	}

	/**
	 * Decision D5: the owner member endpoint persists the child limit.
	 */
	public function testUpdateMemberAvailability_AcceptsChildLimit(): void {
		$this->mockHouse();
		$updates = ['maxDailyTasks' => 4];
		$this->mockMemberRequest($updates, 'kid');

		$this->schedulingService->expects($this->once())
			->method('updateMemberAvailabilityByNextcloudUserId')
			->with(1, 'kid', $updates);

		$response = $this->controller->updateMemberAvailability('kid');

		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * Decision D5: `0` is a validation error, not "unlimited".
	 */
	public function testUpdateMemberAvailability_RejectsZeroLimit(): void {
		$this->mockHouse();
		$this->mockMemberRequest(['maxDailyTasks' => 0], 'kid');

		$response = $this->controller->updateMemberAvailability('kid');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('Max daily tasks must be an integer between 1 and 100', $response->getData()['error']['message']);
	}

	/**
	 * Decision D5: the update payload is a closed set.
	 */
	public function testUpdateMemberAvailability_RejectsUnknownField(): void {
		$this->mockHouse();
		$this->mockMemberRequest(['maxDailyTasksX' => 4], 'kid');

		$response = $this->controller->updateMemberAvailability('kid');

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * RF-065-05 regression: the route internals (`userId`, `_route`) never
	 * leak into the update payload, and an explicit `maxDailyTasks: null`
	 * (D5 "unlimited") survives as a present key.
	 */
	public function testUpdateMemberAvailability_StripsRouteInternalsFromPayload(): void {
		$this->mockHouse();
		$this->mockMemberRequest(['maxDailyTasks' => null], 'kid');

		$this->schedulingService->expects($this->once())
			->method('updateMemberAvailabilityByNextcloudUserId')
			->with(1, 'kid', ['maxDailyTasks' => null]);

		$response = $this->controller->updateMemberAvailability('kid');

		$this->assertEquals(200, $response->getStatus());
	}

	/**
	 * Decision D5: the personal endpoint can never change the child limit.
	 */
	public function testUpdatePreferences_RejectsPersonalMaxDailyTasks(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => match ($key) {
			'maxDailyTasks' => 4,
			default => null,
		});

		$this->schedulingService->expects($this->never())
			->method('updatePreferencesByNextcloudUserId');

		$response = $this->controller->updatePreferences($this->fullWeek(fn () => true), null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('maxDailyTasks can only be changed by a house owner', $response->getData()['error']['message']);
	}

	// ------------------------------------------------------------------
	// Preferred days and the live suggestion (CS-PLAN-091 WP-C, ADR-022)
	// ------------------------------------------------------------------

	public function testUpdatePreferences_PreferredDaysOnly_Success(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->schedulingService->expects($this->once())->method('normalizePreferredWeekdays')->with(['saturday']);
		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', null, null, ['saturday']);

		$response = $this->controller->updatePreferences(null, null, ['saturday']);

		$this->assertEquals(200, $response->getStatus());
	}

	public function testUpdatePreferences_NoPreferredDays_IsAValidSection(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', null, null, []);

		$this->assertEquals(200, $this->controller->updatePreferences(null, null, [])->getStatus());
	}

	public function testUpdatePreferences_InvalidPreferredDay_IsRefusedBeforeTheWrite(): void {
		$this->mockHouse();
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->schedulingService->method('normalizePreferredWeekdays')
			->willThrowException(new \InvalidArgumentException('Preferred days must be a list of weekdays'));
		$this->schedulingService->expects($this->never())->method('updatePreferencesByNextcloudUserId');

		$response = $this->controller->updatePreferences(null, null, ['someday']);

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testUpdateMemberAvailability_AcceptsPreferredDays(): void {
		$this->mockHouse();
		$updates = ['preferredWeekdays' => ['wednesday', 'saturday']];
		$this->mockMemberRequest($updates, 'kid');
		$this->schedulingService->expects($this->once())->method('normalizePreferredWeekdays')->with(['wednesday', 'saturday']);
		$this->schedulingService->expects($this->once())
			->method('updateMemberAvailabilityByNextcloudUserId')
			->with(1, 'kid', $updates);

		$this->assertEquals(200, $this->controller->updateMemberAvailability('kid')->getStatus());
	}

	public function testSuggestion_ValidRange_ReturnsTheViewersSuggestion(): void {
		$house = $this->mockHouse();
		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$payload = ['days' => [], 'overdue' => [], 'members' => []];
		$this->suggestionService->expects($this->once())->method('range')
			->with($house, $viewer, '2026-11-23', '2026-11-29')
			->willReturn($payload);

		$response = $this->controller->suggestion('2026-11-23', '2026-11-29');

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame($payload, $response->getData()['data']);
	}

	/**
	 * @dataProvider invalidSuggestionDays
	 */
	public function testSuggestion_DayThatIsNoRealDay_IsRefusedWithoutARead(string $from, string $to): void {
		$this->mockHouse();
		$this->suggestionService->expects($this->never())->method('range');

		$response = $this->controller->suggestion($from, $to);

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/** @return array<string, array{string, string}> */
	public static function invalidSuggestionDays(): array {
		return [
			'missing' => ['', ''],
			'no day key' => ['today', '2026-11-29'],
			'a day that does not exist' => ['2026-11-23', '2026-11-31'],
		];
	}

	public function testSuggestion_RangeOutsideTheHorizon_IsAValidationError(): void {
		$this->mockHouse();
		$this->suggestionService->method('range')
			->willThrowException(new \InvalidArgumentException('The suggestion covers 14 days from today'));

		$response = $this->controller->suggestion('2026-11-23', '2026-12-31');

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('The suggestion covers 14 days from today', $response->getData()['error']['message']);
	}

	public function testSuggestion_NonMember_IsForbiddenWithoutARead(): void {
		$this->mockHouse();
		$this->authService->method('assertHouseMember')->willThrowException(new ForbiddenException('Not a house member'));
		$this->suggestionService->expects($this->never())->method('range');

		$this->assertEquals(403, $this->controller->suggestion('2026-11-23', '2026-11-29')->getStatus());
	}
}
