<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\PreferenceService;
use OCA\ChoreStrike\Service\RewardRequestService;
use OCA\ChoreStrike\Service\RewardService;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

// This executable fixture is not part of the HTTP app or PHPUnit lifecycle.
// Owned targets only: the legacy cs081 rehearsal (<owned>-db), a staged cs082
// P1/P2 compose installation (fixed db-<provider> service, empty for SQLite) or
// a cs082 P3 restore rehearsal (<owned>-db). Anything else refuses before boot.
$ownedTarget = is_file('/evidence/owned') ? trim((string)file_get_contents('/evidence/owned')) : '';
$dbHost = getenv('DB_HOST') ?: '';
$isOwnedTarget = require __DIR__ . '/owned-target.php';
$owned = $isOwnedTarget($ownedTarget, $dbHost);
if (PHP_SAPI !== 'cli' || getenv('CS_RECOVERY_REHEARSAL') !== 'true'
	|| getenv('NEXTCLOUD_TEST_MODE') !== 'true' || !$owned) {
	throw new RuntimeException('Recovery fixture requires its isolated owned test target');
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
// Nextcloud's production exception handler can return exit 0 without CLI output.
// A rehearsal must fail visibly, never accept a swallowed fixture failure.
restore_exception_handler();
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function uuid(): string {
	return sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
		substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
}

function owner(int $houseId): \OCA\ChoreStrike\Db\HouseUser {
	$user = \OC::$server->get(IUserManager::class)->get('mom');
	check($user !== null, 'Synthetic owner account missing');
	\OC::$server->get(IUserSession::class)->setUser($user);
	$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'mom');
	check($member !== null, 'Synthetic owner membership missing');
	return $member;
}

function receipt(int $houseId, int $roomTaskId): array {
	$service = \OC::$server->get(OccurrenceService::class);
	$row = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);
	$selection = $service->selectionFor($row, new DateTime(), $houseId);
	check($selection !== null, 'Synthetic occurrence selection missing');
	return [
		'occurrenceId' => $selection['occurrence']->toPublicKey(),
		'expectedVersion' => $selection['occurrence']->getVersion(),
		'selectionToken' => $service->selectionToken($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, null, $selection['workAppointment'] ?? null),
	];
}

function seedWork(int $houseId, array $roomTask): int {
	$actor = owner($houseId);
	$id = (int)$roomTask['id'];
	$checklists = \OC::$server->get(ChecklistService::class);
	$item = $checklists->addTaskItem((int)$roomTask['taskId'], 'Synthetic recovery checklist', null, $houseId, $actor);
	$receipt = receipt($houseId, $id);
	\OC::$server->get(AgreementService::class)->amend($houseId, 'mom', $id,
		$receipt + ['commandId' => uuid(), 'consent' => true, 'date' => gmdate('Y-m-d')]);
	$receipt = receipt($houseId, $id);
	$checklists->toggleItem($id, $receipt['occurrenceId'], (int)$item->getId(), 0, $houseId, $actor);
	$service = \OC::$server->get(CompletionService::class);
	$result = $service->complete($houseId, 'mom', $id, uuid(), $receipt['occurrenceId'],
		$receipt['expectedVersion'], $receipt['selectionToken']);
	$completionId = (int)$result['completionId'];
	$service->attribute($houseId, $actor, $completionId, uuid(), (int)$result['rewards']['completion']['version'],
		[['userId' => 'mom', 'sharePct' => 50], ['userId' => 'dad', 'sharePct' => 50]], true, null);
	return $completionId;
}

function seedRewards(int $houseId): int {
	$house = \OC::$server->get(HouseMapper::class)->find($houseId);
	\OC::$server->get(HouseService::class)->setCatalogEnabled($houseId, true, $house->getStateVersion(), uuid());
	$item = \OC::$server->get(RewardService::class)->createItem($houseId,
		['title' => 'Synthetic recovery reservation', 'priceMinor' => 100, 'commandId' => uuid()]);
	$request = \OC::$server->get(RewardRequestService::class)->create($houseId, 'mom',
		['itemId' => $item['id'], 'itemVersion' => $item['version'], 'priceMinor' => 100, 'commandId' => uuid()]);
	check($request['wallet']['reservedMinor'] === 100, 'Synthetic reservation not funded');
	return (int)$request['id'];
}

function seed(): void {
	$data = \OC::$server->get(TestDataService::class)->seedFamily(true)['data'];
	$houseId = (int)$data['house']['id'];
	$actor = owner($houseId);
	$completionId = seedWork($houseId, $data['roomTasks'][0]);
	\OC::$server->get(PreferenceService::class)->updateOwnPreferences($houseId, $actor,
		['expectedVersion' => 0, 'taskDigestEnabled' => false], uuid());
	\OC::$server->get(PauseService::class)->requestForSelf($houseId, 'mom', [
		'commandId' => uuid(), 'startDate' => gmdate('Y-m-d', strtotime('+2 days')),
		'endDate' => gmdate('Y-m-d', strtotime('+3 days')), 'reasonCode' => 'travel',
	]);
	$requestId = seedRewards($houseId);
	$config = \OC::$server->get(IConfig::class);
	$config->setUserValue('admin', 'recovery-sentinel', 'marker', 'before-backup');
	$config->setSystemValue('recovery_marker', 'before-backup');
	file_put_contents('/var/www/html/data/recovery-sentinel.txt', 'before-backup');
	file_put_contents('/evidence/fixture.json', json_encode(compact('houseId', 'completionId', 'requestId'), JSON_THROW_ON_ERROR));
	validateFacts();
}

