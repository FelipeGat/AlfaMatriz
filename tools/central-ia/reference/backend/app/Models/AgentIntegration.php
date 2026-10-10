<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentIntegration extends Model
{
    protected $fillable = ['owner_id', 'provider', 'status', 'generation'];

    protected $hidden = ['credential_ref'];
}
