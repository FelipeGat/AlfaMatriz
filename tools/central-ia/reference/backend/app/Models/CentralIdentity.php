<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralIdentity extends Model
{
    protected $fillable = ['user_id', 'issuer', 'subject', 'status', 'session_epoch'];
}
