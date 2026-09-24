<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LookupController extends Controller
{
    private const ACCOUNT_TYPES = [
        'asset', 'liability', 'equity', 'revenue', 'expense', 'contra_asset', 'contra_revenue',
    ];

    public function accounts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(self::ACCOUNT_TYPES)],
            'account_type' => ['nullable', Rule::in(self::ACCOUNT_TYPES)],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = $filters['q'] ?? $filters['search'] ?? null;
        $type = $filters['type'] ?? $filters['account_type'] ?? null;
        $active = array_key_exists('active', $filters) ? (bool) $filters['active'] : true;
        $perPage = $filters['per_page'] ?? $filters['limit'] ?? 25;

        $allowedTypes = $this->allowedAccountTypes($request);

        $query = Account::query()
            ->select(['id', 'code', 'name', 'account_type', 'active'])
            ->where('active', $active)
            ->whereIn('account_type', $allowedTypes)
            ->when($type, fn ($query, $value) => $query->where('account_type', $value))
            ->when($search, function ($query, $value) {
                $query->where(function ($query) use ($value) {
                    $query->where('code', 'like', "%{$value}%")
                        ->orWhere('name', 'like', "%{$value}%");
                });
            })
            ->orderBy('code');

        return $this->collection($query->paginate($perPage));
    }

    public function bankAccounts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = $filters['q'] ?? $filters['search'] ?? null;
        $active = array_key_exists('active', $filters) ? (bool) $filters['active'] : true;
        $perPage = $filters['per_page'] ?? $filters['limit'] ?? 25;
        $bankRootId = Account::query()->where('code', '1200')->value('id');

        $query = Account::query()
            ->select(['id', 'code', 'name as display_name', 'active'])
            ->where('active', $active)
            ->where(function ($query) use ($bankRootId) {
                if ($bankRootId === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('id', $bankRootId)->orWhere('parent_id', $bankRootId);
                }
            })
            ->when($search, function ($query, $value) {
                $query->where(function ($query) use ($value) {
                    $query->where('code', 'like', "%{$value}%")
                        ->orWhere('name', 'like', "%{$value}%");
                });
            })
            ->orderBy('code');

        return $this->collection($query->paginate($perPage));
    }

    public function parties(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = $filters['q'] ?? $filters['search'] ?? null;
        $perPage = $filters['per_page'] ?? $filters['limit'] ?? 25;
        $partyType = $filters['party_type'] ?? null;

        $customers = DB::table('customers')
            ->select(['id', DB::raw("'customer' as party_type"), 'name as display_name', 'phone_num as phone_reference'])
            ->whereNull('deleted_at')
            ->when($search, fn ($query, $value) => $this->searchParty($query, $value));
        $suppliers = DB::table('providers')
            ->select(['id', DB::raw("'supplier' as party_type"), 'name as display_name', 'phone_num as phone_reference'])
            ->whereNull('deleted_at')
            ->when($search, fn ($query, $value) => $this->searchParty($query, $value));

        $query = match ($partyType) {
            'customer' => $customers,
            'supplier' => $suppliers,
            default => $customers->unionAll($suppliers),
        };

        return $this->collection($query->orderBy('display_name')->orderBy('party_type')->paginate($perPage));
    }

    private function searchParty($query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('phone_num', 'like', "%{$search}%");
        });
    }

    private function allowedAccountTypes(Request $request): array
    {
        $user = $request->user();
        if ($user->hasPermission('accounting.reports')) {
            return self::ACCOUNT_TYPES;
        }

        $types = [];
        if ($user->hasPermission('expenses.view') || $user->hasPermission('expenses.create')) {
            $types[] = 'expense';
        }
        if ($user->hasPermission('other_income.view') || $user->hasPermission('other_income.create')) {
            $types[] = 'revenue';
        }

        return $types;
    }
}
