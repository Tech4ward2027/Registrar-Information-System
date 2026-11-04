<?php

namespace App\Contracts;

use App\Models\Notification;
use App\Models\SystemUser;

/**
 * Contract for the notification delivery layer.
 *
 * Bind in AppServiceProvider so callers depend on this interface,
 * not the concrete NotificationService class.
 */
interface NotificationServiceInterface
{
    /**
     * Save a notification row and broadcast it to a specific user.
     */
    public function send(
        SystemUser $recipient,
        string     $triggerEvent,
        array      $data      = [],
        ?int       $requestId = null,
    ): ?Notification;

    /**
     * Dispatch a bulk-notification job targeting exactly the given roles
     * (an allow-list: a role added to the system later is NOT included).
     *
     * @param int[] $roleIds
     */
    public function sendToRoles(
        array  $roleIds,
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void;

    /**
     * Dispatch a bulk-notification job targeting only super-admin users.
     */
    public function sendToSuperAdmins(
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void;

    /**
     * Dispatch a bulk-notification job targeting only admin and super-admin users.
     */
    public function sendToAdmins(
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void;

    /**
     * Dispatch a bulk-notification job excluding the specified role IDs.
     */
    public function sendToAllExcept(
        array  $excludedRoleIds,
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void;

    /**
     * Return the count of unread notifications for a user.
     */
    public function unreadCount(SystemUser $user): int;

    /**
     * Mark all of a user's unread notifications as read.
     */
    public function markAllAsRead(SystemUser $user): void;
}