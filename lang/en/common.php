<?php

return [
    'error_stats_retrieved' => 'Failed to retrieve partner statistics.',
    'stats_retrieved' => 'Statistics retrieved successfully.',
    'users_retrieved' => 'Users retrieved successfully.',
    'locations_retrieved' => 'Locations retrieved successfully.',
    'location_country_not_found' => 'No location data for that country code.',
    'location_format_invalid' => 'Format must be either list or map.',
    'user_count_retrieved' => 'User count retrieved.',
    'user_count_not_applicable' => 'User count not applicable.',
    'user_count_failed' => 'Failed to retrieve user count.',
    'user_data_refreshed' => 'User data refreshed successfully.',
    'user_data_refresh_failed' => 'Failed to retrieve users.',
    'global_configurations_retrieved' => 'Global configurations retrieved successfully.',
    'error_global_configurations_retrieved' => 'Failed to retrieve global configurations.',
    'email_changed_subject' => 'Your Email has been changed',
    'password_updated_subject' => 'Your Password has been updated',
    'postal_code_invalid' => 'Enter a 5-digit ZIP code, e.g. 10001.',
    'path_traversal' => 'The file path must not contain "..".',
    'business_ein_invalid' => 'Enter a 9-digit EIN as XX-XXXXXXX, e.g. 12-3456789.',
    'ssn_invalid' => 'Enter a valid 9-digit SSN as XXX-XX-XXXX.',
    'duns_number_invalid' => 'Enter a 9-digit D-U-N-S number, e.g. 123456789.',
    // Shown when an operation fails unexpectedly. The real exception is logged;
    // it is never returned to the client, because its text can carry SQL,
    // file paths and other internals.
    'something_went_wrong' => 'Something went wrong. Please try again.',
];
