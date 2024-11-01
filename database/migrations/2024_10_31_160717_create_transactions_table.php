<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('from_operator'); // Opérateur source (e.g., Orange)
            $table->string('to_operator');   // Opérateur destination (e.g., Wave)
            $table->string('customer_msisdn'); // Numéro du client qui effectue la transaction
            $table->string('receiver_msisdn'); // Numéro du compte receveur
            $table->decimal('amount', 10, 2);  // Montant de la transaction
            $table->decimal('balance_before', 10, 2)->nullable(); // Solde avant transaction
            $table->decimal('balance_after', 10, 2)->nullable();  // Solde après transaction
            $table->string('status')->default('pending'); // Statut de la transaction (pending, completed, failed)
            $table->string('transaction_id')->nullable(); // ID de transaction de l'API opérateur
            $table->text('error_message')->nullable(); // Message d'erreur en cas d'échec
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
