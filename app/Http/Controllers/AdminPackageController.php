<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPackageController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);

        return Package::orderBy('sort_order')->orderBy('key')->get();
    }

    public function reorder(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:1000'],
            'keys.*' => ['required', 'string', 'max:64', 'distinct:strict'],
        ]);

        return DB::transaction(function () use ($data) {
            $packages = Package::orderBy('key')->lockForUpdate()->get();
            $keys = $data['keys'];
            $expected = $packages->pluck('key')->all();
            $sorted = $keys;
            sort($sorted);
            sort($expected);
            abort_unless($sorted === $expected, 409, 'Список тарифов изменился. Обновите страницу и повторите перенос.');
            foreach ($keys as $index => $key) {
                $packages->firstWhere('key', $key)->update(['sort_order' => $index + 1]);
            }

            return Package::orderBy('sort_order')->orderBy('key')->get();
        }, 3);
    }

    public function save(Request $request, ?string $key = null): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'credits' => ['required', 'integer', 'min:1', 'max:100000'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'active' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($key, $data) {
            $packages = Package::orderBy('key')->lockForUpdate()->get();
            $package = $key === null
                ? new Package(['key' => (string) Str::uuid(), 'sort_order' => ($packages->max('sort_order') ?? 0) + 1])
                : $packages->firstWhere('key', $key);
            abort_unless($package, 404);
            $package->fill($data)->save();

            return $package;
        }, 3);
    }
}
