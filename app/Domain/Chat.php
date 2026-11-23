<?php

namespace App\Domain;

use App\Models\AppNotification;
use App\Models\RentalRequest;
use App\Models\RequestMessage;
use App\Models\User;

/**
 * Customer <-> shop conversation on a rental request. Open at any stage (questions before accepting, delivery
 * details, after the event); no problem case is needed. The other side gets an in-app notification (one per
 * burst of messages, not one per line).
 */
class Chat
{
    public const MAX = 2000;

    public const SIDES = ['customer', 'supplier'];

    /** Messages of a request as plain rows for the page / polling ([] keys: id, side, name, body, at, mine). */
    public static function rows(RentalRequest $r, string $side, int $after = 0): array
    {
        return $r->messages()->with('user:id,name')->where('id', '>', $after)->get()->map(fn (RequestMessage $m) => [
            'id' => $m->id, 'side' => $m->side, 'mine' => $m->side === $side,
            'name' => $m->side === 'supplier' ? ($m->user?->name ? $m->user->name.' · '.$r->supplier->name : $r->supplier->name) : ($m->user?->name ?? 'Customer'),
            'body' => $m->body, 'at' => $m->created_at?->format('M j, g:i A'),
        ])->all();
    }

    /** Messages from the other side that this side has not opened yet. */
    public static function unread(RentalRequest $r, string $side): int
    {
        $seen = $side === 'customer' ? $r->chat_customer_seen_at : $r->chat_supplier_seen_at;

        return $r->messages()->where('side', '!=', $side)->when($seen, fn ($q) => $q->where('created_at', '>', $seen))->count();
    }

    public static function markSeen(RentalRequest $r, string $side): void
    {
        $r->forceFill([$side === 'customer' ? 'chat_customer_seen_at' : 'chat_supplier_seen_at' => now()])->saveQuietly();
    }

    public static function post(RentalRequest $r, User $by, string $side, string $body, bool $notify = true): array
    {
        $body = trim(preg_replace("/\r\n?/", "\n", $body));
        if (! in_array($side, self::SIDES, true)) {
            return ['ok' => false, 'error' => 'You are not part of this conversation.'];
        }
        if ($body === '') {
            return ['ok' => false, 'error' => 'Write a message first.'];
        }
        if (mb_strlen($body) > self::MAX) {
            return ['ok' => false, 'error' => 'Messages can be at most '.self::MAX.' characters.'];
        }
        $m = RequestMessage::create(['rental_request_id' => $r->id, 'user_id' => $by->id, 'side' => $side, 'body' => $body, 'created_at' => now()]);
        self::markSeen($r, $side);

        if (! $notify) {
            return ['ok' => true, 'message' => $m];
        }
        $snippet = mb_strimwidth(str_replace("\n", ' ', $body), 0, 120, '…');
        if ($side === 'customer') {
            $title = 'New message on '.$r->code;
            $link = route('supplier.requests.chat', $r, false);
            if (! self::recentlyNotified($r->supplier->owner_id, 'supplier', $title)) {
                Notifier::notify($r->supplier->owner_id, 'supplier', $title, $by->name.': '.$snippet, $link);
            }
        } else {
            $title = 'New message from '.$r->supplier->name;
            $link = route('rentals.chat', $r, false);
            if (! self::recentlyNotified($r->customer_id, 'customer', $title.' · '.$r->code)) {
                Notifier::notify($r->customer_id, 'customer', $title.' · '.$r->code, $snippet, $link);
            }
        }

        return ['ok' => true, 'message' => $m];
    }

    /** One notification per burst: skip when the same alert was sent in the last 15 minutes and is still unread. */
    private static function recentlyNotified(int $userId, string $scope, string $title): bool
    {
        return AppNotification::where('user_id', $userId)->where('scope', $scope)->where('title', $title)
            ->where('created_at', '>', now()->subMinutes(15))->get()->contains(fn ($n) => ! $n->isReadBy($userId));
    }
}
