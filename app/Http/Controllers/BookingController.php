<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Rules\ValidBookingDuration;
use App\Rules\WithinOfficeWorkingHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $previousLocale = app()->getLocale();
        app()->setLocale('ru');

        try {
            $request->validate([
                'room_id' => ['required', 'integer', 'exists:rooms,id'],
            ]);

            $room = Room::query()
                ->with('office')
                ->findOrFail($request->integer('room_id'));

            $timezone = $room->office->timezone;

            $validated = $request->validate([
                'starts_at' => ['required', 'date'],
                'ends_at' => [
                    'required',
                    'date',
                    new ValidBookingDuration($timezone),
                    new WithinOfficeWorkingHours($timezone),
                ],
            ]);
        } finally {
            app()->setLocale($previousLocale);
        }

        $startUtc = Carbon::parse($validated['starts_at'])->utc();
        $endUtc = Carbon::parse($validated['ends_at'])->utc();

        $conflict = Booking::query()
            ->overlapping($room->id, $startUtc, $endUtc)
            ->exists();

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        $booking = Booking::create([
            ...$request->except(['starts_at', 'ends_at']),
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
        ]);

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy(Request $request, int $id): Response
    {
        Booking::query()
            ->ownedBy($request->user())
            ->findOrFail($id)
            ->delete();

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
