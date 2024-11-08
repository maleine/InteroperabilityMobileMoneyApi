<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;
class ExchangeController extends Controller
{
    public function exchangeFunds(Request $request)
    {
        $validatedData = $request->validate([
            'from_operator' => 'required|string|in:orange,wave',
            'to_operator' => 'required|string|in:orange,wave',
            'amount' => 'required|numeric|min:500',
            'from_phone' => 'required|string',
            'to_phone' => 'required|string',
        ]);

        $fromOperator = $validatedData['from_operator'];
        $toOperator = $validatedData['to_operator'];
        $amount = $validatedData['amount'];
        $fromPhone = $validatedData['from_phone'];
        $toPhone = $validatedData['to_phone'];

        // Calcul des frais et du montant total
        $fee = ceil(($amount * 0.01) / 5) * 5;
        $totalAmount = $amount + $fee;

        // Création de la transaction en base de données (statut en "pending")
        try {



            $transaction = Transaction::create([
                'from_operator' => $fromOperator,
                'to_operator' => $toOperator,
                'customer_msisdn' => $fromPhone,
                'receiver_msisdn' => $toPhone,
                'amount' => $amount,
                'fee' => $fee,

                'status' => 'pending', // Le statut est "pending" car la transaction n'est pas encore validée
            ]);

            return response()->json([
                'message' => 'Transaction initiée avec succès',
                'transaction_id' => $transaction->id,
                'from_operator' => $fromOperator,
                'to_operator' => $toOperator,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Erreur lors de l\'initiation de la transaction: ' . $e->getMessage()], 500);
        }
    }




    public function confirmTransactionEchange($transaction_id, Request $request)
    {
        // Validation des données de la requête pour pinCode
        $validatedData = $request->validate([
            'pinCode' => 'required|string|min:4|max:6', // Assurez-vous que le pinCode a entre 4 et 6 caractères
        ]);
        $pinCode = $validatedData['pinCode'];

        // Récupérer la transaction à partir de l'ID
        $transaction = Transaction::find($transaction_id);

        // Vérifier si la transaction existe
        if (!$transaction) {
            return response()->json(['error' => 'Transaction non trouvée'], 404);
        }

            // Récupérer les détails de la transaction
    $fromOperator = $transaction->from_operator;
    $toOperator = $transaction->to_operator;
    $fromPhone = $transaction->customer_msisdn;
    $toPhone = $transaction->receiver_msisdn;
    $amount = $transaction->amount;
    $fee = $transaction->fee;


    // Calcul du montant total
    $totalAmount = $amount + $fee;
                    // Vérifiez le solde avant la transaction
                    $publicKey = $this->getPublicKey(); // Ajoutez le signe $ pour appeler la méthode
                    $encryptedPin = $this->encryptPin($pinCode, $publicKey); // Chiffrement du code PIN
                    $balanceBefore = ($fromOperator === 'orange') ?
                     $this->checkCustomerBalance($fromPhone, $encryptedPin) :
                     $this->checkWaveCustomerBalance($fromPhone, $pinCode);

        if ($balanceBefore < $totalAmount) {
            return response()->json(['error' => 'Solde insuffisant sur le compte ' . ucfirst($fromOperator)], 400);
        }
        // Effectuer le retrait en fonction de l'opérateur (Wave ou Orange)
        if ($fromOperator === 'wave') {
            $withdrawalResponse = $this->withdrawFromWave($totalAmount, $fromPhone);
        } else {
            $withdrawalResponse = $this->withdrawFromOrangeMoney($totalAmount, $fromPhone);
        }

        // Vérification de la réponse de retrait
        if (!$withdrawalResponse['success']) {
            $transaction->update([
                'status' => 'failed',

                'error_message' => 'Échec du retrait depuis ' . ucfirst($fromOperator) . ': ' . $withdrawalResponse['message'],
            ]);
            return response()->json(['error' => 'Échec du retrait depuis ' . ucfirst($fromOperator) . ': ' . $withdrawalResponse['message']], 400);
        }

        // Récupérer l'identifiant de la transaction depuis la réponse de retrait
        $transactionIdKey = ($fromOperator === 'orange') ? 'transactionId' : 'transaction_id';
        if (!isset($withdrawalResponse[$transactionIdKey])) {
            $transaction->update([
                'status' => 'failed',
                'error_message' => "$transactionIdKey non fourni dans la réponse de retrait",
            ]);
            return response()->json(['error' => "$transactionIdKey non fourni dans la réponse de retrait"], 400);
        }

        $transactionId = $withdrawalResponse[$transactionIdKey];

        // Effectuer le dépôt en fonction de l'opérateur de destination
        if ($toOperator === 'wave') {
            $depositResponse = $this->depositToWave($totalAmount, $toPhone);
        } else {
            $depositResponse = $this->depositToOrange($totalAmount, $toPhone);
        }

        // Vérification de la réponse de dépôt
        if (!$depositResponse['success']) {
            $transaction->update([
                'status' => 'failed',
                'error_message' => 'Échec du dépôt vers ' . ucfirst($toOperator) . ': ' . $depositResponse['message'],
            ]);
            return response()->json(['error' => 'Échec du dépôt vers ' . ucfirst($toOperator) . ': ' . $depositResponse['message']], 400);
        }

        // Confirmer le retrait après un dépôt réussi
        if ($fromOperator === 'orange') {
            $publicKey = $this->getPublicKey();
            $confirmationResponse = $this->confirmTransaction($transactionId, $fromPhone,$pinCode,$publicKey);
        } else {
            $confirmationResponse = $this->confirmTransactionWave($transactionId, $pinCode);
        }

        // Vérification de la confirmation du retrait
        if (!$confirmationResponse['success']) {
            $transaction->update([
                'status' => 'failed',
                'error_message' => 'Échec de la confirmation du retrait: ' . $confirmationResponse['message'],
            ]);
            return response()->json(['error' => 'Échec de la confirmation du retrait: ' . $confirmationResponse['message']], 400);
        }

        // Calcul du solde après transaction
        $balanceAfter = $balanceBefore - $totalAmount;

        // Mise à jour de la transaction comme réussie
        $transaction->update([
            'balance_after' => $balanceAfter,
            'status' => 'completed',
            'balance_before' => $balanceBefore,
            'transaction_id' => $transactionId,
        ]);

        // Retourner la réponse avec les informations de la transaction
        return response()->json([
            'message' => 'Échange effectué avec succès',
            'data' => [
                'from_operator' => $fromOperator,
                'to_operator' => $toOperator,
                'amount' => $totalAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_id' => $transactionId,
            ]
        ], 200);
    }










