<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Real-DB API tests for the house timezone surface (CS-PLAN-057).
 *
 * Pins the owner-editable `houses.timezone` round-trip through
 * `PUT /api/v1/house` (`HouseController::update`): closed-set validation
 * against IANA identifiers (unknown id → 400 VALIDATION_ERROR), empty
 * meaning "leave unchanged", and the persisted value coming back from both
 * the update payload and a fresh `houseMapper->find` read.
 *
 * The controller is wired with the real HouseService/HouseMapper/DB stack;
 * only the authorization service is mocked (the shared instance house's
 * ownership is a container-wide fixture, not this test's concern) and the
 * shared house is restored to its previous name/timezone afterwards.
 */
class HouseTimezoneApiTest extends IntegrationTestCase {
	private HouseMapper $houseMapper;
	private HouseService $houseService;
	private ?HouseController $controller = null;
	private string $originalTimezone = 'UTC';
	private const OWNER = 'tzapi_owner';
	private const MEMBER = 'tzapi_member';

	protected function setUp(): void {
		parent::setUp();
		$this->houseMapper = \OC::$server->get(HouseMapper::class);
		$this->houseService = \OC::$server->get(HouseService::class);

		// Snapshot the shared instance house so tearDown can restore it.
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		$this->originalTimezone = $house->getTimezone() ?: 'UTC';

		// HouseService::updateHouse enforces ownership in the service now
		// (CS-PLAN-061 D4) via the real AuthorizationService, so the acting
		// user must be a seeded owner member of the shared instance house.
		$houseId = $house->getId();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'nextcloud_user_id',
				$qb->createNamedParameter([self::OWNER, self::MEMBER], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
			));
		$qb->executeStatement();
		$this->addHouseMember($houseId, self::OWNER, 'TZ Owner', 'owner');
	}

	protected function tearDown(): void {
		// Restore the shared house (name is never changed by these tests —
		// each update passes the house's current name).
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		$house->setTimezone($this->originalTimezone);
		$house->touch();
		$this->houseMapper->update($house);

		parent::tearDown();
	}

	private function buildController(string $userId): HouseController {
		$this->actingAs($userId);

		$request = $this->createMock(IRequest::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$authService = $this->createMock(AuthorizationService::class);
		$authService->method('ensureHouseMember');
		$authService->method('requireHouseOwner');

		return new HouseController(
			$request,
			$this->houseService,
			$authService,
			\OC::$server->get(ActivityService::class),
			$l10n,
			\OC::$server->get(AppLogger::class),
			new CalendarService(),
			\OC::$server->get(\OCA\ChoreStrike\Service\ArcadeAccessService::class),
		);
	}

	private function sharedHouseName(): string {
		return $this->houseService->getOrCreateHouse($this->houseService->getInstanceId())->getName();
	}

	public function testUpdate_ValidTimezone_PersistsAndReturnsTimezone(): void {
		$controller = $this->buildController('tzapi_owner');
		$name = $this->sharedHouseName();

		$response = $controller->update($name, 'Europe/Berlin');

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame('Europe/Berlin', $data['data']['timezone']);

		// The value is persisted — a fresh read returns it.
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		self::assertSame('Europe/Berlin', $house->getTimezone());
	}

	public function testUpdate_UnknownTimezone_RejectedWithValidationError(): void {
		$controller = $this->buildController('tzapi_owner');
		$name = $this->sharedHouseName();

		$response = $controller->update($name, 'Mars/Olympus_Mons');

		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('VALIDATION_ERROR', $data['error']['code']);
		self::assertSame('Unknown timezone', $data['error']['message']);

		// The invalid value never reached the DB.
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		self::assertSame($this->originalTimezone, $house->getTimezone());
	}

	public function testUpdate_EmptyTimezone_LeavesTimezoneUnchanged(): void {
		$controller = $this->buildController('tzapi_owner');
		$name = $this->sharedHouseName();

		// Seed a known value first…
		$response = $controller->update($name, 'Pacific/Auckland');
		self::assertSame(200, $response->getStatus());

		// …then a name-only update must not touch it.
		$response = $controller->update($name);
		self::assertSame(200, $response->getStatus());

		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		self::assertSame('Pacific/Auckland', $house->getTimezone());
	}

	public function testGet_IncludesTimezoneInPayload(): void {
		$controller = $this->buildController('tzapi_member');

		// Seed a known timezone directly (single entity fetch → mutate → update).
		$house = $this->houseService->getOrCreateHouse($this->houseService->getInstanceId());
		$house->setTimezone('Europe/Berlin');
		$house->touch();
		$this->houseMapper->update($house);

		$response = $controller->get();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame('Europe/Berlin', $data['data']['timezone']);
	}
}
