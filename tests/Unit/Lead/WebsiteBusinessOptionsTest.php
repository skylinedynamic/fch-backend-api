<?php

namespace Tests\Unit\Lead;

use App\Support\Lead\WebsiteBusinessOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-090: the website pre-qualification offers its own entity and industry
 * options; a lead and a borrower hold the portal's (funding_application.options).
 * Each website answer becomes the portal option it means, or Other with the
 * answer kept as the note - never a value the portal's dropdowns cannot show.
 */
#[CoversClass(WebsiteBusinessOptions::class)]
class WebsiteBusinessOptionsTest extends TestCase
{
    /** @return iterable<string, array{?string, array{0: ?string, 1: ?string}}> */
    public static function entities(): iterable
    {
        yield 'LLC' => ['LLC', ['Limited Liability Company (LLC)', null]];
        yield 'S-Corporation' => ['S-Corporation', ['S-Corporation', null]];
        yield 'Corporation' => ['Corporation', ['C-Corporation', null]];
        yield 'Public Corporation' => ['Public Corporation', ['C-Corporation', null]];
        yield 'Sole Proprietorship' => ['Sole Proprietorship', ['Sole Proprietor', null]];
        yield 'Partnership' => ['Partnership', ['Partnership', null]];
        yield 'PLLC - no match' => ['PLLC', ['Other', 'PLLC']];
        yield 'Other' => ['Other', ['Other', null]];
        yield 'already a portal option' => ['Limited Liability Company (LLC)', ['Limited Liability Company (LLC)', null]];
        yield 'not given' => [null, [null, null]];
        yield 'blank' => ['  ', [null, null]];
    }

    #[Test]
    #[DataProvider('entities')]
    public function each_website_entity_becomes_a_portal_option(?string $website, array $expected): void
    {
        $this->assertSame($expected, WebsiteBusinessOptions::entity($website));
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function industries(): iterable
    {
        yield ['Accounting & Financial Services', 'Financial Services (Non-Lending)', null];
        yield ['Automotive Services & Dealerships', 'Automotive (Sales, Repair, Transport)', null];
        yield ['Beauty, Salon & Spa Services', 'Personal Services (Beauty, Fitness, Wellness)', null];
        yield ['Cleaning & Janitorial Services', 'Home Services (Cleaning, Landscaping, Repair)', null];
        yield ['Construction & Contracting', 'Construction & Contracting', null];
        yield ['E-Commerce / Online Retail', 'E-Commerce & Online Businesses', null];
        yield ['Education & Tutoring Services', 'Education & Training', null];
        yield ['Fitness & Wellness (Gyms, Yoga, Etc.)', 'Personal Services (Beauty, Fitness, Wellness)', null];
        yield ['Healthcare & Medical Practices', 'Healthcare & Medical Services', null];
        yield ['Home Services (Plumbing, HVAC, Landscaping, etc.)', 'Home Services (Cleaning, Landscaping, Repair)', null];
        yield ['Hospitality (Hotels / Lodging)', 'Hospitality (Hotels, Short-Term Rentals)', null];
        yield ['IT & Software Development', 'Information Technology & Software', null];
        yield ['Legal & Professional Services', 'Consulting & Professional Services', null];
        yield ['Manufacturing & Wholesale', 'Manufacturing', null];
        yield ['Marketing, Advertising & Media', 'Advertising, Marketing & Media', null];
        yield ['Non-Profit Organization', 'Non-Profit Organization', null];
        yield ['Real Estate & Property Management', 'Real Estate (Non-Lending)', null];
        yield ['Restaurants, Bars & Catering', 'Food Service & Restaurants', null];
        yield ['Retail (Brick & Mortar)', 'Retail (Brick & Mortar)', null];
        yield ['Transportation & Logistics', 'Transportation & Logistics', null];
        yield ['Cannabis / CBD Industry', 'Other', 'Cannabis / CBD Industry'];
        yield ['Childcare & Daycare', 'Other', 'Childcare & Daycare'];
        yield ['Franchise Businesses', 'Other', 'Franchise Businesses'];
        yield ['Government Contractor / Vendor', 'Other', 'Government Contractor / Vendor'];
        yield ['Beauty/Salon', 'Other', 'Beauty/Salon'];
        yield ['Other', 'Other', null];
    }

    #[Test]
    #[DataProvider('industries')]
    public function each_website_industry_becomes_a_portal_option(string $website, string $type, ?string $note): void
    {
        $this->assertSame([$type, $note], WebsiteBusinessOptions::industry($website));
    }

    #[Test]
    public function every_result_is_one_of_the_portals_options(): void
    {
        foreach (self::industries() as [$website]) {
            $this->assertContains(WebsiteBusinessOptions::industry($website)[0], config('funding_application.options.industry_types'));
        }
        foreach (self::entities() as [$website, [$type]]) {
            if ($type !== null) {
                $this->assertContains(WebsiteBusinessOptions::entity($website)[0], config('funding_application.options.entity_types'));
            }
        }
    }
}
