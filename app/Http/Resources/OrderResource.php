<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (float) $this->quantity,
            'total_price' => (float) $this->total_price,
            'total_price_formatted' => 'GMD ' . number_format($this->total_price, 2),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->getPaymentStatusLabel(),
            'payout_status' => $this->payout_status,
            'payout_status_label' => $this->getPayoutStatusLabel(),
            'payout_release_reason' => $this->payout_release_reason,
            'payout_released_at' => $this->payout_released_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'commission_amount' => $this->when($this->commission_amount !== null, fn () => (float) $this->commission_amount),
            'farmer_net_amount' => $this->when($this->farmer_net_amount !== null, fn () => (float) $this->farmer_net_amount),
            // Explicit, unmistakable signal for admin dispute review — a
            // dispute can be filed on a delivered order whose payout has
            // already gone out, and nothing should imply money is still
            // held back in that case.
            'funds_held_for_payout' => $this->payout_status === 'pending_release',
            'special_instructions' => $this->special_instructions,
            'delivery_method' => $this->delivery_method,
            'delivery_method_label' => $this->getDeliveryMethodLabel(),
            'delivery_deadline' => $this->delivery_deadline?->toISOString(),
            'delivery_deadline_display' => $this->delivery_deadline?->format('M d, Y'),
            'pickup_date' => $this->pickup_date?->toISOString(),
            'pickup_date_display' => $this->pickup_date?->format('M d, Y'),
            'delivery_address' => $this->delivery_address,
            'order_date' => $this->order_date?->toISOString(),
            'order_date_display' => $this->order_date?->format('M d, Y H:i'),
            'created_at' => $this->created_at?->toISOString(),
            'buyer' => $this->whenLoaded('buyer', function () {
                return new UserResource($this->buyer);
            }),
            'product' => $this->whenLoaded('product', function () {
                return new ProductResource($this->product);
            }),
            'review' => $this->whenLoaded('review', function () {
                return new ReviewResource($this->review);
            }),
        ];
    }

    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    protected function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'shipped' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    protected function getPaymentStatusLabel(): string
    {
        return match ($this->payment_status) {
            'pending' => 'Payment pending',
            'processing' => 'Payment processing',
            'paid' => 'Paid',
            'failed' => 'Payment failed',
            'expired' => 'Checkout expired',
            'cancelled' => 'Payment cancelled',
            'refunded' => 'Refunded',
            'partially_refunded' => 'Partially refunded',
            default => ucfirst($this->payment_status ?? 'pending'),
        };
    }

    protected function getPayoutStatusLabel(): string
    {
        return match ($this->payout_status) {
            'not_applicable' => 'Not applicable',
            'pending_release' => 'Held — pending buyer confirmation',
            'released' => 'Payout in progress',
            'paid' => 'Paid to farmer',
            'failed' => 'Payout failed',
            'voided' => 'Voided (refunded)',
            default => ucfirst(str_replace('_', ' ', $this->payout_status ?? 'not_applicable')),
        };
    }

    protected function getDeliveryMethodLabel(): string
    {
        return match ($this->delivery_method) {
            'pickup' => 'Pickup',
            'farmer_delivery' => 'Farmer Delivery',
            default => ucfirst($this->delivery_method),
        };
    }
}