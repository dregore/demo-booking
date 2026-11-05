<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const ISO_8601_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    private const STEP_MINUTES = 15;

    private const MIN_DURATION_MINUTES = 15;

    private const MAX_DURATION_MINUTES = 240;

    private const OPENING_MINUTE_OF_DAY = 9 * 60;

    private const CLOSING_MINUTE_OF_DAY = 20 * 60;

    private ?Room $room = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', 'regex:'.self::ISO_8601_WITH_OFFSET, 'date'],
            'ends_at' => ['required', 'string', 'regex:'.self::ISO_8601_WITH_OFFSET, 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.regex' => 'The starts at must be an ISO 8601 date-time with a UTC offset, e.g. 2026-11-02T10:00:00+03:00.',
            'ends_at.regex' => 'The ends at must be an ISO 8601 date-time with a UTC offset, e.g. 2026-11-02T11:00:00+03:00.',
        ];
    }

    /**
     * Business rules that depend on the room's office timezone. They run only when the basic rules passed.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateBookingTimes($validator);
            },
        ];
    }

    public function room(): Room
    {
        return $this->room ??= Room::with('office')->findOrFail($this->integer('room_id'));
    }

    public function startsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('starts_at')->toString())->utc();
    }

    public function endsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_at')->toString())->utc();
    }

    private function validateBookingTimes(Validator $validator): void
    {
        $timezone = $this->room()->office->timezone;
        $start = $this->startsAtUtc()->setTimezone($timezone);
        $end = $this->endsAtUtc()->setTimezone($timezone);

        $this->validateStep($validator, 'starts_at', $start);
        $this->validateStep($validator, 'ends_at', $end);

        if ($end->lessThanOrEqualTo($start)) {
            $validator->errors()->add('ends_at', 'The ends at must be after the starts at.');

            return;
        }

        $durationMinutes = $start->diffInMinutes($end);

        if ($durationMinutes < self::MIN_DURATION_MINUTES || $durationMinutes > self::MAX_DURATION_MINUTES) {
            $validator->errors()->add('ends_at', 'A booking must last from 15 minutes to 4 hours.');
        }

        $this->validateWorkingHours($validator, $start, $end, $timezone);
    }

    private function validateStep(Validator $validator, string $field, CarbonImmutable $moment): void
    {
        $isOnStep = $moment->second === 0
            && $moment->micro === 0
            && $moment->minute % self::STEP_MINUTES === 0;

        if (! $isOnStep) {
            $validator->errors()->add($field, 'The '.str_replace('_', ' ', $field).' must be on a 15 minute step (e.g. 10:00, 10:15) in the office time.');
        }
    }

    private function validateWorkingHours(Validator $validator, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $message = "A booking must fit into working hours 09:00-20:00 of the office ({$timezone}).";
        $startMinute = $start->hour * 60 + $start->minute;
        $endMinute = $end->hour * 60 + $end->minute;

        if ($startMinute < self::OPENING_MINUTE_OF_DAY || $startMinute >= self::CLOSING_MINUTE_OF_DAY) {
            $validator->errors()->add('starts_at', $message);
        }

        $endsOnAnotherDay = ! $end->isSameDay($start);

        if ($endsOnAnotherDay || $endMinute > self::CLOSING_MINUTE_OF_DAY || $endMinute <= self::OPENING_MINUTE_OF_DAY) {
            $validator->errors()->add('ends_at', $message);
        }
    }
}
