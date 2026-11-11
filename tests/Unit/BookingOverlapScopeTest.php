<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingOverlapScopeTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->room = Room::factory()->create();

        Booking::factory()->for($this->room)->create([
            'starts_at' => '2026-11-20 10:00:00',
            'ends_at' => '2026-11-20 11:00:00',
        ]);
    }

    public function test_it_finds_an_overlapping_booking_in_the_same_room(): void
    {
        $this->assertTrue($this->hasOverlap('2026-11-20 10:30:00', '2026-11-20 11:30:00'));
    }

    public function test_it_does_not_treat_adjacent_bookings_as_overlapping(): void
    {
        $this->assertFalse($this->hasOverlap('2026-11-20 09:00:00', '2026-11-20 10:00:00'));
        $this->assertFalse($this->hasOverlap('2026-11-20 11:00:00', '2026-11-20 12:00:00'));
    }

    public function test_it_ignores_bookings_in_other_rooms(): void
    {
        $otherRoom = Room::factory()->create();

        $this->assertFalse(
            Booking::query()
                ->overlapping(
                    $otherRoom->id,
                    Carbon::parse('2026-11-20 10:00:00'),
                    Carbon::parse('2026-11-20 11:00:00'),
                )
                ->exists()
        );
    }

    private function hasOverlap(string $startsAt, string $endsAt): bool
    {
        return Booking::query()
            ->overlapping(
                $this->room->id,
                Carbon::parse($startsAt),
                Carbon::parse($endsAt),
            )
            ->exists();
    }
}
