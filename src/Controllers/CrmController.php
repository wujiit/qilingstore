<?php

declare(strict_types=1);

namespace Qiling\Controllers;

use Qiling\Controllers\Crm\CrmActivityController;
use Qiling\Controllers\Crm\CrmCompanyController;
use Qiling\Controllers\Crm\CrmContactController;
use Qiling\Controllers\Crm\CrmDashboardController;
use Qiling\Controllers\Crm\CrmDealController;
use Qiling\Controllers\Crm\CrmLeadController;
use Qiling\Controllers\Crm\CrmPipelineController;

final class CrmController
{
    public static function dashboard(): void
    {
        CrmDashboardController::dashboard();
    }

    public static function pipelines(): void
    {
        CrmPipelineController::pipelines();
    }

    public static function upsertPipeline(): void
    {
        CrmPipelineController::upsertPipeline();
    }

    public static function companies(): void
    {
        CrmCompanyController::companies();
    }

    public static function createCompany(): void
    {
        CrmCompanyController::createCompany();
    }

    public static function updateCompany(): void
    {
        CrmCompanyController::updateCompany();
    }

    public static function contacts(): void
    {
        CrmContactController::contacts();
    }

    public static function createContact(): void
    {
        CrmContactController::createContact();
    }

    public static function exportContacts(): void
    {
        CrmContactController::exportContacts();
    }

    public static function updateContact(): void
    {
        CrmContactController::updateContact();
    }

    public static function leads(): void
    {
        CrmLeadController::leads();
    }

    public static function exportLeads(): void
    {
        CrmLeadController::exportLeads();
    }

    public static function createLead(): void
    {
        CrmLeadController::createLead();
    }

    public static function updateLead(): void
    {
        CrmLeadController::updateLead();
    }

    public static function batchUpdateLeads(): void
    {
        CrmLeadController::batchUpdateLeads();
    }

    public static function convertLead(): void
    {
        CrmLeadController::convertLead();
    }

    public static function deals(): void
    {
        CrmDealController::deals();
    }

    public static function createDeal(): void
    {
        CrmDealController::createDeal();
    }

    public static function updateDeal(): void
    {
        CrmDealController::updateDeal();
    }

    public static function batchUpdateDeals(): void
    {
        CrmDealController::batchUpdateDeals();
    }

    public static function activities(): void
    {
        CrmActivityController::activities();
    }

    public static function createActivity(): void
    {
        CrmActivityController::createActivity();
    }

    public static function updateActivityStatus(): void
    {
        CrmActivityController::updateActivityStatus();
    }
}
