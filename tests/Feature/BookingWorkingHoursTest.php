<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    private const WORKING_HOURS_ERROR = 'Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса';

    private User $user;

    private Room $moscowRoom;

    private Room $novosibirskRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $moscow = Office::factory()->create();
        $this->moscowRoom = Room::factory()->for($moscow)->create();

        $novosibirsk = Office::factory()->novosibirsk()->create();
        $this->novosibirskRoom = Room::factory()->for($novosibirsk)->create();

        Sanctum::actingAs($this->user);
    }

    public function test_booking_with_local_offset_is_allowed(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            $this->moscowRoom,
            '2026-11-20T10:00:00+03:00',
            '2026-11-20T11:00:00+03:00',
        ))->assertCreated();
    }

    public function test_booking_with_utc_offset_is_converted_to_office_timezone(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            $this->moscowRoom,
            '2026-11-20T07:00:00Z',
            '2026-11-20T08:00:00Z',
        ))->assertCreated();
    }

    public function test_novosibirsk_booking_with_utc_offset_is_converted_to_office_timezone(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            $this->novosibirskRoom,
            '2026-11-20T03:00:00Z',
            '2026-11-20T04:00:00Z',
        ))->assertCreated();
    }

    public function test_booking_with_non_hour_offset_is_validated_in_office_timezone(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            $this->moscowRoom,
            '2026-11-20T14:20:00+05:20',
            '2026-11-20T15:20:00+05:20',
        ))->assertCreated();
    }

    public function test_booking_at_exact_working_hours_boundaries_is_allowed(): void
    {
        $this->postJson('/api/bookings', $this->payload(
            $this->moscowRoom,
            '2026-11-20T06:00:00Z',
            '2026-11-20T07:00:00Z',
        ))->assertCreated();

        $this->postJson('/api/bookings', $this->payload(
            $this->moscowRoom,
            '2026-11-20T13:00:00Z',
            '2026-11-20T17:00:00Z',
        ))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_booking_starting_before_office_hours_is_rejected(): void
    {
        $this->assertRejected(
            $this->moscowRoom,
            '2026-11-20T08:45:00+03:00',
            '2026-11-20T09:45:00+03:00',
        );
    }

    public function test_booking_ending_after_office_hours_is_rejected(): void
    {
        $this->assertRejected(
            $this->moscowRoom,
            '2026-11-20T19:30:00+03:00',
            '2026-11-20T20:30:00+03:00',
        );
    }

    public function test_novosibirsk_booking_starting_after_office_hours_is_rejected(): void
    {
        $this->assertRejected(
            $this->novosibirskRoom,
            '2026-11-20T13:00:00Z',
            '2026-11-20T14:00:00Z',
        );
    }

    public function test_novosibirsk_booking_ending_after_office_hours_is_rejected(): void
    {
        $this->assertRejected(
            $this->novosibirskRoom,
            '2026-11-20T12:30:00Z',
            '2026-11-20T13:30:00Z',
        );
    }

    private function assertRejected(Room $room, string $startsAt, string $endsAt): void
    {
        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', self::WORKING_HOURS_ERROR);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Room $room, string $startsAt, string $endsAt): array
    {
        return [
            'room_id' => $room->id,
            'user_id' => $this->user->id,
            'title' => 'Встреча',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
