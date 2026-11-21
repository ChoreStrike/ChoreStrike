<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\CycleReceipt;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The receipt of a cycle command (ADR-021 decisions 4, 6 and 7): one
 * boundary validation for the complete, postpone and sleep commands.
 */
class CycleReceiptTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
	}

	public function testFromPayload_ReadsTheReceipt_AndLowersTheCommandId(): void {
		$receipt = CycleReceipt::fromPayload(
			['commandId' => strtoupper(self::UUID), 'cycleKey' => 'c88', 'expectedVersion' => 4],
			$this->l10n,
			CycleReceipt::KEY_REQUIRED
		);

		$this->assertSame([self::UUID, 'c88', 4], [$receipt->commandId, $receipt->cycleKey, $receipt->expectedVersion]);
	}

	/** @return array<string, array{mixed, int}> */
	public static function versions(): array {
		return [
			'zero' => [0, 0],
			'a whole number' => [12, 12],
			// A form body carries the number as its canonical decimal string.
			'its decimal string' => ['12', 12],
		];
	}

	/** @dataProvider versions */
	public function testFromPayload_TakesAWholeVersion(mixed $version, int $expected): void {
		$receipt = CycleReceipt::fromPayload(['commandId' => self::UUID, 'expectedVersion' => $version], $this->l10n, CycleReceipt::KEY_NONE);

		$this->assertSame($expected, $receipt->expectedVersion);
	}

	/** @return array<string, array{mixed}> */
	public static function noVersions(): array {
		return [
			'absent' => [null],
			'negative' => [-1],
			'a negative string' => ['-1'],
			'a fraction' => [4.5],
			'a float that happens to be whole' => [4.0],
			'a padded string' => ['04'],
			'a string with a space' => ['4 '],
			'an exponent' => ['1e2'],
			'text' => ['four'],
			'a boolean' => [true],
			'a list' => [[4]],
		];
	}

	/** @dataProvider noVersions */
	public function testFromPayload_RefusesAnythingElseAsVersion(mixed $version): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A valid expectedVersion is required');

		CycleReceipt::fromPayload(['commandId' => self::UUID, 'expectedVersion' => $version], $this->l10n, CycleReceipt::KEY_NONE);
	}

	public function testFromPayload_AnOptionalKeyMayBeAbsent_ButNeverMalformed(): void {
		$body = ['commandId' => self::UUID, 'expectedVersion' => 2];

		// A bonus or sleeping task has no cycle to name.
		$this->assertNull(CycleReceipt::fromPayload($body, $this->l10n, CycleReceipt::KEY_OPTIONAL)->cycleKey);
		$this->assertSame('d2026-11-18', CycleReceipt::fromPayload($body + ['cycleKey' => 'd2026-11-18'], $this->l10n, CycleReceipt::KEY_OPTIONAL)->cycleKey);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid cycle key');
		CycleReceipt::fromPayload($body + ['cycleKey' => 'p2026-11-25'], $this->l10n, CycleReceipt::KEY_OPTIONAL);
	}

	/** @return array<string, array{array<string, mixed>, string, list<string>, string}> */
	public static function refusedBodies(): array {
		$valid = ['commandId' => self::UUID, 'expectedVersion' => 2];
		return [
			'a required key that is absent' => [$valid, CycleReceipt::KEY_REQUIRED, [], 'Invalid cycle key'],
			'a key that is no text' => [$valid + ['cycleKey' => 88], CycleReceipt::KEY_REQUIRED, [], 'Invalid cycle key'],
			'a key where the command names none' => [$valid + ['cycleKey' => 'c88'], CycleReceipt::KEY_NONE, [], 'Unknown field: cycleKey'],
			'a field the command does not have' => [$valid + ['wakeOn' => '2026-12-01'], CycleReceipt::KEY_NONE, ['sleeping'], 'Unknown field: wakeOn'],
			'no command id' => [['expectedVersion' => 2], CycleReceipt::KEY_NONE, [], 'A valid commandId (UUID) is required'],
			'a command id that is no UUID' => [['commandId' => 'nope'] + $valid, CycleReceipt::KEY_NONE, [], 'A valid commandId (UUID) is required'],
		];
	}

	/** @dataProvider refusedBodies */
	public function testFromPayload_RefusesWithTheRuleThatApplies(array $payload, string $key, array $further, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		CycleReceipt::fromPayload($payload, $this->l10n, $key, $further);
	}

	public function testFromPayload_TakesTheFurtherFieldsOfItsCommand(): void {
		$receipt = CycleReceipt::fromPayload(
			['commandId' => self::UUID, 'expectedVersion' => 2, 'sleeping' => true, 'wakeOn' => null],
			$this->l10n,
			CycleReceipt::KEY_NONE,
			['sleeping', 'wakeOn']
		);

		$this->assertSame(2, $receipt->expectedVersion);
	}

	/** @return array<string, array{mixed}> */
	public static function versionsCommandServiceRefuses(): array {
		return [
			'absent' => [null],
			'negative' => [-1],
			'a padded string' => ['04'],
			'a float' => [4.0],
			'a float string' => ['4.0'],
			'a signed string' => ['+4'],
			'a negative string' => ['-1'],
			'a word' => ['four'],
			'a boolean' => [true],
		];
	}

	/**
	 * One rule for the version a client showed (RF-091-10): a whole number
	 * or its canonical decimal string, for every command surface.
	 *
	 * @dataProvider versionsCommandServiceRefuses
	 */
	public function testRequireExpectedVersion_RefusesWhatIsNoWholeNumber(mixed $version): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A valid expectedVersion is required');

		CommandService::requireExpectedVersion($version === null ? [] : ['expectedVersion' => $version], $this->l10n);
	}

	public function testRequireExpectedVersion_TakesTheNumberAndItsString(): void {
		$this->assertSame([0, 7, 7], [
			CommandService::requireExpectedVersion(['expectedVersion' => 0], $this->l10n),
			CommandService::requireExpectedVersion(['expectedVersion' => 7], $this->l10n),
			CommandService::requireExpectedVersion(['expectedVersion' => '7'], $this->l10n),
		]);
	}

	public function testAssertClosedBody_NamesTheFirstUnknownField(): void {
		CommandService::assertClosedBody(['commandId' => self::UUID, 'factor' => 2], ['commandId', 'factor'], $this->l10n);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown field: days');
		CommandService::assertClosedBody(['commandId' => self::UUID, 'days' => 3], ['commandId', 'factor'], $this->l10n);
	}
}
