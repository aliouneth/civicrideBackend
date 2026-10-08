<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvailabilityRequest;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\RideAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DriverController extends Controller
{
    public function __construct(private RideAssignmentService $assignments) {}

    public function index(Request $request)
    {
        $query = DriverProfile::with(['user', 'availability'])
            ->withCount('activeAssignments')
            ->when($request->q, fn ($q, $term) => $q->whereHas('user', fn ($u) => $u->whereRaw("name LIKE ?", ["%{$term}%"])))
            ->orderBy('id');

        if ($request->page) {
            return $query->cursorPaginate($request->page);
        }

        return $query->cursorPaginate(25);
    }

    public function show(DriverProfile $driverProfile)
    {
        return response()->json($driverProfile->load(['user', 'documents', 'availability']));
    }

    /** Onboarding: driver completes license + vehicle details. */
    public function updateApplication(Request $request)
    {
        $profile = $request->user()->driverProfile()->firstOrFail();

        $data = $request->validate([
            'license_number' => ['required', 'string', 'max:64'],
            'license_expiry' => ['required', 'date', 'after:today'],
            'vehicle_make' => ['required', 'string', 'max:64'],
            'vehicle_model' => ['required', 'string', 'max:64'],
            'vehicle_year' => ['required', 'integer', 'min:2000', 'max:2030'],
            'vehicle_color' => ['required', 'string', 'max:32'],
            'vehicle_plate' => ['required', 'string', 'max:16'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $profile->update([...$data, 'onboarding_status' => 'documents_pending']);

        return response()->json($profile->fresh());
    }

    /** Document upload (license, insurance, registration). */
    public function uploadDocument(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:license,insurance,vehicle_registration,background_check'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $profile = $request->user()->driverProfile()->firstOrFail();
        $path = $data['file']->store(
            "tenants/{$profile->tenant_id}/drivers/{$profile->id}", 's3'
        );

        $document = $profile->documents()->create([
            'type' => $data['type'],
            'file_path' => $path,
            'mime_type' => $data['file']->getMimeType(),
        ]);

        return response()->json($document, 201);
    }

    /** Approve/reject/suspend a driver application (admin only). */
    public function review(Request $request, DriverProfile $driverProfile)
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected,suspended'],
        ]);

        $driverProfile->update([
            'onboarding_status' => $data['decision'],
            'approved_at' => $data['decision'] === 'approved' ? now() : null,
            'approved_by' => $request->user()->id,
        ]);

        return response()->json($driverProfile->fresh());
    }

    /** Replace the driver's whole weekly availability grid. */
    public function updateAvailability(UpdateAvailabilityRequest $request)
    {
        $profile = $request->user()->driverProfile()->firstOrFail();

        $profile->availability()->delete();
        $profile->availability()->createMany($request->validated()['windows']);

        return response()->json($profile->fresh('availability'));
    }

    /** Home base: used to compute each ride's total mileage (home → trip → home). */
    public function updateHome(Request $request)
    {
        $data = $request->validate([
            'home_address' => ['required', 'string', 'max:255'],
            'home_lat' => ['required', 'numeric', 'between:-90,90'],
            'home_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $profile = $request->user()->driverProfile()->firstOrFail();
        $profile->update($data);

        return response()->json($profile->fresh());
    }

    /** Add a new driver profile (platform admin only). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'home_address' => ['required', 'string', 'max:255'],
            'home_lat' => ['required', 'numeric', 'between:-90,90'],
            'home_lng' => ['required', 'numeric', 'between:-180,180'],
            'onboarding_status' => ['sometimes', 'required', 'string', 'in:pending,approved,suspended'],
        ]);

        $user = $request->user()->driverProfile()->firstOrFail()->user;
        // Create a new user+driver profile under the same tenant
        $tenant = $request->user()->tenant;

        $newUser = $tenant->users()->create([
            'name' => $data['home_address'], // placeholder; we'll set name below
            'email' => strtolower(uniqid()) . '@test.local', // temporary
            'is_active' => true,
            'role_id' => $tenant->roles()->where('name', 'supervisor')->value('id'),
        ]);

        $newProfile = $newUser->driverProfile()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $newUser->id,
            'home_address' => $data['home_address'],
            'home_lat' => $data['home_lat'],
            'home_lng' => $data['home_lng'],
            'onboarding_status' => $data['onboarding_status'] ?? 'pending',
            'vehicle_make' => '',
            'vehicle_model' => '',
            'vehicle_year' => null,
            'vehicle_color' => '',
            'vehicle_plate' => '',
            'seats' => 1,
            'license_number' => '',
            'license_expiry' => null,
        ]);

        return response()->json(
            $newProfile->load(['user', 'availability', 'activeAssignments']),
            201
        );
    }

    /** Update a driver profile. */
    public function update(Request $request, DriverProfile $driverProfile)
    {
        // Schedulers edit the driver record (name/email, contact/vehicle/license,
        // home base) and may flip the onboarding status (e.g. temporary suspend).
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:users,email,'.$driverProfile->user_id],
            'home_address' => ['sometimes', 'required', 'string', 'max:255'],
            'home_lat' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'home_lng' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'vehicle_make' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vehicle_model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vehicle_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2030'],
            'vehicle_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:16'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'onboarding_status' => ['sometimes', 'string', 'in:pending,approved,suspended'],
        ]);

        // Names/emails live on the linked user account.
        $userUpdates = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null);
        if ($userUpdates) {
            $driverProfile->user->update($userUpdates);
        }

        // Empty payload fields arrive as null (ConvertEmptyStringsToNull) but
        // these driver_profiles columns are NOT NULL — fall back to '' where safe.
        if (array_key_exists('home_address', $data)) {
            $driverProfile->home_address = $data['home_address'];
        }
        if (array_key_exists('home_lat', $data)) {
            $driverProfile->home_lat = $data['home_lat'];
        }
        if (array_key_exists('home_lng', $data)) {
            $driverProfile->home_lng = $data['home_lng'];
        }

        foreach (['vehicle_make', 'vehicle_model', 'vehicle_color', 'vehicle_plate', 'license_number'] as $column) {
            if (array_key_exists($column, $data)) {
                $driverProfile->{$column} = (string) ($data[$column] ?? '');
            }
        }
        if (array_key_exists('vehicle_year', $data) && $data['vehicle_year'] !== null) {
            $driverProfile->vehicle_year = (int) $data['vehicle_year'];
        }
        if (array_key_exists('seats', $data) && $data['seats'] !== null) {
            $driverProfile->seats = (int) $data['seats'];
        }
        if (isset($data['license_expiry'])) {
            $driverProfile->license_expiry = $data['license_expiry'];
        }
        if (array_key_exists('onboarding_status', $data)) {
            $driverProfile->onboarding_status = $data['onboarding_status'];
        }

        $driverProfile->save();

        return response()->json($driverProfile->load(['user', 'availability', 'activeAssignments']));
    }

    /** Remove a driver profile. */
    public function destroy(DriverProfile $driverProfile)
    {
        $driverProfile->delete();

        return response()->json(null, 204);
    }

    /** Self-assignment: allowed only when the tenant enables it. */
    public function selfAssign(Request $request, Ride $ride)
    {
        if (! app('tenant')->settings->allow_driver_self_assignment) {
            return response()->json(['message' => 'Self-assignment is disabled by your agency.'], 403);
        }

        $profile = $request->user()->driverProfile()->firstOrFail();
        $assignment = $this->assignments->assign($ride, $profile, $request->user(), true);

        return response()->json($assignment, 201);
    }

    /** Driver's own rides: active first, then upcoming. Optional status/month filters back the history view. */
    public function myRides(Request $request)
    {
        $profile = $request->user()->driverProfile()->firstOrFail();

        $rides = Ride::with(['status', 'events'])
            ->whereHas('assignments', fn ($q) => $q
                ->where('driver_profile_id', $profile->id)
                ->whereNull('unassigned_at'))
            ->when($request->status, fn ($q, $code) => $q->whereHas('status', fn ($s) => $s->where('code', $code)))
            ->when($request->month, function ($q, string $month): void {
                // Guard non-conforming values so they fall through to "no filter".
                if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                    return;
                }
                [$year, $m] = explode('-', $month);
                $q->whereYear('scheduled_pickup_at', (int) $year)
                    ->whereMonth('scheduled_pickup_at', (int) $m);
            })
            ->orderBy('scheduled_pickup_at', $request->sort === 'desc' ? 'desc' : 'asc')
            ->cursorPaginate(25);

        return response()->json($rides);
    }

    public function downloadUrl(DriverProfile $driverProfile, int $documentId)
    {
        // Schedulers/admins review via short-lived signed URLs — files never proxy through PHP.
        $document = $driverProfile->documents()->findOrFail($documentId);

        return response()->json([
            'url' => Storage::disk('s3')->temporaryUrl($document->file_path, now()->addMinutes(15)),
        ]);
    }
}
