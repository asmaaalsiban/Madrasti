<?php

declare(strict_types=1);

namespace App\Central\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform owner. Manages schools; has no access inside school databases.
 */
class SuperAdmin extends Authenticatable
{
    use CentralConnection, HasApiTokens;

    protected $table = 'super_admins';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
