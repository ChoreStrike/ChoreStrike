<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\ScheduleRevision;
use PHPUnit\Framework\TestCase;

/**
 * T1-A identity pins (CS-PLAN-072 WP1): the public occurrence key
 * `<scheduleRevisionId>:<ordinal>` with canonical numeric spelling —
 * no leading zeros, signs or whitespace; bounded ordinal parsing; and
 * identical keys for persisted and virtual representations.
 */
class OccurrenceTest extends TestCase {
	public function testPublicKeyIsCanonical(): void {
		$occurrence = new Occurrence();
		$occurrence->setScheduleRevId(12);
		$occurrence->setOrdinal(3);
		self::assertSame('12:3', $occurrence->toPublicKey());
	}

	public function testParseAcceptsCanonicalKeys(): void {
		self::assertSame([12, 3], Occurrence::parsePublicKey('12:3'));
		self::assertSame([1, 1], Occurrence::parsePublicKey('1:1'));
	}

	public function testParseRejectsNonCanonicalSpelling(): void {
		foreach (['012:3', '+12:3', '12 :3', ' 12:3', '12:03', '12:-3', 'abc:def', '12', '12:3:4', ''] as $bad) {
			try {
				Occurrence::parsePublicKey($bad);
				self::fail("non-canonical key must be rejected: '$bad'");
			} catch (\InvalidArgumentException $e) {
				// expected
			}
		}
	}

	public function testParseRejectsOutOfBoundsParts(): void {
		foreach (['0:1', '1:0', '1:' . (Occurrence::MAX_ORDINAL + 1), '-1:1'] as $bad) {
			try {
				Occurrence::parsePublicKey($bad);
				self::fail("out-of-bounds key must be rejected: '$bad'");
			} catch (\InvalidArgumentException $e) {
				// expected
			}
		}
	}

	public function testVirtualAndPersistedRepresentationsShareTheKey(): void {
		$revision = new ScheduleRevision();
		$revision->setId(7);

		$virtual = new Occurrence();
		$virtual->setScheduleRevId(7);
		$virtual->setOrdinal(2);

		$persisted = new Occurrence();
		$persisted->setId(999); // surrogate — NEVER part of the public key
		$persisted->setScheduleRevId(7);
		$persisted->setOrdinal(2);

		self::assertSame($virtual->toPublicKey(), $persisted->toPublicKey());
		self::assertSame('7:2', $persisted->toPublicKey());
	}
}
