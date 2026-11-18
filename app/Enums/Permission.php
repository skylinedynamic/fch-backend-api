<?php

namespace App\Enums;

use App\Enums\Attributes\PermissionInfo;
use ReflectionEnumBackedCase;

/**
 * Every permission in the system, declared once.
 *
 * The value is the name stored in the permissions table. Each case's
 * PermissionInfo says which module it is listed under and which partners may
 * hold it. config/permissions.php - which the seeders read - is built from
 * here, so a new permission is one new case.
 *
 * Check permissions through a case, never by writing the name:
 *   Route::...->middleware(Permission::UpdateClients->middleware());
 *   $user->can(Permission::PayInvoice->value);
 * (Tests\Unit\Permissions\PermissionEnumTest fails on a name written as a
 * string anywhere in app/ or routes/.)
 */
enum Permission: string
{

    // Partners
    #[PermissionInfo(PermissionModule::Partners, certified: false, host: true)]
    case ViewPartners = 'view partners';
    #[PermissionInfo(PermissionModule::Partners, certified: false, host: true)]
    case CreatePartners = 'create partners';
    #[PermissionInfo(PermissionModule::Partners, certified: false, host: true)]
    case UpdatePartners = 'update partners';
    #[PermissionInfo(PermissionModule::Partners, certified: false, host: true)]
    case DeletePartners = 'delete partners';
    #[PermissionInfo(PermissionModule::Partners, certified: false, host: true, key: 'manage_configuration')]
    case ManagePartnerConfiguration = 'manage partner configuration';

    // Demo request
    #[PermissionInfo(PermissionModule::DemoRequest, certified: false, host: true)]
    case ViewDemoRequest = 'view demo request';
    #[PermissionInfo(PermissionModule::DemoRequest, certified: false, host: true)]
    case CreateDemoRequest = 'create demo request';
    #[PermissionInfo(PermissionModule::DemoRequest, certified: false, host: true)]
    case UpdateDemoRequest = 'update demo request';
    #[PermissionInfo(PermissionModule::DemoRequest, certified: false, host: true)]
    case DeleteDemoRequest = 'delete demo request';

    // Leads
    #[PermissionInfo(PermissionModule::Leads, certified: true, host: false)]
    case ViewLeads = 'view leads';
    #[PermissionInfo(PermissionModule::Leads, certified: true, host: false)]
    case CreateLeads = 'create leads';
    #[PermissionInfo(PermissionModule::Leads, certified: true, host: false)]
    case UpdateLeads = 'update leads';
    #[PermissionInfo(PermissionModule::Leads, certified: true, host: false)]
    case DeleteLeads = 'delete leads';

    // Contacts
    #[PermissionInfo(PermissionModule::Contacts, certified: true, host: false)]
    case ViewContacts = 'view contacts';
    #[PermissionInfo(PermissionModule::Contacts, certified: true, host: false)]
    case CreateContacts = 'create contacts';
    #[PermissionInfo(PermissionModule::Contacts, certified: true, host: false)]
    case UpdateContacts = 'update contacts';
    #[PermissionInfo(PermissionModule::Contacts, certified: true, host: false)]
    case DeleteContacts = 'delete contacts';

    // Clients
    #[PermissionInfo(PermissionModule::Clients, certified: true, host: false)]
    case ViewClients = 'view clients';
    #[PermissionInfo(PermissionModule::Clients, certified: true, host: false)]
    case CreateClients = 'create clients';
    #[PermissionInfo(PermissionModule::Clients, certified: true, host: false)]
    case UpdateClients = 'update clients';
    #[PermissionInfo(PermissionModule::Clients, certified: true, host: false)]
    case DeleteClients = 'delete clients';

