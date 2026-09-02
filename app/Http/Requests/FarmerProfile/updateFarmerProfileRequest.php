<?php

namespace App\Http\Requests\FarmerProfile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class updateFarmerProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bio' => 'nullable|string|max:500',
            'farm_name' => 'nullable|string|max:255',
            'farm_location' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|max:5120',
            // Only two networks exist on ModemPay in Gambia — confirmed
            // directly against their docs. All three fields must be
            // submitted together — a farmer can't leave the payout
            // destination half-set, which FarmerProfile::hasSettlementDetails()
            // would otherwise silently treat as "nothing on file" anyway,
            // but requiring all three here gives a clear validation error
            // instead of a silent no-op.
            'settlement_network' => 'nullable|required_with:settlement_account_number,settlement_beneficiary_name|in:wave,afrimoney',
            'settlement_account_number' => 'nullable|required_with:settlement_network,settlement_beneficiary_name|string|max:50',
            'settlement_beneficiary_name' => 'nullable|required_with:settlement_network,settlement_account_number|string|max:255',
        ];
    }
}
