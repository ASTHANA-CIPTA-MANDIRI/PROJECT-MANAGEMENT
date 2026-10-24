<?php

namespace App\Services\Doku;

use RuntimeException;

/**
 * Thrown by DokuClient::createPayment() when DOKU's create-payment call
 * (spec section A) fails outright - a non-200 response, a transport-level
 * error, or a 200 response missing the fields the checkout flow needs
 * (response.payment.url/token_id). Caught by
 * App\Http\Controllers\SubscriptionCheckoutController, which never lets a
 * raw DOKU error response reach the browser.
 */
class DokuPaymentException extends RuntimeException
{
    //
}
