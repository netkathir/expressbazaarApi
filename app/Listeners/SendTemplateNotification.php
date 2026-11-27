<?php

namespace App\Listeners;

use App\Events\TriggerNotificationEvent;
use App\Mail\GenericTemplateMail;
use App\Models\NotificationLog;
use App\Support\NotificationTemplates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendTemplateNotification
{
    public function handle(TriggerNotificationEvent $event): void
    {
        $email = $event->data['email'] ?? null;
        $template = NotificationTemplates::render($event->trigger, $event->data);

        if (! $email || ! $template) {
            return;
        }

        $duplicateKey = $this->duplicateKey($event);

        if ($duplicateKey && ! Cache::add($duplicateKey, true, now()->addDay())) {
            return;
        }

        try {
            Mail::to($email)->send(new GenericTemplateMail($template['subject'], $template['message']));
            $this->logNotification($event, $template['message'], 'sent');
        } catch (Throwable $exception) {
            if ($duplicateKey) {
                Cache::forget($duplicateKey);
            }

            Log::error('Template notification failed.', [
                'trigger' => $event->trigger,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            $this->logNotification($event, $template['message'], 'failed', $exception->getMessage());
        }
    }

    private function logNotification(TriggerNotificationEvent $event, string $message, string $status, ?string $error = null): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        NotificationLog::create([
            'template_key' => $event->trigger,
            'recipient_type' => $event->data['recipient_type'] ?? 'user',
            'recipient_id' => $event->data['recipient_id'] ?? null,
            'channel' => 'email',
            'message' => $message,
            'status' => $status,
            'error_message' => $error,
        ]);
    }

    private function duplicateKey(TriggerNotificationEvent $event): ?string
    {
        $orderId = $event->data['order_id'] ?? $event->data['order_number'] ?? null;
        $recipientId = $event->data['recipient_id'] ?? $event->data['email'] ?? null;

        if (! $orderId || ! $recipientId) {
            return null;
        }

        return 'notification_sent:'.sha1(implode('|', [
            $event->trigger,
            $event->data['recipient_type'] ?? 'user',
            $recipientId,
            $orderId,
            $event->data['status'] ?? $event->data['order_status'] ?? '',
        ]));
    }
}
