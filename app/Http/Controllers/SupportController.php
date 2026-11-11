<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\PushNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    public function __construct(private PushNotifications $push) {}

    private function ticket(Request $r, int $id): SupportTicket
    {
        $q = SupportTicket::query()->withUnreadReply();
        if ($r->user()->is_support_admin) {
            $q->with('user:id,name,username,email');
        }
        if (! $r->user()->is_support_admin) {
            $q->where('user_id', $r->user()->id);
        }

        return $q->findOrFail($id);
    }

    public function index(Request $r): mixed
    {
        $q = SupportTicket::query()->withUnreadReply();
        if ($r->user()->is_support_admin) {
            $q->with('user:id,name,username,email');
        }
        if (! $r->user()->is_support_admin) {
            $q->where('user_id', $r->user()->id);
        }

        return $q->orderByDesc('updated_at')->paginate(25);
    }

    public function show(Request $r, int $id): array
    {
        $t = $this->ticket($r, $id);
        $messages = SupportMessage::where('ticket_id', $id)->orderBy('id');
        $page = $r->has('page') ? max(1, (int) $r->input('page')) : max(1, (int) ceil((clone $messages)->count() / 50));
        $result = $messages->paginate(50, ['*'], 'page', $page);
        if ((int) $t->user_id === (int) $r->user()->id && ! $r->user()->is_support_admin && $result->currentPage() === $result->lastPage()) {
            $lastRead = $result->getCollection()->where('from_support', true)->max('created_at');
            if ($lastRead && (! $t->user_read_at || $t->user_read_at->lt($lastRead))) {
                // Reading must not move the ticket to the top of the activity feed.
                $t->timestamps = false;
                $t->update(['user_read_at' => $lastRead]);
                $t->timestamps = true;
                $t->refresh()->loadExists(['messages as has_unread_reply' => fn ($q) => $q->where('from_support', true)->where('created_at', '>', $lastRead)]);
            }
        }

        return ['ticket' => $t, 'messages' => $result];
    }

    public function unread(Request $r): array
    {
        $count = SupportTicket::where('user_id', $r->user()->id)->whereHas('messages', fn ($q) => $q->where('from_support', true)
            ->where(fn ($q) => $q->whereNull('support_tickets.user_read_at')->orWhereColumn('support_messages.created_at', '>', 'support_tickets.user_read_at')))->count();

        return ['count' => $count];
    }

    public function store(Request $r): mixed
    {
        $d = $r->validate(['subject' => 'required|string|max:160', 'body' => 'required|string|max:5000']);

        $response = DB::transaction(function () use ($r, $d) {
            $t = SupportTicket::create(['user_id' => $r->user()->id, 'subject' => $d['subject']]);
            SupportMessage::create(['ticket_id' => $t->id, 'author_id' => $r->user()->id, 'from_support' => false, 'body' => $d['body']]);

            return response()->json($t, 201);
        });
        $this->push->supportMessage($response->getOriginalContent(), false, $r->user()->id);

        return $response;
    }

    public function reply(Request $r, int $id): mixed
    {
        $d = $r->validate(['body' => 'required|string|max:5000']);

        $response = DB::transaction(function () use ($r, $id, $d) {
            $t = $this->ticket($r, $id);
            $admin = (bool) $r->user()->is_support_admin;
            $m = SupportMessage::create(['ticket_id' => $t->id, 'author_id' => $r->user()->id, 'from_support' => $admin, 'body' => $d['body']]);
            $t->update(['status' => $admin ? 'answered' : 'open', 'updated_at' => now()] + ($admin ? ['user_read_at' => null] : []));

            return response()->json($m, 201);
        });
        $this->push->supportMessage($this->ticket($r, $id), (bool) $r->user()->is_support_admin, $r->user()->id);

        return $response;
    }

    public function close(Request $r, int $id): mixed
    {
        $t = $this->ticket($r, $id);
        $t->update(['status' => 'closed']);

        return $t;
    }
}