    // Méthode pour vérifier le solde du compte Orange
    public function checkCustomerBalance($fromPhone,$encryptedPin)
    {
        // Envoi de la requête à l'API Orange
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . '/account/customer/balance', [
            'id' => $fromPhone,
            'encryptedPinCode' => $encryptedPin,
            'wallet' => 'PRINCIPAL',
            'idType' => 'MSISDN'
        ]);

        // Vérifier si le code de statut est 200
        if ($response->status() === 200) {
            // Récupérer le solde à partir de la réponse
            $balanceData = $response->json();

            if (isset($balanceData['value'])) {
                return $balanceData['value']; // Retourne uniquement le montant
            }

            Log::error('Erreur lors de la récupération du solde: champ "value" manquant dans la réponse', [
                'response' => $balanceData
            ]);
            throw new \Exception('Erreur lors de la récupération du solde: champ "value" manquant dans la réponse');
        } else {
            // Log l'erreur en cas de réponse non réussie
            Log::error('Erreur lors de la récupération du solde', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            throw new \Exception('Erreur lors de la récupération du solde: ' . $response->body());
        }
    }

    /**
     * Effectue un retrait depuis le compte du client Orange
     */
    public function withdrawFromOrangeMoney($totalAmount,$fromPhone)
    {
        $data = [
            'amount' => [
                'unit' => 'XOF',
                'value' => $totalAmount, // Assurez-vous que $amount est le montant correct
            ],
            'customer' => [
                'id' =>$fromPhone,
                'idType' => 'MSISDN',
                'walletType' => 'PRINCIPAL',
            ],
            'partner' => [
                'encryptedPinCode' => env('ORANGE_RETAILER_ENCRYPTED_PIN'),
                'id' => env('ORANGE_RETAILER_MSISDN'),
                'idType' => 'MSISDN',
                'walletType' => 'PRINCIPAL',
            ],
            'receiveNotification' => true, // ou false selon votre besoin
            'reference' => 'unique-reference-id', // Remplacez par une référence unique générée
            'requestDate' => now()->toISOString(), // Assurez-vous que la méthode toISOString fonctionne
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . '/cashouts', $data);

        if ($response->successful()) {
            // Récupérer le transactionId depuis la réponse
            $responseData = $response->json();
            return [
                'success' => true,
                'transactionId' => $responseData['transactionId'] ?? null, // Assurez-vous que cela correspond à la structure de votre réponse
            ];
        } else {
            Log::error('Erreur lors de l\'initiation du retrait', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de l\'initiation du retrait: ' . $response->body()
            ];
        }
    }

