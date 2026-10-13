<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Models\ReportExport;
use App\Models\Ride;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** Tenant-level metrics: counts by status + per-driver performance. */
    public function metrics(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // Date inputs arrive as YYYY-MM-DD: cover the full days, otherwise a
        // same-day query (the picker's default) only matches exact midnight.
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $tenantId = app('tenant')->id;

        // Ride carries the tenant global scope, so this stays inside the agency.
        $byStatus = Ride::query()
            ->join('ride_statuses', 'rides.status_id', '=', 'ride_statuses.id')
            ->whereBetween('rides.scheduled_pickup_at', [$from, $to])
            ->groupBy('ride_statuses.code')
            ->select('ride_statuses.code', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'code')
            ->map(fn ($total) => (int) $total);

        // Joined tables ignore the tenant scope, so scope rides explicitly.
        // Counts mirror DriverController::report: active assignments only.
        $perDriver = DB::table('ride_assignments')
            ->join('rides', 'ride_assignments.ride_id', '=', 'rides.id')
            ->join('ride_statuses', 'rides.status_id', '=', 'ride_statuses.id')
            ->where('ride_assignments.tenant_id', $tenantId)
            ->where('rides.tenant_id', $tenantId)
            ->whereNull('ride_assignments.unassigned_at')
            ->whereBetween('rides.scheduled_pickup_at', [$from, $to])
            ->groupBy('ride_assignments.driver_profile_id')
            ->orderBy('ride_assignments.driver_profile_id')
            ->selectRaw('ride_assignments.driver_profile_id as driver_profile_id,
                COUNT(*) as assigned,
                COALESCE(SUM(ride_statuses.code = ?), 0) as completed,
                AVG(TIMESTAMPDIFF(MINUTE, rides.scheduled_pickup_at, rides.actual_pickup_at)) as avg_pickup_delay_min',
                ['completed'])
            ->get()
            ->map(fn ($row) => [
                'driver_profile_id' => (int) $row->driver_profile_id,
                'assigned' => (int) $row->assigned,
                'completed' => (int) $row->completed,
                'avg_pickup_delay_min' => $row->avg_pickup_delay_min === null ? null : (float) $row->avg_pickup_delay_min,
            ])
            ->values();

        return response()->json(['by_status' => $byStatus, 'per_driver' => $perDriver]);
    }

    /** Queue an async CSV/JSON export; poll show() until ready. */
    public function export(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:rides.daily,rides.range,driver.performance,tenant.metrics'],
            'format' => ['required', 'string', 'in:csv,json'],
            'from' => ['required_if:type,rides.range', 'date'],
            'to' => ['required_if:type,rides.range', 'date', 'after_or_equal:from'],
            'driver_profile_id' => ['nullable', 'integer'],
        ]);

        $export = ReportExport::create([
            'requested_by' => $request->user()->id,
            'type' => $data['type'],
            'filters' => $data,
            'status' => 'queued',
        ]);

        ExportReportJob::dispatch($export);

        return response()->json($export, 202);
    }

    public function show(ReportExport $reportExport)
    {
        return response()->json($reportExport);
    }
}
