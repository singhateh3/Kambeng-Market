<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderCollection extends ResourceCollection
{
    public $collects = OrderResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
            ],
        ];
    }

    /**
     * Suppress Laravel's own auto-generated pagination meta/links.
     *
     * Without this, PaginatedResourceResponse merges its own 'meta' key
     * into the array above via array_merge_recursive(), and matching
     * scalar keys (current_page, last_page, etc.) turn into two-element
     * arrays instead of plain numbers — which silently breaks any frontend
     * comparison like `meta.last_page > 1`. Same issue as ProductCollection.
     */
    public function paginationInformation($request, $paginated, $default)
    {
        return [];
    }
}