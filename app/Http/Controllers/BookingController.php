<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $room = $request->room();
        $startsAt = $request->startsAtUtc();
        $endsAt = $request->endsAtUtc();

        $booking = DB::transaction(function () use ($request, $room, $startsAt, $endsAt): Booking {
            // Serialize concurrent bookings of the same room so the overlap check and the insert are atomic.
            Room::whereKey($room->id)->lockForUpdate()->first();

            $isRoomBusy = Booking::where('room_id', $room->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($isRoomBusy) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The room is already booked for this time.',
                ]);
            }

            return Booking::create([
                'room_id' => $room->id,
                'user_id' => $request->user()->id,
                'title' => $request->string('title')->toString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, int $id): Response
    {
        $booking = Booking::findOrFail($id);

        abort_unless($booking->user_id === $request->user()->id, 403, 'Only the author can cancel a booking.');

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
