<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_time_order_check
            CHECK (starts_at < ends_at)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_room_time_exclude
            EXCLUDE USING gist (
                room_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_room_time_exclude');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_time_order_check');
    }
};
