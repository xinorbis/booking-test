# Ревью проекта «Бронирование переговорных»

## Сводка

| ID  | Серьёзность | Проблема                                                                       |
| --- | ----------- | ------------------------------------------------------------------------------ |
| C1  | critical    | SQL-инъекция в `store`                                                         |
| C2  | critical    | Любой пользователь может удалить чужую бронь                                   |
| C3  | critical    | Подмена автора через `user_id`                                                 |
| C4  | critical    | Время хранится без нормализации к UTC; проверки идут по часовому поясу клиента |
| M1  | major       | Брони «впритык» считаются пересечением                                         |
| M2  | major       | Нет валидации запроса: 500 вместо 422                                          |
| M3  | major       | Проверка рабочих часов смотрит только на час начала и не проверяет конец       |
| M4  | major       | Гонка между проверкой конфликта и вставкой                                     |
| M5  | major       | Нет тестов на `store`, `destroy` и `today`                                     |
| M6  | major       | Запрос без `Accept: application/json` без токена возвращает 500 вместо 401     |
| M7  | major       | `today` использует UTC/`today()` вместо часового пояса офиса                   |
| m1  | minor       | `Log::withContext` не попадает в канал `bookings`                              |
| m2  | minor       | `today` отдаёт полную дату и секунды вместо времени                            |
| m3  | minor       | N+1 при загрузке авторов в `today`                                             |
| m4  | minor       | Нет индексов под проверку пересечений и `today`                                |
| m5  | minor       | Нет DB-ограничений на `ends_at > starts_at` и уникальность комнат              |
| m6  | minor       | `index` отдаёт сырые модели без API Resource                                   |
| m7  | minor       | Сидер неидемпотентен                                                           |
| m8  | minor       | Проблемы конфигурации окружения                                                |
| m9  | minor       | Нет return-типов у методов контроллера, `$id` без типа                         |
| m10 | minor       | Запись в лог после создания брони и отсутствие аудита удаления                 |
| m11 | minor       | Дефолтные данные `BookingFactory` пересекаются                                 |
| m12 | minor       | Разный формат ошибок у разных проверок                                         |

---

## Critical

### C1. SQL-инъекция в `store`

**Где:** `app/Http/Controllers/BookingController.php:23-26`.

```php
$conflict = DB::select("SELECT id FROM bookings
    WHERE room_id = {$request->room_id}
    AND starts_at <= '{$request->ends_at}'
    AND ends_at >= '{$request->starts_at}'");
```

**Почему critical:** значения из запроса подставляются в SQL без биндингов. Любой аутентифицированный пользователь может управлять SQL-выражением. Это не только «сломается на кавычке» — это полноценная инъекция.

**Что будет при эксплуатации:**

- `room_id: "1 OR 1=1"` изменит условие и вернёт чужие брони (минимум — boolean-инъекция).
- `room_id: 999` или отсутствие поля даст SQL-ошибку и `500`.
- `starts_at: "not-a-date"` даст исключение парсинга/SQL и `500`.
- При `APP_DEBUG=true` наружу утекут SQL и стек (в текущем `.env.example` debug включён).

**Как исправить:**

1. Сначала валидировать запрос (см. M2).
2. Убрать ручной SQL и использовать Query Builder/Eloquent:

```php
$conflict = Booking::query()
    ->where('room_id', $data['room_id'])
    ->where('starts_at', '<', $endUtc)
    ->where('ends_at', '>', $startUtc)
    ->exists();
```

Если нужен именно сырой SQL — только с биндингами:

```php
DB::select(
    'SELECT id FROM bookings WHERE room_id = ? AND starts_at < ? AND ends_at > ?',
    [$roomId, $endUtc, $startUtc]
);
```

3. Не выполнять запросы к БД, пока входные данные не прошли валидацию.
4. В продакшене `APP_DEBUG` должен быть `false`.

---

### C2. Любой пользователь может удалить чужую бронь (IDOR)

**Где:** `app/Http/Controllers/BookingController.php:45-50`.

```php
public function destroy($id)
{
    Booking::findOrFail($id)->delete();
    return response()->noContent();
}
```

**Почему critical:** проверяется только аутентификация, но не авторство. Пользователь с валидным токеном может перебирать `id` и удалять чужие брони. `findOrFail` находит бронь по глобальному ID, а не по связи с текущим пользователем.

**Как исправить:**

