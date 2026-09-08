<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    /**
     * Fixed at all of Policy::MODULE_KEYS — previously missing
     * 'access_requests' and 'business_calendar' meant a policy granting
     * only those two modules showed "No permissions assigned" in the
     * "Description" column despite genuinely having access, and any
     * future module added to MODULE_KEYS without a matching entry here
     * will quietly repeat the same bug. Keeping both lists the same
     * length/keys is the whole point of this constant existing at all.
     */
    private const MODULE_LABELS = [
        'dashboard'         => 'Dashboard',
        'inbox'             => 'Inbox',
        'analytics'         => 'Admin Analytics',
        'logbook'           => 'Admin Logbook',
        'profile'           => 'Admin Profile',
        'access_requests'   => 'Access Requests',
        'business_calendar' => 'Business Calendar',
        'cashier_overrides' => 'Cashier OR Overrides',
        'free_requests'     => 'Free Requests',
    ];

    public function toArray($request): array
    {
        $permissions = $this->permissions ?? [];

        return [
            'policy_id'   => $this->policy_id,
            'name'        => $this->name,
            'is_system'   => (bool) $this->is_system,
            'type'        => $this->is_system ? 'System managed' : 'Custom policy',

            // Raw module => actions map — what PolicyManagement.jsx's
            // handleOpenEdit() reads back into selectedModuleValues.
            'permissions' => $permissions,

            // Human-readable summary — what the "Description" column shows.
            'permissions_label' => $this->formatPermissionsLabel($permissions),

            // How many admins currently have this policy attached — drives
            // the "Used as" column and the "Assigned Admins" modal.
            'admins_count' => $this->whenCounted('users'),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function formatPermissionsLabel(array $permissions): string
    {
        $parts = [];
        foreach (self::MODULE_LABELS as $key => $label) {
            if (!empty($permissions[$key])) {
                $parts[] = $label;
            }
        }

        return $parts ? implode(', ', $parts) : 'No permissions assigned';
    }
}