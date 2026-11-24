<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $staffRole = $user->hasAnyRole([
            'super_admin',
            'admin',
            'property_manager',
            'accountant',
        ]);

        $canViewRentals = $staffRole
            && $user->can('rentals.view');

        $canViewSales = $staffRole
            && $user->can('sales.view');

        abort_unless(
            $canViewRentals || $canViewSales,
            403
        );

        $data = $request->validate([
            'type' => [
                'sometimes',
                Rule::in(['all', 'rental', 'sale']),
            ],
            'status' => [
                'sometimes',
                Rule::in([
                    'all',
                    'pending',
                    'verified',
                    'rejected',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:120',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ]);

        $type = $data['type'] ?? 'all';
        $status = $data['status'] ?? 'all';
        $search = trim($data['search'] ?? '');
        $perPage = (int) ($data['per_page'] ?? 20);

        if ($type === 'rental') {
            abort_unless($canViewRentals, 403);
        }

        if ($type === 'sale') {
            abort_unless($canViewSales, 403);
        }

        $queries = [];

        if (
            $canViewRentals
            && in_array($type, ['all', 'rental'], true)
        ) {
            $queries[] = $this->sourceQuery(
                'property_rent_payments',
                'property_rentals',
                'property_rental_id',
                'rental'
            );
        }

        if (
            $canViewSales
            && in_array($type, ['all', 'sale'], true)
        ) {
            $queries[] = $this->sourceQuery(
                'property_sale_payments',
                'property_sales',
                'property_sale_id',
                'sale'
            );
        }

        abort_if(count($queries) === 0, 403);

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $base = DB::query()->fromSub(
            $union,
            'payment_records'
        );

        if ($search !== '') {
            $keyword = '%' . $search . '%';

            $base->where(function (Builder $query) use (
                $keyword
            ) {
                $query
                    ->where(
                        'reference',
                        'like',
                        $keyword
                    )
                    ->orWhere(
                        'agreement_reference',
                        'like',
                        $keyword
                    )
                    ->orWhere(
                        'customer_name',
                        'like',
                        $keyword
                    )
                    ->orWhere(
                        'property_title',
                        'like',
                        $keyword
                    );
            });
        }

        // Summary is grouped by currency and status.
        // Different currencies must never be added together.
        // Status filtering affects the table, not this summary.
        $summary = (clone $base)
            ->select('currency', 'status')
            ->selectRaw(
                'COUNT(*) AS record_count, '
                . 'COALESCE(SUM(amount), 0) AS total_amount'
            )
            ->groupBy('currency', 'status')
            ->orderBy('currency')
            ->orderBy('status')
            ->get();

        $listing = clone $base;

        if ($status !== 'all') {
            $listing->where('status', $status);
        }

        $payments = $listing
            ->orderByDesc('payment_date')
            ->orderByDesc('payment_id')
            ->orderBy('category')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'payments' => $payments,
            'summary' => $summary,
            'filters' => [
                'type' => $type,
                'status' => $status,
                'search' => $search,
            ],
            'access' => [
                'rentals' => $canViewRentals,
                'sales' => $canViewSales,
            ],
        ]);
    }

    private function sourceQuery(
        string $paymentTable,
        string $agreementTable,
        string $foreignKey,
        string $category
    ): Builder {
        return DB::table($paymentTable . ' as pay')
            ->join(
                $agreementTable . ' as agreement',
                'agreement.id',
                '=',
                'pay.' . $foreignKey
            )
            // Archived sales must not appear in the active
            // dashboard. Rental queries remain unchanged.
            ->when(
                $category === 'sale',
                fn (Builder $query) =>
                    $query->whereNull('agreement.deleted_at')
            )
            ->leftJoin(
                'customers as customer',
                'customer.id',
                '=',
                'agreement.customer_id'
            )
            ->leftJoin(
                'properties as property',
                'property.id',
                '=',
                'agreement.property_id'
            )
            ->selectRaw(
                '? AS category',
                [$category]
            )
            ->addSelect([
                'pay.id as payment_id',
                'pay.reference as reference',
                'pay.payment_method as payment_method',
                'pay.amount as amount',
                'pay.currency as currency',
                'pay.payment_date as payment_date',
                'pay.status as status',
                'agreement.id as agreement_id',
                'agreement.reference as agreement_reference',
                'customer.id as customer_id',
                'customer.name as customer_name',
                'property.id as property_id',
                'property.title as property_title',
            ]);
    }
}
