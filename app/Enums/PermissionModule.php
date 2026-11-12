<?php

namespace App\Enums;

/**
 * The groups permissions are listed under on the Roles & Permissions screen
 * (the `module` column). The values are stored, so they keep their spelling.
 */
enum PermissionModule: string
{
    case Partners = 'partners';
    case DemoRequest = 'demo request';
    case Leads = 'leads';
    case Contacts = 'contacts';
    case Clients = 'clients';
    case IntakeAndReview = 'intake and review';
    case Commission = 'commission';
    case Payments = 'payments';
    case Invoices = 'invoices';
    case UpcomingBillings = 'upcoming billings';
    case Subscriptions = 'subscriptions';
    case Appointments = 'appointments';
    case TasksAndReminders = 'tasks & reminders';
    case TrainingAndCourses = 'training & courses';
    case SupportTickets = 'support tickets';
    case Resources = 'resources';
    case Employees = 'employees';
    case Users = 'users';
    case BorrowerDocuments = 'borrower documents';
    case CreditBuilder = 'credit builder';
    case RolesAndPermissions = 'roles & permissions';
    case Settings = 'settings';
    case Funding = 'funding';
    case Lenders = 'lenders';
    case Cms = 'cms';
    case EmbeddedLoanApplicationForm = 'enbedded loan application form';
}
