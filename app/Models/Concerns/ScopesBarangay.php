<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ScopesBarangay implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Skip scoping in console and for admin guard
        if (app()->runningInConsole() || Auth::guard('admin')->check()) {
            return;
        }

        $user = Auth::guard('barangay')->user() ?: Auth::user();

        // Apply only when the authenticated principal has a barangay_id
        if ($user && isset($user->barangay_id)) {
            $builder->where($model->getTable().'.barangay_id', $user->barangay_id);
        }
    }
}


