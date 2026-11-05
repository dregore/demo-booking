<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingDatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_overlapping_bookings_of_one_room(): void
    {
        $room = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        $this->expectException(QueryException::class);

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:30:00',
            'ends_at' => '2026-11-02 08:30:00',
        ]);
    }

    public function test_database_allows_back_to_back_bookings_of_one_room(): void
    {
        $room = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 08:00:00',
            'ends_at' => '2026-11-02 09:00:00',
        ]);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_database_rejects_booking_that_ends_before_it_starts(): void
    {
        $this->expectException(QueryException::class);

        Booking::factory()->create([
            'starts_at' => '2026-11-02 08:00:00',
            'ends_at' => '2026-11-02 07:00:00',
        ]);
    }
}
