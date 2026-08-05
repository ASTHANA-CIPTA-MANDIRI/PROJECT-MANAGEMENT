<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\RoadMap\DataController;
use App\Models\Ticket;
use App\Models\User;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Share ticket (public, throttled to 60/min per IP)
Route::get('/tickets/share/{ticket:code}', function (Ticket $ticket) {
    return redirect()->to(route('filament.resources.tickets.view', $ticket));
})->middleware('throttle:public')->name('filament.resources.tickets.share');

// Validate an account (public, throttled to 60/min per IP)
Route::get('/validate-account/{user:creation_token}', function (User $user) {
    return view('validate-account', compact('user'));
})
    ->name('validate-account')
    ->middleware([
        'web',
        'throttle:public',
        DispatchServingFilamentEvent::class,
    ]);

// Login default redirection
Route::redirect('/login-redirect', '/login')->name('login');

// Road map JSON data
Route::get('road-map/data/{project}', [DataController::class, 'data'])
    ->middleware(['verified', 'auth'])
    ->name('road-map.data');

// Ticket attachments / project covers - authorization is enforced in the
// controller (TicketPolicy/ProjectPolicy) since it depends on which model
// the media belongs to.
Route::get('/media/{media}', [MediaController::class, 'show'])
    ->middleware('auth')
    ->name('media.show');

Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill(); // menandai email sebagai terverifikasi

    Auth::logout(); // keluar dari sesi login

    return redirect()->route('login')->with('verified', true);
})->middleware(['auth', 'signed'])->name('verification.verify');
