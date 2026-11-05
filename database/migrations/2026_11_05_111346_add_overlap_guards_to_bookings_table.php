<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level guards for bookings: a valid time range and no overlaps within one room.
     * Back-to-back bookings are allowed because the range is half-open: [starts_at, ends_at).
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_ends_after_starts CHECK (ends_at > starts_at)');

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (room_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_ends_after_starts');
    }
};
