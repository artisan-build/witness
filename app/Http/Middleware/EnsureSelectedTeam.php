<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureSelectedTeam
{
    public function __construct(private SelectedTeam $selectedTeam) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $teams = Team::query()
            ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            $team = DB::transaction(function () use ($user): Team {
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $existing = Team::query()
                    ->whereHas('users', fn ($query) => $query->whereKey($user->getKey()))
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $team = Team::query()->create(['name' => $user->name.'\'s team']);
                $team->users()->attach($user);

                return $team;
            });
            $teams = collect([$team]);
        }

        $teamId = $request->session()->get('witness.team_id');
        $team = $teams->firstWhere('id', $teamId);

        if ($team === null && $teams->count() === 1) {
            $team = $teams->firstOrFail();
            $request->session()->put('witness.team_id', $team->getKey());
        }

        if ($team === null) {
            return redirect()->route('sender.teams.select');
        }

        $this->selectedTeam->set($team);
        view()->share('selectedTeam', $team);

        return $next($request);
    }
}
