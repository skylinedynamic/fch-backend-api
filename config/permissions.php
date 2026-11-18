<?php

/*
|--------------------------------------------------------------------------
| Application Permission List
|--------------------------------------------------------------------------
|
| Built from App\Enums\Permission - add or change a permission there, not
| here. The seeders read this list: module => ['permissions' => [...]], each
| with its name, is_certified (a Certified Partner's roles may hold it) and
| is_host (only the host's roles may hold it).
|
*/

return App\Enums\Permission::toConfig();
