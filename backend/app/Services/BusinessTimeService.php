<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

class BusinessTimeService
{
    public function timezone(): string
    {
        $timezone = (string) config('app.business_timezone', config('app.timezone'));
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Invalid business timezone [$timezone].");
        }

        return $timezone;
    }

    public function date(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    public function utcTimestamp(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }

    public function applyUtcDateFilter(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from) $query->where($column, '>=', $this->utcBoundary($from, false));
        if ($to) $query->where($column, '<=', $this->utcBoundary($to, true));
    }

    public function utcStart(string $date): string
    {
        return $this->utcBoundary($date, false);
    }

    public function toBusinessTimestamp(string $utcTimestamp): string
    {
        return CarbonImmutable::parse($utcTimestamp, 'UTC')->setTimezone($this->timezone())->format('Y-m-d H:i:s');
    }

    private function utcBoundary(string $date, bool $endOfDay): string
    {
        $value = CarbonImmutable::parse($date, $this->timezone());
        $value = $endOfDay ? $value->endOfDay() : $value->startOfDay();

        return $value->utc()->format('Y-m-d H:i:s.u');
    }
}
