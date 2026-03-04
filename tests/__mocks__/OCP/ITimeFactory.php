<?php

/**
 * Mock ITimeFactory for testing
 */

namespace OCP;

interface ITimeFactory
{
    /**
     * Get current time
     * @return int
     */
    public function getTime(): int;

    /**
     * Get DateTime object
     * @return \DateTime
     */
    public function getDateTime(): \DateTime;
}
