<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\Ride;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

// Streams rows with cursor() (constant memory) → S3 file → marks export ready.
class ExportReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReportExport $export) {}

    public function handle(): void
    {
        $export = $this->export;
        $export->update(['status' => 'processing']);

        try {
            // Run tenant-scoped: jobs have no request, so bind the tenant explicitly.
            app()->instance('tenant', $export->tenant);

            $rows = $this->collectRows();
            $path = "tenants/{$export->tenant_id}/exports/{$export->id}.{$export->filters['format']}";

            Storage::disk('s3')->put($path, $this->serialize($rows, $export->filters['format']));
            $export->update(['status' => 'ready', 'file_path' => $path, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function collectRows(): array
    {
        $filters = $this->export->filters;

        $query = Ride::with(['status', 'activeAssignment.driver.user'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('scheduled_pickup_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('scheduled_pickup_at', '<=', $to))
            ->orderBy('id');

        $rows = [];
        foreach ($query->cursor() as $ride) { // cursor() = no OFFSET, constant memory
            $rows[] = [
                'id' => $ride->id,
                'status' => $ride->status->code,
                'passenger' => $ride->passenger_info['name'] ?? null,
                'pickup' => $ride->pickup_address,
                'dropoff' => $ride->dropoff_address,
                'scheduled_pickup_at' => $ride->scheduled_pickup_at->toIso8601String(),
                'driver' => $ride->activeAssignment?->driver?->user?->name,
            ];
        }

        return $rows;
    }

    private function serialize(array $rows, string $format): string
    {
        if ($format === 'json') {
            return json_encode($rows, JSON_PRETTY_PRINT);
        }

        $out = fopen('php://temp', 'r+');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }

        return stream_get_contents($out, -1, 0) ?: '';
    }
}
