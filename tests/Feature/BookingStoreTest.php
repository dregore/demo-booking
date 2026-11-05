<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $moscowRoom;

    private Room $novosibirskRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->moscowRoom = Room::factory()->for(Office::factory())->create();
        $this->novosibirskRoom = Room::factory()->for(Office::factory()->novosibirsk())->create();

        Sanctum::actingAs($this->user);
    }

    private function book(Room $room, string $startsAt, string $endsAt, array $extra = []): TestResponse
    {
        return $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Planning',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...$extra,
        ]);
    }

    private function seedBooking(Room $room, string $startsAt, string $endsAt): Booking
    {
        return Booking::factory()->for($room)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function test_creates_booking_in_moscow_office_and_stores_utc(): void
    {
        $this->book($this->moscowRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->moscowRoom->id,
            'user_id' => $this->user->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_creates_booking_in_novosibirsk_office_and_stores_utc(): void
    {
        $this->book($this->novosibirskRoom, '2026-11-02T10:00:00+07:00', '2026-11-02T11:00:00+07:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->novosibirskRoom->id,
            'starts_at' => '2026-11-02 03:00:00',
            'ends_at' => '2026-11-02 04:00:00',
        ]);
    }

    public function test_author_is_the_authenticated_user_not_the_payload(): void
    {
        $other = User::factory()->create();

        $this->book(
            $this->moscowRoom,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
            ['user_id' => $other->id],
        )->assertCreated();

        $this->assertDatabaseHas('bookings', ['user_id' => $this->user->id]);
        $this->assertDatabaseMissing('bookings', ['user_id' => $other->id]);
    }

    public function test_past_dates_are_allowed(): void
    {
        $this->book($this->moscowRoom, '2020-01-15T10:00:00+03:00', '2020-01-15T11:00:00+03:00')
            ->assertCreated();
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer invalid')
            ->postJson('/api/bookings', [])
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string, string}> new start, new end (MSK) against an existing 10:00-11:00; offending field
     */
    public static function overlappingProvider(): array
    {
        return [
            'identical' => ['10:00', '11:00', 'starts_at'],
            'starts inside existing' => ['10:30', '11:30', 'starts_at'],
            'ends inside existing' => ['09:30', '10:30', 'starts_at'],
            'contained in existing' => ['10:15', '10:45', 'starts_at'],
            'contains existing' => ['09:30', '11:30', 'starts_at'],
        ];
    }

    #[DataProvider('overlappingProvider')]
    public function test_rejects_overlapping_booking(string $from, string $to, string $errorKey): void
    {
        $this->seedBooking($this->moscowRoom, '2026-11-02 07:00:00', '2026-11-02 08:00:00'); // 10:00-11:00 MSK

        $this->book($this->moscowRoom, "2026-11-02T{$from}:00+03:00", "2026-11-02T{$to}:00+03:00")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_booking_right_after_existing_one(): void
    {
        $this->seedBooking($this->moscowRoom, '2026-11-02 07:00:00', '2026-11-02 08:00:00');

        $this->book($this->moscowRoom, '2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00')
            ->assertCreated();
    }

    public function test_allows_booking_right_before_existing_one(): void
    {
        $this->seedBooking($this->moscowRoom, '2026-11-02 07:00:00', '2026-11-02 08:00:00');

        $this->book($this->moscowRoom, '2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00')
            ->assertCreated();
    }

    public function test_allows_same_time_in_another_room(): void
    {
        $this->seedBooking($this->moscowRoom, '2026-11-02 07:00:00', '2026-11-02 08:00:00');
        $anotherRoom = Room::factory()->for($this->moscowRoom->office)->create();

        $this->book($anotherRoom, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();
    }

    public function test_overlap_is_detected_when_client_uses_another_offset(): void
    {
        $this->seedBooking($this->moscowRoom, '2026-11-02 07:00:00', '2026-11-02 08:00:00'); // 10:00-11:00 MSK

        $this->book($this->moscowRoom, '2026-11-02T07:30:00Z', '2026-11-02T08:30:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * @return array<string, array{string, string}> start, end (MSK)
     */
    public static function acceptedDurationsProvider(): array
    {
        return [
            '15 minutes' => ['10:00', '10:15'],
            '45 minutes' => ['10:00', '10:45'],
            '4 hours' => ['10:00', '14:00'],
        ];
    }

    #[DataProvider('acceptedDurationsProvider')]
    public function test_accepts_valid_durations(string $from, string $to): void
    {
        $this->book($this->moscowRoom, "2026-11-02T{$from}:00+03:00", "2026-11-02T{$to}:00+03:00")
            ->assertCreated();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedDurationsProvider(): array
    {
        return [
            'zero length' => ['10:00', '10:00'],
            '14 minutes' => ['10:00', '10:14'],
            'ends before it starts' => ['11:00', '10:00'],
            '4 hours 15 minutes' => ['10:00', '14:15'],
            'not a 15 minute step' => ['10:00', '10:50'],
        ];
    }

    #[DataProvider('rejectedDurationsProvider')]
    public function test_rejects_invalid_durations(string $from, string $to): void
    {
        $this->book($this->moscowRoom, "2026-11-02T{$from}:00+03:00", "2026-11-02T{$to}:00+03:00")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_rejects_start_not_on_a_15_minute_step(): void
    {
        $this->book($this->moscowRoom, '2026-11-02T10:07:00+03:00', '2026-11-02T11:07:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_times_with_seconds(): void
    {
        $this->book($this->moscowRoom, '2026-11-02T10:00:30+03:00', '2026-11-02T11:00:30+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * @return array<string, array{string, string, string}> start, end (local time), offending field
     */
    public static function workingHoursProvider(): array
    {
        return [
            'starts before 09:00' => ['08:45', '09:45', 'starts_at'],
            'ends after 20:00' => ['19:30', '20:15', 'ends_at'],
            'late evening' => ['21:00', '22:00', 'starts_at'],
            'night' => ['02:00', '03:00', 'starts_at'],
        ];
    }

    #[DataProvider('workingHoursProvider')]
    public function test_rejects_booking_outside_working_hours_in_moscow(string $from, string $to, string $errorKey): void
    {
        $this->book($this->moscowRoom, "2026-11-02T{$from}:00+03:00", "2026-11-02T{$to}:00+03:00")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);
    }

    #[DataProvider('workingHoursProvider')]
    public function test_rejects_booking_outside_working_hours_in_novosibirsk(string $from, string $to, string $errorKey): void
    {
        $this->book($this->novosibirskRoom, "2026-11-02T{$from}:00+07:00", "2026-11-02T{$to}:00+07:00")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);
    }

    public function test_accepts_booking_exactly_at_working_hours_boundaries(): void
    {
        $this->book($this->moscowRoom, '2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00')
            ->assertCreated();
        $this->book($this->moscowRoom, '2026-11-02T19:00:00+03:00', '2026-11-02T20:00:00+03:00')
            ->assertCreated();
    }

    public function test_rejects_booking_that_crosses_midnight(): void
    {
        $this->book($this->moscowRoom, '2026-11-02T19:00:00+03:00', '2026-11-03T00:00:00+03:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_working_hours_follow_office_timezone_not_client_offset(): void
    {
        // 03:00Z is 10:00 in Novosibirsk (valid) but 06:00 in Moscow (invalid).
        $this->book($this->novosibirskRoom, '2026-11-02T03:00:00Z', '2026-11-02T04:00:00Z')
            ->assertCreated();

        $this->book($this->moscowRoom, '2026-11-02T03:00:00Z', '2026-11-02T04:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_novosibirsk_room_accepts_client_offset_of_another_zone(): void
    {
        // 06:00+03:00 is 10:00 in Novosibirsk.
        $this->book($this->novosibirskRoom, '2026-11-02T06:00:00+03:00', '2026-11-02T07:00:00+03:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', ['starts_at' => '2026-11-02 03:00:00']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing room' => [['room_id' => null], 'room_id'],
            'unknown room' => [['room_id' => 999999], 'room_id'],
            'non-numeric room' => [['room_id' => '1 OR 1=1'], 'room_id'],
            'missing title' => [['title' => null], 'title'],
            'missing starts_at' => [['starts_at' => null], 'starts_at'],
            'missing ends_at' => [['ends_at' => null], 'ends_at'],
            'garbage starts_at' => [['starts_at' => 'tomorrow-ish'], 'starts_at'],
            'starts_at without offset' => [['starts_at' => '2026-11-02T10:00:00'], 'starts_at'],
            'sql in ends_at' => [['ends_at' => "x' OR '1'='1"], 'ends_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_rejects_invalid_payload(array $override, string $errorKey): void
    {
        $payload = array_merge([
            'room_id' => $this->moscowRoom->id,
            'title' => 'Planning',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $override);

        $this->postJson('/api/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('bookings', 0);
    }
}
