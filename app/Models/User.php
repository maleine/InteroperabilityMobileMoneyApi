<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'name', 'numero', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    // Méthode pour obtenir l'ID du sujet JWT
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    // Méthode pour obtenir les données de charge utile du JWT
    public function getJWTCustomClaims()
    {
        return [];
    }
}
