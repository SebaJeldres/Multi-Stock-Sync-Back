<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class getStockRotationController
{
    public function getStockRotation($clientId)
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
        $month = request()->query('month');

        $page = request()->query('page', 1);
        $perPage = request()->query('per_page', 10);

        if ($month) {
            $dateFrom = "{$year}-{$month}-01T00:00:00.000-00:00";
            $dateTo = date("Y-m-t\T23:59:59.999-00:00", strtotime($dateFrom));
        } else {
            $dateFrom = "{$year}-01-01T00:00:00.000-00:00";
            $dateTo = "{$year}-12-31T23:59:59.999-00:00";
        }

        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search?seller={$userId}&order.status=paid&order.date_created.from={$dateFrom}&order.date_created.to={$dateTo}");

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }

        $orders = $response->json()['results'];
        $stockRotation = [];
        $paymentMethods = [];

        foreach ($orders as $order) {
            foreach ($order['order_items'] as $item) {
                $productId = $item['item']['id'];
                $variationId = $item['item']['variation_id'] ?? 'N/A';
                $size = 'N/A';

                // Obtener detalles del producto para encontrar la talla
                $productDetailsResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/items/{$productId}");

                if ($productDetailsResponse->successful()) {
                    $productData = $productDetailsResponse->json();

                    // Si hay una variante específica, obtener la información de la variante
                    if ($variationId !== 'N/A') {
                        $variationResponse = Http::withToken($credentials->access_token)
                            ->get("https://api.mercadolibre.com/items/{$productId}/variations/{$variationId}");

                        if ($variationResponse->successful()) {
                            $variationData = $variationResponse->json();

                            foreach ($variationData['attribute_combinations'] ?? [] as $attribute) {
                                if (in_array(strtolower($attribute['id']), ['size', 'talle'])) {
                                    $size = $attribute['value_name'];
                                    break;
                                }
                            }
                        }
                    }

                    if (!isset($stockRotation[$productId])) {
                        $stockRotation[$productId] = [
                            'id' => $productId,
                            'variation_id' => $variationId,
                            'title' => $item['item']['title'],
                            'quantity' => 0,
                            'size' => $size,
                        ];
                    }

                    $stockRotation[$productId]['quantity'] += $item['quantity'];
                }
            }

            foreach ($order['payments'] as $payment) {
                $method = $payment['payment_type'];
                if (!isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = 0;
                }
                $paymentMethods[$method]++;
            }
        }

        usort($stockRotation, function ($a, $b) {
            return $b['quantity'] - $a['quantity'];
        });

        $totalProducts = count($stockRotation);
        $totalPages = ceil($totalProducts / $perPage);
        $offset = ($page - 1) * $perPage;
        $stockRotation = array_slice($stockRotation, $offset, $perPage);

        arsort($paymentMethods);

        return response()->json([
            'status' => 'success',
            'message' => 'Rotación de stock obtenida con éxito.',
            'current_page' => $page,
            'total_pages' => $totalPages,
            'data' => $stockRotation,
            'payment_methods' => $paymentMethods,
        ]);
    }
}