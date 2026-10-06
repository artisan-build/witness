<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamSelectionController
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        $teams = Team::query()->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))->orderBy('name')->get();

        return view('sender.teams.select', compact('teams'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['team_id' => ['required', 'integer']]);
        $team = Team::query()
            ->whereKey($data['team_id'])
            ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
            ->firstOrFail();

        $request->session()->put('witness.team_id', $team->getKey());

        return redirect()->route('sender.dashboard');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
