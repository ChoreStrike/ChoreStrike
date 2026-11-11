<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;

class RecoveryOwnershipTest extends TestCase {
	/** @dataProvider ownedTargets */
	public function testOwnedTarget_ExactShape_RefusesForeignTargets(string $target, string $host, bool $expected): void {
		$isOwnedTarget = require dirname(__DIR__, 2) . '/recovery/owned-target.php';
		$this->assertSame($expected, $isOwnedTarget($target, $host));
	}

	public static function ownedTargets(): array {
		return [
			['cs081-recovery-ab12cd34', 'cs081-recovery-ab12cd34-db', true],
			['cs081-recovery-ab12cd34', 'other-db', false],
			['cs082-abcd1234-p1-pgsql', 'db-pgsql', true],
			['cs082-abcd1234-p2-sqlite', '', true],
			['cs082-abcd1234-p2-pgsql-aio', 'db-pgsql', true],
			['cs082-abcd1234-p2-mysql', 'db-mysql', true],
			['cs082-abcd1234-p2-mysql', 'db-pgsql', false],
			['cs082-abcd1234-p1-pgsql', '', false],
			['cs082-abcd1234-p3-pgsql', 'cs082-abcd1234-p3-pgsql-db', true],
			['cs082-abcd1234-p3-pgsql', 'db-pgsql', false],
			['cs082-abcd1234-p4-pgsql', 'cs082-abcd1234-p4-pgsql-db', true],
			['cs082-abcd1234-p4-pgsql-aio', 'cs082-abcd1234-p4-pgsql-aio-db', true],
			['cs082-abcd1234-p4-mysql', 'cs082-abcd1234-p4-mysql-db', true],
			['cs082-abcd1234-p4-sqlite', '', true],
			['cs082-abcd1234-p4-sqlite', 'cs082-abcd1234-p4-sqlite-db', false],
			['cs082-abcd1234-p4-pgsql', 'db-pgsql', false],
			['cs082-abcd1234-p4-pgsql', '', false],
			['CS082-abcd1234-p1-pgsql', 'db-pgsql', false],
			["cs082-abcd1234-p1-pgsql\n", 'db-pgsql', false],
			['cs082-abcd1234-p1-pgsql', 'DB-PGSQL', false],
			['production', 'db-pgsql', false],
			['', 'db-pgsql', false],
		];
	}

	/** @dataProvider fixtures */
	public function testFixture_InvalidTarget_RefusesBeforeBootstrap(string $file, string $message): void {
		$fixture = dirname(__DIR__, 2) . '/recovery/' . $file;
		$command = 'CS_RECOVERY_REHEARSAL=true NEXTCLOUD_TEST_MODE=true DB_HOST=foreign-db '
			. escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' seed 2>&1';
		exec($command, $output, $exit);
		$this->assertNotSame(0, $exit);
		$this->assertStringContainsString($message, implode("\n", $output));
		$this->assertStringNotContainsString('lib/base.php', implode("\n", $output));
	}

	public static function fixtures(): array {
		return [
			['fixture.php', 'Recovery fixture requires its isolated owned test target'],
			['upgrade-fixture.php', 'Upgrade fixture requires its isolated owned test target'],
		];
	}
}
