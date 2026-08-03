<?php

namespace Tests\Security\Support;

/**
 * A deliberately ungated controller, used only by ControllerAuthorizationTest to prove its
 * audit still fires.
 *
 * The audit's job is to notice a controller that sits behind `auth` and makes no authorization
 * decision. Asserting that on the real codebase only ever proves the *absence* of a finding —
 * which is indistinguishable from a scanner that quietly stopped working. So the test mounts
 * this class at runtime and requires the audit to name it.
 *
 * It is never routed by the application. Do not add an authorization call: being ungated is
 * the entire point.
 */
class UngatedCanaryController
{
    public function index(): string
    {
        return 'ungated';
    }
}
