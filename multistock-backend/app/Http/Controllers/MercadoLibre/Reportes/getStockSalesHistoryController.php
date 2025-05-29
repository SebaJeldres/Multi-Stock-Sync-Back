<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Log;

class getStockSalesHistoryController
{
    public function getStockSalesHistory($clientId, $productId)
    {
        try {
            set_time_limit(180); // Extender tiempo máximo de ejecución

            // Get credentials by client_id
            $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

            // Check if credentials exist
            if (!$credentials) {
                Log::error("No credentials found for client_id: $clientId");
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontraron credenciales válidas.',
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

            // Obtener userId del vendedor
            $userResponse = Http::withToken($credentials->access_token)
                ->get('https://api.mercadolibre.com/users/me');
            if ($userResponse->failed()) {
                Log::error("Failed to get user ID for client_id: $clientId. URL: " . request()->fullUrl());
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se pudo obtener el ID del usuario.',
                    'error' => $userResponse->json(),
                ], $userResponse->status());
            }

            $userId = $userResponse->json()['id'] ?? null;

            if (!$userId) {
                throw new Exception('El ID del usuario no está definido.');
            }

            // Variables de paginación
            $allSales = [];
            $offset = 0;
            $limit = 50;
            $totalProducts = 0; // Contador de productos

            // Obtener todas las órdenes pagadas filtradas por producto
            do {
                $response = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/orders/search", [
                        'seller' => $userId,
                        'order.status' => 'paid',
                        'limit' => $limit,
                        'offset' => $offset,
                    ]);

                if ($response->failed()) {
                    throw new Exception('Error al conectar con la API: ' . json_encode($response->json()));
                }

                $orders = $response->json()['results'] ?? [];

                // Filtrar las órdenes para incluir solo las que contienen el producto específico
                foreach ($orders as $order) {
                    foreach ($order['order_items'] as $item) {
                        $totalProducts++; // Incrementar el contador de productos por cada ítem en el pedido
                        if ($item['item']['id'] == $productId) {
                            $allSales[] = $order;
                            break; // Salta al siguiente pedido si ya encontró el producto específico
                        }
                    }
                }

                $offset += $limit;

            } while (count($orders) == $limit);

            // Procesar las órdenes para extraer datos relevantes
            $totalSales = 0;
            $salesData = [];
            $salesDetails = [];

            foreach ($allSales as $order) {
                foreach ($order['order_items'] as $item) {
                    if ($item['item']['id'] == $productId) {
                        $orderId = $order['id'] ?? null;
                        $quantity = $item['quantity'] ?? 0;
                        $unitPrice = $item['unit_price'] ?? 0;
                        $saleDate = $order['date_created'] ?? null;
                        $productTitle = $item['item']['title'] ?? 'Sin nombre';

                        $salesDetails[] = [
                            'quantity' => $quantity,
                            'sale_date' => $saleDate,
                        ];

                        if (!isset($salesData[$productId])) {
                            $salesData[$productId] = [
                                'product_id' => $productId,
                                'product_title' => $productTitle,
                                'total_sales' => 0,
                            ];
                        }

                        $salesData[$productId]['total_sales'] += $quantity;
                        $totalSales += $quantity;
                    }
                }
            }

            // Obtener la fecha de la última venta
            $lastSaleDate = !empty($salesDetails) ? end($salesDetails)['sale_date'] : 'No hay ventas registradas';

            return response()->json([
                'status' => 'success',
                'message' => 'Historial de ventas del producto',
                'total_sales' => $totalSales,
                'sales_count' => count($salesDetails),
                'last_sale_date' => $lastSaleDate, // Se agrega la fecha de la última venta
                'total_products' => $totalProducts, // Añadir el contador de productos
                'data' => array_values($salesData),
                'sales' => $salesDetails, 
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Se produjo un error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
