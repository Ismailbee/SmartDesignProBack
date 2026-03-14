<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
    public function createForUser(User $user, string $title, string $message, string $type = 'info', ?string $sentBy = null, array $metadata = []): Notification
    {
        return Notification::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'read' => false,
            'sent_by' => $sentBy,
            'metadata' => $metadata,
        ]);
    }

    public function createForMany(iterable $users, string $title, string $message, string $type = 'admin_message', ?string $sentBy = null, array $metadata = []): int
    {
        $count = 0;

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $this->createForUser($user, $title, $message, $type, $sentBy, $metadata);
            $count++;
        }

        return $count;
    }

    public function latestForUser(User $user, int $limit = 20): Collection
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function toArray(Notification $notification, User $user): array
    {
        return [
            'id' => $notification->id,
            'userId' => $user->api_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'read' => (bool) $notification->read,
            'readAt' => optional($notification->read_at)?->toIso8601String(),
            'sentBy' => $notification->sent_by,
            'createdAt' => optional($notification->created_at)?->toIso8601String(),
            'metadata' => $notification->metadata ?? [],
        ];
    }
}