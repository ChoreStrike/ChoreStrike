<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RewardItem;
use OCA\ChoreStrike\Db\RewardItemMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\RewardService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalog owner (CS-PLAN-070 WP7, CS-PLAN-078 §3.3):
 * C4.4/C4.5 catalog gating, scope/actor matrix, exact price validation,
 * whitelisted item fields and the versioned owner-only gate command.
 */
class RewardServiceTest extends TestCase {
	private RewardItemMapper $itemMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private AuthorizationService $authorization;
	private CommandService $commands;
	private RewardService $service;

	/** @var list<callable> Under-lock guards captured from commands. */
	private array $capturedGuards = [];

	private House $house;

	protected function setUp(): void {
		$this->itemMapper = $this->createMock(RewardItemMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->commands = $this->createMock(CommandService::class);
		$this->capturedGuards = [];

		$this->house = new House();
		$this->house->setId(1);
		$this->house->setStateVersion(5);
		$this->house->setCatalogEnabled(false);
		$this->houseMapper->method('find')->willReturn($this->house);
		$this->houseMapper->method('bumpStateVersion')->willReturnCallback(function (int $houseId): void {
			$this->house->setStateVersion($this->house->getStateVersion() + 1);
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf(preg_replace('/%[0-9]+\$[sd]/', '%s', $text), $params));

		$this->service = new RewardService(
			$this->commands,
			$this->itemMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->authorization,
			new \OCA\ChoreStrike\Service\EffortAllocationService(),
			$l10n,
			$this->createMock(AppLogger::class),
		);

		// Receipt contract stand-in: run the aggregate, capture the guard.
		$this->commands->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorId, string $uuid, string $action, array $payload, callable $fn, ?callable $guard = null): array {
				if ($guard !== null) {
					$this->capturedGuards[] = $guard;
				}
				return ['result' => $fn(), 'receipt' => null, 'replay' => false];
			}
		);
	}

	private function owner(): HouseUser {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(1);
		$member->setNextcloudUserId('owner');
		$member->setRole('owner');
		$member->setWalletId(55);
		return $member;
	}

	private function member(): HouseUser {
		$member = new HouseUser();
		$member->setId(12);
		$member->setHouseId(1);
		$member->setNextcloudUserId('member');
		$member->setRole('member');
		$member->setWalletId(66);
		return $member;
	}

	private function item(int $id, bool $archived = false): RewardItem {
		$item = new RewardItem();
		$item->setId($id);
		$item->setHouseId(1);
		$item->setTitle('Cinema night');
		$item->setPriceMinor(1250);
		$item->setArchived($archived);
		$item->setVersion(0);
		return $item;
	}

	private function mockOwnerGate(): void {
		$this->authorization->method('requireHouseOwner')->willReturn($this->owner());
		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(function (int $id): HouseUser {
			if ($id === 11) {
				return $this->owner();
			}
			throw new DoesNotExistException('gone');
		});
	}

	/**
	 * ListItems_ActiveScope_DisabledGateReportsExplicitEmptyState (C4.4)
	 */
	public function testListItems_ActiveScope_DisabledGateReportsExplicitEmptyState(): void {
		$result = $this->service->listItems(1, $this->member(), RewardService::SCOPE_ACTIVE);
		self::assertFalse($result['catalogEnabled']);
		self::assertSame([], $result['items'], 'the disabled gate exposes no catalog content');
	}

	/**
	 * ListItems_ActiveScope_EnabledGateReturnsActiveItemsOnly
	 */
	public function testListItems_ActiveScope_EnabledGateReturnsActiveItemsOnly(): void {
		$this->house->setCatalogEnabled(true);
		$this->itemMapper->method('findByHouseId')->with(1, false, null, RewardService::DEFAULT_LIMIT)
			->willReturn([$this->item(3)]);
		$result = $this->service->listItems(1, $this->member(), RewardService::SCOPE_ACTIVE);
		self::assertTrue($result['catalogEnabled']);
		self::assertSame('Cinema night', $result['items'][0]['title']);
		self::assertSame(1250, $result['items'][0]['priceMinor']);
		self::assertArrayNotHasKey('createdByWalletId', $result['items'][0], 'no actor references leak through the catalog DTO');
	}

	/**
	 * ListItems_AdminScope_IsOwnerOnly (403 for visible-role denial)
	 */
	public function testListItems_AdminScope_IsOwnerOnly(): void {
		$this->expectException(ForbiddenException::class);
		$this->service->listItems(1, $this->member(), RewardService::SCOPE_ADMIN);
	}

