<?php

namespace App\Http\Controllers;

use App\Models\Counter;
use App\Models\User;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    /**
     * Page d'accueil (arcade) — stats personnelles de l'utilisateur connecté.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return view('welcome', [
            'value'    => $user->counters()->count(),
            'userName' => $user->name,
            'userId'   => $user->id,
        ]);
    }

    /**
     * +1 clic attaché à l'utilisateur connecté. Renvoie son total personnel.
     */
    public function add(Request $request)
    {
        $request->user()->counters()->create(['count' => 1]);

        return response()->json([
            "value" => $request->user()->counters()->count(),
        ], 200);
    }

    /**
     * Total global (tous utilisateurs + clics anonymes). Public.
     */
    public function get()
    {
        return response()->json([
            "value" => (int) Counter::sum('count'),
        ], 200);
    }

    /**
     * Statistiques personnelles de l'utilisateur connecté.
     */
    public function stats(Request $request)
    {
        $counters = $request->user()->counters();

        return response()->json([
            "total"    => (clone $counters)->count(),
            "today"    => (clone $counters)->whereDate('created_at', today())->count(),
            "first_at" => (clone $counters)->min('created_at'),
            "last_at"  => (clone $counters)->max('created_at'),
        ], 200);
    }

    /**
     * Classement : top 10 joueurs par nombre de clics.
     */
    public function leaderboard(Request $request)
    {
        $top = User::query()
            ->withCount('counters')
            ->orderByDesc('counters_count')
            ->orderBy('id')
            ->take(10)
            ->get(['id', 'name']);

        return response()->json([
            "me"      => $request->user()->id,
            "players" => $top->map(fn ($u) => [
                "id"     => $u->id,
                "name"   => $u->name,
                "clicks" => $u->counters_count,
            ]),
        ], 200);
    }
}
