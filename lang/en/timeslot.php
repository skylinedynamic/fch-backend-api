<?php

return [
    // Success messages
    'retrieved' => 'Time slots retrieved successfully.',
    'created' => 'Time slot created successfully.',
    'updated' => 'Time slot updated successfully.',
    'deleted' => 'Time slot deleted successfully.',
    
    // Error messages
    'not_found' => 'Time slot not found.',
    'creation_failed' => 'Failed to create time slot.',
    'update_failed' => 'Failed to update time slot.',
    'delete_failed' => 'Failed to delete time slot.',
    'already_booked' => 'This time slot is already booked.',
    
    // Validation messages
    'start_time_required' => 'Start time is required.',
    'end_time_required' => 'End time is required.',
    'invalid_time_format' => 'Invalid time format.',
    'end_before_start' => 'End time must be after start time.',
];
