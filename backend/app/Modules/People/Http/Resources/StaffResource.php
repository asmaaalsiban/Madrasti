<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->staffProfile;
        $subject = $profile?->subject;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'staff_type' => $profile?->staff_type->value,
            'category' => $profile?->staff_type->category(),
            'subject' => $subject ? ['id' => $subject->id, 'name_ar' => $subject->name_ar, 'name_en' => $subject->name_en] : null,
            'floor' => $profile?->floor,
        ];
    }
}
