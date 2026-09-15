<?php

namespace App\Providers;

use App\Contracts\AlumniSystemClientInterface;
use App\Contracts\CashierServiceInterface;
use App\Contracts\DocumentRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\UndergradRequestorRegistrationServiceInterface;
use App\Models\NotificationType;
use App\Observers\NotificationTypeObserver;
use App\Services\AuditLogger;
use App\Services\SecurityEventLogger;
use App\Services\Alumni\AlumniSystemClient;
use App\Services\Alumni\FakeAlumniSystemClient;
use App\Services\CashierService;
use App\Services\DocumentRequestService;
use App\Services\NotificationService;
use App\Services\UndergradRequestorRegistrationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register interface → concrete-class bindings.
     *
     * Controllers and services depend on the interfaces (left-hand side).
     * Laravel's container resolves them to the concrete classes (right-hand side).
     * To swap an implementation — e.g. a fake notification service for tests —
     * change only this file; no controller or service needs to change.
     */
    public function register(): void
    {
        $this->app->bind(
            DocumentRequestServiceInterface::class,
            DocumentRequestService::class,
        );

        $this->app->bind(
            NotificationServiceInterface::class,
            NotificationService::class,
        );

        // CashierService — no swap condition today (unlike the alumni
        // client's mock/real split above), but every consumer now depends
        // on the interface rather than the concrete class, so a future
        // provider change, a different transport, or a local-dev fake
        // becomes a one-line change here instead of a hunt through every
        // constructor/type-hint that references CashierService directly.
        $this->app->bind(
            CashierServiceInterface::class,
            CashierService::class,
        );

        // Alumni System client — swap between real HTTP and fake based on env.
        // Set ALUMNI_MOCK=true in .env (or docker-compose environment) to use
        // hardcoded dummy data instead of calling PUPTAPS.
        // Production: ALUMNI_MOCK=false (or omit entirely — defaults to false).
        $this->app->bind(
            AlumniSystemClientInterface::class,
            env('ALUMNI_MOCK', false)
                ? FakeAlumniSystemClient::class
                : AlumniSystemClient::class,
        );

        // Undergrad Requestor Registration — Phase 2.
        $this->app->bind(
            UndergradRequestorRegistrationServiceInterface::class,
            UndergradRequestorRegistrationService::class,
        );

        // AuditLogger is a concrete class — no interface needed.
        // Singleton so the same instance is reused within a request.
        $this->app->singleton(AuditLogger::class);

        // SecurityEventLogger (Phase 3) — same reasoning as AuditLogger
        // above: concrete class, no interface needed, singleton so the
        // same instance (and any request-scoped state it may accrue) is
        // reused within a request.
        $this->app->singleton(SecurityEventLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Invalidate the NotificationType cache whenever a type is saved or
        // deleted. Without this, admin edits to notification templates would
        // have no effect for up to 6 hours (the cache TTL in NotificationService).
        NotificationType::observe(NotificationTypeObserver::class);
    }
}