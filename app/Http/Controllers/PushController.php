<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushEndpoint;
use App\Services\PushNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PushController extends Controller
{
    public function settings(): array
    {
        return ['enabled' => PushNotifications::enabled(), 'public_key' => PushNotifications::enabled() ? config('push.public_key') : null];
    }

    public function store(Request $request): mixed
    {
        abort_unless(PushNotifications::enabled(), 503, 'Push-уведомления пока не настроены владельцем сервиса.');
        $data = $request->validate(['endpoint' => 'required|string|max:2048', 'keys' => 'required|array:p256dh,auth', 'keys.p256dh' => 'required|string|max:100', 'keys.auth' => 'required|string|max:30']);
        if (! PushEndpoint::allowed($data['endpoint']) || ! PushEndpoint::validKey($data['keys']['p256dh'], 65) || ! PushEndpoint::validKey($data['keys']['auth'], 16)) {
            throw ValidationException::withMessages(['endpoint' => 'Браузер передал неподдерживаемую push-подписку.']);
        }
        $hash = hash('sha256', $data['endpoint']);
        DB::transaction(function () use ($request, $data, $hash) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = PushSubscription::where('endpoint_hash', $hash)->first();
            abort_if($existing && $existing->user_id !== $request->user()->id, 409, 'Этот браузер связан с другим аккаунтом. Отключите подписку и включите уведомления заново.');
            abort_if(! $existing && PushSubscription::where('user_id', $request->user()->id)->count() >= 5, 422, 'Можно подключить до пяти браузеров. Отключите уведомления на одном из них.');
            PushSubscription::updateOrCreate(['endpoint_hash' => $hash], ['user_id' => $request->user()->id, 'subscription' => $data]);
        });
        $request->session()->put('push_endpoint_hash', $hash);

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): mixed
    {
        $request->validate(['endpoint' => 'required|string|max:2048']);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $request->input('endpoint')))->delete();
        $request->session()->forget('push_endpoint_hash');

        return response()->noContent();
    }

    public function test(Request $request, PushNotifications $push): array
    {
        $request->validate(['endpoint' => 'required|string|max:2048']);
        $subscription = PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $request->input('endpoint')))->firstOrFail();
        $sent = $push->send(collect([$subscription]), ['title' => 'ЛКО / ЛКР', 'body' => 'Уведомления на этом устройстве подключены.', 'url' => '/?push=support', 'tag' => 'push-test']);
        abort_unless($sent > 0, 503, 'Push-сервис не принял уведомление. Проверьте настройки или подключите устройство заново.');

        return ['accepted' => true];
    }
}
