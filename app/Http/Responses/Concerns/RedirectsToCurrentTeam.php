<?php

namespace App\Http\Responses\Concerns;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->personalTeam() ?? $user->teams()->first();

        if (! $team) {
            $team = Team::create([
                'name' => $user->name."'s Fleet",
                'is_personal' => true,
                'status' => 'approved',
                'created_by' => $user->id,
            ]);
            $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        }

        if ($user->current_team_id !== $team->id) {
            $user->update(['current_team_id' => $team->id]);
            $user->setRelation('currentTeam', $team);
        }

        return $team;
    }
}
