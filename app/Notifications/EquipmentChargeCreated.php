<?php

namespace App\Notifications;

use App\Models\EquipmentCharge;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EquipmentChargeCreated extends Notification
{
    use Queueable;

    public function __construct(public array $charge) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'equipment_charge_created',
            'title' => 'Equipment Charge Recorded',
            'message' => 'A charge has been recorded for equipment related to your reservation.',
            'details' => [$this->charge['equipment'], 'Quantity: '.$this->charge['quantity'], 'Amount: ₱'.number_format((float) $this->charge['amount'], 2)],
            'equipment_charge_id' => $this->charge['id'],
        ];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'mail';
    }
}
