<?php

namespace App\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class JwtGuard implements Guard
{
    use GuardHelpers;

    protected Request $request;

    public function __construct(UserProvider $provider, Request $request)
    {
        $this->provider = $provider;
        $this->request = $request;
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->getTokenFromRequest();
        if (!$token) {
            return null;
        }

        return $this->user = $this->validateToken($token);
    }

    /**
     * Validate a user's credentials.
     * Not used for stateless JWT auth.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Extract the Bearer token from the Authorization header.
     */
    protected function getTokenFromRequest(): ?string
    {
        $header = $this->request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Validate a JWT token and return the authenticated user.
     */
    protected function validateToken(string $token): ?User
    {
        // Quick format check - JWT has 3 parts separated by dots
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        // Decode payload without verification to get the subject (user_id)
        try {
            $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
            $payload = json_decode($payloadJson, true);
        } catch (\Exception $e) {
            return null;
        }

        if (!$payload || !isset($payload['sub'])) {
            return null;
        }

        // Find the user by the subject claim
        $user = User::find($payload['sub']);
        if (!$user || !$user->jwt_secret) {
            return null;
        }

        // Now verify the token with the user's secret
        try {
            JWT::$leeway = config('jwt.leeway', 60);

            $decoded = JWT::decode(
                $token,
                new Key($user->jwt_secret, config('jwt.algorithm', 'HS256'))
            );

            // Validate required claims
            $requiredClaims = config('jwt.required_claims', ['sub', 'exp', 'iat']);
            foreach ($requiredClaims as $claim) {
                if (!isset($decoded->$claim)) {
                    return null;
                }
            }

            // Validate issuer if configured
            $issuer = config('jwt.issuer');
            if ($issuer !== null && (!isset($decoded->iss) || $decoded->iss !== $issuer)) {
                return null;
            }

            // Validate max TTL
            $maxTtl = config('jwt.max_ttl', 3600);
            if ($maxTtl > 0 && isset($decoded->iat)) {
                if ((time() - $decoded->iat) > $maxTtl) {
                    return null;
                }
            }

            return $user;

        } catch (ExpiredException $e) {
            // Token has expired
            return null;
        } catch (SignatureInvalidException $e) {
            // Invalid signature - wrong secret
            return null;
        } catch (BeforeValidException $e) {
            // Token not yet valid (nbf claim)
            return null;
        } catch (\UnexpectedValueException $e) {
            // Malformed token or other issues
            return null;
        } catch (\Exception $e) {
            // Any other JWT errors
            return null;
        }
    }
}
