<?php

namespace Symfony\Component\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mock Symfony Console Command for unit-testing OCC commands without the
 * real symfony/console package (Nextcloud core provides it at runtime;
 * the app's composer dev dependencies do not).
 *
 * Mirrors the surface ChoreStrike commands touch: name/description
 * setters, addArgument/addOption recording, and the constructor-time
 * configure() call. Tests invoke run() directly with mock input/output.
 */
class Command {
	public const SUCCESS = 0;
	public const FAILURE = 1;
	public const INVALID = 2;

	/** @var string */
	private $name = '';

	/** @var string */
	private $description = '';

	/** @var array<string, array{mode: int|null, description: string, default: mixed}> */
	private $arguments = [];

	/** @var array<string, array{shortcut: mixed, mode: int|null, description: string, default: mixed}> */
	private $options = [];

	public function __construct(?string $name = null) {
		if ($name !== null) {
			$this->name = $name;
		}

		$this->configure();
	}

	/**
	 * Configures the current command. Overridden by subclasses.
	 */
	protected function configure(): void {
	}

	public function setName(string $name): self {
		$this->name = $name;
		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setDescription(string $description): self {
		$this->description = $description;
		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @param mixed $default
	 */
	public function addArgument(string $name, ?int $mode = null, string $description = '', $default = null): self {
		$this->arguments[$name] = [
			'mode' => $mode,
			'description' => $description,
			'default' => $default,
		];
		return $this;
	}

	/**
	 * @param mixed $shortcut
	 * @param mixed $default
	 */
	public function addOption(string $name, $shortcut = null, ?int $mode = null, string $description = '', $default = null): self {
		$this->options[$name] = [
			'shortcut' => $shortcut,
			'mode' => $mode,
			'description' => $description,
			'default' => $default,
		];
		return $this;
	}

	/**
	 * Recorded argument definitions (test inspection helper).
	 *
	 * @return array<string, array{mode: int|null, description: string, default: mixed}>
	 */
	public function getRecordedArguments(): array {
		return $this->arguments;
	}

	/**
	 * Recorded option definitions (test inspection helper).
	 *
	 * @return array<string, array{shortcut: mixed, mode: int|null, description: string, default: mixed}>
	 */
	public function getRecordedOptions(): array {
		return $this->options;
	}

	/**
	 * Runs the command. Mirrors Symfony's public entry point; the default
	 * implementation fails like the real one unless a subclass overrides
	 * execute().
	 */
	public function run(InputInterface $input, OutputInterface $output): int {
		return $this->execute($input, $output);
	}

	/**
	 * Executes the current command. Overridden by subclasses.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		throw new \LogicException('You must override the execute() method in the concrete command class.');
	}
}
