<?php

namespace App\Services;

use App\Models\PropertyRental;
use Illuminate\Support\Facades\DB;

class PropertyRentOverpaymentService
{
    private function cents(string $value): int
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new \LogicException(
                'Invalid monetary value in rental financial records.'
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '0'
        );

        if (strlen(ltrim($whole, '0')) > 13) {
            throw new \LogicException(
                'Rental financial amount exceeds supported limits.'
            );
        }

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);

        return ($negative ? '-' : '')
            . intdiv($absolute, 100)
            . '.'
            . str_pad(
                (string) ($absolute % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    public function calculate(PropertyRental $rental): array
    {
        $billed = $rental->rentSchedules()
            ->selectRaw('COALESCE(SUM(amount_due), 0) AS total')
            ->value('total');

        $credits = $rental->adjustments()
            ->whereIn('type', [
                'early_termination',
                'manual_credit',
            ])
            ->sum('amount');

        $debits = $rental->adjustments()
            ->where('type', 'manual_debit')
            ->sum('amount');

        $verifiedPaid = DB::table(
            'property_rent_payment_allocations as a'
        )
            ->join(
                'property_rent_payments as p',
                'p.id',
                '=',
                'a.property_rent_payment_id'
            )
            ->join(
                'property_rent_schedules as s',
                's.id',
                '=',
                'a.property_rent_schedule_id'
            )
            ->where('p.property_rental_id', $rental->id)
            ->where('s.property_rental_id', $rental->id)
            ->where('p.status', 'verified')
            ->selectRaw('COALESCE(SUM(a.amount), 0) AS total')
            ->value('total');

        $netBilled = $this->cents((string) $billed)
            + $this->cents((string) $debits)
            - $this->cents((string) $credits);

        $paid = $this->cents((string) $verifiedPaid);

        $outstanding = max(0, $netBilled - $paid);
        $overpayment = max(0, $paid - $netBilled);

        $reservedRefunds = DB::table('property_rent_refunds')
            ->where('property_rental_id', $rental->id)
            ->whereIn('status', [
                'pending',
                'approved',
                'processing',
                'paid',
            ])
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->value('total');

        $reservedCents = $this->cents(
            (string) $reservedRefunds
        );

        $availableRefundCents = max(
            0,
            $overpayment - $reservedCents
        );

        return [
            'rental_id' => $rental->id,
            'currency' => $rental->currency,
            'original_billed' => $this->money(
                $this->cents((string) $billed)
            ),
            'total_credits' => $this->money(
                $this->cents((string) $credits)
            ),
            'total_debits' => $this->money(
                $this->cents((string) $debits)
            ),
            'net_billed' => $this->money($netBilled),
            'verified_paid' => $this->money($paid),
            'outstanding' => $this->money($outstanding),
            'overpayment' => $this->money($overpayment),
            'reserved_refunds' => $this->money($reservedCents),
            'available_refund' => $this->money(
                $availableRefundCents
            ),
        ];
    }
}
