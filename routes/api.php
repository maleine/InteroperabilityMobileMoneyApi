<?php
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OTPController;
use App\Http\Controllers\ExchangeController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/encrypt-pin', [ExchangeController::class, 'handlePinEncryption']);
Route::post('/transaction/{transaction_id}/confirm', [ExchangeController::class, 'confirmTransactionEchange']);
Route::post('send-otp', [OTPController::class, 'sendOtp']);
Route::post('verify-otp1', [AuthController::class, 'verifyOtp1']);
Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('verifyotp', [AuthController::class, 'verifyOtpPassword']);
Route::put('updateUser', [AuthController::class, 'updateUser']);
Route::get('getUserInfo', [AuthController::class, 'getUserInfo']);
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('archiveUser', [AuthController::class, 'archiveUser']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
//Route pour demander la réinitialisation du mot de passe (envoi d'OTP)
Route::post('/requestPasswordReset', [AuthController::class, 'requestPasswordReset']);
//Route::post('/exchange/initiate', [ExchangeController::class, 'initiateExchange']);

    // Route pour confirmer un échange (valider le retrait et l'envoi)
   // Route::post('/exchange/confirm/{transactionId}', [ExchangeController::class, 'confirmExchange']);
// Route pour vérifier l'OTP et réinitialiser le mot de passe
Route::post('/resetPassword', [AuthController::class, 'resetPassword']);
Route::post('/exchangeFunds', [ExchangeController::class, 'exchangeFunds']);

