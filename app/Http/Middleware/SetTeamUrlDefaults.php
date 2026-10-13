<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    /**
     * Set the default URL parameters for team-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $currentTeam = $user->currentTeam;

            if (! $currentTeam) {
                $currentTeam = $user->personalTeam() ?? $user->teams()->first();

                if (! $currentTeam) {
                    $currentTeam = Team::create([
                        'name' => $user->name."'s Fleet",
                        'is_personal' => true,
                        'status' => 'approved',
                        'created_by' => $user->id,
                    ]);
                    $currentTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
                }

                $user->update(['current_team_id' => $currentTeam->id]);
                $user->setRelation('currentTeam', $currentTeam);
            }

            URL::defaults([
                'current_team' => $currentTeam->slug,
                'team' => $currentTeam->slug,
            ]);
        }

        return $next($request);
    }
}
