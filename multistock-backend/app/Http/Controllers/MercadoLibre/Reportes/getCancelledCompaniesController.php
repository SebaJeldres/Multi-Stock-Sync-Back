<?php
namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Http\Controllers\Controller;
use App\Models\MercadoLibreCredential;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;


class getCancelledCompaniesController extends Controller
{
    public function getCancelledProductsAllCompanies(Request $request)
    {
        $clientIds = Company::whereNotNull('client_id')->pluck('client_id')->toArray();

        $year = (int) $request->query('year', date('Y'));
        $dateFrom = "{$year}-01-01T00:00:00.000-00:00";
        $dateTo = "{$year}-12-31T23:59:59.999-00:00";
        
        $totalCancelled = 0;
        $client = new \GuzzleHttp\Client(['timeout' => 20]);
        $cancelledByCompany = [];

        foreach ($clientIds as $clientId) {
            Log::info("Procesando empresa", ['client_id' => $clientId]);

            $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();
            if (!$credentials) {
                Log::warning("No credentials found for client_id: $clientId");
                continue;
            }

            // Refresh token
            if ($credentials->isTokenExpired()) {
                Log::info("Token expirado, refrescando para client_id: $clientId");
                $refreshResponse = \Illuminate\Support\Facades\Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => env('MELI_CLIENT_ID'),
                    'client_secret' => env('MELI_CLIENT_SECRET'),
                    'refresh_token' => $credentials->refresh_token,
                ]);
                if ($refreshResponse->failed()) {
                    Log::error("Token refresh failed for client_id: $clientId", ['response' => $refreshResponse->json()]);
                    continue;
                }
                $newTokenData = $refreshResponse->json();
                $credentials->access_token = $newTokenData['access_token'];
                $credentials->refresh_token = $newTokenData['refresh_token'] ?? $credentials->refresh_token;
                $credentials->expires_in = $newTokenData['expires_in'];
                $credentials->updated_at = now();
                $credentials->save();
                Log::info("Token refrescado correctamente para client_id: $clientId");
            }

            $userResponse = \Illuminate\Support\Facades\Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');
            if ($userResponse->failed()) {
                Log::error("Failed to get user ID for client_id: $clientId", ['response' => $userResponse->json()]);
                continue;
            }
            $userId = $userResponse->json()['id'];
            Log::info("Obtenido user_id para client_id: $clientId", ['user_id' => $userId]);

            $limit = 50;
            $page = 0;
            $hasMore = true;
            $clientOrders = [];
            while ($hasMore && $page < 20) {
                $params = [
                    'seller' => $userId,
                    'order.status' => 'cancelled',
                    'order.date_created.from' => $dateFrom,
                    'order.date_created.to' => $dateTo,
                    'limit' => $limit,
                    'offset' => $page * $limit
                ];
                $response = $client->get('https://api.mercadolibre.com/orders/search', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $credentials->access_token,
                    ],
                    'query' => $params
                ]);
                if ($response->getStatusCode() === 200) {
                    $data = json_decode($response->getBody()->getContents(), true);
                    $results = $data['results'] ?? [];
                    if (count($results) === 0) {
                        $hasMore = false;
                    } else {
                        $clientOrders = array_merge($clientOrders, $results);
                        if (count($results) < $limit) {
                            $hasMore = false;
                        }
                    }
                } else {
                    $hasMore = false;
                }
                $page++;
            }

            
            foreach ($clientOrders as $order) {
                if (!isset($order['order_items']) || !is_array($order['order_items'])) continue;
                $orderMonth = \Carbon\Carbon::parse($order['date_created'])->format('Y-m');
                if (!isset($cancelledByCompany[$clientId][$orderMonth])) {
                    $cancelledByCompany[$clientId][$orderMonth] = [
                        'total_cancelled' => 0,
                        'orders' => []
                    ];
                }
                if (isset($order['total_amount'])) {
                    $cancelledByCompany[$clientId][$orderMonth]['total_cancelled'] += $order['total_amount'];
                    $totalCancelled += $order['total_amount'];
                }
                $orderData = [
                    'id' => $order['id'],
                    'created_date' => $order['date_created'] ?? null,
                    'total_amount' => $order['total_amount'] ?? null,
                    'status' => $order['status'] ?? null,
                    'products' => []
                ];
                foreach ($order['order_items'] as $item) {
                    $orderData['products'][] = [
                        'title' => $item['item']['title'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'price' => $item['unit_price'] ?? null
                    ];
                }
                $cancelledByCompany[$clientId][$orderMonth]['orders'][] = $orderData;
            }
        }

        
        foreach ($cancelledByCompany as $clientId => &$months) {
            $months = array_filter($months, function ($monthData) {
                return !empty($monthData['orders']);
            });
            if (empty($months)) {
                unset($cancelledByCompany[$clientId]);
            } else {
                $cancelledByCompany[$clientId] = $months;
            }
        }
        unset($months);

        
        $cancelledByCompany = array_filter($cancelledByCompany);

        return response()->json([
            'status' => 'success',
            'message' => 'Órdenes canceladas de todas las compañías obtenidas con éxito.',
            'cancelled_by_company' => $cancelledByCompany,
            'total_cancelled' => $totalCancelled,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
        ]);
    }
}