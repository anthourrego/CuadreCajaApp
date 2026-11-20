<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/departments', [LocationController::class, 'departments']);
    Route::get('/departments/{department}/municipalities', [LocationController::class, 'municipalities']);
    Route::post('/departments', [LocationController::class, 'storeDepartment']);
    Route::put('/departments/{department}', [LocationController::class, 'updateDepartment']);
    Route::post('/municipalities', [LocationController::class, 'storeMunicipality']);
    Route::put('/municipalities/{municipality}', [LocationController::class, 'updateMunicipality']);

    Route::apiResource('branches', BranchController::class)->only(['index', 'store', 'show', 'update']);
    Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('/registers/options', [CashRegisterController::class, 'options']);
    Route::apiResource('registers', CashRegisterController::class)
        ->parameters(['registers' => 'cashRegister'])
        ->only(['index', 'store', 'show', 'update']);
    Route::get('/users/default-branch-options', [UserController::class, 'defaultBranchOptions']);
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
    Route::get('/roles/options', [RoleController::class, 'options']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
});
