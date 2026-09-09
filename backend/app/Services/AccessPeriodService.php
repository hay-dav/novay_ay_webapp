<?php

namespace App\Services;

use App\Models\AccessPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccessPeriodService
{
    /** Apply an access change made from the participant card, without a term. */
    public function setManualAccess(User $user, string $accessStatus): void
    {
        DB::transaction(function () use ($user, $accessStatus): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            AccessPeriod::query()
                ->where('user_id', $lockedUser->id)
                ->whereIn('status', ['scheduled', 'active'])
                ->update(['status' => 'cancelled']);

            $lockedUser->update([
                'access_status' => $accessStatus,
                'access_ends_at' => null,
            ]);
        });

        $user->refresh();
    }

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

    public function extend(AccessPeriod $period, int $months): AccessPeriod
    {
        return DB::transaction(function () use ($period, $months): AccessPeriod {
            $lockedPeriod = AccessPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($lockedPeriod->status !== 'active') {
                throw new \RuntimeException('Продлить можно только активный доступ.');
            }

            $endsOn = $lockedPeriod->ends_on->copy()->addMonthsNoOverflow($months);
            $lockedPeriod->update([
                'ends_on' => $endsOn->toDateString(),
                'months' => $lockedPeriod->months + $months,
            ]);

            User::query()->whereKey($lockedPeriod->user_id)->update([
                'access_status' => 'paid',
                'access_ends_at' => $endsOn->startOfDay(),
            ]);

            return $lockedPeriod->refresh();
        });
    }

    public function revoke(AccessPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $lockedPeriod = AccessPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($lockedPeriod->status !== 'active') {
                throw new \RuntimeException('Отключить можно только активный доступ.');
            }

            AccessPeriod::query()
                ->where('user_id', $lockedPeriod->user_id)
                ->whereIn('status', ['scheduled', 'active'])
                ->update(['status' => 'cancelled']);

            User::query()->whereKey($lockedPeriod->user_id)->update([
                'access_status' => 'free',
                'access_ends_at' => null,
            ]);
        });
    }
}
