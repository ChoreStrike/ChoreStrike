<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CompletionAuthority;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Who may correct a history entry (ADR-021 decision 9, D-091-23): the
 * owner everything; every other member only the latest valid entry of a
 * task that they recorded, and never the people.
 */
class CompletionAuthorityTest extends TestCase {
	private CompletedTaskMapper $mapper;
	private CompletionAuthority $authority;

	protected function setUp(): void {
		$this->mapper = $this->createMock(CompletedTaskMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->authority = new CompletionAuthority($this->mapper, $l10n);
	}

	private function member(int $id, string $role = 'member'): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setRole($role);
		return $member;
	}

	private function entry(int $id, ?int $recordedBy, bool $deleted = false): CompletedTask {
		$entry = new CompletedTask();
		$entry->setId($id);
		$entry->setRoomTaskId(11);
		$entry->setUserId($recordedBy);
		if ($deleted) {
			$entry->setUndoneAt(new \DateTime('2026-11-18 08:00:00'));
		}
		return $entry;
	}

	/** @return array<string, array{string, ?int, int, ?int, bool, bool}> */
	public static function rights(): array {
		return [
			// role, recorded by, entry id, latest valid id, deleted, expected
			'own latest entry' => ['member', 7, 9, 9, false, true],
			'a child\'s own latest entry' => ['child', 7, 9, 9, false, true],
			'own older entry' => ['member', 7, 9, 10, false, false],
			'someone else\'s latest entry' => ['member', 8, 9, 9, false, false],
			'an entry whose recorder was erased' => ['member', null, 9, 9, false, false],
			'the owner, any entry' => ['owner', 8, 9, 10, false, true],
			'a deleted entry, even for the owner' => ['owner', 7, 9, null, true, false],
		];
	}

	/** @dataProvider rights */
	public function testCanCorrect(string $role, ?int $recordedBy, int $entryId, ?int $latestValidId, bool $deleted, bool $expected): void {
		$this->assertSame(
			$expected,
			$this->authority->canCorrect($this->member(7, $role), $this->entry($entryId, $recordedBy, $deleted), $latestValidId)
		);
	}

	public function testCanAttribute_IsTheOwnersAlone(): void {
		$this->assertTrue($this->authority->canAttribute($this->member(7, 'owner'), $this->entry(9, 8)));
		$this->assertFalse($this->authority->canAttribute($this->member(7), $this->entry(9, 7)), 'not even on one\'s own latest entry');
		$this->assertFalse($this->authority->canAttribute($this->member(7, 'owner'), $this->entry(9, 8, true)), 'a deleted entry has no people to change');
	}

	public function testAssertCanCorrect_ReadsTheLatestEntryOfTheTask(): void {
		$this->mapper->expects($this->exactly(2))->method('findLatestValidIdsByRoomTasks')->with([11])
			->willReturnOnConsecutiveCalls([11 => 9], [11 => 10]);

		$this->authority->assertCanCorrect($this->member(7), $this->entry(9, 7));

		$this->expectException(ForbiddenException::class);
		$this->authority->assertCanCorrect($this->member(7), $this->entry(9, 7));
	}

	public function testAssertCanCorrect_OwnerAndRepeatedDelete_NeedNoRead(): void {
		$this->mapper->expects($this->never())->method('findLatestValidIdsByRoomTasks');

		$this->authority->assertCanCorrect($this->member(7, 'owner'), $this->entry(9, 8));
		// A repeated delete of one's own entry ends as the no-op it is.
		$this->authority->assertCanCorrect($this->member(7), $this->entry(9, 7, true));
		$this->addToAssertionCount(1);
	}

	public function testAssertCanCorrect_SomeoneElsesDeletedEntry_Forbidden(): void {
		$this->expectException(ForbiddenException::class);
		$this->authority->assertCanCorrect($this->member(7), $this->entry(9, 8, true));
	}

	public function testAssertCanAttribute(): void {
		$this->authority->assertCanAttribute($this->member(7, 'owner'));

		$this->expectException(ForbiddenException::class);
		$this->authority->assertCanAttribute($this->member(7));
	}
}
