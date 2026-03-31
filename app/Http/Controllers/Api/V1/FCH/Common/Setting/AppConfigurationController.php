<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Http\Controllers\Controller;
use App\Models\Common\AppConfiguration;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class AppConfigurationController extends Controller
{
    /**
     * Get value of a config key
     */
    public function getSetting(Request $request)
    {
        $key = $request->key;
        $auth = auth()->user();

        $value = AppConfiguration::get($key, '0', $auth->entity_type, $auth->entity_id);

        return ApiResponse::success([
            'key' => $key,
            'value' => $value
        ]);
    }

    /**
     * Save/Toggle value of a config key
     */
    public function saveSetting(Request $request)
    {
        $request->validate([
            'key' => 'required|string',
            'value' => 'required',
        ]);

        $key = $request->key;
        $value = $request->value;
        $auth = auth()->user();

        AppConfiguration::set($key, $value, $auth->entity_type, $auth->entity_id);

        return ApiResponse::success(null, __('app_config.saved_successfully'));
    }
}
