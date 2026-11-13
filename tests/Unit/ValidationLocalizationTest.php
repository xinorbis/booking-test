<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationLocalizationTest extends TestCase
{
    #[DataProvider('validationMessages')]
    public function test_validation_messages_are_in_russian(
        string $rule,
        string $attribute,
        string $expected,
    ): void {
        $attributeName = Lang::get("validation.attributes.{$attribute}", [], 'ru');

        $this->assertSame(
            $expected,
            Lang::get("validation.{$rule}", ['attribute' => $attributeName], 'ru'),
        );
    }

    public function test_multiple_errors_summary_is_in_russian(): void
    {
        $this->assertSame(
            '(и ещё ошибок: 1)',
            Lang::choice('(and :count more error)', 1, [], 'ru'),
        );

        $this->assertSame(
            '(и ещё ошибок: 2)',
            Lang::choice('(and :count more errors)', 2, [], 'ru'),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validationMessages(): array
    {
        return [
            'room_id required' => ['required', 'room_id', 'Поле «id комнаты» обязательно для заполнения.'],
            'room_id integer' => ['integer', 'room_id', 'Поле «id комнаты» должно быть целым числом.'],
            'room_id exists' => ['exists', 'room_id', 'Выбранное значение для «id комнаты» некорректно.'],
            'starts_at required' => ['required', 'starts_at', 'Поле «время начала» обязательно для заполнения.'],
            'starts_at date' => ['date', 'starts_at', 'Поле «время начала» должно быть действительной датой.'],
            'ends_at required' => ['required', 'ends_at', 'Поле «время окончания» обязательно для заполнения.'],
            'ends_at date' => ['date', 'ends_at', 'Поле «время окончания» должно быть действительной датой.'],
        ];
    }
}
