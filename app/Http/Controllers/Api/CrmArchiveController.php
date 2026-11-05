<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmArchiveController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function authorizeRecord(
        Request $request,
        Customer|Lead $record,
        string $permission
    ): void {
        $user = $request->user();

        abort_unless($user->can($permission), 403);

        $accessible = $this->manager($user)
            || $record->created_by === $user->id;

        if ($record instanceof Lead) {
            $accessible = $accessible
                || $record->assigned_agent_id === $user->id;
        }

        abort_unless($accessible, 404);
    }

    public function archivedCustomers(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('customers.view')
                && $user->can('customers.update'),
            403
        );

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Customer::onlyTrashed();

        if (!$this->manager($user)) {
            $query->where('created_by', $user->id);
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function archivedLeads(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('leads.view')
                && $user->can('leads.update'),
            403
        );

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Lead::onlyTrashed()->with([
            'customer' => fn ($q) => $q->withTrashed()
                ->select('id', 'reference', 'name'),
            'assignedAgent:id,name',
        ]);

        if (!$this->manager($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhere('assigned_agent_id', $user->id);
            });
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function archiveCustomer(
        Request $request,
        int $customer
    ): JsonResponse {
        return DB::transaction(function () use ($request, $customer) {
            $record = Customer::withTrashed()
                ->lockForUpdate()
                ->findOrFail($customer);

            $this->authorizeRecord(
                $request,
                $record,
                'customers.update'
            );

            if ($record->trashed()) {
                return response()->json([
                    'message' => 'Customer is already archived.',
                ], 409);
            }

            if ($record->leads()->exists()) {
                return response()->json([
                    'message' => 'Archive active leads before archiving this customer.',
                ], 409);
            }

            $record->delete();

            return response()->json([
                'message' => 'Customer archived successfully.',
                'customer' => $record->fresh(),
            ]);
        });
    }

    public function restoreCustomer(
        Request $request,
        int $customer
    ): JsonResponse {
        return DB::transaction(function () use ($request, $customer) {
            $record = Customer::withTrashed()
                ->lockForUpdate()
                ->findOrFail($customer);

            $this->authorizeRecord(
                $request,
                $record,
                'customers.update'
            );

            if (!$record->trashed()) {
                return response()->json([
                    'message' => 'Customer is not archived.',
                ], 409);
            }

            $record->restore();

            return response()->json([
                'message' => 'Customer restored successfully.',
                'customer' => $record->fresh(),
            ]);
        });
    }

    public function archiveLead(
        Request $request,
        int $lead
    ): JsonResponse {
        return DB::transaction(function () use ($request, $lead) {
            $record = Lead::withTrashed()
                ->lockForUpdate()
                ->findOrFail($lead);

            $this->authorizeRecord(
                $request,
                $record,
                'leads.update'
            );

            if ($record->trashed()) {
                return response()->json([
                    'message' => 'Lead is already archived.',
                ], 409);
            }

            $record->delete();

            return response()->json([
                'message' => 'Lead archived successfully.',
                'lead' => $record->fresh(),
            ]);
        });
    }

    public function restoreLead(
        Request $request,
        int $lead
    ): JsonResponse {
        return DB::transaction(function () use ($request, $lead) {
            $record = Lead::withTrashed()
                ->lockForUpdate()
                ->findOrFail($lead);

            $this->authorizeRecord(
                $request,
                $record,
                'leads.update'
            );

            if (!$record->trashed()) {
                return response()->json([
                    'message' => 'Lead is not archived.',
                ], 409);
            }

            if (
                !Customer::query()
                    ->whereKey($record->customer_id)
                    ->exists()
            ) {
                return response()->json([
                    'message' => 'Restore the customer before restoring this lead.',
                ], 409);
            }

            $record->restore();

            return response()->json([
                'message' => 'Lead restored successfully.',
                'lead' => $record->fresh(),
            ]);
        });
    }
}
