<?php

namespace App\Http\Controllers\MercadoLibre\Reportes;

use App\Models\MercadoLibreCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class getStockCriticController
{


    public function getStockCritic(Request $request, $clientId)
    {


        set_time_limit(180);
        try {
            $validatedData = $request->validate([
                'excel' => 'sometimes|max:4',
                'mail' => 'sometimes|email|max:255',
            ]);
            $excel = false;
            $mail=null;
            if (isset($validatedData['excel'])) {
                $excel = $request->boolean('excel');
                error_log("excel " . json_encode($excel));
            }
            if (isset($validatedData['mail'])) {
                $mail = $validatedData['mail'];
                error_log("mail " . json_encode($mail));
            }
        } catch (\Exception $e) {
            // Log the error message
            error_log('Error en getStockCriticController: ' . $e->getMessage());
            // You can also log the stack trace for debugging purposes
            error_log('Stack Trace: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar la solicitud.' . $e->getMessage(),
            ], 500);
        }



        if (empty($clientId) || !is_numeric($clientId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El clientId debe ser un número válido.',
            ], 400);
        }


        $cacheKey = "mercado_libre:stock_critic:{$clientId}";


        if (Cache::has($cacheKey)) {
            error_log("entro a cache download");
            $cachedData = Cache::get($cacheKey);

            if ($excel == true) {
                return $this->reportStockCriticExcel($cachedData);
            } else if ($mail) {
                return $this->reportStockCriticMail($cachedData, $mail);
            } else {
                // Ni excel ni mail fueron solicitados, devolver los datos en JSON
                return response()->json([
                    'status' => 'success',
                    'message' => 'Datos obtenidos de cache',
                    'data' => $cachedData,
                    'from_cache' => true
                ]);
            }
        }
        //Validar y obtener credenciales
        $credentials = MercadoLibreCredential::where('client_id', $clientId)->first();
        error_log("credentials " . json_encode($credentials));
        if (!$credentials) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron credenciales válidas para el client_id proporcionado.',
            ], 404);
        }
        try {
            if ($credentials->isTokenExpired()) {
                $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => $credentials->client_id,
                    'client_secret' => $credentials->client_secret,
                    'refresh_token' => $credentials->refresh_token,
                ]);
                // Si la solicitud falla, devolver un mensaje de error
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
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al refrescar token: ' . $e->getMessage(),
            ], 500);
        }
        // Comprobar si el token ha expirado y refrescarlo si es necesario


        $userResponse = Http::withToken($credentials->access_token)
            ->get('https://api.mercadolibre.com/users/me');

        // If it fails by token, try refreshing again
        if ($userResponse->status() === 401) {
            $refreshResponse = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $credentials->client_id,
                'client_secret' => $credentials->client_secret,
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
        }

        if ($userResponse->failed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener el ID del usuario.',
                'error' => $userResponse->json(),
            ], 500);
        }
        error_log("userResponse " . json_encode($userResponse));
        // Obtener el ID del usuario
        $userId = $userResponse->json()['id'];
        $limit = 100;
        $offset = 0;
        $totalItems = 0;
        $productsStock = [];
        $processedIds = [];

        // Construir la URL base
        $baseUrl = "https://api.mercadolibre.com/users/{$userId}/items/search";
        try {

            $maxProductos = 1000; // Ajustar según necesidades (1000 es el maximo)
            $productosProcessed = 0; //contador de productos para terminar la ejecucion el caso de alcanzar $maxProductos
            //se setea los headers y el tiempo de espera de la conexion asyncrona
            $client = new Client([
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->access_token
                ]
            ]);
            do {
                // se arma la url para obtener lotes de IDs de productos para consultar a travez de ids
                $searchUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') .
                    http_build_query(['limit' => $limit, 'offset'=> $offset]);
                error_log("URL: {$searchUrl}");
                $response = Http::timeout(30)->withToken($credentials->access_token)->get($searchUrl);
                if ($response->failed()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Error al conectar con la API de MercadoLibre.',
                        'error' => $response->json(),
                        'request_url' => $searchUrl,
                    ], $response->status());
                }
                $json = $response->json();
                $items = $json['results'] ?? [];
                $total = $json['paging']['total'] ?? 0;

                if (empty($items)) {
                    break; // No hay más items para procesar
                }

                //se separan los 100 items de la peticion en grupos de 20 que es el maximo
                // de items que se pueden pedir a la vez
                $itemBatches = array_chunk($items, 20);
                $totalItems += count($items);
                $productosProcessed += count($items);

                // Solucion azyncrona mmultiples peticiones paralelas
                foreach ($itemBatches as $batch) {
                    // Filtrar IDs ya procesados
                    $uniqueBatch = array_diff($batch, $processedIds);
                    $processedIds = array_merge($processedIds, $uniqueBatch);

                    if (empty($uniqueBatch)) continue;

                    // Crear promesas para peticiones paralelas
                    $promises = [];
                    foreach (array_chunk($uniqueBatch, 20) as $subBatch) {
                        $batchIds = implode(',', $subBatch);
                        $promises[] = $client->getAsync('https://api.mercadolibre.com/items', [
                            'query' => [
                                'ids' => $batchIds,
                                'attributes' => 'id,title,available_quantity,price,permalink'
                            ]
                        ]);
                    }

                    // Ejecutar todas las promesas en paralelo
                    try {
                        $responses = Promise\Utils::unwrap($promises);

                        // Procesar cada respuesta de las promesas
                        foreach ($responses as $response) {
                            if ($response->getStatusCode() == 200) {
                                $batchResults = json_decode($response->getBody()->getContents(), true);

                                // Validar que batchResults sea un array antes de procesarlo
                                if (!is_array($batchResults)) {
                                    error_log("Error: La respuesta no es un array válido: " . $response->getBody());
                                    continue;
                                }

                                // Procesar los resultados
                                foreach ($batchResults as $itemResult) {
                                    if (
                                        isset($itemResult['code']) &&
                                        $itemResult['code'] == 200 &&
                                        isset($itemResult['body']['available_quantity']) &&
                                        $itemResult['body']['available_quantity'] <= 5
                                    ) {
                                        $productsStock[] = [
                                            'id' => $itemResult['body']['id'],
                                            'title' => $itemResult['body']['title'],
                                            'available_quantity' => $itemResult['body']['available_quantity'],
                                            'price' => $itemResult['body']['price'] ?? null,
                                            'permalink' => $itemResult['body']['permalink'] ?? null
                                        ];
                                    }
                                }
                            } else {
                                error_log("Respuesta de API con estado no exitoso: " . $response->getStatusCode());
                            }
                        }
                    } catch (\Exception $e) {
                        error_log("Error en peticiones asincrónicas: " . $e->getMessage());
                        error_log("Traza del error: " . $e->getTraceAsString());
                    }
                }


                $offset += $limit;

                // terminar si se procesaron todos los productos
                if ($productosProcessed >= $maxProductos) {
                    break;
                }
            } while ($offset < $total);

            $responseData = [
                'total_items_processed' => $totalItems,
                'products_count' => count($productsStock),
                'productos' => $productsStock

            ];


            Cache::put($cacheKey, $responseData, now()->addMinutes(10));
            if (isset($validatedData['excel']) && $excel == true) {
                return $this->reportStockCriticExcel($productsStock);
            } else if ($mail) {
                return $this->reportStockCriticMail($productsStock, $mail);
            } else {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Productos obtenidos correctamente',
                    'data' => $responseData,
                    'from_cache' => false
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar datos: ' . $e->getMessage(),
            ], 500);
        }
    }
    private function reportStockCriticMail($result, $email = null)
    {
        try {
            if (!empty($result)) {
                // Crear directorio para asegurar que existe (con permisos adecuados)
                $directoryPath = storage_path('app/reports');
                if (!File::isDirectory($directoryPath)) {
                    File::makeDirectory($directoryPath, 0755, true, true);
                }

                $fileName = 'reports/stock_critico_' . date('Ymd_His') . '.xlsx';
                $fullPath = storage_path('app/' . $fileName);

                // Crear el archivo Excel con PhpSpreadsheet
                $spreadsheet = $this->createStockCriticoSpreadsheet($result);
                $writer = new Xlsx($spreadsheet);

                // Guardar el archivo en el storage
                $writer->save($fullPath);

                // Verificar que el archivo existe
                if (!file_exists($fullPath)) {
                    throw new \Exception("No se pudo generar el archivo Excel en la ruta: " . $fullPath);
                }

                // Enviar por correo electrónico con el archivo adjunto
                Mail::to($email)->send(new StockCriticoReport($fileName));

                // Registrar el envío correcto
                Log::info('Reporte enviado exitosamente a: ' . $email . ', archivo: ' . $fullPath);

                // Eliminar el archivo después de enviar el correo (opcional, puedes mantenerlo si necesitas)
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                    Log::info('Archivo Excel eliminado después de enviar el correo: ' . $fullPath);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Reporte generado y enviado por email',
                    'email' => $email,
                    'data_count' => is_array($result['productos'] ?? $result) ? count($result['productos'] ?? $result) : 'N/A'
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No hay datos para generar el reporte',
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error al generar o enviar mail: ' . $e->getMessage());
            Log::error('Traza del error: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar los datos y enviar mail: ' . $e->getMessage(),
                'file_path' => storage_path('app/reports'),
                'directory_exists' => File::isDirectory(storage_path('app/reports')),
                'storage_writable' => is_writable(storage_path('app'))
            ], 500);
        }
    }


    private function reportStockCriticExcel($result)
    {
        try {


            if (!empty($result)) {
                $fileName = 'stock_critico_' . date('Ymd_His') . '.xlsx';

                // Crear un archivo temporal para guardar el Excel
                $tempFile = tempnam(sys_get_temp_dir(), 'stock_critico_');

                // Crear el objeto Spreadsheet
                $spreadsheet = $this->createStockCriticoSpreadsheet($result);

                // Crear el writer y guardar en el archivo temporal
                $writer = new Xlsx($spreadsheet);
                $writer->save($tempFile);

                // Leer el contenido del archivo
                $fileContent = file_get_contents($tempFile);

                // Eliminar el archivo temporal
                @unlink($tempFile);

                // Crear la respuesta con el archivo como contenido para descarga directa
                return response($fileContent)
                    ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                    ->header('Content-Length', strlen($fileContent))
                    ->header('Cache-Control', 'max-age=0');
            } else {
                error_log('No hay datos para generar Excel');
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontraron datos para generar el reporte',
                    'received_keys' => empty($result) ? [] : array_keys($result)
                ], 404);
            }
        } catch (\Exception $e) {
            error_log('Error al generar el archivo Excel: ' . $e->getMessage());
            error_log('Traza: ' . $e->getTraceAsString());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el archivo Excel: ' . $e->getMessage(),
            ], 500);
        }
    }


    private function createStockCriticoSpreadsheet($data)
    {
        // Asegurarse de que tenemos la estructura correcta de datos
        $productos = isset($data['productos']) ? $data['productos'] : $data;

        // Crear una nueva instancia de Spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock Crítico');

        // Definir los encabezados
        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Producto');
        $sheet->setCellValue('C1', 'Stock Actual');
        $sheet->setCellValue('D1', 'Precio');
        $sheet->setCellValue('E1', 'Enlace');

        // Dar formato a la fila de encabezados
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN
                ]
            ]
        ];

        $headerRange = 'A1:E1';
        $sheet->getStyle($headerRange)->applyFromArray($headerStyle);

        // Agregar los datos de los productos
        $row = 2;
        foreach ($productos as $producto) {
            $sheet->setCellValue('A' . $row, $producto['id']);
            $sheet->setCellValue('B' . $row, $producto['title']);
            $sheet->setCellValue('C' . $row, $producto['available_quantity']);
            $sheet->setCellValue('D' . $row, $producto['price'] ?? 'N/A');

            // Crear un enlace clicable si existe permalink
            if (!empty($producto['permalink'])) {
                $sheet->setCellValue('E' . $row, $producto['permalink']);
                $sheet->getCell('E' . $row)->getHyperlink()->setUrl($producto['permalink']);
                $sheet->getStyle('E' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLUE));
                $sheet->getStyle('E' . $row)->getFont()->setUnderline(true);
            } else {
                $sheet->setCellValue('E' . $row, '');
            }

            // aplicar color rojo cuando stock es menor o igual a 2
            if ($producto['available_quantity'] <= 2) {
                $sheet->getStyle('C' . $row)->getFont()->getColor()->setRGB('FF0000');
                $sheet->getStyle('C' . $row)->getFont()->setBold(true);
            }

            $row++;
        }

        // Auto-dimensionar columnas
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Congelar la primera fila
        $sheet->freezePane('A2');

        // Agregar filtros
        $lastRow = count($productos) + 1;
        $sheet->setAutoFilter('A1:E' . $lastRow);

        return $spreadsheet;
    }
}




class StockCriticoReport extends Mailable
{
    use Queueable, SerializesModels;

    public $fileName;

    public function __construct($fileName)
    {
        $this->fileName = $fileName;
    }

    public function build()
    {
        $filePath = storage_path('app/' . $this->fileName);

        if (!file_exists($filePath)) {
            Log::error("Archivo no encontrado: " . $filePath);
            throw new \Exception("El archivo adjunto no existe en la ruta: " . $filePath);
        }

        Log::info("Adjuntando archivo desde: " . $filePath);

        return $this->subject('Reporte de Stock Crítico - ' . date('d/m/Y'))
            ->view('emails.stock_report')
            ->attach($filePath, [
                'as' => 'stock_critico_' . date('Ymd') . '.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
    }
}
