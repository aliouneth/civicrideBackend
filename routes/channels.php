<?php

use Illuminate\Support\Facades\Broadcast;

// Private per-tenant ride channel: only members of the tenant may listen.
Broadcast::channel('tenant.{tenantId}.rides', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
