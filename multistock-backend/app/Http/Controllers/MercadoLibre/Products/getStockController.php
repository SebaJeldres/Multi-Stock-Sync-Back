<?php

namespace App\Http\Controllers\MercadoLibre\Products;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class getStockController
{
    public function getStock($clientId)
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

        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/users/{$userId}/items/search");

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }

        $items = $response->json()['results'];
        $productsStock = [];

        foreach ($items as $itemId) {
            $itemResponse = Http::withToken($credentials->access_token)
                ->get("https://api.mercadolibre.com/items/{$itemId}");

            if ($itemResponse->successful()) {
                $itemData = $itemResponse->json();
                $productsStock[] = [
                    'id' => $itemData['id'],
                    'title' => $itemData['title'],
                    'available_quantity' => $itemData['available_quantity'],
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Stock de productos obtenidos con éxito.',
            'data' => $productsStock,
        ]);
    }
}