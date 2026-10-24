<?php

namespace App\Support\Lead;

/**
 * The website pre-qualification's entity and industry answers, as the portal's
 * own options (funding_application.options) - the lists a lead and a borrower
 * hold and the portal's dropdowns show (BUG-090).
 *
 * An answer that is already a portal option is kept; one the portal has no
 * option for becomes Other, with the answer kept as the note.
 */
final class WebsiteBusinessOptions
{
    private const OTHER = 'Other';

    /** Website "Business Structure" => portal entity type. */
    private const ENTITIES = [
        'LLC' => 'Limited Liability Company (LLC)',
        'S-Corporation' => 'S-Corporation',
        'Corporation' => 'C-Corporation',
        'Public Corporation' => 'C-Corporation',
        'Sole Proprietorship' => 'Sole Proprietor',
        'Partnership' => 'Partnership',
    ];

    /** Website "Industry" => portal industry type. */
    private const INDUSTRIES = [
        'Accounting & Financial Services' => 'Financial Services (Non-Lending)',
        'Automotive Services & Dealerships' => 'Automotive (Sales, Repair, Transport)',
        'Beauty, Salon & Spa Services' => 'Personal Services (Beauty, Fitness, Wellness)',
        'Cleaning & Janitorial Services' => 'Home Services (Cleaning, Landscaping, Repair)',
        'Construction & Contracting' => 'Construction & Contracting',
        'E-Commerce / Online Retail' => 'E-Commerce & Online Businesses',
        'Education & Tutoring Services' => 'Education & Training',
        'Fitness & Wellness (Gyms, Yoga, Etc.)' => 'Personal Services (Beauty, Fitness, Wellness)',
        'Healthcare & Medical Practices' => 'Healthcare & Medical Services',
        'Home Services (Plumbing, HVAC, Landscaping, etc.)' => 'Home Services (Cleaning, Landscaping, Repair)',
        'Hospitality (Hotels / Lodging)' => 'Hospitality (Hotels, Short-Term Rentals)',
        'IT & Software Development' => 'Information Technology & Software',
        'Legal & Professional Services' => 'Consulting & Professional Services',
        'Manufacturing & Wholesale' => 'Manufacturing',
        'Marketing, Advertising & Media' => 'Advertising, Marketing & Media',
        'Non-Profit Organization' => 'Non-Profit Organization',
        'Real Estate & Property Management' => 'Real Estate (Non-Lending)',
        'Restaurants, Bars & Catering' => 'Food Service & Restaurants',
        'Retail (Brick & Mortar)' => 'Retail (Brick & Mortar)',
        'Transportation & Logistics' => 'Transportation & Logistics',
    ];

    /** @return array{0: ?string, 1: ?string} [entity type, note] */
    public static function entity(?string $answer): array
    {
        return self::toPortal($answer, self::ENTITIES, config('funding_application.options.entity_types', []));
    }

    /** @return array{0: ?string, 1: ?string} [industry type, note] */
    public static function industry(?string $answer): array
    {
        return self::toPortal($answer, self::INDUSTRIES, config('funding_application.options.industry_types', []));
    }

    /**
     * @param array<string, string> $map
     * @param array<int, string> $portalOptions
     * @return array{0: ?string, 1: ?string}
     */
    private static function toPortal(?string $answer, array $map, array $portalOptions): array
    {
        $answer = trim((string) $answer);

        if ($answer === '') {
            return [null, null];
        }
        if (isset($map[$answer])) {
            return [$map[$answer], null];
        }
        if (in_array($answer, $portalOptions, true)) {
            return [$answer, null];
        }

        return [self::OTHER, $answer];
    }
}
