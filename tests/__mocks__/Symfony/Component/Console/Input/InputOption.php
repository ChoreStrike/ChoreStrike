<?php

namespace Symfony\Component\Console\Input;

/**
 * Mock Symfony Console InputOption constants for unit-testing OCC commands.
 */
class InputOption {
	public const VALUE_NONE = 1;
	public const VALUE_REQUIRED = 2;
	public const VALUE_OPTIONAL = 4;
	public const VALUE_IS_ARRAY = 8;
}
