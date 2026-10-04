<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CommandReceipt;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RewardItem;
use OCA\ChoreStrike\Db\RewardItemMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\EffortAllocationService;
use OCA\ChoreStrike\Service\RewardService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CatalogAuditTest extends TestCase {
	/** @dataProvider catalogAuditProvider */
	public function testCatalogAudit_CommitReplayNoChangeDenialAndRollback_ReportsTruthfully(string $method, string $mode): void {
		$db = $this->createMock(IDBConnection::class);
		$houses = $this->createMock(HouseMapper::class);
		$members = $this->createMock(HouseUserMapper::class);
		$items = $this->createMock(RewardItemMapper::class);
		$logger = $this->createMock(AppLogger::class);
		$authorization = $this->createMock(AuthorizationService::class);
		$receipts = $this->createMock(CommandReceiptMapper::class);
		$receipt = null;
		$receipts->method('insert')->willReturnCallback(static function (CommandReceipt $row) use (&$receipt): CommandReceipt {
			$row->setId(5);
			return $receipt = $row;
		});
		// Keep the lookup live across the replay rather than freezing a value.
		$receipts->method('findByHouseActorUuid')->willReturnCallback(static function () use (&$receipt): ?CommandReceipt {
			return $receipt;
		});
		$commands = new CommandService(new TransactionExecutor($db), $houses, $receipts, $logger);
		$actor = new HouseUser();
		$actor->setId(11);
		$actor->setHouseId(1);
		$actor->setRole('owner');
		$actor->setWalletId(66);
		$members->method('findForUpdate')->willReturn($actor);
		if ($mode === 'denial') {
			$authorization->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		} else {
			$authorization->method('requireHouseOwner')->willReturn($actor);
		}
		$house = new House();
		$house->setId(1);
		$house->setStateVersion(5);
		$house->setCatalogEnabled(false);
		$houses->method('find')->willReturn($house);
		$item = new RewardItem();
		$item->setId(9);
		$item->setHouseId(1);
		$item->setVersion(0);
		$items->method('find')->willReturn($item);
		$items->method('insert')->willReturnCallback(static function (RewardItem $created) use ($item, $mode): RewardItem {
			if ($mode === 'without-description') {
				self::assertNull($created->getDescription());
			}
			return $item;
		});
		$language = $this->createMock(IL10N::class);
		$language->method('t')->willReturnArgument(0);
		$service = new RewardService($commands, $items, $houses, $members, $authorization, new EffortAllocationService(), $language, $logger);
		$committed = false;
		$db->method('commit')->willReturnCallback(static function () use (&$committed, $mode): void {
			if ($mode === 'rollback') {
				throw new \RuntimeException('Synthetic commit failure');
			}
			$committed = true;
		});
		$events = [];
		$logger->expects(in_array($mode, ['denial', 'rollback'], true) ? $this->never() : $this->exactly($mode === 'replay' ? 2 : 1))
			->method('audit')->willReturnCallback(static function (string $message, array $context) use (&$events, &$committed, $method): void {
				self::assertTrue($committed);
				self::assertSame(1, $context['houseId']);
				self::assertSame(11, $context['actorId']);
				self::assertSame(match ($method) {
					'createItem' => 'reward.catalog.item_created', 'updateItem' => 'reward.catalog.item_updated',
					default => 'reward_catalog_toggled',
				}, $context['event']);
				foreach (['title', 'description', 'priceMinor', 'currentUserId', 'payload'] as $forbidden) {
					self::assertArrayNotHasKey($forbidden, $context);
				}
				$events[] = $context;
			});
		if ($mode === 'denial') {
			$db->expects($this->never())->method('commit');
			$this->expectException(ForbiddenException::class);
		} elseif ($mode === 'rollback') {
			$db->expects($this->once())->method('rollBack');
			$this->expectException(\RuntimeException::class);
		}
		$uuid = '11111111-1111-4111-8111-111111111111';
		$args = match ($method) {
			'createItem' => [1, ['title' => 'Private title', 'description' => 'Private description', 'priceMinor' => 100, 'commandId' => $uuid]],
			'updateItem' => [1, 9, ['title' => 'Private title', 'commandId' => $uuid, 'expectedVersion' => 0]],
			'setCatalogEnabled' => [1, $mode !== 'no-change', 5, $uuid],
		};
		if ($mode === 'without-description') {
			unset($args[1]['description']);
		}
		$service->{$method}(...$args);
		if ($mode === 'replay') {
			$committed = false;
			$service->{$method}(...$args);
			self::assertSame('replayed', $events[1]['outcome']);
		}
		self::assertSame(match ($method) {
			'createItem' => 'created', 'updateItem' => 'updated',
			default => $mode === 'no-change' ? 'no-change' : 'changed',
		}, $events[0]['outcome']);
	}

	public static function catalogAuditProvider(): array {
		$cases = [];
		foreach (['createItem', 'updateItem', 'setCatalogEnabled'] as $method) {
			foreach (['commit', 'replay', 'denial', 'rollback'] as $mode) {
				$cases[$method . ' ' . $mode] = [$method, $mode];
			}
		}
		$cases['gate no-change'] = ['setCatalogEnabled', 'no-change'];
		$cases['create without optional description'] = ['createItem', 'without-description'];
		return $cases;
	}
}
