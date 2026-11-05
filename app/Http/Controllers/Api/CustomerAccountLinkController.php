<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAccountLinkController extends Controller
{
    public function store(
        Request $request,
        Customer $customer
    ): JsonResponse {
        $actor = $request->user();

        abort_unless(
            $actor->hasAnyRole(['super_admin', 'admin'])
                && $actor->can('customers.update')
                && $actor->can('users.update'),
            403
        );

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $accountId = (int) $data['user_id'];

        $result = DB::transaction(function () use (
            $customer,
            $accountId
        ) {
            $account = User::query()
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $account->status !== 'active'
                || $account->getRoleNames()->count() !== 1
                || ! $account->hasRole('customer')
            ) {
                throw ValidationException::withMessages([
                    'user_id' =>
                        'Only active customer-only accounts can be linked.',
                ]);
            }

            $record = Customer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->user_id !== null) {
                throw ValidationException::withMessages([
                    'user_id' =>
                        'This customer record already has a linked account.',
                ]);
            }

            if (
                Customer::withTrashed()
                    ->where('user_id', $accountId)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'user_id' =>
                        'This account is already linked to a customer.',
                ]);
            }

            $record->forceFill([
                'user_id' => $accountId,
            ])->save();

            return [
                'customer_id' => $record->id,
                'customer_reference' => $record->reference,
                'user_id' => $account->id,
                'user_email' => $account->email,
            ];
        });

        return response()->json([
            'message' => 'Customer account linked successfully.',
            'link' => $result,
        ]);
    }
}
