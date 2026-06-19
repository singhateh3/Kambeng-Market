<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\FarmerProfile;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'location', 'role', 
        'avatar', 'verified_at', 'password'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function farmerProfile()
    {
        return $this->hasOne(FarmerProfile::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'farmer_id');
    }

    public function ordersAsBuyer()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function isFarmer()
    {
        return $this->role === 'farmer';
    }

    public function isBuyer()
    {
        return $this->role === 'buyer';
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }
}