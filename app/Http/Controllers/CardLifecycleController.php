<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use App\Services\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CardLifecycleController extends Controller
{
    public function __construct(private PdfRenderer $renderer) {}

    public function update(Request $request, string $id): mixed
    {
        $data = $request->validate([
            'data_rights' => 'accepted', 'action' => ['required', Rule::in(['type', 'replace'])],
            'template_key' => ['required_if:action,type', Rule::in(array_keys(config('cards.templates')))],
            'profile_id' => 'required_if:action,type|nullable|integer',
            'issue_date' => 'required_if:action,replace|nullable|date_format:Y-m-d',
            'request_key' => 'required_if:action,replace|nullable|uuid',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $source = Generation::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            abort_if($source->deleted_at || ! $source->snapshot || ! $source->pdf, 410, 'Документ удалён.');
            $fingerprint = hash_hmac('sha256', json_encode([$id, $data['issue_date'] ?? null]), config('app.key'));
            if ($data['action'] === 'replace') {
                $existing = Generation::where('user_id', $user->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->source_generation_id === $id && hash_equals($existing->fingerprint, $fingerprint), 409, 'Этот запрос уже использован с другими данными.');

                    return $existing;
                }
            }
            abort_if($source->inactive_at, 409, 'Карточка уже неактуальна. Используйте её актуальную замену.');
            if ($data['action'] === 'replace') {
                abort_unless(filled($source->card_number), 422, 'Для перевыпуска сначала закрепите номер исходной карточки.');
                abort_if($user->credits < 1, 402, 'Недостаточно генераций. Для перевыпуска нужна 1 генерация.');
                $snapshot = $source->snapshot;
                $snapshot['data']['issue_date'] = $data['issue_date'];
                $pdf = $this->renderer->render($source->template_key, $snapshot['data'], $snapshot['settings']);
                $replacement = Generation::create([
                    'id' => (string) Str::uuid(), 'user_id' => $user->id,
                    'employee_id' => $source->employee_id, 'source_generation_id' => $source->id,
                    'request_key' => $data['request_key'], 'fingerprint' => $fingerprint,
                    'template_key' => $source->template_key, 'template_version' => $source->template_version,
                    'snapshot' => $snapshot, 'registry' => $source->registry,
                    'rights_confirmed_at' => now(), 'pdf' => base64_encode($pdf),
                ]);
                $user->decrement('credits');
                LedgerEntry::create([
                    'user_id' => $user->id, 'amount' => -1, 'balance' => $user->credits,
                    'reason' => 'generation', 'reference' => 'generation:'.$replacement->id, 'created_at' => now(),
                ]);
                $source->update(['inactive_at' => now()]);

                return $replacement;
            }
            $snapshot = $source->snapshot;
            $corrections = $source->corrections ?? [];
            if ($source->template_key === $data['template_key']) {
                return $source;
            }
            $profile = PrinterProfile::where('user_id', $user->id)->where('template_key', $data['template_key'])->whereNotNull('calibrated_at')->find($data['profile_id']);
            abort_unless($profile, 422, 'Выберите свой настроенный принтер для нового типа карточки.');
            $corrections[] = ['action' => 'type', 'from' => $source->template_key, 'to' => $data['template_key'], 'previous_number' => $source->card_number, 'at' => now()->toISOString()];
            $snapshot['template_key'] = $data['template_key'];
            $snapshot['settings'] = CardInput::settings($profile->settings);
            $source->template_key = $data['template_key'];
            $source->template_version = config('cards.templates.'.$data['template_key'].'.version');
            // The previous number belongs to the old series and stays in correction history.
            $source->card_number = $source->card_number_hash = $source->assigned_at = null;
            $source->pdf = base64_encode($this->renderer->render($source->template_key, $snapshot['data'], $snapshot['settings']));
            $source->snapshot = $snapshot;
            $source->corrections = $corrections;
            $source->rights_confirmed_at = now();
            $source->save();

            return $source;
        }, 3);
    }
}
