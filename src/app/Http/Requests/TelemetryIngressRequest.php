<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TelemetryIngressRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $latestAllowed = now()->timestamp + (int) config('ingress.max_future_seconds');

        return [
            'sensor_uid' => ['required', 'string', 'max:64'],

            // Required, but not stored or compared against anything.
            'gateway_id' => ['required', 'string', 'max:64'],

            'event_type' => ['required', Rule::in(array_keys(config('rabbitmq.routing_keys')))],

            // These ranges fit the sensor_readings columns.
            'payload' => ['sometimes', 'array'],
            'payload.battery_level_pct' => ['nullable', 'numeric', 'between:0,100'],
            'payload.temperature_c' => ['nullable', 'numeric', 'between:-999.99,999.99'],
            'payload.signal_rssi_dbm' => ['nullable', 'integer', 'between:-200,0'],

            // No limit for old timestamps: late packets are expected.
            'timestamp' => ['required', 'integer', 'min:1', "max:{$latestAllowed}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timestamp.max' => 'The timestamp is too far in the future.',
        ];
    }
}