```php
public function destroy(Request $request, int $id)
{
    $booking = $request->user()->bookings()->findOrFail($id);
    $booking->delete();

    return response()->noContent();
}
```

Либо через policy:

```php
$this->authorize('delete', $booking);
```

`findOrFail` по связи пользователя вернёт `404` для чужой брони (не раскрывает её существование). Если нужен `403` — использовать policy и `authorize`.

**Тест:** автор удаляет — `204`; другой пользователь — `403/404`; несуществующая — `404`.

---

### C3. Подмена автора через `user_id`

**Где:** `app/Http/Controllers/BookingController.php:37` и `app/Models/Booking.php:11`.

```php
$booking = Booking::create($request->all());
```

`user_id` есть в `#[Fillable]`, поэтому значение из запроса попадает в модель.

**Почему critical:** автор брони должен определяться токеном, а не телом запроса. Иначе пользователь может создать бронь от имени другого сотрудника. Это ломает и права доступа, и данные для аудита.

**Как исправить:**

```php
$data = $request->validate([
    'room_id' => ['required', 'integer', 'exists:rooms,id'],
    'title' => ['required', 'string', 'max:255'],
    'starts_at' => ['required', 'date'],
    'ends_at' => ['required', 'date', 'after:starts_at'],
]);

$booking = Booking::create([
    ...$data,
    'user_id' => $request->user()->id,
    'starts_at' => $startUtc,
    'ends_at' => $endUtc,
]);
```

`user_id` можно оставить в `Fillable` для фабрик/сидера, но из HTTP-запроса его брать нельзя. `$request->all()` лучше не использовать вообще — только `$request->validated()`.

---

### C4. Время хранится без нормализации к UTC, проверки идут по часовому поясу клиента

**Где:** `app/Http/Controllers/BookingController.php:32-37`, `database/migrations/2026_10_29_000003_create_bookings_table.php:15-16`, `config/app.php:68`.

В миграции:

```php
$table->timestamp('starts_at');
$table->timestamp('ends_at');
```

В контроллере:

```php
$start = Carbon::parse($request->starts_at);
if ($start->hour < 9 || $start->hour >= 20) { ... }
...
$booking = Booking::create($request->all());
```

**Почему critical:** здесь сразу несколько ошибок, которые приводят к неверному времени и пропущенным пересечениям:

1. `Carbon::parse('2026-11-11T10:00:00+03:00')` сохраняет смещение `+03:00`.
2. Eloquent при записи в `timestamp` (без timezone) форматирует локальное «настенное» время: `10:00`, а не `07:00 UTC`. Смещение теряется.
3. При чтении приложение считает это время UTC и отдаёт `10:00Z`, то есть другой момент времени.
4. `$start->hour` — это час в смещении клиента, а не в часовом поясе офиса. Клиент может прислать `09:00+03:00` как `06:00Z` — код скажет «нерабочее время», хотя по Москве это 09:00.
5. Сравнение пересечений идёт по «настенным» значениям, поэтому один и тот же момент, присланный с разными смещениями, может не считаться пересечением.

**Как исправить:**

- Всегда приводить вход к UTC для хранения и сравнения.
- Для бизнес-проверок (рабочие часы, сетка времени) — приводить к часовому поясу офиса комнаты.

```php
$room = Room::with('office')->findOrFail($data['room_id']);
$officeTz = $room->office->timezone;

$startLocal = Carbon::parse($data['starts_at'])->setTimezone($officeTz);
$endLocal = Carbon::parse($data['ends_at'])->setTimezone($officeTz);

$startUtc = $startLocal->copy()->utc();
$endUtc = $endLocal->copy()->utc();
```

- Для БД выбрать один вариант:
    - `timestamptz` (`$table->timestampTz('starts_at')`) и хранить UTC; или
    - оставить `timestamp`, но жёстко хранить в нём UTC (и никогда не писать локальное время).
- В `config/app.php` уже стоит `'timezone' => 'UTC'` — это правильно; нельзя менять глобальный часовой пояс на Москву, иначе сломается Новосибирск.
- Существующие данные в проде нужно мигрировать/перезалить с пересчётом, иначе они останутся «сдвинутыми».

---

## Major

### M1. Брони «впритык» считаются пересечением

**Где:** `app/Http/Controllers/BookingController.php:25-26`.

```sql
AND starts_at <= '{$request->ends_at}'
AND ends_at >= '{$request->starts_at}'
```

