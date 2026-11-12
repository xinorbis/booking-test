<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDurationTest extends TestCase
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

    public function test_minimum_duration_is_allowed(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:00:00Z',
            '2026-11-20T10:15:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_duration_on_fifteen_minute_grid_is_allowed(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:15:00Z',
            '2026-11-20T11:00:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_maximum_duration_is_allowed(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T10:00:00Z',
            '2026-11-20T14:00:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_duration_shorter_than_fifteen_minutes_is_rejected(): void
    {
        $this->assertRejected('10:00', '10:14');
    }

    public function test_duration_longer_than_four_hours_is_rejected(): void
    {
        $this->assertRejected('10:00', '14:15');
    }

    public function test_duration_must_be_a_multiple_of_fifteen_minutes(): void
    {
        $this->assertRejected('10:00', '10:20');
    }

    public function test_start_time_must_be_on_the_fifteen_minute_grid(): void
    {
        $this->assertRejected('10:07', '10:22');
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $this->assertRejected('10:30', '10:15');
    }

    public function test_seconds_are_not_allowed(): void
    {
        $this->assertRejected('10:00:30', '10:15:30');
    }

    private function assertRejected(string $startsAt, string $endsAt): void
    {
        $this->postJson('/api/bookings', $this->payload(
            '2026-11-20T'.$startsAt.'Z',
            '2026-11-20T'.$endsAt.'Z',
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath(
                'errors.ends_at.0',
                'Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут',
            );

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $startsAt, string $endsAt): array
    {
        return [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'title' => 'Встреча',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
