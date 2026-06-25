<?php

// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Notification::where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc');

            // Filter by read status
            if ($request->has('is_read')) {
                if ($request->is_read === 'true') {
                    $query->where('is_read', true);
                } elseif ($request->is_read === 'false') {
                    $query->where('is_read', false);
                }
            }

            $notifications = $query->paginate($request->per_page ?? 20);

            return response()->json([
                'success' => true,
                'data' => $notifications->items(),
                'meta' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'unread_count' => Notification::where('user_id', $request->user()->id)
                        ->where('is_read', false)
                        ->count(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching notifications',
            ], 500);
        }
    }

    /**
     * Get unread notification count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $count = Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'count' => $count,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching unread count',
            ], 500);
        }
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        try {
            // Check if the notification belongs to the authenticated user
            if ($notification->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $notification,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error marking notification as read',
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error marking notifications as read',
            ], 500);
        }
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        try {
            // Check if the notification belongs to the authenticated user
            if ($notification->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting notification',
            ], 500);
        }
    }

    /**
     * Delete all read notifications
     */
    public function deleteRead(Request $request): JsonResponse
    {
        try {
            Notification::where('user_id', $request->user()->id)
                ->where('is_read', true)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Read notifications deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting read notifications',
            ], 500);
        }
    }
}