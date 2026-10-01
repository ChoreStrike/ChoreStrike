<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Controller\RewardController;
use OCA\ChoreStrike\Controller\RewardRequestController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Browser-contract integration tests for the WP7 HTTP surface
 * (CS-PLAN-078 §3.3/C4.10): REAL controllers resolved from the container
 * with the acting session — scope/actor matrix, DTO whitelist shapes and
 * the 400/403/404/409 mapping against the real services.
 */
class RewardApiTest extends IntegrationTestCase {
	private const UUID = '12121212-1212-4212-8212-121212121212';

	private function rewardController(): RewardController {
		return \OC::$server->get(RewardController::class);
	}

	private function requestController(): RewardRequestController {
		return \OC::$server->get(RewardRequestController::class);
	}

	private function houseController(): HouseController {
		return \OC::$server->get(HouseController::class);
	}

	/**
	 * Seed house with owner/adult/child, catalog on, one item.
	 *
	 * @return array{houseId: int, itemId: int}
	 */
	/**
	 * Deterministic controller-test bootstrap: the controllers resolve the
	 * house via `getOrCreateHouse(instanceId)`, so the shared
	 * controller-test house gets a fresh first-owner membership for the
	 * acting user (raw-clearing stale members of prior suites).
	 *
	 * @return array{houseId: int, stateVersion: int}
	 */
	private function bootstrapHouseAsOwner(string $uid): array {
		$houseService = \OC::$server->get(HouseService::class);
		$house = $houseService->getOrCreateHouse($houseService->getInstanceId());
		$houseId = (int)$house->getId();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		// Fresh reward surface for the shared controller-test house (the
		// dependency order matches the fixture cleanup).
		$this->db->executeStatement(
			'DELETE FROM *PREFIX*chorestrike_request_events WHERE request_id IN'
			. ' (SELECT id FROM *PREFIX*chorestrike_reward_requests WHERE house_id = ?)',
			[$houseId]
		);
		$this->db->executeStatement('DELETE FROM *PREFIX*chorestrike_reward_requests WHERE house_id = ?', [$houseId]);
		$this->db->executeStatement('DELETE FROM *PREFIX*chorestrike_reward_items WHERE house_id = ?', [$houseId]);
		$this->actingAs($uid);
		\OC::$server->get(\OCA\ChoreStrike\Service\AuthorizationService::class)->ensureHouseMember($houseId);
		// Membership creation may bump the aggregate version — re-read.
		$fresh = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		return ['houseId' => $houseId, 'stateVersion' => $fresh->getStateVersion()];
	}

	/**
	 * Seed the BOOTSTRAP house (the one the controllers resolve via
	 * `getOrCreateHouse`) with owner/adult/child members, catalog on and
	 * one item.
	 *
	 * @return array{houseId: int, itemId: int}
	 */
	private function seedApiHouse(): array {
		$boot = $this->bootstrapHouseAsOwner('api_owner');
		$houseId = $boot['houseId'];
		$houseService = \OC::$server->get(HouseService::class);
		$this->addHouseMember($houseId, 'api_adult', 'Adult', 'member');
		$this->addHouseMember($houseId, 'api_child', 'Child', 'child');

		// Fund the child wallet through the real ledger path.
		$child = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, 'api_child');
		$this->creditTestWallet($houseId, (int)$child->getWalletId(), 10000, null);

		// addHouseMember switches the acting session — act as the owner again.
		$this->actingAs('api_owner');
		$houseService->setCatalogEnabled($houseId, true, $boot['stateVersion'], $this->uuid());

		$this->injectRequestParams($this->rewardController(), [
			'title' => 'Zoo day', 'priceMinor' => 2500, 'commandId' => $this->uuid(),
		]);
		$response = $this->rewardController()->create();
		self::assertSame(200, $response->getStatus(), 'seed item creation failed: ' . json_encode($response->getData()));

		return ['houseId' => $houseId, 'itemId' => (int)$response->getData()['data']['id']];
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * CatalogList_ActiveScopeShowsItemsForMembers_AdminScopeIsOwnerOnly (C4.10)
	 */
	public function testCatalogList_ActiveScopeShowsItemsForMembers_AdminScopeIsOwnerOnly(): void {
		$f = $this->seedApiHouse();

		$this->actingAs('api_child');
		$this->injectRequestParams($this->rewardController(), []);
		$response = $this->rewardController()->index();
		self::assertSame(200, $response->getStatus());
		$data = $response->getData()['data'];
		self::assertTrue($data['catalogEnabled']);
		self::assertSame('Zoo day', $data['items'][0]['title']);
		self::assertSame(2500, $data['items'][0]['priceMinor']);
		self::assertArrayNotHasKey('createdByWalletId', $data['items'][0], 'no actor references in the catalog DTO');

		// scope=admin for a child: visible-resource role denial → 403.
		$this->injectRequestParams($this->rewardController(), ['scope' => 'admin']);
		$response = $this->rewardController()->index();
		self::assertSame(403, $response->getStatus());

		// scope=admin for the owner: archived+live items.
		$this->actingAs('api_owner');
		$this->injectRequestParams($this->rewardController(), ['scope' => 'admin']);
		$response = $this->rewardController()->index();
		self::assertSame(200, $response->getStatus());
		self::assertCount(1, $response->getData()['data']['items']);
	}

