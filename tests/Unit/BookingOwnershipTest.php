<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_by_scope_finds_booking_for_its_author(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        $this->assertTrue(
            Booking::query()->ownedBy($author)->whereKey($booking->id)->exists()
        );
    }

    public function test_owned_by_scope_does_not_find_another_users_booking(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        $this->assertFalse(
            Booking::query()->ownedBy($other)->whereKey($booking->id)->exists()
        );
    }

    public function test_owned_by_scope_throws_for_another_users_booking(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        $this->expectException(ModelNotFoundException::class);

        Booking::query()->ownedBy($other)->findOrFail($booking->id);
    }
}
