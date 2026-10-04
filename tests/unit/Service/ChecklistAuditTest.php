<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ChecklistAuditTest extends TestCase {
	/** @dataProvider mutationProvider */
	public function testDefinitionMutation_CommitDenialAndRollback_HasNoFalseSuccess(string $method, string $writer, string $mode): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(AppLogger::class);
		$authorization = $this->createMock(AuthorizationService::class);
		$owner = new HouseUser();
		$owner->setId(7);
		$owner->setRole('owner');
		if ($mode === 'denial') {
			$authorization->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		} else {
			$authorization->method('requireHouseOwner')->willReturn($owner);
		}
		$task = new Task();
		$task->setId(10);
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('find')->willReturn($task);
		$item = new TaskChecklistItem();
		$item->setId(11);
		$item->setTaskId(10);
		$items = $this->createMock(TaskChecklistItemMapper::class);
		$items->method('find')->willReturn($item);
		$items->method('findByIds')->willReturnOnConsecutiveCalls([$item], $mode === 'item-disappeared' ? [] : [$item]);
		$items->method('create')->willReturn($item);
		$items->method('updateLabel')->willReturn($item);
		$items->expects(in_array($mode, ['denial', 'visibility-revoked', 'item-disappeared'], true) ? $this->never() : $this->once())->method($writer);
		$policy = $this->createMock(TaskAccessPolicy::class);
		$reads = 0;
		$policy->method('canViewTask')->willReturnCallback(static function () use (&$reads, $mode): bool {
			return ++$reads === 1 || $mode !== 'visibility-revoked';
		});
		$committed = false;
		$db->method('commit')->willReturnCallback(static function () use (&$committed, $mode): void {
			if ($mode === 'rollback') {
				throw new \RuntimeException('Synthetic commit failure');
			}
			$committed = true;
		});
		$logger->expects($mode === 'commit' ? $this->once() : $this->never())->method('audit')->with(
			$this->anything(), $this->callback(static function (array $context) use (&$committed): bool {
				return $committed && $context['actorId'] === 7 && $context['houseId'] === 1
					&& str_starts_with($context['event'], 'house.checklist.')
					&& !isset($context['label'], $context['currentUserId']);
			})
		);
		$language = $this->createMock(IL10N::class);
		$language->method('t')->willReturnCallback(static fn (string $message): string => $message);
		$service = new ChecklistService($items, $this->createMock(RoomTaskChecklistProgressMapper::class),
			$this->createMock(RoomTaskMapper::class), $tasks, $this->createMock(HouseMapper::class),
			new TransactionExecutor($db), $this->createMock(RoomTaskService::class),
			$this->createMock(OccurrenceService::class), $language, $logger, $authorization, $policy);
		if ($mode !== 'commit') {
			$db->expects($this->once())->method('rollBack');
			if ($mode !== 'rollback') {
				$db->expects($this->never())->method('commit');
			}
			$this->expectException(match ($mode) {
				'denial' => ForbiddenException::class,
				'visibility-revoked', 'item-disappeared' => NotFoundException::class,
				default => \RuntimeException::class,
			});
		}
		match ($method) {
			'addTaskItem' => $service->addTaskItem(10, 'Private label', 0, 1, $owner),
			'updateTaskItem' => $service->updateTaskItem(11, 'Private relabel', 1, $owner),
			'deleteTaskItem' => $service->deleteTaskItem(11, 1, $owner),
			'reorderTaskItems' => $service->reorderTaskItems([11 => 0], 1, $owner),
		};
	}

	public static function mutationProvider(): array {
		$cases = [];
		foreach (['addTaskItem' => 'create', 'updateTaskItem' => 'updateLabel',
			'deleteTaskItem' => 'delete', 'reorderTaskItems' => 'updateSortOrders'] as $method => $writer) {
			foreach (['commit', 'denial', 'rollback', 'visibility-revoked'] as $mode) {
				$cases[$method . ' ' . $mode] = [$method, $writer, $mode];
			}
		}
		$cases['reorder fresh item disappeared'] = ['reorderTaskItems', 'updateSortOrders', 'item-disappeared'];
		return $cases;
	}

	public function testReorder_ManyItems_ChecksFreshTaskAccessOncePerDistinctTask(): void {
		$db = $this->createMock(IDBConnection::class);
		$owner = new HouseUser();
		$owner->setId(7);
		$owner->setRole('owner');
		$authorization = $this->createMock(AuthorizationService::class);
		$authorization->method('requireHouseOwner')->willReturn($owner);
		$items = $this->createMock(TaskChecklistItemMapper::class);
		$rows = [];
		$orders = [];
		foreach ([11, 12, 13, 14] as $id) {
			$item = new TaskChecklistItem();
			$item->setId($id);
			$item->setTaskId(10);
			$rows[] = $item;
			$orders[$id] = $id - 11;
		}
		$items->expects($this->exactly(2))->method('findByIds')->willReturn($rows);
		$items->expects($this->once())->method('updateSortOrders')->with($orders);
		$tasks = $this->createMock(TaskMapper::class);
		$task = new Task();
		$task->setId(10);
		$tasks->expects($this->exactly(2))->method('find')->with(10)->willReturn($task);
		$rooms = $this->createMock(RoomTaskMapper::class);
		$rooms->expects($this->exactly(2))->method('findHouseIdsForTask')->with(10)->willReturn([1]);
		$policy = $this->createMock(TaskAccessPolicy::class);
		$policy->expects($this->exactly(2))->method('canViewTask')->with($task, $owner)->willReturn(true);
		$language = $this->createMock(IL10N::class);
		$service = new ChecklistService($items, $this->createMock(RoomTaskChecklistProgressMapper::class),
			$rooms, $tasks, $this->createMock(HouseMapper::class), new TransactionExecutor($db),
			$this->createMock(RoomTaskService::class), $this->createMock(OccurrenceService::class),
			$language, $this->createMock(AppLogger::class), $authorization, $policy);
		$service->reorderTaskItems($orders, 1, $owner);
	}
}
