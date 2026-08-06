<?php

/*
|--------------------------------------------------------------------------
| Engineer888 access
|--------------------------------------------------------------------------
|
| PROTECTED POLICY INFRASTRUCTURE. This file, the access policy class and the
| grant table are outside Engineer888's own write authority: a reasoning
| candidate that proposes a change to any of them is refused before it is
| installed. A system that can widen its own access has no access control.
|
| require_mfa is false because no MFA is enrolled on the canonical account and
| turning it on would lock Mark out of his own supervision surface. The hook is
| live: set it true the moment enrolment exists and high-risk mutations begin
| returning BLOCKED_MFA_ENROLLMENT_REQUIRED instead of proceeding.
|
*/

return [
    'require_mfa' => env('E888_ACCESS_REQUIRE_MFA', false),

    // Unauthorised callers get 404, not 403: confirming that a private internal
    // feature exists is itself a disclosure. One convention, used everywhere.
    'deny_status' => 404,
];
