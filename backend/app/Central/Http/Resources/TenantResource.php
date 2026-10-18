<?php

declare(strict_types=1);

namespace App\Central\Http\Resources;

use App\Central\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'primary_color' => $this->primary_color,
            'default_locale' => $this->default_locale,
            'is_active' => $this->is_active,
            'subscription_ends_at' => $this->subscription_ends_at,
            'domains' => $this->domains->pluck('domain'),
        ];
    }
}
