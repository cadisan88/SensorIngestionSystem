<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EmulateGateway extends Command
{
    protected $signature = 'gateway:emulate
        {event_type : OCCUPIED, VACANT, HEARTBEAT or ERROR. Other values are sent too, to test validation.}
        {sensor_uid=sensor-99f4a1 : The sensor that sends the packet}
        {--count=1 : How many times to send the same packet. More than 1 creates duplicates.}
        {--offset=0 : Seconds added to the current time. Negative means a late packet.}
        {--timestamp= : Exact Unix timestamp. Replaces --offset.}
        {--gateway=gw-01 : The gateway id}
        {--battery=98.5 : Battery level in percent}
        {--temperature=14.2 : Temperature in Celsius}
        {--rssi=-85 : Signal strength in dBm}
        {--token= : Gateway token. Default: the configured token.}
        {--url= : Ingress URL. Default: the configured URL.}';

    protected $description = 'Send test packets to the ingress endpoint, like a real gateway does.';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $url = (string) ($this->option('url') ?? config('ingress.emulator_url'));
        $token = (string) ($this->option('token') ?? config('ingress.gateway_token'));
        $packet = $this->packet();

        $this->info('Packet: '.json_encode($packet, JSON_PRETTY_PRINT));

        $allAccepted = true;

        for ($i = 1; $i <= $count; $i++) {
            try {
                $response = Http::withHeaders(['X-Gateway-Token' => $token])
                    ->acceptJson()
                    ->timeout(10)
                    ->post($url, $packet);
            } catch (ConnectionException $e) {
                $this->error("Could not reach {$url}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->line("[{$i}/{$count}] {$response->status()} {$response->body()}");

            $allAccepted = $allAccepted && $response->status() === 202;
        }

        return $allAccepted ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        $timestamp = $this->option('timestamp') ?? now()->timestamp + (int) $this->option('offset');

        return [
            'sensor_uid' => $this->argument('sensor_uid'),
            'gateway_id' => $this->option('gateway'),
            'event_type' => $this->argument('event_type'),
            'payload' => [
                'battery_level_pct' => (float) $this->option('battery'),
                'temperature_c' => (float) $this->option('temperature'),
                'signal_rssi_dbm' => (int) $this->option('rssi'),
            ],
            'timestamp' => (int) $timestamp,
        ];
    }
}
