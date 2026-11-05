<?php

namespace App\Services;

use App\Models\PropertyRental;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PropertyRentStatementService
{
    private function cents(string $value): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            '0'
        );

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100) . '.'
            . str_pad(
                (string) ($cents % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    public function build(
        PropertyRental $rental,
        ?string $asOf = null
    ): array {
        $today = $asOf
            ? CarbonImmutable::parse($asOf)->toDateString()
            : now()->toDateString();

        $schedules = $rental->rentSchedules()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $verified = DB::table(
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
            ->groupBy('a.property_rent_schedule_id')
            ->select('a.property_rent_schedule_id')
            ->selectRaw('SUM(a.amount) AS paid')
            ->pluck('paid', 'property_rent_schedule_id');

        $adjustments = DB::table(
            'property_rent_adjustments'
        )
            ->where('property_rental_id', $rental->id)
            ->groupBy('property_rent_schedule_id')
            ->select('property_rent_schedule_id')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'manual_debit' "
                . "THEN amount ELSE 0 END), 0) AS debits"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type IN "
                . "('manual_credit', 'early_termination') "
                . "THEN amount ELSE 0 END), 0) AS credits"
            )
            ->get()
            ->keyBy('property_rent_schedule_id');

        $totalBilled = 0;
        $totalCredits = 0;
        $totalDebits = 0;
        $totalPaid = 0;
        $totalPeriodOverpayments = 0;
        $overdue = 0;
        $dueToday = 0;
        $future = 0;
        $periods = [];

        foreach ($schedules as $schedule) {
            $billed = $this->cents((string) $schedule->amount_due);
            $paid = $this->cents(
                (string) ($verified[$schedule->id] ?? '0')
            );
            $adjustment = $adjustments->get($schedule->id);

            $credits = $this->cents(
                (string) ($adjustment->credits ?? '0')
            );

            $debits = $this->cents(
                (string) ($adjustment->debits ?? '0')
            );

            $revised = $billed + $debits - $credits;

            if ($revised < 0) {
                throw new \LogicException(
                    'Negative revised rent on schedule '
                    . $schedule->reference
                );
            }

            $remaining = max(0, $revised - $paid);
            $periodOverpayment = max(0, $paid - $revised);
            $totalPeriodOverpayments += $periodOverpayment;

            $dueDate = $schedule->due_date->toDateString();

            if ($dueDate < $today) {
                $bucket = 'overdue';
                $overdue += $remaining;
            } elseif ($dueDate === $today) {
                $bucket = 'due_today';
                $dueToday += $remaining;
            } else {
                $bucket = 'future';
                $future += $remaining;
            }

            $totalBilled += $billed;
            $totalCredits += $credits;
            $totalDebits += $debits;
            $totalPaid += $paid;

            $periods[] = [
                'schedule_id' => $schedule->id,
                'reference' => $schedule->reference,
                'period_start' =>
                    $schedule->period_start->toDateString(),
                'period_end' =>
                    $schedule->period_end->toDateString(),
                'due_date' => $dueDate,
                'currency' => $schedule->currency,
                'amount_due' => $this->money($billed),
                'credits' => $this->money($credits),
                'debits' => $this->money($debits),
                'revised_amount' => $this->money($revised),
                'verified_paid' => $this->money($paid),
                'balance' => $this->money($remaining),
                'overpayment' => $this->money($periodOverpayment),
                'bucket' => $bucket,
                'is_paid' => $remaining === 0,
            ];
        }

        $netBilled = $totalBilled + $totalDebits - $totalCredits;
        $netOutstanding = max(0, $netBilled - $totalPaid);
        $netOverpayment = max(0, $totalPaid - $netBilled);

        return [
            'rental_id' => $rental->id,
            'rental_reference' => $rental->reference,
            'currency' => $rental->currency,
            'as_of' => $today,
            'summary' => [
                'total_billed' => $this->money($totalBilled),
                'total_credits' => $this->money($totalCredits),
                'total_debits' => $this->money($totalDebits),
                'net_billed' => $this->money($netBilled),
                'verified_paid' => $this->money($totalPaid),
                'total_outstanding' => $this->money($netOutstanding),
                'overpayment' => $this->money($netOverpayment),
                'period_overpayments' => $this->money(
                    $totalPeriodOverpayments
                ),
                'overdue' => $this->money($overdue),
                'due_today' => $this->money($dueToday),
                'future' => $this->money($future),
            ],
            'periods' => $periods,
        ];
    }
}
