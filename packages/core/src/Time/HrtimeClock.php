<?php

declare(strict_types=1);

namespace MaxServ\Core\Time;

/**
 * Reads the system's high-resolution monotonic timer.
 */
final readonly class HrtimeClock implements MonotonicClock
{
    /**
     * Returns the current reading of the system's monotonic timer.
     *
     * @return int The reading, in nanoseconds.
     */
    #[\Override]
    public function nanoseconds(): int
    {
        return hrtime(true);
    }
}
