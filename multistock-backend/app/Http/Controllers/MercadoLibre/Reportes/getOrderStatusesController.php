<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Carbon\Carbon;

class getOrderStatusesController
{
    /**
     * Get order statuses (paid, pending, canceled) from MercadoLibre API using client_id.
    */
    public function getOrderStatuses(Request $request, $clientId)
    {
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }

        if ($credentials->isTokenExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El token ha expirado. Por favor, renueve su token.',
            ], 401);
        }

        $response = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario. Por favor, valide su token.',
                'error' => $response->json(),
            ], 500);
        }

        $userId = $response->json()['id'];

        $month = $request->query('month', Carbon::now()->month);
        $year = $request->query('year', Carbon::now()->year);

        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search?seller={$userId}&order.date_created.from={$year}-{$month}-01T00:00:00.000-00:00&order.date_created.to={$year}-{$month}-".Carbon::now()->daysInMonth."T23:59:59.999-00:00");

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }

        $orders = $response->json()['results'];
        $statuses = [
            'paid' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'used' => 0,
        ];
        $products = [];

        foreach ($orders as $order) {
            if (isset($statuses[$order['status']])) {
                $statuses[$order['status']]++;
            }
            foreach ($order['order_items'] as $item) {
                // Obtener SKU del producto
                $productId = $item['item']['id'];
                $sku = 'No tiene SKU'; 

                // Obtener detalles del producto para obtener el SKU
                $productDetailsResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/items/{$productId}");

                if ($productDetailsResponse->successful()) {
                    $productData = $productDetailsResponse->json();

                    // Buscar el SKU en los atributos del producto
                    foreach ($productData['attributes'] as $attribute) {
                        if (strtolower($attribute['name']) === 'sku') {
                            $sku = $attribute['value_name'];
                            break;
                        }
                    }
                }

                // Asignar el SKU al producto y agregarlo a la lista de productos
                $item['item']['sku'] = $sku;
                $item['item']['status'] = $order['status'];

                // Remove condition
                unset($item['item']['condition']);
                $products[] = $item['item'];
            }
        }

        // Return order statuses and related products data
        return response()->json([
            'status' => 'success',
            'message' => 'Estados de órdenes y productos relacionados obtenidos con éxito.',
            'data' => [
                'statuses' => $statuses,
                'products' => $products,
            ],
        ]);
    }

    public function getOrder(Request $request, $clientId)
    {
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();

        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }

        if ($credentials->isTokenExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El token ha expirado. Por favor, renueve su token.',
            ], 401);
        }

        $response = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario. Por favor, valide su token.',
                'error' => $response->json(),
            ], 500);
        }

        $userId = $response->json()['id'];

        $month = $request->query('month', Carbon::now()->month);
        $year = $request->query('year', Carbon::now()->year);

        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search?seller={$userId}&order.date_created.from={$year}-{$month}-01T00:00:00.000-00:00&order.date_created.to={$year}-{$month}-".Carbon::now()->daysInMonth."T23:59:59.999-00:00");

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }

        $orders = $response->json()['results'];
        $statuses = [
            'paid' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'used' => 0,
        ];
        $products = [];

        foreach ($orders as $order) {
            if (isset($statuses[$order['status']])) {
                $statuses[$order['status']]++;
            }
            foreach ($order['order_items'] as $item) {
                // Obtener SKU del producto
                $productId = $item['item']['id'];
                $sku = 'No tiene SKU'; 

                // Obtener detalles del producto para obtener el SKU
                $productDetailsResponse = Http::withToken($credentials->access_token)
                    ->get("https://api.mercadolibre.com/items/{$productId}");

                if ($productDetailsResponse->successful()) {
                    $productData = $productDetailsResponse->json();

                    // Buscar el SKU en los atributos del producto
                    foreach ($productData['attributes'] as $attribute) {
                        if (strtolower($attribute['name']) === 'sku') {
                            $sku = $attribute['value_name'];
                            break;
                        }
                    }
                }

                // Asignar el SKU al producto
                $item['item']['sku'] = $sku;
                $item['item']['status'] = $order['status'];

                // Remove condition
                unset($item['item']['condition']);
                $products[] = $item['item'];
            }
        }

        // Return order statuses and related products data
        return response()->json([
            'status' => 'success',
            'message' => 'Estados de órdenes y productos relacionados obtenidos con éxito.',
            'data' => [
                'statuses' => $statuses,
                'products' => $products,
            ],
        ]);
    }
}
