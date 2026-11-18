<?php

declare(strict_types=1);

namespace MaxServ\Core\Time;

/**
 * Measures elapsed time with a clock that never jumps, for deadlines and timeouts.
 */
interface MonotonicClock
{
    /**
     * Returns the current reading of the clock; only differences between readings are meaningful.
     *
     * @return int The reading, in nanoseconds.
     */
    public function nanoseconds(): int;
}
