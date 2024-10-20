<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Claims\JwtId;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected $twilio;

    public function __construct(TwilioService $twilioService)
    {
        $this->twilio = $twilioService;
    }

    public function register(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'numero' => 'required|string|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Générer un OTP de 6 chiffres
        $otp = rand(100000, 999999);

        // Stocker l'OTP temporairement dans le cache
        Cache::put('otp_' . $request->numero, $otp, 300); // Expire dans 5 minutes

        // Envoyer l'OTP par SMS
        $message = "Votre code OTP pour valider votre inscription est : $otp";
        $this->twilio->sendSms($request->numero, $message);

        return response()->json(['message' => 'OTP envoyé avec succès'], 200);
    }

    public function verifyOtp(Request $request)
    {
        // Valider la requête entrante
        $request->validate([
            'numero' => 'required|string',
            'otp' => 'required|digits:6',
            'name' => 'sometimes|required|string|max:255', // Champ optionnel pour l'inscription
            'password' => 'sometimes|required|string|min:6', // Champ optionnel pour l'inscription
        ]);

        // Récupérer l'OTP du cache
        $cachedOtp = Cache::get('otp_' . $request->numero);

        // Vérifier si l'OTP est valide
        if (!$cachedOtp || $cachedOtp != $request->otp) {
            return response()->json(['message' => 'OTP invalide ou expiré'], 401);
        }

        // Supprimer l'OTP du cache après la vérification
        Cache::forget('otp_' . $request->numero);

        // Vérifier si le nom et le mot de passe sont fournis pour l'inscription
        if ($request->filled('name') && $request->filled('password')) {
            // Créer l'utilisateur si l'OTP est valide
            $user = User::create([
                'name' => $request->name,
                'numero' => $request->numero,
                'password' => Hash::make($request->password),
            ]);

            // Générer un token pour l'utilisateur nouvellement créé
            $token = JWTAuth::fromUser($user);

            return response()->json(['message' => 'Inscription réussie.', 'token' => $token], 201);
        }

        // Si l'OTP est valide mais qu'il n'y a pas de détails d'inscription, procéder à la connexion
        if ($request->filled('password')) {
            // Logique de connexion ici
            $user = User::where('numero', $request->numero)->first();
            $token = JWTAuth::fromUser($user);

            return response()->json(['token' => $token], 200);
        }

        return response()->json(['message' => 'Veuillez fournir un mot de passe pour la connexion.'], 400);
    }

    public function login(Request $request)
{
    // Validation des données d'entrée
    $request->validate([
        'numero' => 'required',
        'password' => 'required',
    ]);

    // Vérification des informations d'identification
    $user = User::where('numero', $request->numero)->first();

    if (!$user) {
        return response()->json(['message' => 'Informations d\'identification invalides.'], 401);
    }

    // Vérifier si l'utilisateur est archivé
    if ($user->archived) {
        return response()->json(['message' => 'Cet utilisateur est archivé et ne peut pas se connecter.'], 403);
    }

    // Vérifier le mot de passe
    if (!Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Informations d\'identification invalides.'], 401);
    }

    // Générer un token pour l'utilisateur
    $token = JWTAuth::fromUser($user);

    return response()->json([
        'message' => 'Connexion réussie',
        'name' => $user->name,
        'token' => $token,
    ]);
}


    public function updateUser(Request $request)
    {
        // Valider les données envoyées
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'numero' => 'required|string|max:15',
            'password' => 'nullable|string|min:6|confirmed', // Facultatif, confirmé si présent
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Récupérer l'utilisateur authentifié via JWTAuth
        try {
            if (!$user = JWTAuth::parseToken()->authenticate()) {
                return response()->json(['error' => 'Utilisateur non trouvé'], 404);
            }
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['error' => 'Token expiré'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['error' => 'Token invalide'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['error' => 'Token absent'], 401);
        }

        // Mettre à jour les informations de l'utilisateur
        $user->name = $request->input('name');
        $user->numero = $request->input('numero');

        if (!empty($request->input('password'))) {
            $user->password = bcrypt($request->input('password'));
        }

        // Sauvegarder les modifications
        $user->save();

        // Retourner une réponse avec les informations mises à jour
        return response()->json([
            'message' => 'Informations mises à jour avec succès',
            'user' => $user
        ], 200);
    }


    public function logout(Request $request)
    {
        // Vérifiez que l'utilisateur est authentifié
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        // Invalidating the token
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Déconnexion réussie.'], 200);
    }

    public function getUserInfo(Request $request)
    {
        // Récupérer l'utilisateur à partir du jeton JWT
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
            }

            // Retourner les informations de l'utilisateur
            return response()->json([

                'name' => $user->name,
                'numero' => $user->numero,

            ], 200);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token expiré.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token invalide.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['message' => 'Token non fourni.'], 401);
        }
    }
    public function archiveUser(Request $request)
    {
        // Récupérer l'utilisateur authentifié via JWT
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$user) {
                return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
            }

            // Archiver l'utilisateur
            $user->archived = true; // Assurez-vous que ce champ existe dans votre modèle
            $user->save(); // Sauvegarder les modifications

            return response()->json(['message' => 'Utilisateur archivé avec succès.'], 200);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json(['message' => 'Token expiré.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['message' => 'Token invalide.'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['message' => 'Token non fourni.'], 401);
        } catch (\Exception $e) {
            // Répondre avec des détails sur l'exception pour le débogage
            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'archivage de l\'utilisateur.',
                'error' => $e->getMessage() // Retourne le message d'erreur
            ], 500);
        }
    }


    public function requestPasswordReset(Request $request)
    {
        // Valider la requête
        $request->validate([
            'numero' => 'required|string'
        ]);

        // Vérifier si l'utilisateur existe avec ce numéro de téléphone
        $user = User::where('numero', $request->numero)->first();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
        }

        // Générer un OTP de 6 chiffres
        $otp = rand(100000, 999999);

        // Stocker l'OTP temporairement dans le cache (expire dans 5 minutes)
        Cache::put('reset_password_otp_' . $user->numero, $otp, 300);

        // Envoyer l'OTP par SMS (à l'aide de Twilio par exemple)
        $message = "Votre code de réinitialisation de mot de passe est : $otp";
        $this->twilio->sendSms($user->numero, $message);

        return response()->json(['message' => 'OTP envoyé avec succès.'], 200);
    }



    public function resetPassword(Request $request)
    {
        // Valider les données d'entrée
        $request->validate([
            'numero' => 'required|string',
            'password' => 'required|string|min:6|confirmed'
        ]);

        // Récupérer l'utilisateur correspondant au numéro de téléphone
        $user = User::where('numero', $request->numero)->first();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
        }

        // Mettre à jour le mot de passe de l'utilisateur
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.'], 200);
    }



public function verifyOtpPassword(Request $request)
{
    // Valider les données d'entrée
    $request->validate([
        'numero' => 'required|string',
        'otp' => 'required|digits:6',
    ]);

    // Récupérer l'OTP du cache
    $cachedOtp = Cache::get('reset_password_otp_' . $request->numero);

    // Vérifier si l'OTP est valide
    if (!$cachedOtp || $cachedOtp != $request->otp) {
        return response()->json(['message' => 'OTP invalide ou expiré.'], 401);
    }

    // Supprimer l'OTP du cache après la vérification
    Cache::forget('reset_password_otp_' . $request->numero);

    // Si l'OTP est valide, renvoyer une réponse de succès
    return response()->json(['message' => 'OTP vérifié avec succès. Vous pouvez maintenant réinitialiser votre mot de passe.'], 200);
}


}
