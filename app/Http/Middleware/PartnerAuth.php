<?php

namespace App\Http\Middleware;

use App\Core\Auth\RefreshTokenService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RFC-0026 section 8: the partner portal signs in with the normal LevelUpGrowth login, but an affiliate has no workspace,
 * so auth.jwt (which needs one) cannot guard it. This checks the same access token and loads the person's affiliate row.
 * `partner.auth` = signed in; `partner.auth:approved` = an approved affiliate.
 */
class PartnerAuth
{
    public function __construct(private RefreshTokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $need = 'any')
    {
        $t = $request->bearerToken();
        if (! $t) return response()->json(['error' => 'Please sign in.', 'code' => 'unauthenticated'], 401);
        try { $p = $this->tokens->decodeAccessToken($t); } catch (\Throwable) { return response()->json(['error' => 'Your session ended. Please sign in again.', 'code' => 'unauthenticated'], 401); }
        $user = User::find($p->sub ?? null);
        if (! $user) return response()->json(['error' => 'Please sign in.', 'code' => 'unauthenticated'], 401);
        $aff = DB::table('affiliates')->where('user_id', $user->id)->first();
        if ($need === 'approved' && (! $aff || $aff->status !== 'approved')) {
            return response()->json(['error' => $aff ? 'Your partner account is ' . $aff->status . '.' : 'You are not a partner yet.', 'code' => 'not_partner'], 403);
        }
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('affiliate', $aff);
        $request->attributes->set('auth_via', 'jwt');
        return $next($request);
    }
}
