<?php

namespace Tests\Unit\Support\Doku;

use App\Support\Doku\DokuSignature;
use PHPUnit\Framework\TestCase;

/**
 * Fase 8-9 - App\Support\Doku\DokuSignature against the verified DOKU spec
 * (developers.doku.com, section C). No worked end-to-end example (real
 * secret key + expected signature) is published in the spec this
 * integration was built against, so these pin the algorithm's own
 * documented pieces individually - the exact component string shape, the
 * exact digest formula (DOKU's own PHP sample code, byte for byte), and
 * the exact signature formula (also DOKU's own PHP sample code) - rather
 * than a single opaque "does it equal this magic string" assertion.
 */
class DokuSignatureTest extends TestCase
{
    public function test_component_string_matches_the_exact_required_order_and_format(): void
    {
        $componentString = DokuSignature::componentString(
            'MCH-0001-10791114622547',
            '479b663f-5c9d-400d-8e80-3e548a8f7639',
            '2020-08-11T08:45:42Z',
            '/checkout/v1/payment',
            'abc123=='
        );

        $expected = "Client-Id:MCH-0001-10791114622547\n"
            ."Request-Id:479b663f-5c9d-400d-8e80-3e548a8f7639\n"
            ."Request-Timestamp:2020-08-11T08:45:42Z\n"
            ."Request-Target:/checkout/v1/payment\n"
            .'Digest:abc123==';

        $this->assertSame($expected, $componentString);
    }

    public function test_component_string_has_no_trailing_newline(): void
    {
        $componentString = DokuSignature::componentString('a', 'b', 'c', 'd', 'e');

        $this->assertFalse(str_ends_with($componentString, "\n"));
    }

    /**
     * DOKU's own sample code: base64_encode(hash('sha256', json_encode($body), true)).
     * Verified independently here rather than trusting our own
     * implementation to be self-consistent.
     */
    public function test_digest_is_base64_of_raw_sha256(): void
    {
        $body = '{"order":{"amount":150000,"invoice_number":"INV-1"}}';

        $expected = base64_encode(hash('sha256', $body, true));

        $this->assertSame($expected, DokuSignature::digest($body));
    }

    public function test_digest_changes_when_the_body_changes_by_even_one_byte(): void
    {
        $digestA = DokuSignature::digest('{"amount":150000}');
        $digestB = DokuSignature::digest('{"amount":150001}');

        $this->assertNotSame($digestA, $digestB);
    }

    /**
     * DOKU's own sample code: "HMACSHA256=" . base64_encode(hash_hmac('sha256', $componentSignature, $secretKey, true)).
     */
    public function test_sign_matches_hmacsha256_prefixed_base64_hmac(): void
    {
        $componentString = "Client-Id:abc\nRequest-Id:def";
        $secretKey = 'top-secret';

        $expected = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $componentString, $secretKey, true));

        $this->assertSame($expected, DokuSignature::sign($componentString, $secretKey));
    }

    public function test_verify_accepts_a_correctly_recomputed_signature(): void
    {
        $componentString = 'some-component-string';
        $secretKey = 'secret';
        $signature = DokuSignature::sign($componentString, $secretKey);

        $this->assertTrue(DokuSignature::verify($componentString, $secretKey, $signature));
    }

    public function test_verify_rejects_a_signature_signed_with_the_wrong_secret(): void
    {
        $componentString = 'some-component-string';
        $signature = DokuSignature::sign($componentString, 'wrong-secret');

        $this->assertFalse(DokuSignature::verify($componentString, 'real-secret', $signature));
    }

    public function test_verify_rejects_a_component_string_that_was_tampered_with_after_signing(): void
    {
        $secretKey = 'secret';
        $signature = DokuSignature::sign('original-component-string', $secretKey);

        $this->assertFalse(DokuSignature::verify('tampered-component-string', $secretKey, $signature));
    }

    public function test_component_string_without_digest_is_four_lines_with_no_trailing_newline(): void
    {
        $this->assertSame(
            "Client-Id:C\nRequest-Id:R\nRequest-Timestamp:T\nRequest-Target:/orders/v1/status/INV-1",
            DokuSignature::componentStringWithoutDigest('C', 'R', 'T', '/orders/v1/status/INV-1')
        );
    }

    public function test_component_string_with_digest_is_the_digestless_one_plus_a_digest_line(): void
    {
        $this->assertSame(
            DokuSignature::componentStringWithoutDigest('C', 'R', 'T', '/p')."\nDigest:D",
            DokuSignature::componentString('C', 'R', 'T', '/p', 'D')
        );
    }

    public function test_verify_rejects_a_null_signature(): void
    {
        $this->assertFalse(DokuSignature::verify('anything', 'secret', null));
    }

    public function test_verify_rejects_an_empty_signature(): void
    {
        $this->assertFalse(DokuSignature::verify('anything', 'secret', ''));
    }
}
