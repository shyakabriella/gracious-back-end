<?php

namespace App\Services;

use App\Models\PropertyRental;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class PropertyRentScheduleService
{
    public function generate(PropertyRental $rental): int
    {
        if ($rental->rentSchedules()->exists()) {
            throw ValidationException::withMessages([
                'status' => [
                    'Rent schedules already exist for this lease.',
                ],
            ]);
        }

        $start = CarbonImmutable::parse($rental->start_date)
            ->startOfDay();
        $end = CarbonImmutable::parse($rental->end_date)
            ->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['Invalid rental period.'],
            ]);
        }

        // Limit unreasonable leases before creating financial records.
        if ($start->diffInMonths($end) > 600) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'Rental schedule cannot exceed 600 months.',
                ],
            ]);
        }

        $monthlyCents = $this->toCents(
            (string) $rental->monthly_rent
        );

        $count = 0;
        $index = 0;

        while (true) {
            // Always calculate from the original anniversary.
            // This avoids accumulating date drift.
            $periodStart = $start->addMonthsNoOverflow($index);

            if ($periodStart->greaterThan($end)) {
                break;
            }

            $nextAnniversary = $start->addMonthsNoOverflow(
                $index + 1
            );

            $normalEnd = $nextAnniversary->subDay();
            $periodEnd = $normalEnd->lessThan($end)
                ? $normalEnd
                : $end;

            $normalDays = (int) $periodStart->diffInDays(
                $nextAnniversary
            );

            $chargedDays = (int) $periodStart->diffInDays(
                $periodEnd
            ) + 1;

            // Round half-up to the nearest cent, using integers.
            $amountCents = intdiv(
                $monthlyCents * $chargedDays
                    + intdiv($normalDays, 2),
                $normalDays
            );

            $rental->rentSchedules()->create([
                'reference' => 'RSCH-' . Str::upper(Str::random(20)),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'due_date' => $periodStart->toDateString(),
                'amount_due' => $this->fromCents($amountCents),
                'currency' => $rental->currency,
                'status' => 'pending',
            ]);

            $count++;
            $index++;
        }

        return $count;
    }

    private function toCents(string $amount): int
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'monthly_rent' => ['Invalid monthly rental amount.'],
            ]);
        }

        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '0'
        );

        // Keep integer-cent arithmetic safely within PHP limits.
        if (strlen(ltrim($whole, '0')) > 13) {
            throw ValidationException::withMessages([
                'monthly_rent' => ['Monthly rent exceeds the supported limit.'],
            ]);
        }

        $cents = (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');

        if ($cents <= 0) {
            throw ValidationException::withMessages([
                'monthly_rent' => ['Monthly rent must be positive.'],
            ]);
        }

        return $cents;
    }

    private function fromCents(int $cents): string
    {
        return intdiv($cents, 100)
            . '.'
            . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
