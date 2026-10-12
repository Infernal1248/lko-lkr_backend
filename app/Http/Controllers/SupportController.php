<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    private function ticket(Request $r, int $id): SupportTicket
    {
        $q = SupportTicket::query();
        if (! $r->user()->is_support_admin) {
            $q->where('user_id', $r->user()->id);
        }

        return $q->findOrFail($id);
    }

    public function index(Request $r): mixed
    {
        $q = SupportTicket::query();
        if (! $r->user()->is_support_admin) {
            $q->where('user_id', $r->user()->id);
        }

        return $q->orderByDesc('updated_at')->paginate(25);
    }

    public function show(Request $r, int $id): array
    {
        $t = $this->ticket($r, $id);
        if ($t->user_id === $r->user()->id) {
            $t->update(['user_read_at' => now()]);
        }

        return ['ticket' => $t, 'messages' => SupportMessage::where('ticket_id', $id)->orderBy('id')->paginate(50)];
    }

    public function store(Request $r): mixed
    {
        $d = $r->validate(['subject' => 'required|string|max:160', 'body' => 'required|string|max:5000']);

        return DB::transaction(function () use ($r, $d) {
            $t = SupportTicket::create(['user_id' => $r->user()->id, 'subject' => $d['subject']]);
            SupportMessage::create(['ticket_id' => $t->id, 'author_id' => $r->user()->id, 'from_support' => false, 'body' => $d['body']]);

            return response()->json($t, 201);
        });
    }

    public function reply(Request $r, int $id): mixed
    {
        $d = $r->validate(['body' => 'required|string|max:5000']);

        return DB::transaction(function () use ($r, $id, $d) {
            $t = $this->ticket($r, $id);
            $admin = (bool) $r->user()->is_support_admin;
            $m = SupportMessage::create(['ticket_id' => $t->id, 'author_id' => $r->user()->id, 'from_support' => $admin, 'body' => $d['body']]);
            $t->update(['status' => $admin ? 'answered' : 'open', 'updated_at' => now()]);

            return response()->json($m, 201);
        });
    }

    public function close(Request $r, int $id): mixed
    {
        $t = $this->ticket($r, $id);
        $t->update(['status' => 'closed']);

        return $t;
    }
}
