<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\PreferenceService;
use OCA\ChoreStrike\Service\RankingService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

// Executable fixture of the P4 app-upgrade proof (CS-PLAN-090 WP-F). It is
// copied next to the app, not into it, because `seed` runs on the installed
// source build (`0.8.0-alpha.4`) and `verify`/`postcheck` on the candidate.
// So `seed` and `verify` name app classes only where both builds have them
// and compare stored rows through the database layer; only `postcheck`
// calls services the candidate alone has. The checks of the cutover to
// derived due windows live in `upgrade-cycles.php` next to this file.
// Owned cs082 P4 targets only.
$ownedTarget = is_file('/evidence/owned') ? trim((string)file_get_contents('/evidence/owned')) : '';
$isOwnedTarget = require __DIR__ . '/owned-target.php';
if (PHP_SAPI !== 'cli' || getenv('CS_RECOVERY_REHEARSAL') !== 'true' || getenv('NEXTCLOUD_TEST_MODE') !== 'true'
	|| preg_match('/-p4-(mysql|pgsql|sqlite)(-aio)?$/D', $ownedTarget) !== 1
	|| !$isOwnedTarget($ownedTarget, getenv('DB_HOST') ?: '')) {
	throw new RuntimeException('Upgrade fixture requires its isolated owned test target');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
// Registers the app's autoloading; the source build ships no vendor tree.
\OC::$server->get(IAppManager::class)->loadApp('chorestrike');
// Nextcloud's production exception handler can return exit 0 without CLI output.
restore_exception_handler();
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/upgrade-cycles.php';

const PREFS = 'chorestrike_member_prefs';
const BEFORE = '/evidence/upgrade-before.json';
const FIXTURE = '/evidence/upgrade-fixture.json';
// What the update adds for derived due windows (CS-PLAN-091 WP-B1), for the
// two coin multipliers (WP-B5) and for the live suggestion (WP-C), in the
// order a snapshot lists its tables.
const NEW_TABLES = ['chorestrike_bonus_periods', 'chorestrike_cycle_progress', 'chorestrike_cycles', 'chorestrike_suggestion_days'];
const NEW_COLUMNS = [
	'chorestrike_houses' => ['cycles_from'],
	'chorestrike_house_users' => ['streak_base', 'longest_streak_base', 'coin_factor'],
	'chorestrike_room_tasks' => ['anchor_on', 'cycle_key', 'postpone_count', 'postpone_days', 'cycle_version', 'sleeping_since', 'wake_on'],
	'chorestrike_tasks' => ['fixed_month_day'],
	'chorestrike_completed_tasks' => ['cycle_key', 'window_start_on', 'window_end_on', 'bonus_percent', 'coin_factors'],
];

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function uuid(): string {
	return sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
		substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
}

/** The membership of a synthetic account, acting as that account. */
function member(int $houseId, string $userId): HouseUser {
	$user = \OC::$server->get(IUserManager::class)->get($userId);
	check($user !== null, 'Synthetic account missing: ' . $userId);
	\OC::$server->get(IUserSession::class)->setUser($user);
	$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $userId);
	check($member !== null, 'Synthetic membership missing: ' . $userId);
	return $member;
}

function save(int $houseId, string $userId, int $expectedVersion, array $fields): array {
	return \OC::$server->get(PreferenceService::class)->updateOwnPreferences(
		$houseId, member($houseId, $userId), ['expectedVersion' => $expectedVersion] + $fields, uuid());
}

/**
 * Preference rows as the source build writes them: an "off" nobody chose
 * (the column default of a first save), an "on" with sound, a deliberate
 * "off" and a row that only ranking consent created. `grandma` and
 * `friend` keep no row.
 */
