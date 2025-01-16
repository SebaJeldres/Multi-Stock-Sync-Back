<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;

use App\Http\Controllers\ClientesController;

use App\Http\Controllers\BrandsController;
use App\Http\Controllers\StockController;

use App\Http\Controllers\WarehouseCompaniesController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public routes
Route::post('/login', [AuthController::class, 'login']); // Login user
Route::post('/users', [UserController::class, 'store']); // Create user

// CRUD routes for Clientes
Route::get('/clientes', [ClientesController::class, 'index']); // Get all clients
Route::post('/clientes', [ClientesController::class, 'store']); // Create a client
Route::get('/clientes/{id}', [ClientesController::class, 'show']); // Get a client
Route::patch('/clientes/{id}', [ClientesController::class, 'update']); // Update a client
Route::delete('/clientes/{id}', [ClientesController::class, 'destroy']); // Delete a client

// CRUD routes for Marcas
Route::get('/marcas', [BrandsController::class, 'index']); // Get all brands
Route::post('/marcas', [BrandsController::class, 'store']); // Create a brand
Route::get('/marcas/{id}', [BrandsController::class, 'show']); // Get a brand
Route::put('/marcas/{id}', [BrandsController::class, 'update']); // Update a brand
Route::patch('/marcas/{id}', [BrandsController::class, 'patch']); // Patch a brand
Route::delete('/marcas/{id}', [BrandsController::class, 'destroy']); // Delete a brand

// Company-specific routes
Route::get('/companies', [WarehouseCompaniesController::class, 'company_list_all']); // List all companies
Route::post('/companies', [WarehouseCompaniesController::class, 'company_store']); // Create a company
Route::patch('/companies/{id}', [WarehouseCompaniesController::class, 'company_update']); // Update a company's name
Route::get('/companies/{id}', [WarehouseCompaniesController::class, 'company_show']); // Get a company by its ID
Route::delete('/companies/{id}', [WarehouseCompaniesController::class, 'company_delete']); // Delete a company

// Warehouse-specific routes
Route::get('/warehouses', [WarehouseCompaniesController::class, 'warehouse_list_all']); // List all warehouses
Route::post('/warehouses', [WarehouseCompaniesController::class, 'warehouse_store']); // Create a warehouse
Route::patch('/warehouses/{id}', [WarehouseCompaniesController::class, 'warehouse_update']); // Update a warehouse
Route::get('/warehouses/{id}', [WarehouseCompaniesController::class, 'warehouse_show']); // Get a warehouse by its ID
Route::delete('/warehouses/{id}', [WarehouseCompaniesController::class, 'warehouse_delete']); // Delete a warehouse


// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']); // Get full users list
    Route::post('/logout', [AuthController::class, 'logout']); // Logout user
});


use App\Http\Controllers\MercadoLibreController;

// Save MercadoLibre credentials
Route::post('/mercadolibre/save-credentials', [MercadoLibreController::class, 'saveCredentials']);
// Generate MerccadoLibre login Auth 2.0 URL
Route::post('/mercadolibre/login', [MercadoLibreController::class, 'login']);
// Handle MercadoLibre callback
Route::get('/mercadolibre/callback', [MercadoLibreController::class, 'handleCallback']);
// Check MercadoLibre connection status
Route::get('/mercadolibre/test-connection/{client_id}', [MercadoLibreController::class, 'testAndRefreshConnection']);
// Get MercadoLibre credentials if are saved in db
Route::get('/mercadolibre/credentials', [MercadoLibreController::class, 'getAllCredentialsData']);
// Delete credentials using client_id
Route::delete('/mercadolibre/credentials/{client_id}', [MercadoLibreController::class, 'deleteCredentials']);

use App\Http\Controllers\MercadoLibreProductController;
// Get MercadoLibre products list
Route::get('/mercadolibre/products', [MercadoLibreProductController::class, 'listProducts']);
