<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Rules\ValidBookingDuration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', new ValidBookingDuration],
        ]);

        $start = Carbon::parse($validated['starts_at']);
        $end = Carbon::parse($validated['ends_at']);

        $conflict = Booking::query()
            ->overlapping($request->integer('room_id'), $start, $end)
            ->exists();

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        if ($start->hour < 9 || $start->hour >= 20) {
            return response()->json(['error' => 'Нерабочее время'], 422);
        }

        $booking = Booking::create($request->all());

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

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