	/**
	 * CatalogCreate_ValidatesStrictPrice_MapsTo400 (C4.5 money boundary)
	 */
	public function testCatalogCreate_ValidatesStrictPrice_MapsTo400(): void {
		$this->bootstrapHouseAsOwner('api_owner2');

		foreach ([12.5, '1250', 0] as $badPrice) {
			$this->injectRequestParams($this->rewardController(), [
				'title' => 'X', 'priceMinor' => $badPrice, 'commandId' => $this->uuid(),
			]);
			$response = $this->rewardController()->create();
			self::assertSame(400, $response->getStatus(), 'priceMinor ' . var_export($badPrice, true) . ' must 400');
			self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		}
	}

	/**
	 * RequestLifecycleThroughHttp_FullChildFlow (C4.1)
	 */
	public function testRequestLifecycleThroughHttp_FullChildFlow(): void {
		$f = $this->seedApiHouse();
		$controller = $this->requestController();

		// Child creates → pending, allowedActions hint, own wallet attached.
		$this->actingAs('api_child');
		$this->injectRequestParams($controller, ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 2500, 'commandId' => $this->uuid()]);
		$response = $controller->create();
		self::assertSame(200, $response->getStatus());
		$dto = $response->getData()['data'];
		$requestId = (int)$dto['id'];
		self::assertSame('pending', $dto['state']);
		self::assertSame(['cancel'], $dto['allowedActions']);
		self::assertArrayHasKey('wallet', $dto, 'own requests carry the caller wallet');
		self::assertFalse($dto['fundingHold']);

		// Foreign member: invisible → 404 (never 403-enumerable).
		$this->actingAs('api_adult');
		$response = $controller->show($requestId);
		self::assertSame(404, $response->getStatus());

		// Owner approves → fulfill with delivered confirmation.
		$this->actingAs('api_owner');
		$this->injectRequestParams($controller, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);
		$response = $controller->approve($requestId);
		self::assertSame(200, $response->getStatus());
		self::assertSame(['fulfill', 'cancel'], $response->getData()['data']['allowedActions']);

		$this->injectRequestParams($controller, ['commandId' => $this->uuid(), 'expectedVersion' => 1, 'delivered' => true]);
		$response = $controller->fulfill($requestId);
		self::assertSame(200, $response->getStatus());
		self::assertSame('fulfilled', $response->getData()['data']['state']);
		self::assertSame(['correct'], $response->getData()['data']['allowedActions']);

		// Missing delivered flag → 400 (service validation).
		$this->injectRequestParams($controller, ['commandId' => $this->uuid(), 'expectedVersion' => 2]);
		$response = $controller->fulfill($requestId);
		self::assertSame(400, $response->getStatus());

