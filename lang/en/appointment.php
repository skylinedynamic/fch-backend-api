<?php

return [
    // Success messages
    'created' => 'Appointment created successfully.',
    'created_synced' => 'Appointment has been created successfully.',
    'created_sync_skipped' => 'Appointment created successfully, but Microsoft 365 sync was skipped.',
    'updated' => 'Appointment updated successfully.',
    'updated_synced' => 'Appointment updated successfully.',
    'updated_sync_failed' => 'Appointment updated successfully, but Microsoft 365 sync failed.',
    'deleted' => 'Appointment deleted successfully.',
    'multiple_deleted' => 'Selected appointments deleted successfully.',
    'retrieved' => 'Appointment details retrieved successfully.',
    'list_retrieved' => 'Appointments retrieved successfully.',
    'users_selection_retrieved' => 'Users list retrieved successfully.',

    // Error messages
    'not_found' => 'Appointment not found.',
    'creation_failed' => 'Failed to create appointment.',
    'update_failed' => 'Failed to update appointment.',
    'delete_failed' => 'Failed to delete appointment.',
    'time_slot_unavailable' => 'The selected time slot is not available for one or both users.',
    'external_email' => 'Microsoft 365 sync is not available for external email addresses.',

    // Availability messages
    'duplicate_found' => 'Duplicate appointments found.',
    'available' => 'Time slot is available.',

    // Validation messages
    'subject_required' => 'Appointment title is required.',
    'date_required' => 'Appointment date is required.',
    'date_invalid' => 'Invalid appointment date format.',
    'duration_required' => 'Duration is required.',
    'duration_min' => 'Duration must be at least 1 minute.',
    'meeting_type_required' => 'Meeting type is required.',
    'meeting_type_invalid' => 'Invalid meeting type.',
    'status_invalid' => 'Invalid appointment status.',
    'assigned_for_invalid' => 'Assigned user does not exist.',
    'assigned_for_not_bookable' => 'You cannot book a meeting with this user.',
    'entity_not_attendees' => 'The selected record does not belong to the person you are meeting.',
    'assigned_for_required' => 'Appointment with field is required.',
    'time_slot_invalid' => 'Selected time slot does not exist.',

    // Legacy keys (merged from resources)
    'appointment_created' => 'Appointment created successfully.',
    'appointment_updated' => 'Appointment updated successfully.',
    'appointment_deleted' => 'Appointment deleted successfully.',
    'appointment_not_found' => 'Appointment not found.',
    'appointment_list_failed' => 'Failed to retrieve appointment list.',
    'appointment_create_failed' => 'Failed to create appointment.',
    'appointment_update_failed' => 'Failed to update appointment.',
    'appointment_delete_failed' => 'Failed to delete appointment.',

    // Status labels
    'status' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'rejected' => 'Rejected',
        'rescheduled' => 'Rescheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    // Meeting type labels
    'meeting_type' => [
        'Initial Consultation' => 'Initial Consultation',
        'Follow-up Meeting' => 'Follow-up Meeting',
        'Business Review' => 'Business Review',
        'Technical Support' => 'Technical Support',
        'Feedback Session' => 'Feedback Session',
        'Partnership Meeting' => 'Partnership Meeting',
    ],
];
