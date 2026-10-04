<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CommandReceipt;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CommandService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CommandAuditTest extends TestCase {
	/** @dataProvider commandAuditProvider */
	public function testCommandAudit_CommitReplayAndRollback_HasNoFalseSuccess(string $action, string $mode): void {
		$db = $this->createMock(IDBConnection::class);
		$houses = $this->createMock(HouseMapper::class);
		$receipts = $this->createMock(CommandReceiptMapper::class);
		$logger = $this->createMock(AppLogger::class);
		$service = new CommandService(new TransactionExecutor($db), $houses, $receipts, $logger);
		$payload = ['reason' => 'private synthetic content'];
		$committed = false;
		$db->method('commit')->willReturnCallback(static function () use (&$committed): void {
			$committed = true;
		});
		if ($mode === 'replay') {
			$receipt = new CommandReceipt();
			$receipt->setId(5);
			$receipt->setPayloadHash($service->hashPayload(['action' => $action, 'payload' => $payload]));
			$receipt->setResultRefs([]);
			$receipts->method('findByHouseActorUuid')->willReturn($receipt);
		}
		$receipts->method('insert')->willReturnCallback(static function (CommandReceipt $receipt): CommandReceipt {
			$receipt->setId(5);
			return $receipt;
		});
		$logger->expects($mode === 'commit' ? $this->once() : $this->never())->method('audit')->with(
			'House command committed',
			$this->callback(static function (array $context) use (&$committed, $action): bool {
				return $committed && $context === [
					'event' => 'house.command.committed', 'service' => 'CommandService',
					'houseId' => 1, 'actorId' => 2, 'receiptId' => 5,
					'action' => $action, 'outcome' => 'committed',
				];
			})
		);
		if ($mode === 'rollback') {
			$db->expects($this->never())->method('commit');
			$db->expects($this->once())->method('rollBack');
			$this->expectException(\RuntimeException::class);
		}
		$executions = 0;
		$service->runCommand(1, 2, 'audit-probe', $action, $payload, static function () use ($mode, &$executions): array {
			$executions++;
			if ($mode === 'rollback') {
				throw new \RuntimeException('Synthetic failure');
			}
			return [];
		});
		self::assertSame($mode === 'replay' ? 0 : 1, $executions);
	}

	public static function commandAuditProvider(): array {
		$cases = [];
		foreach (['house.reset', 'scheduling.apply', 'occurrence.amend', 'occurrence.catch-up', 'occurrence.restart',
			'pause.request', 'pause.house', 'pause.approve', 'pause.reject', 'pause.recognize-illness', 'pause.shorten'] as $action) {
			foreach (['commit', 'replay', 'rollback'] as $mode) {
				$cases[$action . ' ' . $mode] = [$action, $mode];
			}
		}
		return $cases;
	}
}
