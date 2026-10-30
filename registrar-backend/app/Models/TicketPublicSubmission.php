<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public submission awaiting email confirmation. See the migration for the
 * lifecycle. Not mass-assignable from request data: the service sets every
 * column explicitly.
 */
class TicketPublicSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_public_submissions';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'created_at'  => 'datetime',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
