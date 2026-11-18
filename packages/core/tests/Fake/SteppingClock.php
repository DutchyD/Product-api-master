<?php

declare(strict_types=1);

namespace MaxServ\Core\Tests\Fake;

use MaxServ\Core\Time\MonotonicClock;

/**
 * A clock that moves forward by a fixed step every time it is read.
 */
final class SteppingClock implements MonotonicClock
{
    private int $reading = 0;

    /**
     * Creates the clock, starting at zero.
     *
     * @param int $stepNanoseconds How far the clock moves on each reading, in nanoseconds.
     */
    public function __construct(private readonly int $stepNanoseconds = 0)
    {
    }

    /**
     * Returns the current reading and then moves the clock forward by one step.
     *
     * @return int The reading, in nanoseconds.
     */
    #[\Override]
    public function nanoseconds(): int
    {
        $reading = $this->reading;
        $this->reading += $this->stepNanoseconds;

        return $reading;
    }
}
