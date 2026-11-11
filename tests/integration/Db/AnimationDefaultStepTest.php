<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Migration\Version1034Date20261111000000;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

/**
 * The data-only step Version1034 on the real database of every supported
 * provider (CS-PLAN-090 WP-F, D-090-16, D-090-20): the animation is on in
 * every preference row afterwards, each version is one higher and nothing
 * else moves; an empty table is left alone; the migration runner does not
 * apply a recorded step again.
 *
 * The step updates the whole table, so each case runs it inside a
 * transaction that is rolled back: rows of other fixtures and of the
 * instance household stay as they were. The real app upgrade from
 * `0.8.0-alpha.4` is the P4 proof of the preservation runner.
 */
class AnimationDefaultStepTest extends IntegrationTestCase {
	private const STEP = '1034Date20261111000000';

	private MemberPreferenceMapper $prefMapper;
	private int $houseId;

	protected function setUp(): void {
		parent::setUp();
		$this->prefMapper = \OC::$server->get(MemberPreferenceMapper::class);
		$this->houseId = $this->createHouse('anim_owner', 'Animation House');
		foreach (['anim_dad', 'anim_teen', 'anim_kid'] as $userId) {
			$this->addHouseMember($this->houseId, $userId);
		}
	}

	private function houseUserId(string $userId): int {
		return (int)\OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, $userId)->getId();
	}

	/**
	 * Rows as members had them before the update: the old default (off),
	 * a deliberate on with sound and a quiet range, and a row that only
	 * ranking consent created. `anim_kid` has no row.
	 */
	private function seedRows(): void {
		$now = new \DateTime('2026-11-01 10:00:00 UTC');
		$off = MemberPreference::createFor($this->houseId, $this->houseUserId('anim_owner'), $now);
		$off->setAnimationEnabled(false);
		$off->setTaskDigestEnabled(true);
		$off->setDigestTime('19:30');
		$off->setVersion(3);

		$on = MemberPreference::createFor($this->houseId, $this->houseUserId('anim_dad'), $now);
		$on->setSoundEnabled(true);
		$on->setQuietStart('21:00');
		$on->setQuietEnd('07:00');
		$on->setArcadeVisible(false);

		$consent = MemberPreference::createFor($this->houseId, $this->houseUserId('anim_teen'), $now);
		$consent->setAnimationEnabled(false);
		$consent->setRankingConsentGeneration(1);
		$consent->setRankingEffectiveMonday(new \DateTime('2026-11-02'));
		$consent->setRankingSavedZone('Europe/Berlin');

		foreach ([$off, $on, $consent] as $row) {
			$row->setUpdatedAt($now);
			$this->prefMapper->insertOrGet($row);
		}
	}

	/** @return array<int, array<string, mixed>> Raw rows of the fixture house by membership. */
	private function rawRows(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from('chorestrike_member_prefs')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$rows = [];
		foreach ($result->fetchAll() as $row) {
			$rows[(int)$row['house_user_id']] = $row;
		}
		$result->closeCursor();
		ksort($rows);
		return $rows;
	}

	private function runStep(?IOutput $output = null): void {
		\OC::$server->get(Version1034Date20261111000000::class)
			->postSchemaChange($output ?? $this->createMock(IOutput::class), static fn () => null, []);
	}

	public function testStep_SwitchesTheAnimationOnInEveryRowAndBumpsOnlyTheVersion(): void {
		$this->seedRows();
		$before = $this->rawRows();
		self::assertCount(3, $before);

		$this->db->beginTransaction();
		try {
			$this->runStep();
			$after = $this->rawRows();
			$rows = $this->prefMapper->findByHouseId($this->houseId);
		} finally {
			$this->db->rollBack();
		}

		self::assertSame(array_keys($before), array_keys($after), 'no row appears or disappears; the member without a row still has none');
		foreach ($rows as $row) {
			self::assertTrue($row->getAnimationEnabled(), 'animation on for membership ' . $row->getHouseUserId());
		}
		foreach ($before as $houseUserId => $row) {
			self::assertSame((int)$row['version'] + 1, (int)$after[$houseUserId]['version']);
			unset($row['animation_enabled'], $row['version'], $after[$houseUserId]['animation_enabled'], $after[$houseUserId]['version']);
			self::assertSame($row, $after[$houseUserId], 'every other column is untouched');
		}
		self::assertSame($before, $this->rawRows(), 'the rollback left the fixture as seeded');
	}

	public function testStep_LeavesAnEmptyTableAlone(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Completion animation switched on in 0 preference rows');

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_member_prefs')->executeStatement();
			$this->runStep($output);
			$qb = $this->db->getQueryBuilder();
			$result = $qb->select($qb->func()->count('*'))->from('chorestrike_member_prefs')->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
		} finally {
			$this->db->rollBack();
		}

		self::assertSame(0, $count);
	}

	public function testRunner_DoesNotApplyTheRecordedStepAgain(): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter(self::STEP)))
			->executeQuery();
		$recorded = $result->fetchAll();
		$result->closeCursor();
		self::assertCount(1, $recorded, 'the installation records the step exactly once');

		$this->seedRows();
		$before = $this->rawRows();

		(new MigrationService('chorestrike', \OC::$server->get(Connection::class)))->migrate();

		self::assertSame($before, $this->rawRows(), 'a member who switched the animation off after the update keeps that choice');
	}
}
