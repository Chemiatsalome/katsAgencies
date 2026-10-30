<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LandlordApplicationRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $applicantName,
        public string $reason,
        public string $contactEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'About Your Kats Agencies Landlord Application');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.landlord-application-rejected');
    }
}