	/**
	 * ListItems_UnknownScope_IsRejected
	 */
	public function testListItems_UnknownScope_IsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->listItems(1, $this->member(), 'everything');
	}

	/**
	 * SetCatalogEnabled_TogglesTheGateAndBumpsTheHouseVersion (C4.4)
	 */
	public function testSetCatalogEnabled_TogglesTheGateAndBumpsTheHouseVersion(): void {
		$this->mockOwnerGate();
		$result = $this->service->setCatalogEnabled(1, true, 5, '11111111-1111-4111-8111-111111111111');
		self::assertTrue($result['catalogEnabled']);
		self::assertSame(6, $this->house->getStateVersion(), 'every reward mutation advances the aggregate version');
		self::assertCount(1, $this->capturedGuards, 'the WP7 under-lock authorization guard is supplied');
	}

	/**
	 * SetCatalogEnabled_StaleVersion_Conflicts
	 */
	public function testSetCatalogEnabled_StaleVersion_Conflicts(): void {
		$this->mockOwnerGate();
		$this->expectException(ConflictException::class);
		$this->service->setCatalogEnabled(1, true, 4, '11111111-1111-4111-8111-111111111111');
	}

	/**
	 * SetCatalogEnabled_ReplayStillRunsTheOwnerGuard (§3.4)
	 */
	public function testSetCatalogEnabled_ReplayStillRunsTheOwnerGuard(): void {
		$this->mockOwnerGate();
		$this->service->setCatalogEnabled(1, true, 5, '11111111-1111-4111-8111-111111111111');
		// A demoted/departed actor must fail the guard even on a receipt
		// replay where the aggregate is skipped.
		$guard = $this->capturedGuards[0];
		$this->expectException(ForbiddenException::class);
		$guard(1, 12);
	}

	/**
	 * CreateItem_RejectsNonIntegerAndNonPositivePrices (C4.5)
	 */
	public function testCreateItem_RejectsNonIntegerAndNonPositivePrices(): void {
		$this->mockOwnerGate();
		foreach ([12.5, '1250', 0, -1] as $badPrice) {
			try {
				$this->service->createItem(1, ['title' => 'X', 'priceMinor' => $badPrice, 'commandId' => '11111111-1111-4111-8111-111111111111']);
				self::fail('priceMinor ' . var_export($badPrice, true) . ' must be rejected');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('priceMinor', $e->getMessage());
			}
		}
	}

	/**
	 * CreateItem_RejectsUnknownFieldsAndControlCharacters
	 */
	public function testCreateItem_RejectsUnknownFieldsAndControlCharacters(): void {
		$this->mockOwnerGate();
		try {
			$this->service->createItem(1, ['title' => 'X', 'priceMinor' => 100, 'archived' => true, 'commandId' => '11111111-1111-4111-8111-111111111111']);
			self::fail('an unknown create field must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Unknown field', $e->getMessage());
		}
		try {
			$this->service->createItem(1, ['title' => "bad\nline", 'priceMinor' => 100, 'commandId' => '11111111-1111-4111-8111-111111111111']);
			self::fail('control characters must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Control characters', $e->getMessage());
		}
	}

	/**
	 * CreateItem_PersistsTheWhitelistedTerms
	 */
	public function testCreateItem_PersistsTheWhitelistedTerms(): void {
		$this->mockOwnerGate();
		$inserted = null;
		$this->itemMapper->expects($this->once())->method('insert')->willReturnCallback(
			function (RewardItem $item) use (&$inserted): RewardItem {
				$item->setId(9);
				$inserted = $item;
				return $item;
			}
		);
		$this->itemMapper->method('find')->willReturnCallback(fn (int $id): RewardItem => $inserted ?? $this->item($id));

		$result = $this->service->createItem(1, ['title' => 'Cinema', 'description' => 'Pop included', 'priceMinor' => 1250, 'commandId' => '11111111-1111-4111-8111-111111111111']);

		self::assertSame(9, $result['id']);
		self::assertSame(1250, $result['priceMinor']);
		self::assertSame('Cinema', $inserted->getTitle());
		self::assertSame('Pop included', $inserted->getDescription());
		self::assertFalse($inserted->getArchived());
		self::assertSame(55, $inserted->getCreatedByWalletId(), 'creator wallet provenance enables erasure');
	}

	/**
	 * UpdateItem_StaleVersion_Conflicts
	 */
	public function testUpdateItem_StaleVersion_Conflicts(): void {
		$this->mockOwnerGate();
		$this->itemMapper->method('find')->willReturn($this->item(3));
		$this->expectException(ConflictException::class);
		$this->service->updateItem(1, 3, ['priceMinor' => 2000, 'expectedVersion' => 1, 'commandId' => '11111111-1111-4111-8111-111111111111']);
	}

	/**
	 * UpdateItem_ArchivesAndBumpsTheVersion
	 */
	public function testUpdateItem_ArchivesAndBumpsTheVersion(): void {
		$this->mockOwnerGate();
		$item = $this->item(3);
		$this->itemMapper->method('find')->willReturn($item);
		$this->itemMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateItem(1, 3, ['archived' => true, 'expectedVersion' => 0, 'commandId' => '11111111-1111-4111-8111-111111111111']);

		self::assertTrue($result['archived'], 'archiving keeps the promised terms, never deletes them');
		self::assertSame(1, $result['version']);
	}

	/**
	 * UpdateItem_ForeignOrMissingItem_Is404
	 */
	public function testUpdateItem_ForeignOrMissingItem_Is404(): void {
		$this->mockOwnerGate();
		$this->itemMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->expectException(NotFoundException::class);
		$this->service->updateItem(1, 99, ['archived' => true, 'expectedVersion' => 0, 'commandId' => '11111111-1111-4111-8111-111111111111']);
	}

	/**
	 * CreateItem_ArchivedFieldIsNotAcceptedOnCreate
	 */
	public function testCreateItem_ArchivedFieldIsNotAcceptedOnCreate(): void {
		$this->mockOwnerGate();
		$this->expectException(\InvalidArgumentException::class);
		$this->service->createItem(1, ['title' => 'X', 'priceMinor' => 100, 'archived' => true, 'commandId' => '11111111-1111-4111-8111-111111111111']);
	}
}
