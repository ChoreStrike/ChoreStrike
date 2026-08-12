<?php

namespace Symfony\Component\Console\Input;

/**
 * Mock Symfony Console InputInterface for unit-testing OCC commands.
 *
 * Mirrors only the surface ChoreStrike commands read (arguments and
 * options); PHPUnit mocks generate the methods for tests.
 */
interface InputInterface {
	/**
	 * Returns the argument value for a given argument name.
	 *
	 * @return mixed
	 */
	public function getArgument(string $name);

	/**
	 * Returns the option value for a given option name.
	 *
	 * @return mixed
	 */
	public function getOption(string $name);

	/**
	 * Returns true if the InputInterface contains the given option.
	 */
	public function hasOption(string $name): bool;
}
