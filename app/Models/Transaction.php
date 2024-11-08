<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_operator',          // Opérateur source (e.g., Orange)
        'to_operator',            // Opérateur destination (e.g., Wave)
        'customer_msisdn',        // Numéro du client qui effectue la transaction
        'receiver_msisdn',        // Numéro du compte receveur
        'amount',                 // Montant de la transaction
        'balance_before',         // Solde avant transaction
        'balance_after',          // Solde après transaction
        'status',                 // Statut de la transaction (pending, completed, failed)
        'transaction_id',         // ID de transaction de l'API opérateur
        'error_message',
        'fee'        // Message d'erreur en cas d'échec
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',

    ];
}