function validateFacts(): void {
	$ids = json_decode(file_get_contents('/evidence/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
	$actor = owner($ids['houseId']);
	$history = \OC::$server->get(CompletionService::class)->showCompletion($ids['houseId'], $actor, $ids['completionId']);
	check($history['allocationRevision'] > 1 && count($history['allocation']) === 2, 'Corrected work-share history missing');
	$request = \OC::$server->get(RewardRequestService::class)->showForViewer($ids['houseId'], $actor, $ids['requestId']);
	check($request['priceMinor'] === 100 && count($request['audit']) > 0, 'Reward terms or event history missing');
	$config = \OC::$server->get(IConfig::class);
	check($config->getUserValue('admin', 'recovery-sentinel', 'marker') === 'before-backup', 'Unrelated core sentinel changed');
	check($config->getSystemValue('recovery_marker') === 'before-backup', 'Config sentinel changed');
	check(file_get_contents('/var/www/html/data/recovery-sentinel.txt') === 'before-backup', 'Data sentinel changed');
	check(!file_exists('/var/www/html/custom_apps/chorestrike/recovery-divergence.txt'), 'Post-backup code divergence survived');
}

function snapshot(): array {
	$db = \OC::$server->get(IDBConnection::class);
	$tables = (new ReflectionClass(TestDataService::class))->getConstant('RESET_TABLES');
	sort($tables);
	$result = [];
	foreach ($tables as $table) {
		$qb = $db->getQueryBuilder();
		$rows = $qb->select('*')->from($table)->executeQuery()->fetchAll();
		$serialized = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
		sort($serialized);
		$result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $serialized))];
	}
	foreach (['houses', 'floors', 'rooms', 'tasks', 'room_tasks', 'task_assignees', 'house_users', 'member_prefs',
		'chk_items', 'chk_progress', 'schedule_revs', 'occurrences', 'occ_members', 'agreements', 'pauses',
		'completed_tasks', 'completion_revs', 'work_shares', 'room_events', 'commands', 'wallets', 'wallet_entries',
		'reward_items', 'reward_requests', 'request_events'] as $suffix) {
		check($result['chorestrike_' . $suffix]['count'] > 0, 'Required recovery fixture table is empty: ' . $suffix);
	}
	return $result;
}

function diverge(): void {
	$db = \OC::$server->get(IDBConnection::class);
	$qb = $db->getQueryBuilder();
	$qb->update('chorestrike_houses')->set('name', $qb->createNamedParameter('After backup'))->executeStatement();
	$qb = $db->getQueryBuilder();
	$qb->update('chorestrike_wallets')->set('booked_minor', $qb->createFunction('booked_minor + 1'))->executeStatement();
	$config = \OC::$server->get(IConfig::class);
	$config->setUserValue('admin', 'recovery-sentinel', 'marker', 'after-backup');
	$config->setSystemValue('recovery_marker', 'after-backup');
	file_put_contents('/var/www/html/data/recovery-sentinel.txt', 'after-backup');
	file_put_contents('/var/www/html/custom_apps/chorestrike/recovery-divergence.txt', 'after-backup');
	$before = json_decode(file_get_contents('/evidence/before.json'), true, 512, JSON_THROW_ON_ERROR);
	check(snapshot() !== $before, 'Post-backup DB divergence was not observed');
	check($config->getUserValue('admin', 'recovery-sentinel', 'marker') === 'after-backup', 'Core divergence missing');
	check(file_get_contents('/var/www/html/data/recovery-sentinel.txt') === 'after-backup', 'File divergence missing');
}

$mode = $argv[1] ?? '';
switch ($mode) {
	case 'seed':
		seed();
		file_put_contents('/evidence/before.json', json_encode(snapshot(), JSON_THROW_ON_ERROR));
		break;
	case 'diverge':
		diverge();
		break;
	case 'verify':
		$before = json_decode(file_get_contents('/evidence/before.json'), true, 512, JSON_THROW_ON_ERROR);
		check(snapshot() === $before, 'Restored retained facts differ from the recovery point');
		validateFacts();
		echo count($before) . " app table snapshots equal; corrected history, reservation, core/config/file sentinels verified\n";
		break;
	case 'postcheck':
		// Bounded post-update write through production services on the preserved copy:
		// proves reads AND mutations work after a recreate/upgrade/restore.
		$ids = json_decode(file_get_contents('/evidence/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
		$actor = owner($ids['houseId']);
		$preferences = \OC::$server->get(PreferenceService::class);
		$current = $preferences->getOwnPreferences($ids['houseId'], $actor);
		$target = !$current['soundEnabled'];
		$preferences->updateOwnPreferences($ids['houseId'], $actor,
			['expectedVersion' => $current['version'], 'soundEnabled' => $target], uuid());
		$after = $preferences->getOwnPreferences($ids['houseId'], $actor);
		check($after['soundEnabled'] === $target && $after['version'] > $current['version'],
			'Post-preservation preference mutation did not persist');
		echo "Post-preservation read/mutation: PASS (preference version {$current['version']} -> {$after['version']})\n";
		break;
	default:
		throw new InvalidArgumentException('Expected seed, diverge, verify or postcheck');
}
echo "Recovery fixture $mode: PASS\n";
