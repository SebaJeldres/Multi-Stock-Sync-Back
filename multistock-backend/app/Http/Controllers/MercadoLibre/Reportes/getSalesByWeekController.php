<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class getSalesByWeekController
{

    /**
     * Get total sales for a specific week based on the year, month, and week number.
    */

    public function getSalesByWeek(Request $request, $clientId)
    {
        // Get the year, month, week start date, and week end date from the request
        $year = $request->query('year', date('Y')); // Default to current year
        $month = $request->query('month', date('m')); // Default to current month
        $weekStartDate = $request->query('week_start_date'); // Start date of the week
        $weekEndDate = $request->query('week_end_date'); // End date of the week
    
        // Ensure both dates are provided
        if (!$weekStartDate || !$weekEndDate) {
            return response()->json([
                'status' => 'error',
                'message' => 'Las fechas de la semana son requeridas.',
            ], 400);
        }
    
        // Convert to Carbon instances
        $startOfWeek = \Carbon\Carbon::createFromFormat('Y-m-d', $weekStartDate)->startOfDay();
        $endOfWeek = \Carbon\Carbon::createFromFormat('Y-m-d', $weekEndDate)->endOfDay();
    
        // Get credentials and user ID
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();
    
        // Check if credentials exist
        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }
    
        // Check if token is expired
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
                'message' => 'El token ha expirado. Por favor, renueve su token.',
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
    
        $userId = $userResponse->json()['id'];
    
        // Get sales within the specified week date range
        $response = Http::withToken($credentials->access_token)
            ->get("https://api.mercadolibre.com/orders/search", [
                'seller' => $userId,
                'order.status' => 'paid',
                'order.date_created.from' => $startOfWeek->toIso8601String(),
                'order.date_created.to' => $endOfWeek->toIso8601String()
            ]);
    
        // Validate response
        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al conectar con la API de MercadoLibre.',
                'error' => $response->json(),
            ], $response->status());
        }
    
        // Process sales data
        $orders = $response->json()['results'];
        $totalSales = 0;
        $soldProducts = [];
    
        foreach ($orders as $order) {
            $totalSales += $order['total_amount'];
    
            // Extract sold products (titles and quantities)
            foreach ($order['order_items'] as $item) {
                $productId = $item['item']['id'];
                if (!isset($soldProducts[$productId])) {
                    $soldProducts[$productId] = [
                        'title' => $item['item']['title'],
                        'quantity' => 0,
                        'total_amount' => 0,
                    ];
                }
                $soldProducts[$productId]['quantity'] += $item['quantity'];
                $soldProducts[$productId]['total_amount'] += $item['quantity'] * $item['unit_price'];
            }
        }
    
        // Return sales by week data, including sold products
        return response()->json([
            'status' => 'success',
            'message' => 'Ingresos y productos obtenidos con éxito.',
            'data' => [
                'week_start_date' => $startOfWeek->toDateString(),
                'week_end_date' => $endOfWeek->toDateString(),
                'total_sales' => $totalSales,
                'sold_products' => array_values($soldProducts), // List of sold products
            ],
        ]);
    }

    }
}