<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'reason' => $this->reason,
            'reason_label' => $this->getReasonLabel(),
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'admin_note' => $this->admin_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'created_at_display' => $this->created_at?->format('M d, Y'),
            'order' => $this->whenLoaded('order', function () {
                return new OrderResource($this->order);
            }),
            'reporter' => $this->whenLoaded('reporter', function () {
                return new UserResource($this->reporter);
            }),
            'reviewer' => $this->whenLoaded('reviewer', function () {
                return $this->reviewer ? new UserResource($this->reviewer) : null;
            }),
        ];
    }

    protected function getReasonLabel(): string
    {
        return match ($this->reason) {
            'item_not_received' => 'Item not received',
            'item_not_as_described' => 'Item not as described',
            'quality_issue' => 'Quality issue',
            'wrong_item' => 'Wrong item',
            'farmer_unresponsive' => 'Farmer unresponsive',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', $this->reason)),
        };
    }

    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'open' => 'Open',
            'under_review' => 'Under review',
            'resolved' => 'Resolved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }
}
