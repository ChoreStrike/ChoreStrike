<?php

namespace Symfony\Component\Console\Output;

/**
 * Mock Symfony Console OutputInterface for unit-testing OCC commands.
 *
 * Mirrors only the surface ChoreStrike commands write (writeln/write);
 * PHPUnit mocks generate the methods for tests.
 */
interface OutputInterface
{
    /**
     * Writes a message to the output.
     *
     * @param string|iterable $messages
     */
    public function write($messages, bool $newline = false, int $options = 0);

    /**
     * Writes a message to the output and adds a newline.
     *
     * @param string|iterable $messages
     */
    public function writeln($messages, int $options = 0);
}
