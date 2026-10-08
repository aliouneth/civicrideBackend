<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRideRequest;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\RideAssignmentService;
use Illuminate\Http\Request;

class RideAssignmentController extends Controller
{
    public function __construct(private RideAssignmentService $assignments) {}

    /** Manual dispatch: scheduler picks a driver for a ride. */
    public function store(AssignRideRequest $request, Ride $ride)
    {
        $driver = DriverProfile::findOrFail($request->validated()['driver_profile_id']);

        $assignment = $this->assignments->assign($ride, $driver, $request->user());

        return response()->json($assignment, 201);
    }

    /** Free a ride back to the board (assigned → pending). */
    public function destroy(Request $request, Ride $ride)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->assignments->unassign($ride, $request->user(), $data['reason'] ?? null));
    }
}
