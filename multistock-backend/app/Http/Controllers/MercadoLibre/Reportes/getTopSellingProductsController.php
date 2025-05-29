<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class getTopSellingProductsController
{
    const API_PRODUCT_LIMIT = 20;    // Límite de la API por petición
    const MAX_PARALLEL_REQS = 5;     // Máximo de peticiones paralelas
    const MAX_TOTAL_PRODUCTS = 1000; // Límite total de productos a obtener
    const REQUEST_TIMEOUT = 30;      // Timeout en segundos

    public function getTopSellingProducts($clientId)
    {
        // Get credentials by client_id
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

        // Check if credentials exist
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

            // Retry the request
            $userResponse = Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');

            if ($userResponse->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se pudo obtener el ID del usuario',
                    'error' => $userResponse->json(),
                ], 500);
            }
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
        $productSales = [];
        $totalSales = 0;

        // 1. Recolectar todos los productId únicos
        $productIds = [];
        foreach ($orders as $order) {
            foreach ($order['order_items'] as $item) {
                $productIds[$item['item']['id']] = true;
            }
        }
        $productIds = array_keys($productIds);

        // 2. Obtener detalles de productos en lotes de 20 en paralelo
        $productDetails = [];
        $chunks = array_chunk($productIds, 20);
        foreach ($chunks as $chunk) {
            $responses = Http::pool(fn ($pool) =>
                collect($chunk)->map(fn ($productId) =>
                    $pool->withToken($credentials->access_token)
                        ->get("https://api.mercadolibre.com/items/{$productId}")
                )->toArray()
            );
            foreach ($responses as $i => $response) {
                if ($response->successful()) {
                    $productDetails[$chunk[$i]] = $response->json();
                }
            }
        }

        foreach ($orders as $order) {
            foreach ($order['order_items'] as $item) {
                $productId = $item['item']['id'];
                $variationId = $item['item']['variation_id'] ?? null;
                $size = null;
                $skuSource = 'not_found';
                
                // 1. Primero buscar en seller_custom_field del ítem del pedido
                $sku = $item['item']['seller_custom_field'] ?? null;
                
                // 2. Si no está, buscar en seller_sku del ítem del pedido
                if (empty($sku)) {
                    $sku = $item['item']['seller_sku'] ?? null;
                    if ($sku) {
                        $skuSource = 'item_seller_sku';
                    }
                } else {
                    $skuSource = 'item_seller_custom_field';
                }

                $productData = $productDetails[$productId] ?? null;

                // Si no se encontraron detalles del producto, continuar con el siguiente ítem
                if (!$productData) {
                    continue;
                }

                // 3. Si no se encontró en el ítem, buscar en seller_sku del producto
                if (empty($sku)) {
                    if (isset($productData['seller_sku'])) {
                        $sku = $productData['seller_sku'];
                        $skuSource = 'product_seller_sku';
                    }
                }

                // 4. Si aún no se encontró, buscar en los atributos del producto
                if (empty($sku) && isset($productData['attributes'])) {
                    foreach ($productData['attributes'] as $attribute) {
                        if (in_array(strtolower($attribute['id']), ['seller_sku', 'sku', 'codigo', 'reference', 'product_code']) || 
                            in_array(strtolower($attribute['name']), ['sku', 'código', 'referencia', 'codigo', 'código de producto'])) {
                            $sku = $attribute['value_name'];
                            $skuSource = 'product_attributes';
                            break;
                        }
                    }
                }

                // 5. Si sigue sin encontrarse, intentar con el modelo como último recurso
                if (empty($sku) && isset($productData['attributes'])) {
                    foreach ($productData['attributes'] as $attribute) {
                        if (strtolower($attribute['id']) === 'model' || 
                            strtolower($attribute['name']) === 'modelo') {
                            $sku = $attribute['value_name'];
                            $skuSource = 'model_fallback';
                            break;
                        }
                    }
                }

                // 6. Establecer mensaje predeterminado si no se encontró SKU
                if (empty($sku)) {
                    $sku = 'No se encuentra disponible en mercado libre';
                }

                // Manejo de variaciones (tamaño)
                if ($variationId) {
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

                if (!isset($productSales[$productId])) {
                    $productSales[$productId] = [
                        'id' => $productId,
                        'variation_id' => $variationId,
                        'title' => $item['item']['title'],
                        'sku' => $sku,
                        'sku_source' => $skuSource,
                        'quantity' => 0,
                        'total_amount' => 0,
                        'size' => $size,
                        'variation_attributes' => $productData['attributes'],
                    ];
                }

                $productSales[$productId]['quantity'] += $item['quantity'];
                $productSales[$productId]['total_amount'] += $item['quantity'] * $item['unit_price'];
                $totalSales += $item['quantity'] * $item['unit_price'];
            }
        }

        usort($productSales, function ($a, $b) {
            return $b['quantity'] - $a['quantity'];
        });

        $totalProducts = count($productSales);
        $totalPages = ceil($totalProducts / $perPage);
        $offset = ($page - 1) * $perPage;
        $productSales = array_slice($productSales, $offset, $perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Productos más vendidos obtenidos con éxito.',
            'total_sales' => $totalSales,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'data' => $productSales,
        ]);

        // Guardar en cache por 10 minutos (600 segundos)
        cache()->put($cacheKey, $responseData, 600);

        return response()->json($responseData);
    }
}