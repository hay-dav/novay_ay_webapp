<?php

namespace App\Http\Controllers\Api;

use App\Models\AccessPeriod;
use App\Models\User;
use App\Services\AccessPeriodService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessManagementController extends Controller
{
    public function index(Request $request, AccessPeriodService $periods)
    {
        $this->authorizeStaff($request);
        $periods->synchronize();

        $users = User::query()
            ->where('role', 'client')
            ->orderBy('name')
            ->get(['id', 'name', 'access_status', 'access_ends_at']);

        $expiredPeriods = AccessPeriod::query()
            ->with('user:id,name,access_status,access_ends_at')
            ->where('status', 'expired')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('access_periods as newer_periods')
                    ->whereColumn('newer_periods.user_id', 'access_periods.user_id')
                    ->whereColumn('newer_periods.id', '>', 'access_periods.id');
            })
            ->latest('ends_on')
            ->get()
            ->filter(fn (AccessPeriod $period) => $period->user !== null)
            ->map(fn (AccessPeriod $period) => [
                'period_id' => $period->id,
                'user_id' => $period->user_id,
                'name' => $period->user->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'months' => $period->months,
                'access_status' => $period->user->access_status,
            ])
            ->values();

        $scheduledPeriods = AccessPeriod::query()
            ->with('user:id,name,access_status,access_ends_at')
            ->where('status', 'scheduled')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (AccessPeriod $period) => $period->user !== null)
            ->map(fn (AccessPeriod $period) => [
                'period_id' => $period->id,
                'user_id' => $period->user_id,
                'name' => $period->user->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'months' => $period->months,
            ])
            ->values();

        return response()->json(['data' => [
            'users' => $users,
            'active_count' => User::query()->where('role', 'client')->count(),
            'expired' => $expiredPeriods,
            'scheduled' => $scheduledPeriods,
        ]]);
    }

    public function grant(Request $request, AccessPeriodService $periods)
    {
        $this->authorizeStaff($request);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'client')),
            ],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $startsOn = Carbon::createFromFormat('Y-m-d', $validated['starts_on'])->startOfDay();
        if (! in_array($startsOn->day, [2, 15], true)) {
            throw ValidationException::withMessages([
                'starts_on' => 'Дата старта должна быть 2-го или 15-го числа месяца.',
            ]);
        }

        $earliestAllowedStart = now()->startOfDay();
        $previousStartDates = 0;
        while ($previousStartDates < 2) {
            $earliestAllowedStart->subDay();
            if (in_array($earliestAllowedStart->day, [2, 15], true)) {
                $previousStartDates++;
            }
        }

        if ($startsOn->lt($earliestAllowedStart)) {
            throw ValidationException::withMessages([
                'starts_on' => 'Можно выбрать только одну из двух предыдущих дат старта.',
            ]);
        }

        $createdPeriods = DB::transaction(function () use ($validated, $startsOn, $request, $periods) {
            return User::query()
                ->whereIn('id', $validated['user_ids'])
                ->where('role', 'client')
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => $periods->grant(
                    $user,
                    $request->user(),
                    $startsOn,
                    (int) $validated['months'],
                ));
        });

        return response()->json(['data' => [
            'granted_count' => $createdPeriods->count(),
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $startsOn->copy()->addMonthsNoOverflow((int) $validated['months'])->toDateString(),
        ]], 201);
    }

    public function cancelScheduled(Request $request)
    {
        $this->authorizeStaff($request);
        $validated = $request->validate([
            'period_ids' => ['required', 'array', 'min:1', 'max:500'],
            'period_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('access_periods', 'id')->where(fn ($query) => $query->where('status', 'scheduled')),
            ],
        ]);

        $cancelledCount = AccessPeriod::query()
            ->whereIn('id', $validated['period_ids'])
            ->where('status', 'scheduled')
            ->update(['status' => 'cancelled']);

        return response()->json(['data' => [
            'cancelled_count' => $cancelledCount,
        ]]);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(in_array($request->user()->role->value, ['admin', 'curator'], true), 403);
    }
}