    // Méthode pour confirmer le retrait
    public function confirmTransaction($transactionId, $fromPhone, $pinCode,$publicKey)
    {
        // Chiffrement du code PIN
        $encryptedPinCode = $this->encryptPin($pinCode, $publicKey); // Assurez-vous que cette fonction est correctement définie

        // Envoi de la requête pour confirmer la transaction
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . "/transactions/{$transactionId}/confirm", [
            'idType' => 'MSISDN',
            'id' => $fromPhone,
            'encryptedPinCode' => $encryptedPinCode
        ]);

        // Vérification de la réponse
        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Transaction confirmée avec succès'
            ];
        } else {
            Log::error('Erreur lors de la confirmation de la transaction', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la confirmation de la transaction: ' . $response->body()
            ];
        }
    }



    // Méthode pour effectuer le dépôt vers un compte Orange
    public function depositToOrange($amount,$toPhone)
{


    // Paramètres de la requête
    $data = [
        'amount' => [
            'unit' => 'XOF',
            'value' => $amount, // Le montant à déposer
        ],
        'customer' => [
            'id' => $toPhone ,// Numéro de téléphone du client
            'idType' => 'MSISDN',
            'walletType' => 'PRINCIPAL',
        ],

        'partner' => [
            'encryptedPinCode' => env('ORANGE_RETAILER_ENCRYPTED_PIN'), // Remplacez par le vrai code PIN chiffré
            'id' => env('ORANGE_RETAILER_MSISDN'), // ID du partenaire
            'idType' => 'MSISDN',
            'walletType' => 'PRINCIPAL',
        ],

    ];

    // Envoi de la requête à l'API
    try {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . '/cashins', $data);
        // Vérification de la réponse
        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(), // Retournez les données de la réponse
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Erreur lors du dépôt : ' . $response->body(),
            ];
        }
    } catch (\Exception $e) {
        // Gérer les exceptions sans log
        return [
            'success' => false,
            'message' => 'Erreur inattendue : ' . $e->getMessage(),
        ];
    }
}



