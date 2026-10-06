<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

return static function (string $ownedTarget, string $dbHost): bool {
	if (preg_match('/^cs081-recovery-[a-f0-9]+$/D', $ownedTarget) === 1) {
		return $dbHost === $ownedTarget . '-db';
	}
	if (preg_match('/^cs082-[a-z0-9][a-z0-9-]*-(p1|p2)-(mysql|pgsql|sqlite)(-aio)?$/D', $ownedTarget, $m) === 1) {
		return $m[2] === 'sqlite' ? $dbHost === '' : $dbHost === 'db-' . $m[2];
	}
	if (preg_match('/^cs082-[a-z0-9][a-z0-9-]*-p3-pgsql$/D', $ownedTarget) === 1) {
		return $dbHost === $ownedTarget . '-db';
	}
	return false;
};
