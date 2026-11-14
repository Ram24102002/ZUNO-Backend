<?php

use Illuminate\Support\Facades\Route;
use Zuno\Storefront\Http\Controllers\DesignController;

Route::get('designs/{item}/{file}', [DesignController::class, 'show'])
    ->where('file', 'preview|artwork-[0-9]+')
    ->name('zuno.admin.designs.show');