Для интервалов обычно нужна полуоткрытая семантика `[start, end)`: 10:00–11:00 и 11:00–12:00 не пересекаются. С `<=`/`>=` они считаются конфликтом.

**Когда это точно баг:** если встреча «впритык» допустима. **Когда это правило:** если бизнес сознательно запрещает любые соседние брони; тогда текущее поведение корректно, но его стоит явно описать в README/тестах.

**Как исправить (если соседние встречи допустимы):**

```php
->where('starts_at', '<', $endUtc)
->where('ends_at', '>', $startUtc)
```

То есть пересечение есть только при `existing.starts_at < new.ends_at AND existing.ends_at > new.starts_at`.

---

### M2. Нет валидации запроса: 500 вместо 422

**Где:** `app/Http/Controllers/BookingController.php:21-43`. `$request->validate()`/FormRequest отсутствует полностью.

**Что будет при невалидных данных:**

- без `title` → `500` (NOT NULL);
- `room_id: 999` → `500` (нарушение внешнего ключа);
- `starts_at: "not-a-date"` → `500` (исключение парсинга);
- без `room_id` → `500` (SQL-ошибка).

**Как исправить:** добавить FormRequest или `$request->validate()` до любых запросов к БД:

```php
$data = $request->validate([
    'room_id' => ['required', 'integer', 'exists:rooms,id'],
    'title' => ['required', 'string', 'max:255'],
    'starts_at' => ['required', 'date'],
    'ends_at' => ['required', 'date', 'after:starts_at'],
]);
```

Дополнительно:

- `user_id` не принимать из запроса (C3).
- `room_id` проверять через `exists:rooms,id` (и/или `Room::findOrFail`).
- `title` ограничить длиной колонки (255).
- Невалидные даты должны давать `422` с понятными сообщениями, а не исключение `Carbon`/SQL.

---

### M3. Проверка рабочих часов смотрит только на час начала и не проверяет конец

**Где:** `app/Http/Controllers/BookingController.php:32-35`.

```php
$start = Carbon::parse($request->starts_at);
if ($start->hour < 9 || $start->hour >= 20) {
    return response()->json(['error' => 'Нерабочее время'], 422);
}
```

Код явно задумывает рабочие часы `09:00–20:00`, но проверяет только час начала:

- `19:00–23:00` проходит, хотя заканчивается после 20:00;
- `15:00–14:55` проходит, хотя конец раньше начала;
- конец не проверяется вообще;
- час берётся в смещении клиента, а не в часовом поясе офиса (см. C4), поэтому `09:00` по Москве, присланное как `06:00Z`, отклоняется.

**Как исправить:** после приведения к часовому поясу офиса (C4) проверить:

- `end > start`;
- начало не раньше `09:00`, конец не позже `20:00`;
- при необходимости — что начало и конец относятся к одному календарному дню в часовом поясе офиса.

```php
if (! $startLocal->isSameDay($endLocal)) {
    throw ValidationException::withMessages(['ends_at' => 'Бронь должна быть в пределах одного дня.']);
}

$startMinutes = $startLocal->hour * 60 + $startLocal->minute;
$endMinutes = $endLocal->hour * 60 + $endLocal->minute;

if ($startMinutes < 9 * 60 || $endMinutes > 20 * 60 || $endMinutes <= $startMinutes) {
    throw ValidationException::withMessages(['starts_at' => 'Рабочее время: 09:00–20:00.']);
}
```

Важно: `20:00` как конец допустим, `20:00` как начало — нет.

---

### M4. Гонка между проверкой конфликта и вставкой

**Где:** `app/Http/Controllers/BookingController.php:23-37`.

Проверка `$conflict` и `Booking::create()` не находятся в транзакции и не блокируют комнату. Два одновременных запроса на один слот могут оба пройти проверку и создать две брони.

**Как исправить:**

1. Транзакция + блокировка комнаты:

```php
DB::transaction(function () use ($room, $startUtc, $endUtc, $data, $request) {
    Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

    $exists = Booking::where('room_id', $room->id)
        ->where('starts_at', '<', $endUtc)
        ->where('ends_at', '>', $startUtc)
        ->exists();

    if ($exists) {
        throw ValidationException::withMessages(['starts_at' => 'Комната занята.']);
    }

    Booking::create([...]);
});
```

