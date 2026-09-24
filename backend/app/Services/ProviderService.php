<?php

namespace App\Services;

use DB;

class ProviderService
{
    public function index($request = null)
    {
        $query = DB::table('providers');
        $this->applyArchiveStatus($query, $request?->input('archive_status', 'active'));
        if ($request?->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_num', 'like', "%{$search}%"));
        }
        $query->when($request?->filled('name'), fn ($q) => $q->where('name', 'like', '%'.$request->name.'%'))
            ->when($request?->filled('phone'), fn ($q) => $q->where('phone_num', 'like', '%'.$request->phone.'%'))
            ->when($request?->filled('city'), fn ($q) => $q->where('city', 'like', '%'.$request->city.'%'))
            ->when($request?->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->created_from))
            ->when($request?->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->created_to));

        $sort = in_array($request?->input('sort'), ['id', 'name', 'created_at'], true) ? $request->sort : 'id';
        return $query->orderBy($sort, $request?->input('direction') === 'asc' ? 'asc' : 'desc')->paginate(min(max((int) ($request?->input('per_page', 15) ?? 15), 1), 100));
    }

    public function show($id, string $archiveStatus = 'active')
    {
        return DB::table('providers')
            ->where('id', $id)
            ->when($archiveStatus === 'active', fn ($query) => $query->whereNull('deleted_at'))
            ->when($archiveStatus === 'archived', fn ($query) => $query->whereNotNull('deleted_at'))
            ->first();
    }

    public function store($request)
    {
        return DB::transaction(function () use ($request) {
            $id = DB::table('providers')->insertGetId([
                'name' => $request['name'],
                'phone_num' => $request['phone_num'],
                'city' => $request['city'],
            ]);
            // Supplier balances are derived from posted journal entries.
            return true;
        });


    }

    public function update($request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $provider = DB::table('providers')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
            if (!$provider)
                return false;
            DB::table('providers')->where('id', $id)->update($request->validated());
            return true;
        });

    }

    public function destroy($id)
    {
        $provider = DB::table('providers')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();
        if (!$provider)
            return false;

        DB::table('providers')->where('id', $id)->update(['deleted_at' => now()]);
        return true;
    }

    public function restore($id): array
    {
        $provider = DB::table('providers')->where('id', $id)->first();
        if (!$provider) return ['message' => 'Provider not found.', 'status' => 404];
        if ($provider->deleted_at === null) return ['message' => 'Provider is already active.', 'status' => 409];
        DB::table('providers')->where('id', $id)->update(['deleted_at' => null]);
        return ['message' => 'Provider restored.', 'status' => 200, 'data' => $this->show($id)];
    }

    private function applyArchiveStatus($query, string $status): void
    {
        if ($status === 'active') $query->whereNull('deleted_at');
        if ($status === 'archived') $query->whereNotNull('deleted_at');
    }

    // Purchase on credit → we owe supplier
    public function incrementDept($id, $amount, $purchaseId)
    {
        throw new \LogicException('Supplier balances are posted only through purchases and payments.');
    }

    // Paying supplier
    public function decrementDept($id, $amount, $purchaseId)
    {
        throw new \LogicException('Supplier balances are posted only through purchases and payments.');
    }
}
