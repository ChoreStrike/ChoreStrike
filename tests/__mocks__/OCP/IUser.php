<?php

/**
 * Mock IUser interface for testing
 */

namespace OCP;

interface IUser
{
    public function getUID(): string;
    public function getDisplayName(): string;
}
