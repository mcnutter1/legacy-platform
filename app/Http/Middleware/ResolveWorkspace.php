<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Workspace;
use App\Support\AccessContext;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Binds the AccessContext for /api/v1/workspaces/{workspace}/... (session principals). */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->route('workspace');
        $user = $request->user();
        if (! $user || ! Str::isUlid($id)) {
            throw ApiError::notFound();
        }
        $membership = Membership::where('workspace_id', $id)->where('user_id', $user->id)->first();
        if (! $membership || ! $membership->isActive()) {
            throw ApiError::notFound();
        }
        $workspace = Workspace::findOrFail($id);
        app()->instance(AccessContext::class, new AccessContext($user, $workspace, $membership));
        $request->route()->forgetParameter('workspace');

        return $next($request);
    }
}
