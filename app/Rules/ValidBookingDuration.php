<?php

namespace App\Rules;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidBookingDuration implements DataAwareRule, ValidationRule
{
    private const MINIMUM_DURATION_SECONDS = 15 * 60;

    private const MAXIMUM_DURATION_SECONDS = 4 * 60 * 60;

    private const DURATION_STEP_SECONDS = 15 * 60;

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(private readonly ?string $timezone = null) {}

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
            $start = Carbon::parse($startsAt);
            $end = Carbon::parse($value);

            if ($this->timezone !== null) {
                $start->setTimezone($this->timezone);
                $end->setTimezone($this->timezone);
            }
        } catch (\Throwable) {
            return;
        }

        if (! $this->hasValidDuration($start, $end)) {
            $fail('Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут');
        }
    }

    private function hasValidDuration(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            return false;
        }

        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        return $durationSeconds >= self::MINIMUM_DURATION_SECONDS
            && $durationSeconds <= self::MAXIMUM_DURATION_SECONDS
            && $durationSeconds % self::DURATION_STEP_SECONDS === 0
            && $startsAt->minute % 15 === 0
            && $startsAt->second === 0
            && $startsAt->microsecond === 0
            && $endsAt->minute % 15 === 0
            && $endsAt->second === 0
            && $endsAt->microsecond === 0;
    }
}
