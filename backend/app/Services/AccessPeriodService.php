<?php

namespace App\Services;

use App\Models\AccessPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccessPeriodService
{
    public function synchronize(): array
    {
        $today = now()->startOfDay();
        $activated = 0;
        $expired = 0;

        $scheduledIds = AccessPeriod::query()
            ->where('status', 'scheduled')
            ->whereDate('starts_on', '<=', $today->toDateString())
            ->pluck('id');

        foreach ($scheduledIds as $periodId) {
            $didActivate = DB::transaction(function () use ($periodId, $today): bool {
                $period = AccessPeriod::query()->lockForUpdate()->find($periodId);
                if (! $period || $period->status !== 'scheduled' || $period->starts_on->isAfter($today)) {
                    return false;
                }

                $user = User::query()->lockForUpdate()->find($period->user_id);
                if (! $user) {
                    $period->update(['status' => 'cancelled']);

                    return false;
                }

                $period->update(['status' => 'active']);
                $user->update([
                    'access_status' => 'paid',
                    'access_ends_at' => $period->ends_on->startOfDay(),
                ]);

                return true;
            });
            $activated += (int) $didActivate;
        }

        $activeIds = AccessPeriod::query()
            ->where('status', 'active')
            ->whereDate('ends_on', '<=', $today->toDateString())
            ->pluck('id');

        foreach ($activeIds as $periodId) {
            $didExpire = DB::transaction(function () use ($periodId, $today): bool {
                $period = AccessPeriod::query()->lockForUpdate()->find($periodId);
                if (! $period || $period->status !== 'active' || $period->ends_on->isAfter($today)) {
                    return false;
                }

                $period->update(['status' => 'expired']);
                $hasAnotherActivePeriod = AccessPeriod::query()
                    ->where('user_id', $period->user_id)
                    ->where('status', 'active')
                    ->whereDate('ends_on', '>', $today->toDateString())
                    ->exists();

                if (! $hasAnotherActivePeriod) {
                    User::query()->whereKey($period->user_id)->update([
                        'access_status' => 'free',
                        'access_ends_at' => $period->ends_on->startOfDay(),
                    ]);
                }

                return true;
            });
            $expired += (int) $didExpire;
        }

        return compact('activated', 'expired');
    }

    public function grant(User $user, User $grantedBy, Carbon $startsOn, int $months): AccessPeriod
    {
        AccessPeriod::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->update(['status' => 'cancelled']);

        $endsOn = $startsOn->copy()->addMonthsNoOverflow($months);
        $status = $startsOn->isAfter(now()->startOfDay()) ? 'scheduled' : 'active';
        $period = AccessPeriod::query()->create([
            'user_id' => $user->id,
            'granted_by' => $grantedBy->id,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'months' => $months,
            'status' => $status,
        ]);

        if ($status === 'active') {
            $user->update([
                'access_status' => 'paid',
                'access_ends_at' => $endsOn->startOfDay(),
            ]);
        }

        return $period;
    }
}
