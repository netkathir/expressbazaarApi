<?php

namespace App\Support;

/**
 * Order lifecycle stages. Stored values stay stable; labels are what people see.
 *
 * Order placed -> Accepted -> Dispatched -> Out for delivery -> Delivered -> Completed
 * (pending)       (accepted)  (dispatched)  (out_for_delivery)  (delivered)  (completed)
 */
class OrderStatus
{
    public const ALL = ['pending', 'accepted', 'dispatched', 'out_for_delivery', 'delivered', 'completed', 'cancelled'];

    /** Stages from which a customer can still cancel: until the vendor dispatches the order. */
    public const CANCELLABLE = ['pending', 'accepted'];

    public static function customerCanCancel(?string $status): bool
    {
        return in_array(mb_strtolower((string) ($status ?: 'pending')), self::CANCELLABLE, true);
    }

    /** Hours after delivery before an order is closed automatically. */
    public const AUTO_COMPLETE_HOURS = 24;

    private const LABELS = [
        'pending' => 'Order placed',
        'accepted' => 'Accepted',
        'dispatched' => 'Dispatched',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        $status = mb_strtolower((string) $status);

        return self::LABELS[$status] ?? ucfirst($status);
    }

    /**
     * @return array<string, string> value => label, in lifecycle order
     */
    public static function options(): array
    {
        return self::LABELS;
    }
}
