<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class getCancelledOrdersController
{
    public function getCancelledOrders($clientId)
    {
        

        // Obtain client credentials
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }

        // Refresh token if expired
        if ($credentials->isTokenExpired()) {
            $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => env('MELI_CLIENT_ID'),
                'client_secret' => env('MELI_CLIENT_SECRET'),
                'refresh_token' => $credentials->refresh_token,
            ]);

            if ($refreshResponse->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El token ha expirado y no se pudo refrescar',
                    'error' => $refreshResponse->json(),
                ], 401);
            }

            $newTokenData = $refreshResponse->json();
            $credentials->access_token = $newTokenData['access_token'];
            $credentials->refresh_token = $newTokenData['refresh_token'] ?? $credentials->refresh_token;
            $credentials->expires_in = $newTokenData['expires_in'];
            $credentials->updated_at = now();
            $credentials->save();
        }

        $userResponse = Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');

        // If it fails by token, try refreshing again
        if ($userResponse->status() === 401) {
            $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => env('MELI_CLIENT_ID'),
                'client_secret' => env('MELI_CLIENT_SECRET'),
                'refresh_token' => $credentials->refresh_token,
            ]);

            if ($refreshResponse->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El token ha expirado y no se pudo refrescar. Por favor, renueve su token.',
                    'error' => $refreshResponse->json(),
                ], 401);
            }

            $newTokenData = $refreshResponse->json();
            $credentials->access_token = $newTokenData['access_token'];
            $credentials->refresh_token = $newTokenData['refresh_token'] ?? $credentials->refresh_token;
            $credentials->expires_in = $newTokenData['expires_in'];
            $credentials->updated_at = now();
            $credentials->save();

            // Retry the request
            $userResponse = Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');
        }

        if ($userResponse->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario.',
                'error' => $userResponse->json(),
            ], 500);
        }

        $userId = $userResponse->json()['id'];


        $ordersData = [];
        $offset = 0;
        $limit = 50;

        do {
            $params = [
                'seller' => $userId,
                'order.status' => 'cancelled',
                'limit' => $limit,
                'offset' => $offset
            ];

            $response = Http::withToken($credentials->access_token)->get("https://api.mercadolibre.com/orders/search", $params);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error al consultar órdenes canceladas.',
                    'error' => $response->json(),
                ], $response->status());
            }

            $data = $response->json();
            $offset += $limit;

            foreach ($data['results'] as $order) {
                foreach ($order['order_items'] as $item) {
                    $ordersData[] = [
                        'id' => $order['id'],
                        'created_date' => $order['date_created'],
                        'total_amount' => $order['total_amount'],
                        'status' => $order['status'],
                        'product' => [
                            'title' => $item['item']['title'] ?? null,
                            'quantity' => $item['quantity'] ?? null,
                            'price' => $item['unit_price'] ?? null
                        ]
                    ];
                }
            }

        } while ($offset < ($data['paging']['total'] ?? 0));

        return response()->json([
            'status' => 'success',
            'message' => 'Órdenes canceladas obtenidas con éxito.',
            'orders' => $ordersData,
        ]);
    }
}
