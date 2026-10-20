<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class GuardianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'children' => $this->children->map(fn ($child) => $child->only(['id', 'name_ar', 'name_en']))->values(),
        ];
    }
}
