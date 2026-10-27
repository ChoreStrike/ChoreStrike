<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\RoomNamePolicy;
use PHPUnit\Framework\TestCase;

/**
 * FEAT-UX-044 (CS-PLAN-088 D-088-19): unique room names within a house.
 */
class RoomNamePolicyTest extends TestCase {
	/**
	 * @return array<string, array{string, list<string>, string}>
	 */
	public static function names(): array {
		return [
			'free name is kept, trimmed' => ['  Kitchen ', ['Bath'], 'Kitchen'],
			'duplicate gets 2' => ['Bath', ['Bath'], 'Bath 2'],
			'case and whitespace count as equal' => [' kids room ', ['Kids Room'], 'kids room 2'],
			'taken numbers are skipped' => ['Bath', ['Bath', 'bath 2', 'Bath 3'], 'Bath 4'],
			'a numbered duplicate continues its base' => ['Bath 2', ['Bath', 'Bath 2'], 'Bath 3'],
			'a number without a taken base is part of the name' => ['Room 101', ['Room 101'], 'Room 101 2'],
			'existing duplicates do not matter for a free name' => ['Hall', ['Bath', 'Bath'], 'Hall'],
			'no other rooms' => ['Bath', [], 'Bath'],
		];
	}

	/**
	 * @dataProvider names
	 * @param list<string> $taken
	 */
	public function testUnique(string $wanted, array $taken, string $expected): void {
		$this->assertSame($expected, RoomNamePolicy::unique($wanted, $taken));
	}

	public function testUnique_LongDuplicate_StaysWithinTheColumnLength(): void {
		$long = str_repeat('ä', RoomNamePolicy::MAX_LENGTH);

		$unique = RoomNamePolicy::unique($long, [$long]);

		$this->assertSame(RoomNamePolicy::MAX_LENGTH, mb_strlen($unique));
		$this->assertStringEndsWith(' 2', $unique);
	}
}
