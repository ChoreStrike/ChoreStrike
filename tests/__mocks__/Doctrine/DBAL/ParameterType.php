<?php

/**
 * Mock Doctrine DBAL ParameterType for testing
 */

namespace Doctrine\DBAL;

class ParameterType {
    public const NULL = 0;
    public const INTEGER = 1;
    public const STRING = 2;
    public const BOOLEAN = 3;
    public const BINARY = 4;
    public const LARGE_OBJECT = 5;
}
