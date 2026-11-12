<?php

namespace Tests\Unit;

use App\Rules\ValidBookingDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingDurationTest extends TestCase
{
    #[DataProvider('validDurations')]
    public function test_it_accepts_valid_durations(string $startsAt, string $endsAt): void
    {
        $this->assertSame([], $this->validate($startsAt, $endsAt));
    }

    #[DataProvider('invalidDurations')]
    public function test_it_rejects_invalid_durations(string $startsAt, string $endsAt): void
    {
        $this->assertSame(
            ['Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут'],
            $this->validate($startsAt, $endsAt),
        );
    }

    /**
     * @return list<string>
     */
    private function validate(string $startsAt, string $endsAt): array
    {
        $rule = (new ValidBookingDuration)->setData([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $errors = [];

        $rule->validate('ends_at', $endsAt, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

        return $errors;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validDurations(): array
    {
        return [
            'minimum 15 minutes' => ['2026-11-20 10:00:00', '2026-11-20 10:15:00'],
            '45 minutes on grid' => ['2026-11-20 10:15:00', '2026-11-20 11:00:00'],
            'maximum 4 hours' => ['2026-11-20 10:00:00', '2026-11-20 14:00:00'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidDurations(): array
    {
        return [
            'less than 15 minutes' => ['2026-11-20 10:00:00', '2026-11-20 10:14:00'],
            'more than 4 hours' => ['2026-11-20 10:00:00', '2026-11-20 14:15:00'],
            'not a 15-minute step' => ['2026-11-20 10:00:00', '2026-11-20 10:20:00'],
            'start not on grid' => ['2026-11-20 10:07:00', '2026-11-20 10:22:00'],
            'end before start' => ['2026-11-20 10:30:00', '2026-11-20 10:15:00'],
            'end equals start' => ['2026-11-20 10:00:00', '2026-11-20 10:00:00'],
            'seconds not zero' => ['2026-11-20 10:00:30', '2026-11-20 10:15:30'],
            'end seconds not zero' => ['2026-11-20 10:00:00', '2026-11-20 10:15:30'],
        ];
    }
}
