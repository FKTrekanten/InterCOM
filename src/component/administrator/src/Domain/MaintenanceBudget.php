<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class MaintenanceBudget
{
    private float $deadline;
    private \Closure $clock;

    public function __construct(float $seconds = 60, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->deadline = ($this->clock)() + max(0, $seconds);
    }

    public function limit(float $seconds): self
    {
        $limited = new self($seconds, $this->clock);
        $limited->deadline = min($this->deadline, $limited->deadline);
        return $limited;
    }

    public function expired(): bool
    {
        return ($this->clock)() >= $this->deadline;
    }

    public function timeout(int $maximum): int
    {
        return max(1, min($maximum, (int) ceil($this->deadline - ($this->clock)())));
    }
}
