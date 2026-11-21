<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\SubTaskList;
use OCA\ChoreStrike\Service\TaskEdit;
use OCA\ChoreStrike\Service\TaskFormPayload;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The boundary validation of the one form (CS-PLAN-091 WP-B6): its
 * sub-tasks ({@see SubTaskList}), its fields ({@see TaskFormPayload}) and
 * the receipt and scope of a save ({@see TaskEdit}). Every refusal is a
 * translated 400.
 */
class TaskFormTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private IL10N $l10n;
	private CalendarService $calendar;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		$this->calendar = new CalendarService();
	}

	private function day(int $offset): string {
		return (new \DateTime('today', new \DateTimeZone('UTC')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
	}

	// ------------------------------------------------------------------
	// SubTaskList
	// ------------------------------------------------------------------

	public function testSubTasks_KeepTheirOrder_TrimTheirLabels_AndNameTheKeptIds(): void {
		$list = SubTaskList::fromPayload([['id' => 4, 'label' => ' Carpet '], ['label' => 'Sofa'], ['id' => 2, 'label' => 'Rug']], $this->l10n);

		$this->assertSame([
			['id' => 4, 'label' => 'Carpet'],
			['id' => null, 'label' => 'Sofa'],
			['id' => 2, 'label' => 'Rug'],
		], $list->entries);
		$this->assertSame([4, 2], $list->keptIds());
		$this->assertSame([], SubTaskList::fromPayload([], $this->l10n)->entries, 'an empty list removes every sub-task');
	}

	/** @return array<string, array{mixed, string}> */
	public static function unusableSubTasks(): array {
		$shape = 'Invalid value type for field: checklistItems';
		return [
			'not a list' => [['a' => ['label' => 'Carpet']], $shape],
			'a string' => ['Carpet', $shape],
			'an entry that is no object' => [['Carpet'], $shape],
			'an unknown key' => [[['label' => 'Carpet', 'done' => true]], $shape],
			'an id that is no positive integer' => [[['id' => '4', 'label' => 'Carpet']], $shape],
			'a zero id' => [[['id' => 0, 'label' => 'Carpet']], $shape],
			'a label that is no string' => [[['label' => 7]], $shape],
			'no label' => [[['id' => 4]], $shape],
			'an id twice' => [[['id' => 4, 'label' => 'Carpet'], ['id' => 4, 'label' => 'Rug']], $shape],
			'a blank label' => [[['label' => '   ']], 'A sub-task needs a label'],
			'a label too long' => [[['label' => str_repeat('x', 256)]], 'A sub-task has at most 255 characters'],
			'too many' => [array_fill(0, 51, ['label' => 'x']), 'A task takes at most 50 sub-tasks'],
		];
	}

	/** @dataProvider unusableSubTasks */
	public function testSubTasks_Refused(mixed $raw, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		SubTaskList::fromPayload($raw, $this->l10n);
	}

	public function testSubTasks_AtTheLimits_AreTaken(): void {
		$this->assertCount(50, SubTaskList::fromPayload(array_fill(0, 50, ['label' => str_repeat('x', 255)]), $this->l10n)->entries);
	}

	// ------------------------------------------------------------------
	// TaskFormPayload
	// ------------------------------------------------------------------

	public function testFormPayload_ReadsEveryField_AndLeavesWhatIsAbsentAsItIs(): void {
		$form = TaskFormPayload::fromPayload([
			'task' => ['name' => 'Vacuum'],
			'roomId' => 5,
			'assigneeUserIds' => ['kim', 'mia', 'kim'],
			'checklistItems' => [['label' => 'Carpet']],
			'firstDate' => $this->day(3),
			'sleeping' => true,
			'wakeOn' => $this->day(30),
		], $this->calendar, $this->l10n);

		$this->assertSame(['name' => 'Vacuum'], $form->definition);
		$this->assertSame(5, $form->roomId);
		$this->assertSame(['kim', 'mia'], $form->assigneeUserIds, 'unique, in the order sent');
		$this->assertSame([['id' => null, 'label' => 'Carpet']], $form->subTasks?->entries);
		$this->assertSame([$this->day(3), true, $this->day(30)], [$form->firstDate, $form->sleeping, $form->wakeOn]);

		$empty = TaskFormPayload::fromPayload([], $this->calendar, $this->l10n);
		$this->assertSame([null, null, null, null, null, null, null], [
			$empty->definition, $empty->roomId, $empty->assigneeUserIds, $empty->subTasks, $empty->firstDate, $empty->sleeping, $empty->wakeOn,
		]);
		$this->assertSame([], TaskFormPayload::fromPayload(['assigneeUserIds' => []], $this->calendar, $this->l10n)->assigneeUserIds, 'an empty list unassigns');
		$this->assertNull(TaskFormPayload::fromPayload(['firstDate' => ''], $this->calendar, $this->l10n)->firstDate, 'an empty first date is none');
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function unusableForms(): array {
		return [
			'a definition that is a list' => [['task' => ['Vacuum']], 'task must be an object'],
			'a definition that is a string' => [['task' => 'Vacuum'], 'task must be an object'],
			'a room id as a string' => [['roomId' => '5'], 'Invalid value type for field: roomId'],
			'a room id of zero' => [['roomId' => 0], 'Invalid value type for field: roomId'],
			'assignees as an object' => [['assigneeUserIds' => ['a' => 'kim']], 'assigneeUserIds must be an array of user IDs'],
			'a blank assignee' => [['assigneeUserIds' => ['kim', ' ']], 'assigneeUserIds must be an array of user IDs'],
			'an assignee that is no string' => [['assigneeUserIds' => [7]], 'assigneeUserIds must be an array of user IDs'],
			'a first date that is no day' => [['firstDate' => '2026-02-30'], 'Invalid firstDate date format'],
			'a first date as an instant' => [['firstDate' => '2026-09-14T00:00:00Z'], 'Invalid firstDate date format'],
			'sleeping as a string' => [['sleeping' => 'yes'], 'sleeping must be a boolean'],
			'a wake date that is no day' => [['sleeping' => true, 'wakeOn' => '2026-13-01'], 'A valid date (Y-m-d) is required'],
			'a wake date without sleeping' => [['wakeOn' => '2099-01-01'], 'A wake date needs a sleeping task'],
			'a wake date while waking' => [['sleeping' => false, 'wakeOn' => '2099-01-01'], 'A wake date needs a sleeping task'],
			'sub-tasks of the wrong shape' => [['checklistItems' => 'Carpet'], 'Invalid value type for field: checklistItems'],
		];
	}

	/** @dataProvider unusableForms */
	public function testFormPayload_Refused(array $payload, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		TaskFormPayload::fromPayload($payload, $this->calendar, $this->l10n);
	}

	/**
	 * The range of a first date is checked where the date is a new choice
	 * (creation, a changed date of an edit), not at the boundary: the form
	 * sends the stored date of a task that was never done with every save.
	 */
	public function testFormPayload_FirstDateRange_IsAskedForNotImposed(): void {
		$old = TaskFormPayload::fromPayload(['firstDate' => $this->day(-500)], $this->calendar, $this->l10n);
		$this->assertSame($this->day(-500), $old->firstDate, 'the boundary takes any real day');

		TaskFormPayload::fromPayload([], $this->calendar, $this->l10n)->assertFirstDateInRange($this->l10n);
		TaskFormPayload::fromPayload(['firstDate' => $this->day(366)], $this->calendar, $this->l10n)->assertFirstDateInRange($this->l10n);
		TaskFormPayload::fromPayload(['firstDate' => $this->day(-366)], $this->calendar, $this->l10n)->assertFirstDateInRange($this->l10n);

		foreach ([$this->day(367), $this->day(-367), '9999-12-31'] as $day) {
			try {
				TaskFormPayload::fromPayload(['firstDate' => $day], $this->calendar, $this->l10n)->assertFirstDateInRange($this->l10n);
				$this->fail('a first date of ' . $day . ' must be refused');
			} catch (\InvalidArgumentException $e) {
				$this->assertSame('The first date must lie within one year of today', $e->getMessage());
			}
		}
	}

	/** The sleep command must send `sleeping`; the form may leave it out. */
	public function testSleepSwitch_RequiredForTheCommand_OptionalForTheForm(): void {
		$form = \OCA\ChoreStrike\Service\SleepSwitch::fromPayload([], $this->calendar, $this->l10n, false);
		$this->assertSame([null, null], [$form->sleeping, $form->wakeOn]);
		$until = \OCA\ChoreStrike\Service\SleepSwitch::fromPayload(['sleeping' => true, 'wakeOn' => '2099-01-01'], $this->calendar, $this->l10n, true);
		$this->assertSame([true, '2099-01-01'], [$until->sleeping, $until->wakeOn]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('sleeping must be a boolean');
		\OCA\ChoreStrike\Service\SleepSwitch::fromPayload([], $this->calendar, $this->l10n, true);
	}

	public function testFormPayload_IdentityNamesEveryField(): void {
		$form = TaskFormPayload::fromPayload(['task' => ['name' => 'Vacuum'], 'checklistItems' => [['label' => 'Carpet']]], $this->calendar, $this->l10n);

		$this->assertSame([
			'task' => ['name' => 'Vacuum'], 'roomId' => null, 'assigneeUserIds' => null,
			'checklistItems' => [['id' => null, 'label' => 'Carpet']], 'firstDate' => null,
			'sleeping' => null, 'wakeOn' => null,
		], $form->identity());
	}

	// ------------------------------------------------------------------
	// TaskEdit
	// ------------------------------------------------------------------

	public function testEdit_ReadsTheReceiptTheScopeAndTheForm(): void {
		$edit = TaskEdit::fromPayload([
			'commandId' => strtoupper(self::UUID), 'expectedVersion' => '4', 'scope' => TaskEdit::SCOPE_THIS_ROOM,
			'task' => ['name' => 'Vacuum'], 'roomId' => 5,
		], $this->calendar, $this->l10n);

		$this->assertSame([self::UUID, 4, TaskEdit::SCOPE_THIS_ROOM], [$edit->commandId, $edit->expectedVersion, $edit->scope]);
		$this->assertSame(['name' => 'Vacuum'], $edit->form->definition);
		$this->assertSame(5, $edit->form->roomId);
		$this->assertSame(['expectedVersion' => 4, 'scope' => TaskEdit::SCOPE_THIS_ROOM], array_slice($edit->identity(), 0, 2, true));
		$this->assertNull(TaskEdit::fromPayload(['commandId' => self::UUID, 'expectedVersion' => 0], $this->calendar, $this->l10n)->scope);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function unusableEdits(): array {
		$receipt = ['commandId' => self::UUID, 'expectedVersion' => 4];
		return [
			'an unknown field' => [$receipt + ['nextDue' => '2026-12-01'], 'Unknown field: nextDue'],
			'a cycle key' => [$receipt + ['cycleKey' => 'c88'], 'Unknown field: cycleKey'],
			'no command id' => [['expectedVersion' => 4], 'A valid commandId (UUID) is required'],
			'no version' => [['commandId' => self::UUID], 'A valid expectedVersion is required'],
			'an unknown scope' => [$receipt + ['scope' => 'everywhere'], 'Invalid value type for field: scope'],
			'a form field that cannot be' => [$receipt + ['roomId' => 'kitchen'], 'Invalid value type for field: roomId'],
		];
	}

	/** @dataProvider unusableEdits */
	public function testEdit_Refused(array $payload, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		TaskEdit::fromPayload($payload, $this->calendar, $this->l10n);
	}
}