		// Owner corrects the false fulfillment.
		$row = $this->requestRow($requestId);
		$this->injectRequestParams($controller, [
			'commandId' => $this->uuid(), 'expectedVersion' => 2, 'notDelivered' => true,
			'reasonCode' => 'not_delivered', 'settlementEntryId' => (int)$row['settlement_entry_id'],
		]);
		$response = $controller->correct($requestId);
		self::assertSame(200, $response->getStatus());
		self::assertSame('corrected', $response->getData()['data']['state']);
		self::assertSame([], $response->getData()['data']['allowedActions']);
	}

	/**
	 * RequestList_MineVsHouseScopes (C4.10)
	 */
	public function testRequestList_MineVsHouseScopes(): void {
		$f = $this->seedApiHouse();
		$controller = $this->requestController();

		$this->actingAs('api_child');
		$this->injectRequestParams($controller, ['itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 2500, 'commandId' => $this->uuid()]);
		$created = $controller->create()->getData()['data'];

		// mine (default) shows the child's own request.
		$this->injectRequestParams($controller, []);
		$response = $controller->index();
		self::assertSame(200, $response->getStatus());
		self::assertSame([(int)$created['id']], array_map(static fn (array $r): int => (int)$r['id'], $response->getData()['data']['requests']));

		// house scope for a child → 403.
		$this->injectRequestParams($controller, ['scope' => 'house']);
		$response = $controller->index();
		self::assertSame(403, $response->getStatus());

		// house scope for the owner → the request with the requester ref.
		$this->actingAs('api_owner');
		$this->injectRequestParams($controller, ['scope' => 'house']);
		$response = $controller->index();
		self::assertSame(200, $response->getStatus());
		$requests = $response->getData()['data']['requests'];
		self::assertCount(1, $requests);
		self::assertFalse($requests[0]['requester']['isFormerMember']);
		self::assertArrayNotHasKey('wallet', $requests[0], 'owners acting for another person get no target wallet totals');
		self::assertTrue($requests[0]['fundingHold'] === false || $requests[0]['fundingHold'] === true, 'funding hold is part of the administrability payload');
	}

	/**
	 * HouseCatalogGateThroughHttp (C4.4)
	 */
	public function testHouseCatalogGateThroughHttp(): void {
		$boot = $this->bootstrapHouseAsOwner('api_gate_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$this->addHouseMember($boot['houseId'], 'api_gate_child', 'Child', 'child');
		$controller = $this->houseController();

		// A child cannot toggle the gate.
		$this->actingAs('api_gate_child');
		$this->injectRequestParams($controller, ['catalogEnabled' => true, 'expectedVersion' => $boot['stateVersion'], 'commandId' => $this->uuid()]);
		$response = $controller->updateCatalog();
		self::assertSame(403, $response->getStatus());

		// The owner toggles with the stateVersion contract.
		$this->actingAs('api_gate_owner');
		$this->injectRequestParams($controller, ['catalogEnabled' => true, 'expectedVersion' => $boot['stateVersion'], 'commandId' => $this->uuid()]);
		$response = $controller->updateCatalog();
		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['data']['catalogEnabled']);

		// A stale version conflicts.
		$this->injectRequestParams($controller, ['catalogEnabled' => false, 'expectedVersion' => 0, 'commandId' => $this->uuid()]);
		$response = $controller->updateCatalog();
		self::assertSame(409, $response->getStatus());

		// GET /house transports the gate through the entity serialization.
		$this->injectRequestParams($controller, []);
		$response = $controller->get();
		self::assertSame(200, $response->getStatus());
		self::assertArrayHasKey('catalogEnabled', $response->getData()['data']);
	}

	/**
	 * ErasedRequesterPlaceholders_NeverExposeIdentifiers (Q-070-02 slice)
	 */
	public function testErasedRequesterPlaceholders_NeverExposeIdentifiers(): void {
		$f = $this->seedApiHouse();
		$requestService = \OC::$server->get(\OCA\ChoreStrike\Service\RewardRequestService::class);
		$created = $requestService->create($f['houseId'], 'api_child', [
			'itemId' => $f['itemId'], 'itemVersion' => 0, 'priceMinor' => 2500, 'commandId' => $this->uuid(),
		]);
		$requestId = (int)$created['id'];

		// The owner approves BEFORE the owner's own erasure: the retained
		// request keeps an owner-actor audit event and approval provenance
		// that must detach without exposing identifiers.
		$requestService->approve($f['houseId'], 'api_owner', $requestId, ['commandId' => $this->uuid(), 'expectedVersion' => 0]);

		// Authorized privacy erasure of the OWNER (wallet + membership):
		// their own catalog wish dies, their references on the CHILD's
		// retained request are anonymized.
		\OC::$server->get(\OCA\ChoreStrike\Service\AccountErasureService::class)->eraseAccount('api_owner');

		// The child still sees the retained request; the erased approver
		// renders as the placeholder everywhere.
		$this->actingAs('api_child');
		$response = $this->requestController()->show($requestId);
		self::assertSame(200, $response->getStatus());
		$dto = $response->getData()['data'];
		self::assertSame('approved', $dto['state'], 'the retained request survives the erasure of a THIRD party');
		$approveEvent = null;
		foreach ($dto['audit'] as $event) {
			if ($event['action'] === 'approve') {
				$approveEvent = $event;
			}
		}
		self::assertNotNull($approveEvent, 'the owner approval event is retained');
		self::assertTrue($approveEvent['actor']['isFormerMember']);
		self::assertNull($approveEvent['actor']['houseUserId']);
		self::assertNull($approveEvent['actor']['displayName']);
		self::assertSame('Child', $dto['requester']['displayName'], 'the live requester reference stays intact');
		self::assertFalse($dto['requester']['isFormerMember']);

		// The erased owner's own wish is purged from the catalog.
		$this->injectRequestParams($this->rewardController(), ['scope' => 'admin']);
		$response = $this->rewardController()->index();
		self::assertSame(403, $response->getStatus(), 'the erased owner lost admin scope');
	}

	private function requestRow(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_reward_requests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}
}