2. Ограничение на уровне PostgreSQL (надёжнее для нескольких инстансов):

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE bookings
ADD CONSTRAINT bookings_no_overlap
EXCLUDE USING gist (
    room_id WITH =,
    tstzrange(starts_at, ends_at, '[)') WITH &&
);
```

При `timestamp` без timezone использовать `tsrange(starts_at, ends_at, '[)')`. Ошибку нарушения (`SQLSTATE 23P01`) ловить и возвращать `422`.

3. Дополнительно можно добавить `CHECK (ends_at > starts_at)`.

---

### M5. Нет тестов на `store`, `destroy` и `today`

**Где:** `tests/Feature/BookingIndexTest.php` — единственный feature-тест; `tests/Unit` пуст.

Сейчас тесты проверяют только «список своих броней» и «401 без токена». Основные сценарии не покрыты, поэтому ошибки C1–C4 и M1–M3 не обнаруживаются тестами.

**Что добавить:**

- создание брони: успех, невалидные поля, несуществующая комната;
- пересечение той же комнаты и разные комнаты;
- часовые пояса `+03:00`, `+07:00`, `Z` и одинаковые моменты в разных смещениях;
- удаление автором / чужим пользователем / несуществующей брони;
- `index` не показывает чужие брони;
- запрос без `Accept` без токена → `401`, а не `500`;
- `today` для офиса в другом часовом поясе (в feature-тестах удобно зафиксировать время через `Carbon::setTestNow()`).

---

### M6. Без `Accept: application/json` запрос без токена возвращает 500 вместо 401

**Где:** `bootstrap/app.php:15-21`.

`shouldRenderJsonWhen()` для `api/*` настроен правильно, но `withMiddleware()` пустой, поэтому `ApplicationBuilder` подставляет свой дефолт:

```php
->redirectGuestsTo(fn () => route('login'))
```

Роута `login` в проекте нет, поэтому `AuthenticationException` падает с `RouteNotFoundException` ещё до обработчика исключений.

**Поведение:**

```text
GET /api/bookings без Accept и без токена -> 500 Route [login] not defined
GET /api/bookings с Accept: application/json -> 401 {"message":"Unauthenticated."}
```

**Как исправить:** переопределить редирект для API:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn (Request $request) => $request->is('api/*') ? null : route('login')
    );
})
```

Для API-only приложения можно проще: `$middleware->redirectGuestsTo(fn () => null);`. После этого сработает `shouldRenderJsonWhen` и вернётся `401`.

Добавить тест: `$this->get('/api/bookings')` (без `getJson`) → `401`.

---

### M7. `today` использует UTC/`today()` вместо часового пояса офиса

**Где:** `app/Http/Controllers/BookingController.php:52-61`.

```php
$room->bookings()
    ->whereDate('starts_at', today())
```

`today()` возвращает дату в `config('app.timezone')` — сейчас это UTC. Для Новосибирска (UTC+7) локальные сутки не совпадают с UTC:

- после 17:00 UTC в Новосибирске уже следующий день, а API продолжает отдавать «вчера»;
- для Москвы то же самое после 21:00 UTC.

Плюс из-за C4 в БД лежит «настенное» время, поэтому даже корректный диапазон будет сравниваться неправильно.

**Как исправить:**

```php
$office = $room->office;
$tz = $office->timezone;

$dayStart = Carbon::now($tz)->startOfDay()->utc();
$dayEnd = $dayStart->copy()->addDay();

return $room->bookings()
    ->with('user')
    ->where('starts_at', '<', $dayEnd)
    ->where('ends_at', '>', $dayStart)
    ->orderBy('starts_at')
    ->get()
    ->map(fn ($b) => [
        'time' => $b->starts_at->setTimezone($tz)->format('H:i')
            .' – '.$b->ends_at->setTimezone($tz)->format('H:i'),
        'author' => $b->user->name,
    ]);
```

Если «сегодня» должно включать брони, начавшиеся вчера и продолжающиеся сегодня, нужен именно пересечение с диапазоном дня, а не `whereDate(starts_at, ...)`.

---

## Minor

### m1. `Log::withContext` не попадает в канал `bookings`

**Где:** `app/Http/Controllers/BookingController.php:39-40`.

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

`Log::withContext()` применяется к драйверу по умолчанию, а `Log::channel('bookings')` — отдельный логгер. В `storage/logs/bookings.log` записи есть, но `booking_id` в них нет.

**Как исправить:**

```php
Log::channel('bookings')->info('Booking created', [
    'booking_id' => $booking->id,
    'room_id' => $booking->room_id,
    'user_id' => $booking->user_id,
    'starts_at' => $booking->starts_at,
    'ends_at' => $booking->ends_at,
]);
```

или `Log::channel('bookings')->withContext([...])->info('Booking created')`.

---

### m2. `today` отдаёт полную дату и секунды вместо времени

**Где:** `app/Http/Controllers/BookingController.php:58`.

```php
'time' => $b->starts_at . ' – ' . $b->ends_at,
```

Фактический ответ: `"2026-11-10 13:00:00 – 2026-11-10 14:00:00"`, а не `"13:00 – 14:00"`. Плюс время в UTC, а не в часовом поясе офиса.

**Как исправить:** формировать строку через `->setTimezone($officeTz)->format('H:i')` (см. M7).

---

### m3. N+1 при загрузке авторов в `today`

**Где:** `app/Http/Controllers/BookingController.php:59`.

`$b->user->name` внутри `map()` делает отдельный запрос на каждую бронь.

**Как исправить:** `->with('user')` до `get()`. Это убирает N+1; если пользователь когда-либо сможет быть удалён, дополнительно предусмотреть `?->name`.

---

### m4. Нет индексов под проверку пересечений и `today`

**Где:** `database/migrations/2026_10_29_000003_create_bookings_table.php`.

В PostgreSQL `foreignId()->constrained()` создаёт внешний ключ, но не индекс; в миграции `bookings` индексов на `room_id`/`starts_at`/`ends_at` нет. Для запросов `room_id + starts_at/ends_at` и `today` нужен составной индекс:

```php
$table->index(['room_id', 'starts_at', 'ends_at']);
$table->index('starts_at');
```

Для `whereDate()` лучше перейти на диапазон `starts_at >= $dayStart AND starts_at < $dayEnd`, тогда индекс будет использоваться.

---

### m5. Нет DB-ограничений на `ends_at > starts_at` и уникальность комнат

**Где:** миграции `bookings`, `rooms`.

- Нет `CHECK (ends_at > starts_at)`, поэтому даже после валидации можно записать «отрицательную» бронь через прямой SQL/баг.
- Нет уникальности `(office_id, name)` у комнат и `name` у офисов — можно создать дубли.

**Как исправить:** добавить check constraint и unique-индексы (если это соответствует бизнес-правилам). Для пересечений — см. M4.

---

### m6. `index` отдаёт сырые модели без API Resource

**Где:** `app/Http/Controllers/BookingController.php:14-19`.

Пагинатор возвращает `user_id`, `created_at`, `updated_at` и не отдаёт название комнаты/офиса. Для API это не ошибка, но клиенту приходится делать лишние запросы, а внутренние поля утекают наружу.

**Как исправить:** `BookingResource` с полями `id`, `title`, `starts_at`, `ends_at`, `room` (id/name/office), `user` (id/name, если нужно).

---

### m7. Сидер неидемпотентен

**Где:** `database/seeders/DatabaseSeeder.php:16-30`.

Повторный `php artisan db:seed` создаст вторые офисы/комнаты и упадёт на уникальном `email` пользователей.

**Как исправить:** `firstOrCreate`/`updateOrCreate` для офисов и пользователей, либо `truncate` перед заполнением, либо явно документировать, что сидер запускается только после `migrate:fresh`.

---

### m8. Проблемы конфигурации окружения

- `.env.example:4` — `APP_DEBUG=true`. Если скопировать пример в прод, наружу будут утекать SQL и стеки (особенно на ошибках из C1/M2). Для примера лучше `APP_DEBUG=false`, а локально включать явно.
- `composer.json` требует `php: ^8.3`, а README/Docker используют PHP 8.4. Если проект действительно требует 8.4, заменить на `^8.4`; если 8.3 поддерживается — обновить README.
- `phpunit.xml:26-28` задаёт только `DB_CONNECTION`/`DB_DATABASE`; `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` берутся из `.env`. В Docker это работает, но тесты не самодостаточны. Лучше добавить `.env.testing`.
- В проекте нет `.dockerignore`, поэтому в build context попадают `vendor`, `.git` и т.п. Для dev это не критично, но замедляет сборку.

---

### m9. Нет return-типов у методов контроллера, `$id` без типа

**Где:** `app/Http/Controllers/BookingController.php`.

`index`, `store`, `destroy`, `today` не имеют return-типов; `destroy($id)` принимает `mixed`. По правилам проекта нужны явные типы.

**Как исправить:**

```php
public function index(Request $request): LengthAwarePaginator
public function store(StoreBookingRequest $request): JsonResponse
public function destroy(Request $request, int $id): Response
public function today(Room $room): Collection
```

Точные типы зависят от того, оставите ли вы возврат модели/пагинатора или перейдёте на API Resources.

---

### m10. Запись в лог после создания брони и отсутствие аудита удаления

**Где:** `app/Http/Controllers/BookingController.php:37-40`, `45-50`.

- Если запись в лог упадёт, клиент получит `500`, хотя бронь уже создана.
- При удалении ничего не логируется, поэтому отмену нельзя восстановить/расследовать.

**Как исправить:** логировать в `DB::afterCommit()` или оборачивать лог в `try/catch`; при удалении писать запись в `bookings.log` с `booking_id`, `user_id` и временем. Для аудита можно добавить `deleted_at` (soft deletes) вместо физического удаления.

---

### m11. Дефолтные данные `BookingFactory` пересекаются

**Где:** `database/factories/BookingFactory.php:17-24`.

Все брони по умолчанию создаются на «завтра 10:00–11:00» и с новой комнатой, но если в тесте явно указать одну комнату, несколько фабричных броней пересекутся. Это не продовый баг, но тесты легко получить нестабильными.

**Как исправить:** добавить состояния/параметры для времени и комнаты, например `forRoom($room, $start)`, или использовать `fake()->unique()`/смещение по индексу.

---

### m12. Разный формат ошибок у разных проверок

**Где:** `app/Http/Controllers/BookingController.php:28-35`.

- Конфликт возвращает `409` и `{"error": "..."}`.
- «Нерабочее время» возвращает `422` и `{"error": "..."}`.
- Будущая валидация Laravel вернёт `422` и `{"message": ..., "errors": ...}`.

Сам по себе `409 Conflict` для пересечения — нормальный HTTP-статус, это не ошибка. Проблема в том, что клиенту придётся поддерживать два разных формата ошибок.

**Как исправить:** выбрать единый контракт. Например, все ошибки правил — `422` с `message/errors`, а `409` оставить только если это осознанное решение и оно описано в README.

---

## Подозрительные места, которые проблемой не являются

Чтобы не создавать ложных замечаний:

- **`index` не фильтрует по `user_id` из запроса, а берёт `$request->user()->id`** — это правильно, чужие брони не утекают.
- **Сидер хранит `hash('sha256', $token)`** — это корректный формат для Sanctum, а не «сырой пароль в БД». Sanctum сам хеширует токен при поиске.
- **Возврат модели из `store`** — Laravel сам отдаёт `201 Created` и JSON; это не ошибка. Если нужен строгий контракт, лучше Resource + `Location`, но это не баг.
- **`today` показывает брони всех пользователей комнаты** — вероятно, это задумано для табло/расписания переговорной. Если это не так, нужно ограничить видимость; но по текущему README endpoint выглядит общим.
- **Отсутствие запрета на брони в прошлом** — не баг, если такого бизнес-требования нет.
- **`409` для пересечения** — семантически допустимый HTTP-статус (см. m12). Это вопрос контракта, а не «сломанный» код.
- **Замыкание в `shouldRenderJsonWhen` принимает только `Request`** — это нормально: обработчик передаёт второй аргумент, но PHP-замыкание может его игнорировать. Проблема не здесь, а в `redirectGuestsTo` (M6).
- **Постоянные демо-токены и `expiration => null`** — осознанное решение для демо, описано в README. Для продакшена так делать нельзя, но в рамках демо это не дефект.
- **`today` фильтрует по `starts_at`, а не по пересечению с днём** — возможно, это осознанное упрощение. Если нужны «брони, идущие сейчас», поведение надо менять (см. M7).

---

## Порядок исправлений

1. **Сначала C1–C4** — безопасность и корректность времени/пересечений.
2. **Затем M1–M3** — конфликты, валидация и рабочие часы.
3. **M4–M7** — гонка, тесты, 401 и `today`.
4. **Minor** — по мере возможности; они не блокируют основную функциональность.

После исправлений обязательно прогнать:

```sh
docker compose exec app php artisan test
```
