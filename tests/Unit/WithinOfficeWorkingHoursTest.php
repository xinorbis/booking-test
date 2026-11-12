<?php

namespace Tests\Unit;

use App\Rules\WithinOfficeWorkingHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WithinOfficeWorkingHoursTest extends TestCase
{
    private const WORKING_HOURS_ERROR = 'Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса';

    #[DataProvider('validBookings')]
    public function test_it_accepts_bookings_inside_office_working_hours(
        string $timezone,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->assertSame([], $this->validate($timezone, $startsAt, $endsAt));
    }

    #[DataProvider('invalidBookings')]
    public function test_it_rejects_bookings_outside_office_working_hours(
        string $timezone,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->assertSame(
            [self::WORKING_HOURS_ERROR],
            $this->validate($timezone, $startsAt, $endsAt),
        );
    }

    /**
     * @return list<string>
     */
    private function validate(string $timezone, string $startsAt, string $endsAt): array
    {
        $rule = (new WithinOfficeWorkingHours($timezone))->setData([
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
     * @return array<string, array{string, string, string}>
     */
    public static function validBookings(): array
    {
        return [
            'Moscow local time' => [
                'Europe/Moscow',
                '2026-11-20T10:00:00+03:00',
                '2026-11-20T11:00:00+03:00',
            ],
            'Moscow from UTC' => [
                'Europe/Moscow',
                '2026-11-20T07:00:00Z',
                '2026-11-20T08:00:00Z',
            ],
            'Moscow starts at 09:00' => [
                'Europe/Moscow',
                '2026-11-20T06:00:00Z',
                '2026-11-20T07:00:00Z',
            ],
            'Moscow ends at 20:00' => [
                'Europe/Moscow',
                '2026-11-20T16:00:00Z',
                '2026-11-20T17:00:00Z',
            ],
            'Novosibirsk from UTC' => [
                'Asia/Novosibirsk',
                '2026-11-20T03:00:00Z',
                '2026-11-20T04:00:00Z',
            ],
            'Novosibirsk ends at 20:00' => [
                'Asia/Novosibirsk',
                '2026-11-20T12:00:00Z',
                '2026-11-20T13:00:00Z',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidBookings(): array
    {
        return [
            'Moscow starts before 09:00' => [
                'Europe/Moscow',
                '2026-11-20T08:45:00+03:00',
                '2026-11-20T09:45:00+03:00',
            ],
            'Moscow ends after 20:00' => [
                'Europe/Moscow',
                '2026-11-20T19:30:00+03:00',
                '2026-11-20T20:30:00+03:00',
            ],
            'Novosibirsk starts after 20:00' => [
                'Asia/Novosibirsk',
                '2026-11-20T13:00:00Z',
                '2026-11-20T14:00:00Z',
            ],
            'Novosibirsk ends after 20:00' => [
                'Asia/Novosibirsk',
                '2026-11-20T12:30:00Z',
                '2026-11-20T13:30:00Z',
            ],
        ];
    }
}