    // Intake and review
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: true, host: false)]
    case ViewPreQualification = 'view pre qualification';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true)]
    case UpdatePreQualification = 'update pre qualification';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: true, host: false)]
    case ViewFundingApplication = 'view funding application';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true)]
    case UpdateFundingApplication = 'update funding application';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: false)]
    case ViewFundingRequest = 'view funding request';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true)]
    case UpdateFundingRequest = 'update funding request';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true)]
    case ViewChangeRequests = 'view change requests';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true)]
    case UpdateChangeRequests = 'update change requests';
    #[PermissionInfo(PermissionModule::IntakeAndReview, certified: false, host: true, key: 'manage_credit_profile_requests')]
    case ManageCreditProfileRequests = 'manage credit profile requests';

    // Commission
    #[PermissionInfo(PermissionModule::Commission, certified: true, host: false)]
    case ViewCommission = 'view commission';
    #[PermissionInfo(PermissionModule::Commission, certified: false, host: true)]
    case UpdateCommission = 'update commission';

    // Payments
    #[PermissionInfo(PermissionModule::Payments, certified: true, host: false)]
    case ViewPayments = 'view payments';
    #[PermissionInfo(PermissionModule::Payments, certified: true, host: false)]
    case ApprovePayment = 'can approve payment';

    // Invoices
    #[PermissionInfo(PermissionModule::Invoices, certified: true, host: false)]
    case ViewInvoices = 'view invoices';
    #[PermissionInfo(PermissionModule::Invoices, certified: true, host: false)]
    case PayInvoice = 'Pay Invoice';

    // Upcoming billings
    #[PermissionInfo(PermissionModule::UpcomingBillings, certified: true, host: false)]
    case ViewBillings = 'view billings';

    // Subscriptions
    #[PermissionInfo(PermissionModule::Subscriptions, certified: true, host: false)]
    case ViewSubscriptions = 'view subscriptions';
    #[PermissionInfo(PermissionModule::Subscriptions, certified: true, host: false)]
    case CreateSubscriptions = 'create subscriptions';
    #[PermissionInfo(PermissionModule::Subscriptions, certified: true, host: false)]
    case UpdateSubscriptions = 'update subscriptions';
    #[PermissionInfo(PermissionModule::Subscriptions, certified: true, host: false)]
    case DeleteSubscriptions = 'delete subscriptions';

    // Appointments
    #[PermissionInfo(PermissionModule::Appointments, certified: true, host: false)]
    case ViewAppointments = 'view appointments';
    #[PermissionInfo(PermissionModule::Appointments, certified: true, host: false)]
    case CreateAppointments = 'create appointments';
    #[PermissionInfo(PermissionModule::Appointments, certified: true, host: false)]
    case UpdateAppointments = 'update appointments';
    #[PermissionInfo(PermissionModule::Appointments, certified: true, host: false)]
    case DeleteAppointments = 'delete appointments';

    // Tasks & reminders
    #[PermissionInfo(PermissionModule::TasksAndReminders, certified: true, host: false)]
    case ViewReminders = 'view reminders';
    #[PermissionInfo(PermissionModule::TasksAndReminders, certified: true, host: false)]
    case CreateReminders = 'create reminders';
    #[PermissionInfo(PermissionModule::TasksAndReminders, certified: true, host: false)]
    case UpdateReminders = 'update reminders';
    #[PermissionInfo(PermissionModule::TasksAndReminders, certified: true, host: false)]
    case DeleteReminders = 'delete reminders';

    // Training & courses
    #[PermissionInfo(PermissionModule::TrainingAndCourses, certified: true, host: false)]
    case ViewTrainingAndCourses = 'view training & courses';
    #[PermissionInfo(PermissionModule::TrainingAndCourses, certified: false, host: false)]
    case CreateTrainingAndCourses = 'create training & courses';
    #[PermissionInfo(PermissionModule::TrainingAndCourses, certified: false, host: false)]
    case UpdateTrainingAndCourses = 'update training & courses';
    #[PermissionInfo(PermissionModule::TrainingAndCourses, certified: false, host: false)]
    case DeleteTrainingAndCourses = 'delete training & courses';

    // Support tickets
    #[PermissionInfo(PermissionModule::SupportTickets, certified: true, host: false)]
    case ViewSupportTickets = 'view support tickets';
    #[PermissionInfo(PermissionModule::SupportTickets, certified: true, host: false)]
    case CreateSupportTickets = 'create support tickets';
    #[PermissionInfo(PermissionModule::SupportTickets, certified: true, host: false)]
    case UpdateSupportTickets = 'update support tickets';
    #[PermissionInfo(PermissionModule::SupportTickets, certified: true, host: false)]
    case DeleteSupportTickets = 'delete support tickets';

    // Resources
    #[PermissionInfo(PermissionModule::Resources, certified: true, host: false)]
    case ViewResources = 'view resources';
    #[PermissionInfo(PermissionModule::Resources, certified: false, host: true)]
    case CreateResources = 'create resources';
    #[PermissionInfo(PermissionModule::Resources, certified: false, host: true)]
    case UpdateResources = 'update resources';
    #[PermissionInfo(PermissionModule::Resources, certified: false, host: true)]
    case DeleteResources = 'delete resources';

    // Employees
    #[PermissionInfo(PermissionModule::Employees, certified: true, host: false)]
    case ViewEmployees = 'view employees';
    #[PermissionInfo(PermissionModule::Employees, certified: true, host: false)]
    case CreateEmployees = 'create employees';
    #[PermissionInfo(PermissionModule::Employees, certified: true, host: false)]
    case UpdateEmployees = 'update employees';
    #[PermissionInfo(PermissionModule::Employees, certified: true, host: false)]
    case DeleteEmployees = 'delete employees';

    // Users
    #[PermissionInfo(PermissionModule::Users, certified: true, host: false)]
    case ViewUsers = 'view users';
    #[PermissionInfo(PermissionModule::Users, certified: true, host: false)]
    case CreateUsers = 'create users';
    #[PermissionInfo(PermissionModule::Users, certified: true, host: false)]
    case UpdateUsers = 'update users';
    #[PermissionInfo(PermissionModule::Users, certified: true, host: false)]
    case DeleteUsers = 'delete users';

    // Borrower documents
    #[PermissionInfo(PermissionModule::BorrowerDocuments, certified: true, host: false)]
    case ViewBorrowerDocuments = 'view borrower documents';
    #[PermissionInfo(PermissionModule::BorrowerDocuments, certified: true, host: false)]
    case UploadBorrowerDocuments = 'upload borrower documents';
    #[PermissionInfo(PermissionModule::BorrowerDocuments, certified: true, host: false)]
    case UpdateBorrowerDocumentsStatus = 'update borrower documents status';
    #[PermissionInfo(PermissionModule::BorrowerDocuments, certified: true, host: false)]
    case DeleteBorrowerDocuments = 'delete borrower documents';

    // Credit builder
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case ViewTier = 'view tier';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case UpdateTier = 'update tier';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case ViewVerificationRequest = 'view verification request';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case UpdateVerificationRequest = 'update verification request';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case ViewCreditAccounts = 'view credit accounts';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case CreateCreditAccounts = 'create credit accounts';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case UpdateCreditAccounts = 'update credit accounts';
    #[PermissionInfo(PermissionModule::CreditBuilder, certified: false, host: true)]
    case DeleteCreditAccounts = 'delete credit accounts';

    // Roles & permissions
    #[PermissionInfo(PermissionModule::RolesAndPermissions, certified: true, host: false)]
    case ViewRolesAndPermissions = 'view roles & permissions';
    #[PermissionInfo(PermissionModule::RolesAndPermissions, certified: true, host: false)]
    case CreateRolesAndPermissions = 'create roles & permissions';
    #[PermissionInfo(PermissionModule::RolesAndPermissions, certified: true, host: false)]
    case UpdateRolesAndPermissions = 'update roles & permissions';
    #[PermissionInfo(PermissionModule::RolesAndPermissions, certified: true, host: false)]
    case DeleteRolesAndPermissions = 'delete roles & permissions';

    // Settings
    #[PermissionInfo(PermissionModule::Settings, certified: true, host: false)]
    case UpdatePaymentSettings = 'update payment settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: true)]
    case UpdatePartnerAgreementSettings = 'update partner agreement settings';
    #[PermissionInfo(PermissionModule::Settings, certified: true, host: false)]
    case UpdateBorrowerAgreementSettings = 'update borrower agreement settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: false)]
    case UpdateEmailConfigurationSettings = 'update email configuration settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: false)]
    case UpdateSmsConfigurationSettings = 'update sms configuration settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: false)]
    case UpdateBrandingSettings = 'update branding settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: true)]
    case UpdateCreditProfileSettings = 'update credit profile settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: true)]
    case UpdateScoreCalculationSettings = 'update score calculation settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: true)]
    case UpdateMicrobiltSettings = 'update microbilt settings';
    #[PermissionInfo(PermissionModule::Settings, certified: false, host: false)]
    case UpdateDomainConfigurationSettings = 'update domain configuration settings';

    // Funding
    #[PermissionInfo(PermissionModule::Funding, certified: false, host: true)]
    case ViewFunding = 'view funding';
    #[PermissionInfo(PermissionModule::Funding, certified: false, host: true)]
    case CreateFunding = 'create funding';
    #[PermissionInfo(PermissionModule::Funding, certified: false, host: true)]
    case UpdateFunding = 'update funding';
    #[PermissionInfo(PermissionModule::Funding, certified: false, host: true)]
    case DeleteFunding = 'delete funding';

    // Lenders
    #[PermissionInfo(PermissionModule::Lenders, certified: false, host: true)]
    case ViewLenders = 'view lenders';
    #[PermissionInfo(PermissionModule::Lenders, certified: false, host: true)]
    case CreateLenders = 'create lenders';
    #[PermissionInfo(PermissionModule::Lenders, certified: false, host: true)]
    case UpdateLenders = 'update lenders';
    #[PermissionInfo(PermissionModule::Lenders, certified: false, host: true)]
    case DeleteLenders = 'delete lenders';

    // Cms
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case ViewSiteSettings = 'view site settings';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case AddSocialMedia = 'add soical media';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case UpdateSiteSettings = 'update site settings';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case ViewBlogs = 'view blogs';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case CreateBlogs = 'create blogs';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case UpdateBlogs = 'update blogs';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case ViewSeoSettings = 'view seo settings';
    #[PermissionInfo(PermissionModule::Cms, certified: false, host: true)]
    case UpdateSeoSettings = 'update seo settings';

    // Enbedded loan application form
    #[PermissionInfo(PermissionModule::EmbeddedLoanApplicationForm, certified: true, host: false)]
    case ViewConfiguration = 'view configuration';
    #[PermissionInfo(PermissionModule::EmbeddedLoanApplicationForm, certified: true, host: false)]
    case UpdateConfiguration = 'update configuration';
    #[PermissionInfo(PermissionModule::EmbeddedLoanApplicationForm, certified: true, host: false)]
    case ViewApplication = 'view application';
    #[PermissionInfo(PermissionModule::EmbeddedLoanApplicationForm, certified: true, host: false)]
    case ConvertApplication = 'convert application';

    // Business insights - the Developer role's alone
    #[PermissionInfo(PermissionModule::BusinessInsights, certified: false, host: true, developer: true)]
    case ViewBusinessInsights = 'view business insights';
    #[PermissionInfo(PermissionModule::BusinessInsights, certified: false, host: true, developer: true)]
    case ExportBusinessInsights = 'export business insights';
    #[PermissionInfo(PermissionModule::BusinessInsights, certified: false, host: true, developer: true)]
    case ManageBusinessInsightsReport = 'manage business insights report';

    public function module(): PermissionModule
    {
        return $this->info()->module;
    }

    /** A Certified Partner's roles may hold it. */
    public function isCertified(): bool
    {
        return $this->info()->certified;
    }

    /** Only the host's roles may hold it. */
    public function isHost(): bool
    {
        return $this->info()->host;
    }

    /** Only the reserved Developer role may hold it. */
    public function isDeveloper(): bool
    {
        return $this->info()->developer;
    }

    /** The route middleware that requires it. */
    public function middleware(): string
    {
        return 'permission:' . $this->value;
    }

    /**
     * The permissions as config/permissions.php has always listed them:
     * module => ['permissions' => [['name', 'is_certified', 'is_host'], ...]],
     * in declaration order, keyed where a seeder looks one up by key.
     *
     * @return array<string, array{permissions: array<int|string, array{name: string, is_certified: int, is_host: int}>}>
     */
    public static function toConfig(): array
    {
        $config = [];

        foreach (self::cases() as $permission) {
            $info = $permission->info();
            $entry = [
                'name' => $permission->value,
                'is_certified' => (int) $info->certified,
                'is_host' => (int) $info->host,
            ];
            // Only where set, so every other entry keeps its long-standing shape.
            if ($info->developer) {
                $entry['is_developer'] = 1;
            }

            $list = &$config[$info->module->value]['permissions'];
            if ($info->key !== null) {
                $list[$info->key] = $entry;
            } else {
                $list[] = $entry;
            }
            unset($list);
        }

        return $config;
    }

    private function info(): PermissionInfo
    {
        static $cache = [];

        return $cache[$this->name] ??= (new ReflectionEnumBackedCase(self::class, $this->name))
            ->getAttributes(PermissionInfo::class)[0]
            ->newInstance();
    }
}
