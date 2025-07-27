<?php

use App\Http\Controllers\FormFillController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/form-fill', [FormFillController::class, 'show'])->name('form.show');
Route::post('/form-submit', [FormFillController::class, 'submit'])->name('form.submit');