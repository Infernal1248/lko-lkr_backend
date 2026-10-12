<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use App\Services\PdfRenderer;
use App\Services\RegistryData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
    public function __construct(private PdfRenderer $renderer) {}

    private function input(Request $request): array
    {
        $d = CardInput::validate($request->all());
        $d['settings'] = CardInput::settings($request->input('settings', []));

        return $d;
    }

    public function preview(Request $request): mixed
    {
        $d = CardInput::validate($request->all(), true);
        $d['settings'] = CardInput::settings($request->input('settings', []));

        return response($this->renderer->preview($d['template_key'], $d['data'], $d['settings']), 200, ['Content-Type' => 'image/png']);
    }

    public function test(Request $request): mixed
    {
        $request->validate(['template_key' => ['required', Rule::in(array_keys(config('cards.templates')))], 'proof' => 'boolean']);
        $s = CardInput::settings($request->input('settings', []));

        return $this->pdf($this->renderer->render($request->input('template_key'), CardInput::demo(), $s, $request->boolean('proof')), 'calibration.pdf');
    }

    public function store(Request $request): mixed
    {
        $request->validate(['request_key' => 'required|uuid', 'data_rights' => 'accepted', 'registry' => 'nullable|array', 'employee_id' => 'nullable|integer', 'profile_id' => 'required|integer']);
        $d = $this->input($request);
        $d['registry'] = RegistryData::validate($request->input('registry') ?? []);
        $d['employee_id'] = $request->input('employee_id');
        $profile = PrinterProfile::where('user_id', $request->user()->id)->where('template_key', $d['template_key'])->whereNotNull('calibrated_at')->find($request->integer('profile_id'));
        if (! $profile) {
            throw ValidationException::withMessages(['profile_id' => 'Выберите свой настроенный принтер для этой серии карточки.']);
        }
        $d['settings'] = $profile->settings;
        // HMAC prevents offline dictionary attacks on deleted snapshots.
        $fingerprint = hash_hmac('sha256', json_encode($d, JSON_UNESCAPED_UNICODE), config('app.key'));
        $generation = DB::transaction(function () use ($request, $d, $fingerprint) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = Generation::where('user_id', $user->id)->where('request_key', $request->input('request_key'))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Этот запрос уже использован с другими данными.');

                return $existing;
            }
            abort_if($user->credits < 1, 402, 'Недостаточно генераций. Пополните баланс.');
            $pdf = $this->renderer->render($d['template_key'], $d['data'], $d['settings']);
            $employee = $d['employee_id'] ? Card::where('user_id', $user->id)->findOrFail($d['employee_id']) : new Card(['user_id' => $user->id]);
            $employee->fill(['template_key' => $d['template_key'], 'data' => $d['data'], 'registry' => $d['registry'], 'rights_confirmed_at' => now()])->save();
            $g = Generation::create(['employee_id' => $employee->id, 'registry' => $d['registry'], 'rights_confirmed_at' => now(), 'id' => (string) Str::uuid(), 'user_id' => $user->id, 'request_key' => $request->input('request_key'), 'fingerprint' => $fingerprint, 'template_key' => $d['template_key'], 'template_version' => config('cards.templates.'.$d['template_key'].'.version'), 'snapshot' => ['template_key' => $d['template_key'], 'data' => $d['data'], 'settings' => $d['settings']], 'pdf' => base64_encode($pdf)]);
            $user->decrement('credits');
            LedgerEntry::create(['user_id' => $user->id, 'amount' => -1, 'balance' => $user->credits, 'reason' => 'generation', 'reference' => 'generation:'.$g->id, 'created_at' => now()]);

            return $g;
        }, 3);

        return response()->json($generation, 201);
    }

    public function index(Request $request): mixed
    {
        $request->validate(['q' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1']);
        $query = Generation::where('user_id', $request->user()->id)->select(['id', 'template_key', 'template_version', 'snapshot', 'created_at', 'deleted_at', 'card_number', 'assigned_at', 'employee_id', 'registry'])->latest();
        $search = mb_strtolower(trim($request->input('q', '')));
        if ($search === '') {
            return $query->paginate(30);
        }
        $matches = collect();
        $total = 0;
        $page = (int) $request->input('page', 1);
        foreach ($query->cursor() as $g) {
            $data = $g->snapshot['data'] ?? [];
            $name = mb_strtolower(implode(' ', [$data['surname'] ?? '', $data['name'] ?? '', $data['patronymic'] ?? '']));
            if (str_contains($name, $search) || str_contains(mb_strtolower($g->card_number ?? ''), $search)) {
                if ($total >= ($page - 1) * 30 && $total < $page * 30) {
                    $matches->push($g);
                } $total++;
            }
        }

        return new LengthAwarePaginator($matches, $total, 30, $page, ['path' => $request->url(), 'query' => $request->query()]);
    }

    public function download(Request $request, string $id): mixed
    {
        $g = Generation::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($g->deleted_at || ! $g->pdf, 410, 'Файл удалён.');

        return $this->pdf(base64_decode($g->pdf), $g->template_key.'-'.$g->id.'.pdf');
    }

    public function assignNumber(Request $request, string $id): mixed
    {
        $data = $request->validate(['card_number' => ['present', 'nullable', 'string', 'regex:/^[0-9]{1,30}$/']]);

        return DB::transaction(function () use ($request, $id, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $generation = Generation::where('user_id', $request->user()->id)->findOrFail($id);
            abort_if($generation->deleted_at || ! $generation->snapshot, 410, 'Документ удалён.');
            $number = $data['card_number'];
            $hash = $number === null ? null : hash_hmac('sha256', $number, config('app.key'));
            if ($hash && Generation::where('user_id', $request->user()->id)->where('template_key', $generation->template_key)->where('card_number_hash', $hash)->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['card_number' => 'Карточка этой серии с таким номером уже закреплена за другим документом.']);
            }
            $generation->update(['card_number' => $number, 'card_number_hash' => $hash, 'assigned_at' => $number === null ? null : ($generation->assigned_at ?? now())]);

            return $generation;
        }, 3);
    }

    public function destroy(Request $request, string $id): mixed
    {
        $g = Generation::where('user_id', $request->user()->id)->findOrFail($id);
        $g->update(['pdf' => null, 'snapshot' => null, 'registry' => null, 'employee_id' => null, 'card_number' => null, 'card_number_hash' => null, 'assigned_at' => null, 'deleted_at' => now()]);

        return response()->noContent();
    }

    private function pdf(string $bytes, string $name): Response
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
