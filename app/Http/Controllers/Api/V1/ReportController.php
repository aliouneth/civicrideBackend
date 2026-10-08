<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExportReportJob;
use App\Models\ReportExport;
use App\Models\Ride;
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

        $inRange = fn ($q) => $q->whereBetween('scheduled_pickup_at', [$data['from'], $data['to']]);

        $byStatus = Ride::select('ride_statuses.code', DB::raw('COUNT(*) as total'))
            ->join('ride_statuses', 'rides.status_id', '=', 'ride_statuses.id')
            ->tap($inRange)
            ->groupBy('ride_statuses.code')
            ->pluck('total', 'code');

        $perDriver = DB::table('ride_assignments')
            ->join('rides', 'ride_assignments.ride_id', '=', 'rides.id')
            ->join('ride_statuses', 'rides.status_id', '=', 'ride_statuses.id')
            ->where('ride_assignments.tenant_id', app('tenant')->id)
            ->whereNull('ride_assignments.unassigned_at')
            ->whereBetween('rides.scheduled_pickup_at', [$data['from'], $data['to']])
            ->groupBy('ride_assignments.driver_profile_id')
            ->selectRaw('driver_profile_id,
                COUNT(*) as assigned,
                SUM(ride_statuses.code = ?) as completed,
                AVG(TIMESTAMPDIFF(MINUTE, scheduled_pickup_at, actual_pickup_at)) as avg_pickup_delay_min',
                ['completed'])
            ->get();

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
