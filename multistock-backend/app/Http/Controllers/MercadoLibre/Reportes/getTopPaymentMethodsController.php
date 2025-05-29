<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class getTopPaymentMethodsController
{
    /**
     * Get top payment methods from MercadoLibre API using client_id.
     */
public function getTopPaymentMethods($clientId)
{

    // Get credentials by client_id
    $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

    // Check if credentials exist
    if (!$credentials) {
        Log::error("No credentials found for client_id: $clientId");
        return response()->json([
            'status' => 'error',
            'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
        ], 404);
    }

    // Refresh token if expired
if ($credentials->isTokenExpired()) {
    $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $credentials->client_id,
        'client_secret' => $credentials->client_secret,
        'refresh_token' => $credentials->refresh_token,
    ]);
    if ($refreshResponse->failed()) {
        Log::error("Token refresh failed for client_id: $clientId");
        return response()->json(['error' => 'No se pudo refrescar el token'], 401);
    }
    $data = $refreshResponse->json();
    $credentials->update([
        'access_token' => $data['access_token'],
        'refresh_token' => $data['refresh_token'],
        'expires_at' => now()->addSeconds($data['expires_in']),
    ]);
}

    // Get user id from token
    $userResponse = Http::withToken($credentials->access_token)
        ->get('https://api.mercadolibre.com/users/me');
    if ($userResponse->failed()) {
        Log::error("Failed to get user ID for client_id: $clientId. URL: " . request()->fullUrl());
        return response()->json([
            'status' => 'error',
            'message' => 'No se pudo obtener el ID del usuario.',
            'error' => $userResponse->json(),
        ], 500);
    }
    $userId = $userResponse->json()['id'];


    $year = request()->query('year', date('Y')); 
    $paymentStatus = request()->query('payment_status', 'all');

    
    if ($year === 'alloftimes') {
        $dateFrom = '2000-01-01T00:00:00.000-00:00';
        $dateTo = date('Y-m-d\T23:59:59.999-00:00');
    } else {
        $dateFrom = "{$year}-01-01T00:00:00.000-00:00";
        $dateTo = "{$year}-12-31T23:59:59.999-00:00";
    }


    $url = "https://api.mercadolibre.com/orders/search?seller={$userId}&order.date_created.from={$dateFrom}&order.date_created.to={$dateTo}";

    if ($paymentStatus !== 'all') {
        $url .= "&payments.status={$paymentStatus}";
    }

    $response = Http::withToken($credentials->access_token)->get($url);

    if ($response->failed()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error al conectar con la API de MercadoLibre.',
            'error' => $response->json(),
        ], $response->status());
    }


    $orders = $response->json()['results'];
    $paymentMethods = [];

    foreach ($orders as $order) {
        foreach ($order['payments'] as $payment) {
            $method = $payment['payment_type'];
            if (!isset($paymentMethods[$method])) {
                $paymentMethods[$method] = 0;
            }
            $paymentMethods[$method]++;
        }
    }


    unset($paymentMethods['paypal']);

    arsort($paymentMethods);

    return response()->json([
        'status' => 'success',
        'message' => 'Métodos de pago más utilizados obtenidos con éxito.',
        'request_date' => date('Y-m-d H:i:s'),
        'data' => $paymentMethods,
    ]);
}
}