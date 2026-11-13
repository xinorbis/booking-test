<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingValidationLocalizationTest extends TestCase
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

    public function test_room_id_is_required_in_russian(): void
    {
        $this->postWithout(['room_id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «id комнаты» обязательно для заполнения.')
            ->assertJsonPath('errors.room_id.0', 'Поле «id комнаты» обязательно для заполнения.');
    }

    public function test_room_id_must_be_an_integer_in_russian(): void
    {
        $this->postWith(['room_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «id комнаты» должно быть целым числом.')
            ->assertJsonPath('errors.room_id.0', 'Поле «id комнаты» должно быть целым числом.');
    }

    public function test_room_id_must_exist_in_russian(): void
    {
        $this->postWith(['room_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Выбранное значение для «id комнаты» некорректно.')
            ->assertJsonPath('errors.room_id.0', 'Выбранное значение для «id комнаты» некорректно.');
    }

    public function test_starts_at_is_required_in_russian(): void
    {
        $this->postWithout(['starts_at'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «время начала» обязательно для заполнения.')
            ->assertJsonPath('errors.starts_at.0', 'Поле «время начала» обязательно для заполнения.');
    }

    public function test_starts_at_must_be_a_valid_date_in_russian(): void
    {
        $this->postWith(['starts_at' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «время начала» должно быть действительной датой.')
            ->assertJsonPath('errors.starts_at.0', 'Поле «время начала» должно быть действительной датой.');
    }

    public function test_ends_at_is_required_in_russian(): void
    {
        $this->postWithout(['ends_at'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «время окончания» обязательно для заполнения.')
            ->assertJsonPath('errors.ends_at.0', 'Поле «время окончания» обязательно для заполнения.');
    }

    public function test_ends_at_must_be_a_valid_date_in_russian(): void
    {
        $this->postWith(['ends_at' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «время окончания» должно быть действительной датой.')
            ->assertJsonPath('errors.ends_at.0', 'Поле «время окончания» должно быть действительной датой.');
    }

    public function test_multiple_validation_errors_have_a_russian_summary(): void
    {
        $this->postWithout(['starts_at', 'ends_at'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Поле «время начала» обязательно для заполнения. (и ещё ошибок: 1)')
            ->assertJsonPath('errors.starts_at.0', 'Поле «время начала» обязательно для заполнения.')
            ->assertJsonPath('errors.ends_at.0', 'Поле «время окончания» обязательно для заполнения.');
    }

    public function test_booking_rule_errors_have_a_russian_summary(): void
    {
        $this->postWith([
            'starts_at' => '2026-11-20T08:45:00+03:00',
            'ends_at' => '2026-11-20T08:55:00+03:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут (и ещё ошибок: 1)')
            ->assertJsonPath('errors.ends_at.0', 'Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут')
            ->assertJsonPath('errors.ends_at.1', 'Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса');
    }

    public function test_application_locale_is_restored_after_validation(): void
    {
        $this->postWith(['room_id' => 'abc'])
            ->assertStatus(422);

        $this->assertSame('en', app()->getLocale());
    }

    /**
     * @param  list<string>  $fields
     */
    private function postWithout(array $fields): TestResponse
    {
        $payload = $this->payload();

        foreach ($fields as $field) {
            unset($payload[$field]);
        }

        return $this->postJson('/api/bookings', $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postWith(array $overrides): TestResponse
    {
        return $this->postJson('/api/bookings', [
            ...$this->payload(),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'title' => 'Встреча',
            'starts_at' => '2026-11-20T10:00:00+03:00',
            'ends_at' => '2026-11-20T11:00:00+03:00',
        ];
    }
}
