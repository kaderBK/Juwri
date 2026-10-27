<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Inventory/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #78: Inventory tracking (simple, per-menu-item)
            // MONETIZATION B1: the whole inventory module is gated behind
            // `feature:inventory`. Lands DARK (plan_gated=0 → the middleware is a
            // kill-switch only), so nothing changes until WI V0's flip. The
            // advanced supplier routes inside keep their own `feature:supplier_suite`
            // gate — those require BOTH keys, which is correct.
            Route::middleware('feature:inventory')->group(function () {
                Route::get('inventory', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryController@index')->name('inventory.index');
                Route::put('inventory/{menu}', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryController@update')->name('inventory.update');
                Route::post('inventory/enable-all', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryController@enableAll')->name('inventory.enableAll');
                Route::post('inventory/mode', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryController@setMode')->name('inventory.setMode');
                Route::post('inventory/oversell-reset', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryController@resetOversell')->name('inventory.oversellReset'); // H5
                // Full inventory — Phase 1: ingredients + ledger
                Route::get('inventory/ingredients', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@index')->name('inventory.ingredients.index');
                Route::post('inventory/ingredients', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@store')->name('inventory.ingredients.store');
                Route::put('inventory/ingredients/{ingredient}', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@update')->name('inventory.ingredients.update');
                Route::delete('inventory/ingredients/{ingredient}', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@destroy')->name('inventory.ingredients.destroy');
                Route::post('inventory/ingredients/{ingredient}/adjust', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@adjust')->name('inventory.ingredients.adjust');
                Route::get('inventory/ingredients/{ingredient}/history', '\Juwri\Modules\Inventory\Http\Controllers\User\IngredientController@history')->name('inventory.ingredients.history');
                // Full inventory — Phase 2: recipes (bill of materials)
                Route::get('inventory/recipes', '\Juwri\Modules\Inventory\Http\Controllers\User\RecipeController@index')->name('inventory.recipes.index');
                Route::post('inventory/recipes/auto-86', '\Juwri\Modules\Inventory\Http\Controllers\User\RecipeController@setAuto86')->name('inventory.recipes.auto86'); // D10
                Route::get('inventory/recipes/{menu}', '\Juwri\Modules\Inventory\Http\Controllers\User\RecipeController@edit')->name('inventory.recipes.edit');
                Route::post('inventory/recipes/{menu}', '\Juwri\Modules\Inventory\Http\Controllers\User\RecipeController@store')->name('inventory.recipes.store');
                Route::delete('inventory/recipes/{menu}/{ingredient}', '\Juwri\Modules\Inventory\Http\Controllers\User\RecipeController@destroy')->name('inventory.recipes.destroy');
                // Full inventory — Phase 3: suppliers + purchase orders
                Route::get('inventory/suppliers', '\Juwri\Modules\Inventory\Http\Controllers\User\SupplierController@index')->name('inventory.suppliers.index');
                Route::post('inventory/suppliers', '\Juwri\Modules\Inventory\Http\Controllers\User\SupplierController@store')->name('inventory.suppliers.store');
                Route::put('inventory/suppliers/{supplier}', '\Juwri\Modules\Inventory\Http\Controllers\User\SupplierController@update')->name('inventory.suppliers.update');
                Route::delete('inventory/suppliers/{supplier}', '\Juwri\Modules\Inventory\Http\Controllers\User\SupplierController@destroy')->name('inventory.suppliers.destroy');
                Route::get('inventory/purchase-orders', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@index')->name('inventory.purchaseOrders.index');
                Route::post('inventory/purchase-orders', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@store')->name('inventory.purchaseOrders.store');
                Route::get('inventory/purchase-orders/{purchaseOrder}', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@show')->name('inventory.purchaseOrders.show');
                Route::post('inventory/purchase-orders/{purchaseOrder}/items', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@addItem')->name('inventory.purchaseOrders.addItem');
                Route::delete('inventory/purchase-orders/{purchaseOrder}/items/{item}', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@removeItem')->name('inventory.purchaseOrders.removeItem');
                Route::post('inventory/purchase-orders/{purchaseOrder}/ordered', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@markOrdered')->name('inventory.purchaseOrders.ordered');
                Route::post('inventory/purchase-orders/{purchaseOrder}/receive', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@receive')->name('inventory.purchaseOrders.receive');
                // SYSTEMS-RUN SUP-P1: line-level receiving + invoice capture (supplier_suite-gated).
                Route::post('inventory/purchase-orders/{purchaseOrder}/receive-lines', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@receiveLines')->middleware('feature:supplier_suite')->name('inventory.purchaseOrders.receiveLines');
                Route::post('inventory/purchase-orders/{purchaseOrder}/invoice', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@captureInvoice')->middleware('feature:supplier_suite')->name('inventory.purchaseOrders.invoice');
                Route::get('inventory/cogs', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@cogs')->middleware('feature:supplier_suite')->name('inventory.cogs');
                Route::post('inventory/purchase-orders/{purchaseOrder}/cancel', '\Juwri\Modules\Inventory\Http\Controllers\User\PurchaseOrderController@cancel')->name('inventory.purchaseOrders.cancel');
                // Full inventory — Phase 4: stock counts + variance
                Route::get('inventory/counts', '\Juwri\Modules\Inventory\Http\Controllers\User\StockCountController@index')->name('inventory.counts.index');
                Route::post('inventory/counts', '\Juwri\Modules\Inventory\Http\Controllers\User\StockCountController@store')->name('inventory.counts.store');
                Route::get('inventory/counts/{stockCount}', '\Juwri\Modules\Inventory\Http\Controllers\User\StockCountController@show')->name('inventory.counts.show');
                Route::put('inventory/counts/{stockCount}', '\Juwri\Modules\Inventory\Http\Controllers\User\StockCountController@saveCounts')->name('inventory.counts.save');
                Route::post('inventory/counts/{stockCount}/close', '\Juwri\Modules\Inventory\Http\Controllers\User\StockCountController@close')->name('inventory.counts.close');
                // Full inventory — Phase 5: waste log + reports
                Route::get('inventory/waste', '\Juwri\Modules\Inventory\Http\Controllers\User\WasteController@index')->name('inventory.waste.index');
                Route::post('inventory/waste', '\Juwri\Modules\Inventory\Http\Controllers\User\WasteController@store')->name('inventory.waste.store');
                Route::get('inventory/reports', '\Juwri\Modules\Inventory\Http\Controllers\User\InventoryReportController@index')->name('inventory.reports.index');
            }); // end feature:inventory group
