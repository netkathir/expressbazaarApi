<?php

namespace App\Support;

/**
 * The fixed email wording for every system notification. Admins can see these but not change them.
 */
class NotificationTemplates
{
    /**
     * @return array<string, array{name: string, recipient: string, subject: string, message: string}>
     */
    public static function all(): array
    {
        return [
            'ORDER_CONFIRMED' => [
                'name' => 'Order Confirmed',
                'recipient' => 'Customer',
                'subject' => 'Your order #{{order_number}} is confirmed',
                'message' => "Hello {{name}},\n\nYour order #{{order_number}} has been confirmed.\n\nTotal: {{amount}}\n\nThank you for shopping with us.",
            ],
            'ORDER_RECEIVED_VENDOR' => [
                'name' => 'New Order Received',
                'recipient' => 'Vendor',
                'subject' => 'New order received #{{order_number}}',
                'message' => "Hello {{name}},\n\nA new order #{{order_number}} has been received.\n\nCustomer: {{customer_name}}\nTotal: {{amount}}",
            ],
            'ORDER_STATUS_UPDATE' => [
                'name' => 'Order Status Update',
                'recipient' => 'Customer',
                'subject' => 'Order #{{order_number}} is now {{status}}',
                'message' => "Hello {{name}},\n\nYour order #{{order_number}} status has been updated to {{status}}.\n\nVendor: {{vendor_name}}\nTotal: {{amount}}",
            ],
            'ORDER_DELIVERED' => [
                'name' => 'Order Delivered',
                'recipient' => 'Customer',
                'subject' => 'Your order #{{order_number}} has been delivered',
                'message' => "Hello {{name}},\n\nYour order #{{order_number}} has been delivered successfully.\n\nThank you for shopping with us.",
            ],
            'PAYMENT_SUCCESS' => [
                'name' => 'Payment Successful',
                'recipient' => 'Customer',
                'subject' => 'Payment successful for order #{{order_number}}',
                'message' => "Hello {{name}},\n\nPayment was successful for order #{{order_number}}.\n\nAmount: {{amount}}",
            ],
        ];
    }

    public static function name(?string $trigger): ?string
    {
        return self::all()[$trigger]['name'] ?? null;
    }

    /**
     * Fill a trigger's subject and message with the event data. Returns null for an unknown trigger.
     *
     * @return array{subject: string, message: string}|null
     */
    public static function render(string $trigger, array $data): ?array
    {
        $template = self::all()[$trigger] ?? null;

        if (! $template) {
            return null;
        }

        $values = [
            '{{order_number}}' => (string) ($data['order_number'] ?? $data['order_id'] ?? ''),
            '{{amount}}' => (string) ($data['amount'] ?? $data['total_amount'] ?? ''),
            '{{name}}' => (string) ($data['name'] ?? 'Customer'),
            '{{status}}' => (string) ($data['status'] ?? $data['order_status'] ?? ''),
            '{{vendor_name}}' => (string) ($data['vendor_name'] ?? 'Express Bazar'),
            '{{customer_name}}' => (string) ($data['customer_name'] ?? '-'),
        ];

        return [
            'subject' => strtr($template['subject'], $values),
            'message' => strtr($template['message'], $values),
        ];
    }
}
