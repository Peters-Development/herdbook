<?php

namespace App\Mail;

use App\Services\Alerts\AlertMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Str;

/**
 * An alert as a plain-text mail, as bare as the push messages:
 * the headline as subject, then the time (or the list) and a link. Not
 * queued: MailChannel has to know whether it went out.
 */
class AlertMail extends Mailable
{
    public function __construct(public readonly AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Str::limit($this->alert->title, 150));
    }

    /**
     * Machine-sent: no out-of-office replies to every alert. Mail clients
     * that sort on priority put an outage of a critical check on top.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
            ...($this->alert->isUrgent() ? ['X-Priority' => '1 (Highest)', 'Importance' => 'High'] : []),
        ]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert', with: ['text' => $this->plainText()]);
    }

    private function plainText(): string
    {
        return collect([
            $this->alert->title,
            $this->alert->body,
            filled($this->alert->url) ? __('View: :url', ['url' => $this->alert->url]) : null,
        ])->filter(fn (?string $part): bool => filled($part))->implode("\n\n");
    }
}
