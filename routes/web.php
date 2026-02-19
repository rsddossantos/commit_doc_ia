<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\HomeController;

Route::get('/', function () {
    return redirect()->route('home');
});

Route::get('/login', function () {
    return Inertia::render('LoginPage');
})->name('login');

Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/home', [HomeController::class, 'index'])->name('home');
Route::post('/commits', [HomeController::class, 'searchCommits'])->name('commits');


















