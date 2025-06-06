<?php
namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Http\Controllers\Controller;
use App\Models\MercadoLibreCredential;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class getCompaniesProductsController extends Controller
{
    public function getTotalSalesAllCompanies(Request $request)
    {
        $clientIds = Company::whereNotNull('client_id')->pluck('client_id')->toArray();

        // Obtener año y mes desde la query
        
        $year = (int) $request->query('year', date('Y'));
        $month = (int) $request->query('month', date('m'));

        
        $start = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $end = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
        
        $dateFrom = $start->toIso8601String();
        $dateTo = $end->toIso8601String();

        $allSales = [];
        $totalSales = 0;
        $client = new Client(['timeout' => 20]);
        $promises = [];

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
                $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
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

            $userResponse = Http::withToken($credentials->access_token)->get('https://api.mercadolibre.com/users/me');
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
                    'order.status' => 'paid',
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

            // Procesa $clientOrders igual que antes:
            foreach ($clientOrders as $order) {
                $orderDate = \Carbon\Carbon::parse($order['date_created']);
                if ($orderDate->year != $year || $orderDate->month != $month) {
                    continue;
                }
                $orderMonth = $orderDate->format('Y-m');
                if (!isset($salesByCompany[$orderMonth][$clientId])) {
                    $salesByCompany[$orderMonth][$clientId] = [
                        'total_sales' => 0,
                        'orders' => []
                    ];
                }
                if (isset($order['total_amount'])) {
                    $salesByCompany[$orderMonth][$clientId]['total_sales'] += $order['total_amount'];
                    $totalSales += $order['total_amount'];
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
                $salesByCompany[$orderMonth][$clientId]['orders'][] = $orderData;
            }
        }

       foreach ($salesByCompany as $clientId => &$months) {
       ksort($months);
}
       unset($months);

        foreach ($allSales as $clientId => $clientOrders) {
            foreach ($clientOrders as $order) {
                
            }
        }

        
        $monthKey = sprintf('%04d-%02d', $year, $month);
        foreach ($clientIds as $clientId) {
            if (!isset($salesByCompany[$monthKey][$clientId])) {
                $salesByCompany[$monthKey][$clientId] = [
                    'total_sales' => 0,
                    'orders' => []
                ];
            }
            ksort($salesByCompany[$monthKey]);
        }
        ksort($salesByCompany);

        return response()->json([
            'status' => 'success',
            'message' => 'Órdenes pagadas agrupadas por empresa',
            'sales_by_company' => $salesByCompany,
            'total_sales' => $totalSales,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
        ]);
    }
}


