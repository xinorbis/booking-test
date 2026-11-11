<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingOverlapTest extends TestCase
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

    public function test_adjacent_booking_after_existing_booking_is_allowed(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T11:00:00Z',
            '2026-11-20T12:00:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_adjacent_booking_before_existing_booking_is_allowed(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T09:00:00Z',
            '2026-11-20T10:00:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_exactly_matching_booking_is_rejected(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:00:00Z',
            '2026-11-20T11:00:00Z',
        ))
            ->assertStatus(409)
            ->assertJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_starting_during_existing_booking_is_rejected(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:30:00Z',
            '2026-11-20T11:30:00Z',
        ))
            ->assertStatus(409)
            ->assertJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_ending_during_existing_booking_is_rejected(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T09:30:00Z',
            '2026-11-20T10:30:00Z',
        ))
            ->assertStatus(409)
            ->assertJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_containing_existing_booking_is_rejected(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T09:00:00Z',
            '2026-11-20T12:00:00Z',
        ))
            ->assertStatus(409)
            ->assertJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_same_time_booking_in_another_room_is_allowed(): void
    {
        $this->createBooking('2026-11-20 10:00:00', '2026-11-20 11:00:00');
        $otherRoom = Room::factory()->create();

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:00:00Z',
            '2026-11-20T11:00:00Z',
            $otherRoom,
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
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

    /**
     * @return array<string, mixed>
     */
    private function payload(string $startsAt, string $endsAt, ?Room $room = null): array
    {
        return [
            'room_id' => $room?->id ?? $this->room->id,
            'user_id' => $this->user->id,
            'title' => 'Встреча',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
