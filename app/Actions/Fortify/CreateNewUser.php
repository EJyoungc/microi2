<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'account_type' => ['nullable', 'string', 'in:individual,organization'],
            'organization_name' => ['nullable', 'string', 'max:255', 'required_if:account_type,organization'],
            'org_type' => ['nullable', 'string', 'max:100'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'business_address' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $accountType = $input['account_type'] ?? 'individual';

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'system_role' => 'customer',
                'account_type' => $accountType,
                'phone_number' => $input['phone_number'] ?? null,
                'physical_address' => $input['business_address'] ?? null,
                'onboarding_completed' => false,
                'is_active' => true,
            ]);

            if ($accountType === 'organization' && ! empty($input['organization_name'])) {
                // Scenario B: Create pending organization
                $team = Team::create([
                    'name' => $input['organization_name'],
                    'is_personal' => false,
                    'status' => 'pending', // Pending administrator approval
                    'org_type' => $input['org_type'] ?? 'corporate',
                    'registration_number' => $input['registration_number'] ?? null,
                    'contact_email' => $user->email,
                    'contact_phone' => $input['phone_number'] ?? null,
                    'business_address' => $input['business_address'] ?? null,
                    'created_by' => $user->id,
                ]);

                $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
                $user->update(['current_team_id' => $team->id]);
            } else {
                // Individual personal team
                $this->createTeam->handle($user, $user->name."'s Fleet", isPersonal: true);
            }

            return $user;
        });
    }
}
