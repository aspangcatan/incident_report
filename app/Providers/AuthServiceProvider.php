<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Incident::class => \App\Policies\IncidentPolicy::class,
        \App\Models\Investigation::class => \App\Policies\InvestigationPolicy::class,
        \App\Models\CorrectiveAction::class => \App\Policies\CorrectiveActionPolicy::class,
        \App\Models\SafetyAlert::class => \App\Policies\SafetyAlertPolicy::class,
        \App\Models\RecurrenceReview::class => \App\Policies\RecurrenceReviewPolicy::class,
        \App\Models\IncidentType::class => \App\Policies\IncidentTypePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Technical setup: which departments each Leadership user oversees.
        // Workflow time limits are technical configuration: IT/System Admin only.
        Gate::define('manageWorkflowDurations', fn (User $user) => $user->role === Role::Administrator);
        Gate::define('manageLeadership', fn (User $user) => in_array($user->role, [Role::Administrator, Role::QualitySafetyOfficer], true));
    }
}
