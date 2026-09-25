<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        \App\Events\IncidentSubmitted::class => [
            \App\Listeners\NotifyReviewersOfSubmittedIncident::class,
        ],
        \App\Events\IncidentReviewed::class => [
            \App\Listeners\NotifyReporterOfReviewOutcome::class,
        ],
        \App\Events\IncidentReturnedForRevision::class => [
            \App\Listeners\NotifyReporterOfReturnForRevision::class,
        ],
        \App\Events\IncidentAssigned::class => [
            \App\Listeners\NotifyInvestigatorOfAssignment::class,
        ],
        \App\Events\IncidentAssessed::class => [
            \App\Listeners\NotifyReviewersOfAssessedIncident::class,
        ],
        \App\Events\IncidentReturnedToDepartment::class => [
            \App\Listeners\NotifyDepartmentHeadsOfReturn::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
