<?php

use Illuminate\Support\Facades\Route;
use Zuno\Storefront\Http\Controllers\DesignController;
use Zuno\Storefront\Http\Controllers\MessageController;

Route::get('designs/{item}/{file}', [DesignController::class, 'show'])
    ->where('file', 'preview|artwork-[0-9]+')
    ->name('zuno.admin.designs.show');

Route::controller(MessageController::class)->prefix('messages')->name('zuno.admin.messages.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('read-all', 'markAllRead')->name('read_all');
    Route::post('{id}/read', 'markRead')->whereNumber('id')->name('read');
    Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
});
