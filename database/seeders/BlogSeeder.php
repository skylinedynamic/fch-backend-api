<?php

namespace Database\Seeders;

use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed Categories
        $categoriesList = [
            ['slug' => 'finance-funding', 'name' => 'Finance & Funding'],
            ['slug' => 'tax-strategy', 'name' => 'Tax & Underwriting'],
            ['slug' => 'operations', 'name' => 'Business Operations'],
            ['slug' => 'growth-strategy', 'name' => 'Growth Strategy'],
            ['slug' => 'lifestyle-balance', 'name' => 'Executive Lifestyle'],
        ];

        $categoryMap = [];
        foreach ($categoriesList as $cat) {
            $category = BlogCategory::updateOrCreate(
                ['slug' => $cat['slug'], 'partner_id' => 1],
                [
                    'name' => $cat['name'],
                    'created_by' => 1,
                    'updated_by' => 1,
                ]
            );
            $categoryMap[$cat['slug']] = $category->id;
        }

        // 2. Seed Tags
        $tagsList = ["Finance", "Tax", "Underwriting", "Operations", "Balance", "Strategy"];
        $tagMap = [];
        foreach ($tagsList as $tagName) {
            $tag = BlogTag::updateOrCreate(
                ['name' => $tagName, 'partner_id' => 1],
                [
                    'slug' => Str::slug($tagName),
                    'created_by' => 1,
                    'updated_by' => 1,
                ]
            );
            $tagMap[$tagName] = $tag->id;
        }

        // 3. Seed Blogs
        $blogs = [
            [
                'slug' => "financial-consequences-tax-errors",
                'title' => "Financial Errors in Taxes That Can Cost Your Business Thousands",
                'featured_image' => "blogs/news-01.jpg",
                'tags' => ["Finance", "Tax"],
                'category_slug' => 'tax-strategy',
                'summary' => 'For most business owners, taxes are viewed as a compliance requirement — something to file, pay, and move on from. But from an underwriting and funding perspective, tax errors are not just administrative mistakes.',
                'content' => '
      <p class="blog-details__text-2">For most business owners, taxes are viewed as a compliance requirement — something to file, pay, and move on from. But from an underwriting and funding perspective, tax errors are not just administrative mistakes. <strong>They are financial signals.</strong></p>

      <p class="blog-details__text-2">And in many cases, they directly impact loan approvals, creditworthiness, funding amounts, interest rates, and lender confidence. What appears to be a minor tax oversight can translate into thousands — or even hundreds of thousands — in lost capital access.</p>

      <div class="blog-details__quote">
        <i class="fas fa-quote-left"></i>
        <p>Your tax profile is part of your underwriting profile. What you report to the IRS directly shapes what lenders see — and what they\'re willing to offer.</p>
      </div>

      <h4 class="blog-details__section-title">The Real Cost of Tax Errors</h4>
      <p class="blog-details__text-2">Tax mistakes don\'t just result in penalties from the IRS. They create downstream financial consequences that affect how lenders evaluate your business:</p>
      <ul class="blog-details__list">
        <li><i class="fas fa-exclamation-circle"></i> Reduced borrowing capacity</li>
        <li><i class="fas fa-exclamation-circle"></i> Increased risk classification</li>
        <li><i class="fas fa-exclamation-circle"></i> Delayed funding timelines</li>
        <li><i class="fas fa-exclamation-circle"></i> Additional documentation requirements</li>
        <li><i class="fas fa-exclamation-circle"></i> Higher decline rates from lenders</li>
      </ul>

      <h4 class="blog-details__section-title">Most Common Tax Errors That Impact Funding</h4>

      <div class="blog-details__error-card">
        <h5>1. Underreporting Income</h5>
        <p>While some business owners attempt to minimize tax liability by reducing reported income, this approach backfires when seeking financing. Lenders evaluate your ability to repay based on <strong>documented income</strong>. Lower reported income = lower approval potential, smaller loan amounts, and possible automatic declines.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>2. Excessive or Misclassified Deductions</h5>
        <p>Aggressive deductions may reduce taxable income — but they can also make your business appear unprofitable on paper. This is especially problematic for SBA loans, term loans, and lines of credit. The result: weak or negative net income and reduced DSCR (Debt Service Coverage Ratio).</p>
      </div>

      <div class="blog-details__error-card">
        <h5>3. Inconsistent Financial Reporting</h5>
        <p>When your tax returns don\'t align with bank statements, Profit &amp; Loss statements, or merchant processing data — it creates red flags. Underwriters are trained to detect inconsistencies quickly, causing file delays and loss of credibility.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>4. Late or Unfiled Tax Returns</h5>
        <p>Failure to file taxes on time is one of the fastest ways to block funding eligibility. Many lenders require the most recent 1–2 years filed with no outstanding gaps — and missing this leads to immediate disqualification for many programs.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>5. Outstanding Tax Liabilities</h5>
        <p>Unresolved tax debt signals financial instability and increases perceived risk. Even small balances can trigger declined applications, required payment plans, and reduced funding amounts.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>6. Incorrect Business Structure Reporting</h5>
        <p>Filing under the wrong structure — or inconsistently between years — creates compliance flags, approval delays, and additional verification requirements with underwriters.</p>
      </div>

      <h4 class="blog-details__section-title">How Lenders Actually View Your Taxes</h4>
      <p class="blog-details__text-2">From a lender\'s perspective, your tax returns answer three core questions:</p>
      <ul class="blog-details__list blog-details__list--numbered">
        <li><span>1</span> Does this business generate consistent income?</li>
        <li><span>2</span> Is the income stable and verifiable?</li>
        <li><span>3</span> Can the business support new debt obligations?</li>
      </ul>
      <p class="blog-details__text-2">If your tax filings weaken those answers, your funding outcome suffers — <strong>regardless of your actual cash flow.</strong></p>

      <div class="blog-details__insight-box">
        <h5><i class="fas fa-lightbulb"></i> The Underwriting Reality Most Businesses Miss</h5>
        <p>There is often a disconnect between <strong>tax strategy</strong> (minimize liability) and <strong>funding strategy</strong> (maximize approval strength). Optimizing for one without considering the other can hurt your long-term financial position.</p>
        <p>The goal is not just to reduce taxes — it\'s to balance tax efficiency with funding eligibility.</p>
      </div>

      <h4 class="blog-details__section-title">How to Avoid Costly Tax Mistakes</h4>
      <ul class="blog-details__list">
        <li><i class="fas fa-check-circle"></i> Work with professionals who understand lending requirements, not just tax filing</li>
        <li><i class="fas fa-check-circle"></i> Maintain consistency across all financial documents</li>
        <li><i class="fas fa-check-circle"></i> Avoid extreme income suppression strategies</li>
        <li><i class="fas fa-check-circle"></i> Resolve tax issues proactively before applying for funding</li>
        <li><i class="fas fa-check-circle"></i> Align your reporting with your future capital goals</li>
      </ul>

      <div class="blog-details__cta-box">
        <h5>Position Your Business the Right Way</h5>
        <p>At Four Corner Funding, we evaluate how your financial profile aligns with real underwriting standards. If you\'re planning to pursue funding, your tax structure should support that goal — not work against it.</p>
      </div>
    ',
            ],
            [
                'slug' => "work-life-balance-hectic-world",
                'title' => "Achieving Work-Life Balance in a Hectic World",
                'featured_image' => "blogs/news-02.jpg",
                'tags' => ["Operations"],
                'category_slug' => 'lifestyle-balance',
                'summary' => '“Work-life balance” is often framed as a personal productivity issue — manage your time better, work less, relax more. In reality, for business owners and entrepreneurs, that advice is incomplete.',
                'content' => '
      <p class="blog-details__text-2">“Work-life balance” is often framed as a personal productivity issue — manage your time better, work less, relax more. In reality, for business owners and entrepreneurs, that advice is incomplete. <strong>The real issue isn’t time. It’s system design.</strong></p>

      <p class="blog-details__text-2">Without structure, work expands indefinitely. And when work expands, balance disappears. The goal, therefore, isn’t perfect balance. It’s controlled workload and sustainable execution.</p>

      <div class="blog-details__quote">
        <i class="fas fa-quote-left"></i>
        <p>Balance is built, not found. It is something you engineer through structure, delegation, and disciplined systems that allow the business to work without requiring you to be everywhere at once.</p>
      </div>

      <h4 class="blog-details__section-title">The Reality for Business Owners</h4>
      <p class="blog-details__text-2">Unlike employees, business owners don’t operate within fixed hours. You are responsible for everything from revenue generation to risk management. Turning off isn\'t just difficult — it\'s often unrealistic without the right foundation.</p>

      <div class="blog-details__insight-box">
        <h5><i class="fas fa-lightbulb"></i> The Financial Impact of Imbalance</h5>
        <p>Work-life imbalance isn\'t just personal — it\'s a business risk. Chronic overload leads to poor decision-making, missed opportunities, and increased operational errors. Businesses often plateau because the owner becomes the bottleneck.</p>
      </div>

      <h4 class="blog-details__section-title">How to Create Real Balance</h4>

      <div class="blog-details__error-card">
        <h5>1. Remove Yourself as the Bottleneck</h5>
        <p>If every decision requires your involvement, the business cannot scale. Identify tasks only you can do vs. tasks someone else should be doing, and begin transferring responsibility immediately.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>2. Build Repeatable Systems</h5>
        <p>Chaos creates stress; systems create predictability. Document and standardize client onboarding, sales processes, and service delivery. When processes are repeatable, your time becomes more controlled.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>3. Prioritize High-Value Activities</h5>
        <p>Not all work is equal. Your role should focus on revenue strategy, capital access, and growth decisions — not administrative tasks or low-impact daily operations.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>4. Create Operational Boundaries</h5>
        <p>Examples include defined working hours (even if flexible), scheduled response windows, and delegated communication channels. Boundaries protect your performance and your business.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>5. Align Structure with Lifestyle Goals</h5>
        <p>Design your business model to support scalability, delegation, and financial stability from the start. Balance becomes achievable when the business is built with it in mind.</p>
      </div>

      <h4 class="blog-details__section-title">The Hidden Advantage of Structure</h4>
      <p class="blog-details__text-2">When your business operates through systems instead of constant effort:</p>
      <ul class="blog-details__list">
        <li><i class="fas fa-check-circle"></i> Decisions become clearer and execution becomes faster</li>
        <li><i class="fas fa-check-circle"></i> Stress decreases and growth becomes more consistent</li>
        <li><i class="fas fa-check-circle"></i> You regain control over your time without sacrificing income</li>
      </ul>
    ',
            ],
            [
                'slug' => "mastering-work-life-business-goal",
                'title' => "Mastering Work-Life Balance While Staying Focused on Business Goals",
                'featured_image' => "blogs/news-03.jpg",
                'tags' => ["Business", "Strategy"],
                'category_slug' => 'lifestyle-balance',
                'summary' => 'Most business owners are told to “stay focused” and “go all in” on their goals. And while focus is critical, there’s a problem: Unstructured focus leads to imbalance.',
                'content' => '
      <p class="blog-details__text-2">Most business owners are told to “stay focused” and “go all in” on their goals. And while focus is critical, there’s a problem: Unstructured focus leads to imbalance. And imbalance eventually leads to burnout, poor decisions, and stalled growth.</p>

      <p class="blog-details__text-2">The real objective isn’t just staying focused — it’s maintaining performance without sacrificing sustainability. The issue isn’t ambition; it\'s a lack of alignment between your business model and your capacity.</p>

      <div class="blog-details__quote">
        <i class="fas fa-quote-left"></i>
        <p>Sustainable success requires more than just hitting targets — it requires maintaining the capacity to keep performing at a high level. Balance, when structured correctly, becomes a competitive advantage.</p>
      </div>

      <h4 class="blog-details__section-title">What “On-Target” Actually Means</h4>
      <p class="blog-details__text-2">Being “on target” is not about working more hours. It means hitting revenue benchmarks, maintaining operational consistency, and preserving decision quality without constant overextension.</p>

      <div class="blog-details__insight-box">
        <h5><i class="fas fa-lightbulb"></i> The Financial Advantage of Balance</h5>
        <p>Balanced operators make better financial decisions, identify opportunities faster, and maintain stronger lender confidence. Overextended operators often miss key details and create avoidable risks.</p>
      </div>

      <h4 class="blog-details__section-title">How to Stay On Target Without Losing Balance</h4>

      <div class="blog-details__error-card">
        <h5>1. Build a Goal-Driven System, Not Just a Goal</h5>
        <p>Goals without systems create pressure. Systems convert goals into repeatable actions, measurable processes, and predictable outcomes. When your business runs on systems, progress becomes consistent — not chaotic.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>2. Separate Strategic Work From Operational Work</h5>
        <p>Your time should prioritize revenue strategy, capital positioning, and high-level decisions. Operational tasks should be delegated, automated, or systematized. Balance improves when your role is aligned with impact.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>3. Define Performance Thresholds</h5>
        <p>Instead of working endlessly, define what “enough” looks like, what targets must be met, and what metrics signal success. This creates clarity and prevents unnecessary overwork.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>4. Control Workload Through Structure</h5>
        <p>Work expands when there are no constraints. Introduce structure through defined working windows, scheduled decision blocks, and controlled communication flow to improve efficiency and protect your focus.</p>
      </div>

      <div class="blog-details__error-card">
        <h5>5. Build a Business That Doesn’t Collapse Without You</h5>
        <p>If stepping away causes disruption, the issue is system dependency. Document key processes, train support roles, and implement operational controls to create continuity without constant oversight.</p>
      </div>
    ',
            ],
        ];

        foreach ($blogs as $blogData) {
            $blog = Blog::updateOrCreate(
                ['slug' => $blogData['slug'], 'partner_id' => 1],
                [
                    'title' => $blogData['title'],
                    'featured_image' => $blogData['featured_image'],
                    'category_id' => $categoryMap[$blogData['category_slug']] ?? null,
                    'blog_tag_id' => array_map(fn($t) => (string)($tagMap[$t] ?? ''), $blogData['tags'] ?? []),
                    'summary' => $blogData['summary'],
                    'content' => $blogData['content'],
                    'author_id' => 1,
                    'is_published' => true,
                    'is_featured' => true,
                    'published_at' => now(),
                    'created_by' => 1,
                    'updated_by' => 1,
                ]
            );
        }
    }
}
