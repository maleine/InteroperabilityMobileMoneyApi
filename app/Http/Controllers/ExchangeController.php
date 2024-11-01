<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;

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

        $transaction = null;

        try {
            // Vérifiez le solde avant la transaction
            $balanceBefore = $this->checkCustomerBalance();

            if ($balanceBefore < $amount) {
                return response()->json(['error' => 'Solde insuffisant sur le compte Orange'], 400);
            }

            // Initialisez la transaction en base de données avec statut 'pending'
            $transaction = Transaction::create([
                'from_operator' => $fromOperator,
                'to_operator' => $toOperator,
                'customer_msisdn' => $fromPhone,
                'receiver_msisdn' => $toPhone,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'status' => 'pending',
            ]);

            // Effectuer le retrait sans confirmation
            $withdrawalResponse = $this->withdrawFromCustomer($amount);

            if (!$withdrawalResponse['success']) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'Échec du retrait depuis Orange: ' . $withdrawalResponse['message'],
                ]);
                return response()->json(['error' => 'Échec du retrait depuis Orange: ' . $withdrawalResponse['message']], 400);
            }

            if (!isset($withdrawalResponse['transactionId'])) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'transactionId non fourni dans la réponse de retrait',
                ]);
                return response()->json(['error' => 'transactionId non fourni dans la réponse de retrait'], 400);
            }

            // Effectuer le dépôt
            if ($toOperator === 'wave') {
                $depositResponse = $this->depositToWave($toPhone, $amount);
            } else {
                $depositResponse = $this->depositToOrange($toPhone, $amount);
            }

            // Vérifiez le résultat du dépôt
            if (!$depositResponse['success']) {
                // Si le dépôt échoue, annuler la transaction sans confirmer le retrait
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'Échec du dépôt vers ' . ucfirst($toOperator) . ': ' . $depositResponse['message'],
                ]);
                return response()->json(['error' => 'Échec du dépôt vers ' . ucfirst($toOperator) . ': ' . $depositResponse['message']], 400);
            }

            // Confirmer le retrait une fois le dépôt réussi
            $confirmationResponse = $this->confirmTransaction($withdrawalResponse['transactionId']);
            if (!$confirmationResponse['success']) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'Échec de la confirmation du retrait: ' . $confirmationResponse['message'],
                ]);
                return response()->json(['error' => 'Échec de la confirmation du retrait: ' . $confirmationResponse['message']], 400);
            }

            // Mettre à jour la transaction comme réussie avec les soldes
            $balanceAfter = $balanceBefore - $amount;
            $transaction->update([
                'balance_after' => $balanceAfter,
                'status' => 'completed',
                'transaction_id' => $withdrawalResponse['transactionId'],
            ]);

            return response()->json([
                'message' => 'Échange effectué avec succès',
                'data' => [
                    'from_operator' => $fromOperator,
                    'to_operator' => $toOperator,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'transaction_id' => $transaction->id,
                ]
            ], 200);

        } catch (\Exception $e) {
            if ($transaction) {
                $transaction->update([
                    'status' => 'failed',
                    'error_message' => 'Erreur lors de l\'échange: ' . $e->getMessage(),
                ]);
            }
            return response()->json(['error' => 'Erreur lors de l\'échange: ' . $e->getMessage()], 500);
        }
    }



    // Méthode pour vérifier le solde du compte Orange
    public function checkCustomerBalance()
    {
        // Envoi de la requête à l'API Orange
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . '/account/customer/balance', [
            'id' => env('ORANGE_CUSTOMER_MSISDN'),
            'encryptedPinCode' => env('ORANGE_CUSTOMER_ENCRYPTED_PIN'),
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
    public function withdrawFromCustomer($amount)
    {
        $data = [
            'amount' => [
                'unit' => 'XOF',
                'value' => $amount, // Assurez-vous que $amount est le montant correct
            ],
            'customer' => [
                'id' => env('ORANGE_CUSTOMER_MSISDN'),
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
    public function confirmTransaction($transactionId)
    {
        // Envoi de la requête pour confirmer la transaction
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('ORANGE_ACCESS_TOKEN'),
        ])->post(env('ORANGE_API_BASE_URL') . "/transactions/{$transactionId}/confirm", [
            'idType' => 'MSISDN',
            'id' => env('ORANGE_CUSTOMER_MSISDN'),
            'encryptedPinCode' => env('ORANGE_CUSTOMER_ENCRYPTED_PIN'),
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



    // Méthode pour effectuer le dépôt vers un compte Orange
    public function depositToOrange($toPhone, $amount)
    {
        // Logique pour déposer sur Orange
        // ...
        return [
            'success' => true, // Changez cela selon le résultat de l'API
        ];
    }



    private function depositToWave($amount)
    {
        // Envoi d'une requête POST à l'API Wave pour effectuer un dépôt
        $response = Http::post(env('WAVE_API_BASE_URL') . '/deposit', [
            'manager_phone' => env('WAVE_MANAGER_MSISDN'), // Numéro de téléphone du manager
            'amount' => $amount, // Montant à déposer
            'manager_pin' => env('WAVE_MANAGER_PIN'), // PIN du manager
            'client_phone' => env('WAVE_CLIENT_MSISDN') // Numéro de téléphone du client
        ]);

        // Analyse de la réponse JSON
        $data = $response->json();

        // Vérification si la requête a été effectuée avec succès
        if ($response->successful() && isset($data['message']) && $data['message'] === 'Dépôt réussi') {
            // Si le dépôt a réussi, retourner les détails de la transaction
            return [
                'success' => true,
                'transaction' => [
                    'client_id' => $data['transaction']['client_id'], // ID du client
                    'manager_id' => $data['transaction']['manager_id'], // ID du manager
                    'type' => $data['transaction']['type'], // Type de transaction (dépôt)
                    'amount' => $data['transaction']['amount'], // Montant déposé
                    'balance_after' => $data['transaction']['balance_after'], // Solde après le dépôt
                    'updated_at' => $data['transaction']['updated_at'], // Date de mise à jour
                    'created_at' => $data['transaction']['created_at'], // Date de création
                    'id' => $data['transaction']['id'] // ID de la transaction
                ]
            ];
        }

        // Gestion des erreurs spécifiques si la requête échoue
        $errorMessage = 'Erreur lors du dépôt : ';

        // Si la réponse contient un message d'erreur spécifique
        if (isset($data['message'])) {
            $errorMessage .= $data['message'];
        } else {
            // Sinon, inclure le code d'état HTTP
            $errorMessage .= 'Erreur inconnue (Code ' . $response->status() . ')';
        }

        // Retourner l'erreur spécifique
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }



}