function seedPreferences(int $houseId): void {
	save($houseId, 'mom', 0, ['taskDigestEnabled' => false]);
	save($houseId, 'dad', 0, ['animationEnabled' => true]);
	save($houseId, 'dad', 1, ['soundEnabled' => true]);
	save($houseId, 'teen', 0, ['animationEnabled' => false, 'quietStart' => '21:00', 'quietEnd' => '07:00']);
	\OC::$server->get(RankingService::class)->consent($houseId, member($houseId, 'kid'));
}

function appendPause(int $houseId, string $userId, string $state, int $fromDays, int $toDays, ?int $group = null, string $scope = Pause::SCOPE_PERSONAL): int {
	$mapper = \OC::$server->get(PauseMapper::class);
	$member = member($houseId, $userId);
	$now = new DateTime();
	$pause = new Pause();
	$pause->setHouseId($houseId);
	$pause->setPauseGroupId($group ?? $mapper->maxGroupId());
	$pause->setScope($scope);
	if ($scope === Pause::SCOPE_PERSONAL) {
		$pause->setSubjectWalletId((int)$member->getWalletId());
	}
	$pause->setRequesterHouseUserId((int)$member->getId());
	$pause->setState($state);
	$pause->setReasonCode(Pause::REASON_TRAVEL);
	$pause->setStartDate(new DateTime(gmdate('Y-m-d', strtotime($fromDays . ' days'))));
	$pause->setEndDate(new DateTime(gmdate('Y-m-d', strtotime($toDays . ' days'))));
	$pause->setTimezone('Europe/Berlin');
	$pause->setCreatedAt($now);
	$pause->setUpdatedAt($now);
	return (int)$mapper->appendRevision($pause)->getPauseGroupId();
}

/**
 * Pause rows in every stored state, as revisions the source build could
 * hold: acknowledged (running, ended, household), a pending request, a
 * declined shortening, and the two rows the departure rule cancels in
 * place (a first request and a pending shortening, D-090-21).
 */
function seedPauses(int $houseId): void {
	appendPause($houseId, 'dad', Pause::STATE_ACKNOWLEDGED, -1, 4);
	appendPause($houseId, 'grandma', Pause::STATE_ACKNOWLEDGED, -20, -15);
	appendPause($houseId, 'mom', Pause::STATE_ACKNOWLEDGED, 40, 47, null, Pause::SCOPE_HOUSE);
	appendPause($houseId, 'teen', Pause::STATE_REQUESTED, 10, 12);
	$declined = appendPause($houseId, 'teen', Pause::STATE_ACKNOWLEDGED, 20, 26);
	appendPause($houseId, 'teen', Pause::STATE_REJECTED, 20, 23, $declined);
	$shortened = appendPause($houseId, 'kid', Pause::STATE_ACKNOWLEDGED, 20, 26);
	appendPause($houseId, 'kid', Pause::STATE_REQUESTED, 20, 23, $shortened);
	appendPause($houseId, 'kid', Pause::STATE_REQUESTED, 30, 32);
	$cancelled = \OC::$server->get(PauseMapper::class)
		->cancelPendingPersonalForSubject($houseId, (int)member($houseId, 'kid')->getWalletId());
	check($cancelled === 2, 'The departure rule must cancel both pending rows in place');
}

/** @param list<array<string, mixed>> $rows */
function inStableOrder(array $rows): array {
	usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
	return $rows;
}

/** The day of a stored date; SQLite may keep a midnight suffix. */
function day(mixed $value): ?string {
	return $value === null ? null : substr((string)$value, 0, 10);
}

/**
 * @return array<string, list<array<string, mixed>>> Every app table of the running build and the app config, rows in a stable order.
 */