public function checkWaveCustomerBalance($fromPhone,$pinCode)
{
    // Envoi de la requête à l'API Wave
    $response = Http::post(env('WAVE_API_BASE_URL') . '/get-balance', [
        'phone_number' => $fromPhone,
        'pin_code' => $pinCode
    ]);

    // Vérifier si le code de statut est 200
    if ($response->status() === 200) {
        // Récupérer le solde à partir de la réponse
        $balanceData = $response->json();

        if (isset($balanceData['balance'])) {
            return $balanceData['balance']; // Retourne le montant du solde
        }

        Log::error('Erreur lors de la récupération du solde Wave: champ "balance" manquant dans la réponse', [
            'response' => $balanceData
        ]);
        throw new \Exception('Erreur lors de la récupération du solde Wave: champ "balance" manquant dans la réponse');
    } else {
        // Log l'erreur en cas de réponse non réussie
        Log::error('Erreur lors de la récupération du solde Wave', [
            'status' => $response->status(),
            'response' => $response->json()
        ]);
        throw new \Exception('Erreur lors de la récupération du solde Wave: ' . $response->body());
    }
}





    private function depositToWave($amount,$toPhone)
    {
        // Envoi d'une requête POST à l'API Wave pour effectuer un dépôt
        $response = Http::post(env('WAVE_API_BASE_URL') . '/account/deposit', [
            'manager_phone' => env('WAVE_MANAGER_MSISDN'), // Numéro de téléphone du manager
            'amount' => $amount, // Montant à déposer
            'manager_pin' => env('WAVE_MANAGER_PIN'), // PIN en clair du manager (à éviter de hacher ici)
            'client_phone' => $toPhone // Numéro de téléphone du client
        ]);

        // Vérification de la réponse JSON et gestion des erreurs
        if ($response->successful()) {
            $data = $response->json();

            // Vérifier si le dépôt a bien réussi en analysant le message de réponse
            if (isset($data['message']) && $data['message'] === 'Dépôt réussi') {
                return [
                    'success' => true,
                    'transaction' => [
                        'client_id' => $data['transaction']['client_id'],
                        'manager_id' => $data['transaction']['manager_id'],
                        'type' => $data['transaction']['type'],
                        'amount' => $data['transaction']['amount'],
                        'balance_after' => $data['transaction']['balance_after'],
                        'updated_at' => $data['transaction']['updated_at'],
                        'created_at' => $data['transaction']['created_at'],
                        'id' => $data['transaction']['id']
                    ]
                ];
            }

            // Si le dépôt n'a pas réussi mais qu'une réponse a été reçue
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Échec du dépôt, message inconnu.'
            ];
        }

        // Gestion des erreurs HTTP autres que succès (200)
        $errorMessage = 'Erreur lors du dépôt : ';

        // Si le serveur a retourné une réponse JSON contenant un message
        if ($response->json() && isset($response->json()['message'])) {
            $errorMessage .= $response->json()['message'];
        } else {
            // Sinon, utiliser le code d'état HTTP pour l'erreur inconnue
            $errorMessage .= 'Erreur inconnue (Code ' . $response->status() . ')';
        }

        // Retour de l'erreur
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }

    public function withdrawFromWave($totalAmount,$fromPhone)
    {
        // Validation du montant
        if ($totalAmount <= 0) {
            return [
                'success' => false,
                'message' => 'Le montant doit être supérieur à zéro.',
            ];
        }

        // Récupération des numéros de téléphone du manager et du client
        $managerPhone = env('WAVE_MANAGER_MSISDN');
        $clientPhone = $fromPhone;

        // Validation des numéros de téléphone
        if (empty($managerPhone) || empty($clientPhone)) {
            return [
                'success' => false,
                'message' => 'Les numéros de téléphone du manager ou du client ne peuvent pas être vides.',
            ];
        }

        try {
            // Envoi d'une requête POST à l'API Wave pour effectuer un retrait
            $response = Http::post(env('WAVE_API_BASE_URL') . '/withdraw/initiate', [
                'manager_phone' => $managerPhone, // Numéro de téléphone du manager
                'amount' => $totalAmount,               // Montant à retirer
                'client_phone' => $clientPhone,     // Numéro de téléphone du client
            ]);

            // Vérifiez si la requête a réussi
            if ($response->successful()) {
                // Si la réponse est réussie, récupérez les données nécessaires
                $responseData = $response->json();

                // Vérifiez si transaction_id est présent dans la réponse
                if (isset($responseData['transaction_id'])) {
                    return [
                        'success' => true,
                        'transaction_id' => $responseData['transaction_id'], // Utilisez transaction_id ici
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Erreur: transaction_id non fourni dans la réponse.',
                        'response' => $responseData, // Inclure la réponse complète pour le débogage
                    ];
                }
            } else {
                // Si la requête échoue, renvoyez un message d'erreur
                return [
                    'success' => false,
                    'message' => $response->json()['message'] ?? 'Erreur inconnue lors du retrait.',
                    'response' => $response->json(), // Inclure la réponse complète pour le débogage
                ];
            }
        } catch (\Exception $e) {
            // Gérer les exceptions et retourner un message d'erreur
            return [
                'success' => false,
                'message' => 'Erreur lors de la tentative de retrait: ' . $e->getMessage(),
            ];
        }
    }




    public function confirmTransactionWave($transactionId,$pinCode)
    {
        // Envoi de la requête pour confirmer la transaction
        $response = Http::withHeaders([

        ])->post(env('WAVE_API_BASE_URL') . '/withdraw/confirm', [
            'transaction_id' => $transactionId,

            'client_pin' => $pinCode,
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Transaction confirmée avec succès'
            ];
        } else {
            Log::error('Erreur lors de la confirmation de la transaction', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la confirmation de la transaction: ' . $response->body()
            ];
        }
    }

    public function getPublicKey()
{
    // Effectuer la requête à l'API
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
    ])->get('https://api.sandbox.orange-sonatel.com/api/account/v1/publicKeys');

    // Loguer la réponse brute pour aide au débogage
    Log::debug('Réponse brute de l\'API Orange:', ['response' => $response->body()]);

    // Vérifier si la requête a réussi
    if ($response->successful()) {
        // Vérifier si la clé 'key' existe dans la réponse
        $key = $response->json()['key'] ?? null;  // Utilisation de l'opérateur ?? pour éviter une erreur si la clé est absente

        if ($key) {
            return $key;  // Retourner la clé si elle existe
        } else {
            // Loguer si la clé 'key' n'est pas présente dans la réponse
            Log::error('La clé publique est absente dans la réponse API');
            return null;  // Retourner null si la clé n'est pas trouvée
        }
    } else {
        // Loguer les erreurs lorsque la requête échoue
        Log::error('Erreur lors de la récupération de la clé publique', [
            'status' => $response->status(), // Code de statut HTTP
            'response_body' => $response->body(), // Corps de la réponse brute
            'response_json' => $response->json()  // Réponse JSON pour plus de détails
        ]);

        return null;  // Retourner null si la requête échoue
    }
}

