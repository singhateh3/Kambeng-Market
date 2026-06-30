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
                if ($request->is_read === 'true' || $request->is_read === '1') {
                    $query->where('is_read', true);
                } elseif ($request->is_read === 'false' || $request->is_read === '0') {
                    $query->where('is_read', false);
                }
            }

            $perPage = $request->input('per_page', 20);
            $notifications = $query->paginate($perPage);

            // Format the response to match frontend expectations
            $formattedNotifications = $notifications->items();

            // Add time_ago to each notification
            foreach ($formattedNotifications as $notification) {
                $notification->time_ago = $notification->created_at->diffForHumans();
            }

            return response()->json([
                'success' => true,
                'data' => $formattedNotifications,
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
                'error' => $e->getMessage()
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
     * Route: PUT /api/notifications/{notification}/read
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

            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'data' => $notification,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error marking notification as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     * Route: PUT /api/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $updated = Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => "{$updated} notifications marked as read",
                'data' => [
                    'updated_count' => $updated,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error marking notifications as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a notification
     * Route: DELETE /api/notifications/{notification}
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
            \Log::error('Error deleting notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete all read notifications
     * Route: DELETE /api/notifications/read
     */
    public function deleteRead(Request $request): JsonResponse
    {
        try {
            $deleted = Notification::where('user_id', $request->user()->id)
                ->where('is_read', true)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "{$deleted} read notifications deleted successfully",
                'data' => [
                    'deleted_count' => $deleted,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting read notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting read notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