function snapshot(): array {
	$db = \OC::$server->get(IDBConnection::class);
	$tables = (new ReflectionClass(TestDataService::class))->getConstant('RESET_TABLES');
	sort($tables);
	$result = [];
	foreach ($tables as $table) {
		$qb = $db->getQueryBuilder();
		$result[$table] = $qb->select('*')->from($table)->executeQuery()->fetchAll();
	}
	// The app config, except the installed version the update must change.
	$qb = $db->getQueryBuilder();
	$result['appconfig'] = $qb->select('configkey', 'configvalue')->from('appconfig')
		->where($qb->expr()->eq('appid', $qb->createNamedParameter('chorestrike')))
		->andWhere($qb->expr()->neq('configkey', $qb->createNamedParameter('installed_version')))
		->executeQuery()->fetchAll();
	// One representation for the stored and the fresh snapshot.
	return json_decode(json_encode(array_map(inStableOrder(...), $result), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Room tasks on the rules the family seed lacks, created through the
 * source build's own creation service: a fixed weekly rule, a fixed
 * monthly rule and a one-time task. The cutover anchors a fixed rule on
 * its revision and gives the monthly definition its day.
 */
function seedRules(int $houseId, int $roomId): void {
	$mom = member($houseId, 'mom');
	foreach ([
		['name' => 'Upgrade proof: fixed weekly', 'frequencyType' => 'weekly', 'schedulingMode' => 'static'],
		['name' => 'Upgrade proof: fixed monthly', 'frequencyType' => 'monthly', 'schedulingMode' => 'static'],
		['name' => 'Upgrade proof: one-time', 'recurrenceMode' => 'once'],
	] as $definition) {
		\OC::$server->get(TaskCreationService::class)->create($houseId, $mom,
			['task' => $definition, 'roomId' => $roomId, 'assigneeUserIds' => ['mom'], 'nextDue' => gmdate('Y-m-d', strtotime('2 days'))]);
	}
}

function seed(): string {
	$family = \OC::$server->get(TestDataService::class)->seedFamily(true)['data'];
	$houseId = (int)$family['house']['id'];
	seedRules($houseId, (int)$family['rooms'][0]['id']);
	seedPreferences($houseId);
	seedPauses($houseId);
	$before = snapshot();
	check(count($before) === 31, 'Expected the 30 app tables of the source build and the app config');
	check(count($before[PREFS]) === 4, 'Expected four preference rows');
	$states = array_count_values(array_column($before['chorestrike_pauses'], 'state'));
	check(($states['cancelled'] ?? 0) === 2 && isset($states['acknowledged'], $states['rejected'], $states['requested']),
		'Pause rows do not cover every stored state');
	foreach (['chorestrike_houses', 'chorestrike_house_users', 'chorestrike_room_tasks', 'chorestrike_occurrences',
		'chorestrike_completed_tasks', 'chorestrike_wallets', 'chorestrike_commands'] as $table) {
		check(count($before[$table]) > 0, 'Populated source is missing rows in ' . $table);
	}
	file_put_contents(FIXTURE, json_encode(['houseId' => $houseId], JSON_THROW_ON_ERROR));
	file_put_contents(BEFORE, json_encode($before, JSON_THROW_ON_ERROR));
	return count($before[PREFS]) . ' preference rows, ' . count($before['chorestrike_pauses']) . ' pause rows';
}

/**
 * The expected changes and nothing else. The animation is on in every
 * preference row and each version is one higher (D-090-16, D-090-20).
 * The update adds the two cycle tables and the bonus-period table, all
 * empty, and the columns of the cutover and of the coin multipliers
 * (CS-PLAN-091 WP-B1 and WP-B5), whose values `verifyCutover` checks. Every column
 * the source build had, every other table and the app config are
 * identical, and no preference row appeared for a member who had none.
 */
function verify(): string {
	$before = json_decode((string)file_get_contents(BEFORE), true, 512, JSON_THROW_ON_ERROR);
	$after = snapshot();
	check(array_values(array_diff(array_keys($after), array_keys($before))) === NEW_TABLES
		&& array_diff(array_keys($before), array_keys($after)) === [], 'The update must add the cycle, bonus-period and suggestion-day tables and no other');
	foreach (NEW_TABLES as $table) {
		check($after[$table] === [], 'The update wrote rows into ' . $table);
	}
	foreach ($before as $table => $rows) {
		$added = array_flip(NEW_COLUMNS[$table] ?? []);
		foreach ($after[$table] as $row) {
			check(count(array_intersect_key($row, $added)) === count($added), 'A column of the update is missing in ' . $table);
		}
		$kept = inStableOrder(array_map(static fn (array $row): array => array_diff_key($row, $added), $after[$table]));
		check($table === PREFS || $rows === $kept, 'Stored rows changed in ' . $table);
	}
	$cutover = verifyCutover($before, $after);
	$old = array_column($before[PREFS], null, 'id');
	$new = array_column($after[PREFS], null, 'id');
	ksort($old);
	ksort($new);
	check(array_keys($old) === array_keys($new), 'A preference row appeared or disappeared');
	$switched = 0;
	foreach ($old as $id => $row) {
		check((bool)$new[$id]['animation_enabled'], 'Animation still off in preference row ' . $id);
		check((int)$new[$id]['version'] === (int)$row['version'] + 1, 'Version not bumped by one in preference row ' . $id);
		$switched += (bool)$row['animation_enabled'] ? 0 : 1;
		unset($row['animation_enabled'], $row['version'], $new[$id]['animation_enabled'], $new[$id]['version']);
		check($row === $new[$id], 'Another column changed in preference row ' . $id);
	}
	check($switched === 3, 'The populated source must hold three rows with the animation off');
	return count($old) . ' preference rows on (' . $switched . ' switched), versions +1; '
		. (count($before) - 1) . ' other row sets identical in every stored column, ' . count($before['chorestrike_pauses'])
		. ' pause rows among them; ' . count(NEW_TABLES) . ' empty tables added; ' . $cutover;
}

/** Reads and writes through the candidate's services on the upgraded copy. */
function postcheck(): string {
	$houseId = (int)json_decode((string)file_get_contents(FIXTURE), true, 512, JSON_THROW_ON_ERROR)['houseId'];
	$cycles = postcheckCycles($houseId);
	$preferences = \OC::$server->get(PreferenceService::class);

	// A member without a row gets the default, and switching it off as a
	// first save holds.
	$fresh = $preferences->getOwnPreferences($houseId, member($houseId, 'friend'));
	check($fresh['version'] === 0 && $fresh['animationEnabled'] === true && $fresh['soundEnabled'] === false,
		'A member without a row does not read the default');
	$off = save($houseId, 'friend', 0, ['animationEnabled' => false]);
	check($off['animationEnabled'] === false, 'A first save that switches the animation off did not hold');
	// A first save of another field keeps it on.
	check(save($houseId, 'grandma', 0, ['soundEnabled' => true])['animationEnabled'] === true,
		'A first save of another field switched the animation off');

	// A settings page that was open during the update is stale.
	try {
		save($houseId, 'mom', 1, ['animationEnabled' => false]);
		check(false, 'The version before the update was still accepted');
	} catch (ConflictException) {
	}
	$current = $preferences->getOwnPreferences($houseId, member($houseId, 'mom'));
	check($current['animationEnabled'] === true && $current['version'] === 2, 'The migrated row is not on at version 2');
	$again = save($houseId, 'mom', 2, ['animationEnabled' => false]);
	check($again['animationEnabled'] === false && $again['version'] === 3, 'Switching the animation off after the update did not hold');
	return 'default on without a row; off as a first save and after the update holds; pre-update version conflicts; ' . $cycles;
}

$mode = $argv[1] ?? '';
$detail = match ($mode) {
	'seed' => seed(),
	'verify' => verify(),
	'postcheck' => postcheck(),
	default => throw new InvalidArgumentException('Expected seed, verify or postcheck'),
};
echo 'Upgrade fixture ' . $mode . ': PASS (' . $detail . ")\n";
