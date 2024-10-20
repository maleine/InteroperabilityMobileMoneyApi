<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioService
{
    protected $twilio;

    public function __construct()
    {
        $this->twilio = new Client(config('services.twilio.sid'), config('services.twilio.token'));
    }

    public function sendSms($phoneNumber, $message)
    {
        return $this->twilio->messages->create(
            $phoneNumber, // Le numéro de téléphone du destinataire
            [
                'from' => config('services.twilio.from'), // Le numéro Twilio
                'body' => $message, // Le message à envoyer
            ]
        );
    }
}
