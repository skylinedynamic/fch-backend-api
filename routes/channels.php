<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['api', 'auth:api']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
