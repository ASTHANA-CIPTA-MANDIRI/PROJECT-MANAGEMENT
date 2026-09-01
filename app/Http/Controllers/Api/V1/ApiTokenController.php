<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApiTokenRequest;
use App\Http\Resources\PersonalAccessTokenResource;
use App\Support\ApiTokenIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\TransientToken;

/**
 * Self-service management of the caller's own API tokens.
 *
 * Until now a token could only be minted from `tinker`, which meant either
 * handing out shell access or handing out someone else's token. These
 * endpoints — and the matching "API tokens" page in the panel — let a user
 * issue, review and revoke their own credentials.
 *
 * Everything here is scoped to `$request->user()->tokens()`: there is no
 * permission that grants a view of anyone else's tokens, and an id belonging
 * to another account reads as 404 rather than as "forbidden", so the endpoint
 * cannot be used to probe which token ids exist.
 */
class ApiTokenController extends ApiController
{
    /**
     * GET /api/v1/tokens
     *
     * The caller's own tokens. The secrets are not in the response — they are
     * only ever stored hashed — so this lists what exists, not what it is.
     */
    public function index(Request $request)
    {
        $query = $request->user()->tokens()->getQuery();

        $this->applySorting($query, $request, ['name', 'created_at', 'last_used_at', 'expires_at'], 'created_at');

        return PersonalAccessTokenResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/tokens
     *
     * Issues a token and returns its plain text secret once, under
     * `plain_text_token`. It is not recoverable afterwards; a lost token is
     * revoked and replaced.
     */
    public function store(ApiTokenRequest $request)
    {
        $this->assertNotAuthenticatedWithAToken($request);

        $data = $request->validated();

        $token = ApiTokenIssuer::issue(
            $request->user(),
            $data['name'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null
        );

        return (new PersonalAccessTokenResource($token->accessToken))
            ->additional(['plain_text_token' => $token->plainTextToken])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/v1/tokens/{token}
     *
     * Revokes one of the caller's tokens, including the one being used for
     * this very request — the panic button for a leaked credential.
     */
    public function destroy(Request $request, string $token)
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return response()->noContent();
    }

    /**
     * A request authenticated with a personal access token may not mint
     * another one.
     *
     * Bounded expiry (see config/sanctum.php) is what stops a leaked token
     * living forever; a token that can issue successors hands that guarantee
     * straight back, since whoever holds it can renew itself indefinitely.
     * Issuing therefore requires a first-party session — the panel — where
     * Sanctum reports a TransientToken instead of a stored one.
     */
    private function assertNotAuthenticatedWithAToken(Request $request): void
    {
        $token = $request->user()->currentAccessToken();

        abort_if(
            $token !== null && ! $token instanceof TransientToken,
            403,
            'API tokens must be created from a signed-in session, not with another API token.'
        );
    }
}
