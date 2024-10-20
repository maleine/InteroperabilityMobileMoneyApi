<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Cache;

class OTPController extends Controller
{
    protected $twilio;

    public function __construct(TwilioService $twilioService)
    {
        $this->twilio = $twilioService;
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|phone',
        ]);

        // Générer un OTP de 6 chiffres
        $otp = rand(100000, 999999);

        // Stocker l'OTP temporairement dans le cache (vous pouvez utiliser Redis ou une autre méthode)
        Cache::put('otp_' . $request->phone, $otp, 300); // Expire dans 5 minutes

        // Envoyer l'OTP par SMS
        $message = "Votre code OTP est : $otp";
        $this->twilio->sendSms($request->phone, $message);

        return response()->json(['message' => 'OTP envoyé avec succès']);
    }

    public function verifyOtp1(Request $request)
    {
        $request->validate([

            'otp' => 'required|digits:6',
        ]);

        // Récupérer l'OTP stocké
        $cachedOtp = Cache::get('otp_' . $request->phone);

        if ($cachedOtp && $cachedOtp == $request->otp) {
            // L'OTP est valide
            return response()->json(['message' => 'OTP vérifié avec succès']);
        }

        // L'OTP est invalide ou expiré
        return response()->json(['message' => 'OTP invalide ou expiré'], 400);
    }
}
