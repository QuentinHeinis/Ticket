<?php

declare(strict_types=1);

namespace App\Enum;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function points(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 4,
            self::Urgent => 8,
        };
    }

    public function handlingLimitMinutes(): int
    {
        return match ($this) {
            self::Urgent => 30,
            self::High => 120,
            self::Normal => 480,
            self::Low => 1440,
        };
    }

    public function escalationDelayMinutes(): ?int
    {
        return match ($this) {
            self::Low => 240,
            self::Normal => 120,
            self::High => 60,
            self::Urgent => null,
        };
    }

    public function escalated(): self
    {
        return match ($this) {
            self::Low => self::Normal,
            self::Normal => self::High,
            self::High => self::Urgent,
            self::Urgent => self::Urgent,
        };
    }
}