public function encryptPin($pinCode, $key)
{
    // Ajouter les délimiteurs PEM autour de la clé publique
    $pemKey = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";

    // Initialiser la variable pour stocker le PIN chiffré
    $encryptedPin = null;

    // Charger la clé publique à partir du format PEM
    $keyResource = openssl_pkey_get_public($pemKey);

    // Vérifier si la clé publique est valide
    if (!$keyResource) {
        // Log l'erreur pour plus de détails sur l'échec du chargement de la clé publique
        Log::error('Clé publique invalide.', ['key' => $pemKey]);
        return response()->json(['error' => 'Clé publique invalide'], 500);
    }

    // Chiffrer le code PIN avec la clé publique
    $success = openssl_public_encrypt($pinCode, $encryptedPin, $keyResource);

    // Libérer la ressource de la clé publique
    openssl_free_key($keyResource);

    // Vérifier si le chiffrement a réussi
    if (!$success) {
        // Log l'erreur en cas de problème avec le chiffrement
        Log::error('Erreur lors du chiffrement du PIN.');
        return response()->json(['error' => 'Erreur lors du chiffrement du code PIN'], 500);
    }

    // Retourner le PIN chiffré en base64 pour une transmission sécurisée
    return base64_encode($encryptedPin);
}




public function handlePinEncryption(Request $request)
{
    // Récupérer le code PIN fourni par l'utilisateur
    $pinCode = $request->input('pinCode');

    // Ajouter un log pour afficher la valeur du code PIN reçu
    Log::info('Code PIN reçu: ' . $pinCode);

    // Vérification du code PIN
    if ($pinCode !== '2021') {
        // Ajouter un log en cas d'échec
        Log::warning('Code PIN incorrect: ' . $pinCode);

        return response()->json(['error' => 'Code PIN incorrect'], 400);
    }

    // Ajouter un log pour indiquer que le code PIN est correct
    Log::info('Code PIN correct');

    // Obtenir la clé publique de l'API
    $key = $this->getPublicKey();
    if (!$key) {
        // Ajouter un log si la clé publique ne peut pas être récupérée
        Log::error('Erreur lors de la récupération de la clé publique');
        return response()->json(['error' => 'Erreur lors de la récupération de la clé publique'], 500);
    }

    // Chiffrer le code PIN
    $encryptedPin = $this->encryptPin($pinCode, $key);

    // Ajouter un log pour afficher le code PIN chiffré
    Log::info('Code PIN chiffré: ' . $encryptedPin);

    // Retourner la réponse avec le code PIN chiffré
    return response()->json([
        'message' => 'Code PIN chiffré avec succès',
        'encryptedPin' => $encryptedPin,
    ], 200);
}
}
