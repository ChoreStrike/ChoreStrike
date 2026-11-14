<?php

/**
 * Mock for OCP\Config\ValueType (Nextcloud 32+).
 */

namespace OCP\Config;

enum ValueType: int {
	case MIXED = 0;
	case STRING = 1;
	case INT = 2;
	case FLOAT = 3;
	case BOOL = 4;
	case ARRAY = 5;
}
