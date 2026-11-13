<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::factory()->create();

        Sanctum::actingAs($this->user);
    }

    public function test_database_rejects_overlapping_bookings(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        try {
            DB::transaction(fn () => $this->createBooking('2026-11-20 10:30:00', '2026-11-20 11:30:00'));
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0] ?? null);
            $this->assertDatabaseCount('bookings', 1);

            return;
        }

        $this->fail('Ожидалось нарушение exclusion constraint для пересекающихся броней.');
    }

    public function test_database_allows_adjacent_bookings(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');
        $this->createBooking('2026-11-20 11:00:00', '2026-11-20 12:00:00');

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_controller_returns_conflict_when_database_constraint_rejects_booking(): void
    {
        Booking::creating(function (Booking $booking): void {
            DB::table('bookings')->insert([
                'room_id' => $booking->room_id,
                'user_id' => $booking->user_id,
                'starts_at' => $booking->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $booking->ends_at->format('Y-m-d H:i:s'),
                'title' => 'Конкурент',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/bookings', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'title' => 'Встреча',
            'starts_at' => '2026-11-20T10:00:00+03:00',
            'ends_at' => '2026-11-20T11:00:00+03:00',
        ])
            ->assertStatus(409)
            ->assertJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 0);
    }

    private function createBooking(string $startsAt, string $endsAt): Booking
    {
        return Booking::factory()
            ->for($this->room)
            ->for($this->user)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
    }
}
