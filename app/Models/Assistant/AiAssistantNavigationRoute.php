<?php

namespace App\Models\Assistant;

use Illuminate\Database\Eloquent\Model;

/** A host-managed override/addition to an assistant's approved Take Me There map. */
class AiAssistantNavigationRoute extends Model
{
    protected $table = 'ai_assistant_navigation_routes';

    protected $fillable = ['assistant_type', 'route_key', 'label', 'path', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Same-origin, absolute in-app path only: no scheme, host, query, fragment or "//". */
    public const PATH_PATTERN = '/^\/(?:[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*)?$/';

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,59}$/';
}
