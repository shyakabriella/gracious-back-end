<?php

namespace App\Services;

use App\Models\PropertyRental;
use App\Models\PropertyRentPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyRentPaymentAllocationService
{
    private function cents(string $amount): int
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => ['Invalid monetary amount.'],
            ]);
        }

        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '0'
        );

        if (strlen(ltrim($whole, '0')) > 13) {
            throw ValidationException::withMessages([
                'amount' => ['Amount exceeds the supported limit.'],
            ]);
        }

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $amount): string
    {
        return intdiv($amount, 100) . '.'
            . str_pad(
                (string) ($amount % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    /**
     * Must be called inside a transaction after locking the rental.
     */
    public function allocate(
        PropertyRental $rental,
        PropertyRentPayment $payment
    ): void {
        if (
            (int) $payment->property_rental_id !== (int) $rental->id
            || $payment->currency !== $rental->currency
        ) {
            throw ValidationException::withMessages([
                'payment' => ['Payment does not belong to this lease.'],
            ]);
        }

        if ($payment->allocations()->exists()) {
            throw ValidationException::withMessages([
                'status' => ['Payment is already allocated.'],
            ]);
        }

        $remaining = $this->cents((string) $payment->amount);

        if ($remaining <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be positive.'],
            ]);
        }

        $schedules = $rental->rentSchedules()
            ->orderBy('period_start')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'amount' => ['No rental bills exist for this lease.'],
            ]);
        }

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            if ($schedule->currency !== $payment->currency) {
                throw ValidationException::withMessages([
                    'currency' => ['Rent schedule currency mismatch.'],
                ]);
            }

            $alreadyPaid = DB::table(
                'property_rent_payment_allocations as a'
            )
                ->join(
                    'property_rent_payments as p',
                    'p.id',
                    '=',
                    'a.property_rent_payment_id'
                )
                ->where(
                    'a.property_rent_schedule_id',
                    $schedule->id
                )
                ->where('p.status', 'verified')
                ->selectRaw('COALESCE(SUM(a.amount), 0) AS total')
                ->value('total');

            $credits = $schedule->adjustments()
                ->whereIn('type', [
                    'manual_credit',
                    'early_termination',
                ])
                ->sum('amount');

            $debits = $schedule->adjustments()
                ->where('type', 'manual_debit')
                ->sum('amount');

            $revised = $this->cents(
                (string) $schedule->amount_due
            ) + $this->cents(
                (string) $debits
            ) - $this->cents(
                (string) $credits
            );

            $unpaid = $revised - $this->cents(
                (string) $alreadyPaid
            );

            if ($revised < 0) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'The rent schedule has a negative '
                        . 'revised charge.',
                    ],
                ]);
            }

            // Previously verified allocations remain unchanged
            // after an early-termination credit.
            // No new money may be allocated to an overpaid period.
            if ($unpaid <= 0) {
                continue;
            }

            $allocated = min($remaining, $unpaid);

            $payment->allocations()->create([
                'property_rent_schedule_id' => $schedule->id,
                'amount' => $this->money($allocated),
            ]);

            $remaining -= $allocated;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Payment exceeds the remaining unpaid rent.',
                ],
            ]);
        }
    }
}
