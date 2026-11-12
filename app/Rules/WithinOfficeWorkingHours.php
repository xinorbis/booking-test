<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class WithinOfficeWorkingHours implements DataAwareRule, ValidationRule
{
    private const WORKDAY_START_HOUR = 9;

    private const WORKDAY_END_HOUR = 20;

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(private readonly string $timezone) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $startsAt = $this->data['starts_at'] ?? null;

        if (! is_string($startsAt) || ! is_string($value)) {
            return;
        }

        try {
            $startLocal = Carbon::parse($startsAt)->setTimezone($this->timezone);
            $endLocal = Carbon::parse($value)->setTimezone($this->timezone);
        } catch (\Throwable) {
            return;
        }

        $workdayStart = $startLocal->copy()->setTime(self::WORKDAY_START_HOUR, 0);
        $workdayEnd = $startLocal->copy()->setTime(self::WORKDAY_END_HOUR, 0);

        if ($startLocal->lessThan($workdayStart) || $endLocal->greaterThan($workdayEnd)) {
            $fail('Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса');
        }
    }
}
