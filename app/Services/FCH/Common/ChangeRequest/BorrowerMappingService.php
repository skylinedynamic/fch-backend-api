<?php

namespace App\Services\FCH\Common\ChangeRequest;

class BorrowerMappingService
{
    /**
     * Get mapping of frontend fields to database columns and types
     *
     * @param string|null $field
     * @return array|null
     */
    public static function getFieldMapping(?string $field = null)
    {
        $borrowerModel    = \App\Models\Borrower\Borrower::class;
        $bankAccountModel = \App\Models\Borrower\BorrowerBankAccount::class;

        $mapping = [
            // Personal Info
            'first_name'          => ['db_column' => 'first_name',          'visible_column' => 'First Name',             'type' => 'string',  'model' => $borrowerModel],
            'last_name'           => ['db_column' => 'last_name',           'visible_column' => 'Last Name',              'type' => 'string',  'model' => $borrowerModel],
            'email'               => ['db_column' => 'email',               'visible_column' => 'Email Address',          'type' => 'string',  'model' => $borrowerModel],
            'phone_number'        => ['db_column' => 'phone_number',        'visible_column' => 'Phone Number',           'type' => 'string',  'model' => $borrowerModel],
            'date_of_birth'       => ['db_column' => 'date_of_birth',       'visible_column' => 'Date of Birth',          'type' => 'date',    'model' => $borrowerModel],
            'last_4_ssn'          => ['db_column' => 'last_4_ssn',          'visible_column' => 'Last 4 SSN Number',      'type' => 'string',  'model' => $borrowerModel],
            'zip_code'            => ['db_column' => 'postal_code',         'visible_column' => 'Zip Code',               'type' => 'string',  'model' => $borrowerModel],
            'country'             => ['db_column' => 'country',             'visible_column' => 'Country',                'type' => 'string',  'model' => $borrowerModel],
            'country_code'        => ['db_column' => 'country_code',        'visible_column' => 'Country Code',           'type' => 'string',  'model' => $borrowerModel],
            'city'                => ['db_column' => 'city',                'visible_column' => 'City',                   'type' => 'string',  'model' => $borrowerModel],
            'state'               => ['db_column' => 'state',               'visible_column' => 'State',                  'type' => 'string',  'model' => $borrowerModel],
            'home_address'        => ['db_column' => 'home_address',        'visible_column' => 'Home Address',           'type' => 'string',  'model' => $borrowerModel],
            'home_phone'          => ['db_column' => 'home_phone',          'visible_column' => 'Home Phone',             'type' => 'string',  'model' => $borrowerModel],

            // Business Info
            'business_name'         => ['db_column' => 'business_name',         'visible_column' => 'Business Name',          'type' => 'string',  'model' => $borrowerModel],
            'business_ein'          => ['db_column' => 'business_ein',          'visible_column' => 'Business EIN No',        'type' => 'string',  'model' => $borrowerModel],
            'business_phone'        => ['db_column' => 'business_phone',        'visible_column' => 'Business Phone No',      'type' => 'string',  'model' => $borrowerModel],
            'business_email'        => ['db_column' => 'business_email',        'visible_column' => 'Business Email Address', 'type' => 'string',  'model' => $borrowerModel],
            'business_city'         => ['db_column' => 'business_city',         'visible_column' => 'Business City',          'type' => 'string',  'model' => $borrowerModel],
            'business_state'        => ['db_column' => 'business_state',        'visible_column' => 'Business State',         'type' => 'string',  'model' => $borrowerModel],
            'business_country'      => ['db_column' => 'business_country',      'visible_column' => 'Business Country',       'type' => 'string',  'model' => $borrowerModel],
            'business_country_code' => ['db_column' => 'business_country_code', 'visible_column' => 'Business Country Code',  'type' => 'string',  'model' => $borrowerModel],
            'business_zip_code'     => ['db_column' => 'business_postal_code',  'visible_column' => 'Business Zip Code',      'type' => 'string',  'model' => $borrowerModel],
            'business_entity_type'  => ['db_column' => 'business_entity_type',  'visible_column' => 'Business Entity Type',   'type' => 'string',  'model' => $borrowerModel],
            'business_industry_type'=> ['db_column' => 'business_industry_type','visible_column' => 'Business Industry Type', 'type' => 'string',  'model' => $borrowerModel],
            'duns_number'           => ['db_column' => 'duns_number',           'visible_column' => 'DUNS Number',            'type' => 'string',  'model' => $borrowerModel],
            'business_address'      => ['db_column' => 'business_address',      'visible_column' => 'Business Address',       'type' => 'string',  'model' => $borrowerModel],
            'business_website'      => ['db_column' => 'business_website',      'visible_column' => 'Business Website',       'type' => 'string',  'model' => $borrowerModel],
            'business_start_date'   => ['db_column' => 'business_start_date',   'visible_column' => 'Business Start Date',    'type' => 'date',    'model' => $borrowerModel],
            'personal_credit_score' => ['db_column' => 'fico_score',            'visible_column' => 'Personal Credit Score',  'type' => 'integer', 'model' => $borrowerModel],

            // Financial Info
            'currency'          => ['db_column' => 'currency',          'visible_column' => 'Currency',          'type' => 'string',  'model' => $borrowerModel],
            'years_in_business' => ['db_column' => 'years_in_business', 'visible_column' => 'Years in Business', 'type' => 'string',  'model' => $borrowerModel],
            'monthly_revenue'   => ['db_column' => 'monthly_revenue',   'visible_column' => 'Monthly Revenue',   'type' => 'decimal', 'model' => $borrowerModel],
            'annual_revenue'    => ['db_column' => 'annual_revenue',    'visible_column' => 'Annual Revenue',    'type' => 'decimal', 'model' => $borrowerModel],

            // Bank Accounts (child table — entity_id identifies the row)
            'bank_name'         => ['db_column' => 'bank_name',         'visible_column' => 'Bank Name',         'type' => 'string',  'model' => $bankAccountModel, 'table' => 'borrower_bank_accounts'],
            'account_open_date' => ['db_column' => 'account_open_date', 'visible_column' => 'Account Open Date', 'type' => 'date',    'model' => $bankAccountModel, 'table' => 'borrower_bank_accounts'],
        ];

        if ($field) {
            return $mapping[$field] ?? null;
        }

        return $mapping;
    }

    /**
     * Get the database column name for a given frontend field
     *
     * @param string $field
     * @return string
     */
    public static function getDbColumn(string $field): string
    {
        $map = self::getFieldMapping($field);
        return $map ? $map['db_column'] : $field;
    }

    /**
     * Get the visible label for a given frontend field
     *
     * @param string $field
     * @return string
     */
    public static function getVisibleColumn(string $field): string
    {
        $map = self::getFieldMapping($field);
        return $map ? $map['visible_column'] : ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Get the data type for a given frontend field
     *
     * @param string $field
     * @return string
     */
    public static function getDataType(string $field): string
    {
        $map = self::getFieldMapping($field);
        return $map ? $map['type'] : 'string';
    }
}
