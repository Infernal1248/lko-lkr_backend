<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPackageController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);

        return Package::orderBy('sort_order')->orderBy('key')->get();
    }

    public function save(Request $request, ?string $key = null): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'credits' => ['required', 'integer', 'min:1', 'max:100000'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $package = $key === null ? new Package(['key' => (string) Str::uuid()]) : Package::findOrFail($key);
        $package->fill($data)->save();

        return $package;
    }
}
