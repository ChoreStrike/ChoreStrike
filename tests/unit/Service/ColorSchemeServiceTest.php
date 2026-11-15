<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Service\ColorSchemeService;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-090 WP-K (D-090-61/64/67): a person's colour scheme is a per-user
 * setting checked against the list; *Standard* is stored as no value, and
 * account erasure and the test reset delete it.
 */
class ColorSchemeServiceTest extends TestCase {
	private IUserConfig&MockObject $userConfig;
	private ColorSchemeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->userConfig = $this->createMock(IUserConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->service = new ColorSchemeService($this->userConfig, $l10n);
	}

	public function testGet_ReturnsTheStoredScheme(): void {
		$this->userConfig->expects($this->once())->method('getValueString')
			->with('kid', 'chorestrike', 'color_scheme', 'standard')
			->willReturn('forest');

		self::assertSame('forest', $this->service->get('kid'));
	}

	public function testGet_UnknownStoredValue_ReadsAsStandard(): void {
		$this->userConfig->method('getValueString')->willReturn('neon');

		self::assertSame('standard', $this->service->get('kid'));
	}

	public function testSetOwn_StoresAListedSchemeForTheActor(): void {
		$this->userConfig->expects($this->once())->method('setValueString')
			->with('nc-kid', 'chorestrike', 'color_scheme', 'berry');
		$this->userConfig->expects($this->never())->method('deleteUserConfig');

		self::assertSame('berry', $this->service->setOwn($this->member('nc-kid'), 'berry'));
	}

	public function testSetOwn_Standard_DeletesTheStoredValue(): void {
		$this->userConfig->expects($this->once())->method('deleteUserConfig')
			->with('nc-kid', 'chorestrike', 'color_scheme');
		$this->userConfig->expects($this->never())->method('setValueString');

		self::assertSame('standard', $this->service->setOwn($this->member('nc-kid'), 'standard'));
	}

	/**
	 * @dataProvider invalidSchemes
	 */
	public function testSetOwn_AnythingButAListedScheme_IsRefusedBeforeAnyWrite(mixed $scheme): void {
		$this->userConfig->expects($this->never())->method('setValueString');
		$this->userConfig->expects($this->never())->method('deleteUserConfig');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOwn($this->member('nc-kid'), $scheme);
	}

	/** @return array<string, array{0: mixed}> */
	public static function invalidSchemes(): array {
		return [
			'unknown' => ['neon'],
			'case' => ['Ocean'],
			'empty' => [''],
			'null' => [null],
			'number' => [3],
			'array' => [['ocean']],
		];
	}

	public function testForget_DeletesOnePersonsScheme(): void {
		$this->userConfig->expects($this->once())->method('deleteUserConfig')
			->with('gone', 'chorestrike', 'color_scheme');

		$this->service->forget('gone');
	}

	public function testForgetAll_DeletesTheKeyForEveryone(): void {
		$this->userConfig->expects($this->once())->method('deleteKey')->with('chorestrike', 'color_scheme');

		$this->service->forgetAll();
	}

	public function testTheListIsStandardAndFiveSchemes(): void {
		self::assertSame(['standard', 'ocean', 'forest', 'sunset', 'berry', 'slate'], ColorSchemeService::SCHEMES);
		self::assertSame('standard', ColorSchemeService::DEFAULT);
	}

	private function member(string $userId): HouseUser {
		$member = new HouseUser();
		$member->setId(11);
		$member->setNextcloudUserId($userId);
		return $member;
	}
}
