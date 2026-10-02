<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the tax invoice module (Zoho Books connection, login, numbering,
 * VAT and the supplier / bank details printed on the invoice).
 *
 * Secrets are left empty here on purpose: set them in the project's .env file as
 * invoice.<property> = '...' (see .env.example). Never commit real values.
 */
class Invoice extends BaseConfig
{
    // --- Zoho connection (secrets: set in .env) --------------------------------
    public string $zohoClientId     = '';
    public string $zohoClientSecret = '';
    public string $zohoRefreshToken = '';
    public string $zohoOrgId        = '';

    public string $zohoAccountsUrl = 'https://accounts.zoho.com/oauth/v2/token';
    public string $zohoApiUrl      = 'https://www.zohoapis.com/books/v3';

    /**
     * Path to a CA bundle for Zoho's TLS certificates. Empty = SSL verification off
     * (LocalWP workaround); set this before going live.
     */
    public string $zohoCaBundle = '';

    /**
     * Optional: force a Zoho tax_id instead of looking up the tax matching $vatRate.
     */
    public string $zohoVatTaxId = '';

    /**
     * api_name of the TIN custom field on Zoho contacts.
     */
    public string $zohoTinField = 'cf_tin_number';

    // --- App login (single user, secrets: set in .env) -------------------------
    public string $appUsername     = '';
    public string $appPasswordHash = '';

    // --- Invoices --------------------------------------------------------------
    /**
     * Middle part of invoice numbers, e.g. 26SEP_SAMM_00455.
     */
    public string $invoiceCode = 'SAMM';

    /**
     * First running number (e.g. 456 to continue an existing series).
     */
    public int $invoiceSeqStart = 1;

    /**
     * VAT percent.
     */
    public float $vatRate = 18.0;

    public string $supplyPlaceDefault = 'Main';

    /**
     * Seconds customers/items are cached before re-fetching from Zoho (15 min).
     */
    public int $cacheTtl = 900;

    // --- Printed on the tax invoice --------------------------------------------
    public string $supplierTin     = '';
    public string $supplierName    = '';
    public string $supplierAddress = '';
    public string $supplierPhone   = '';

    public string $supplierEmail = '';

    public string $paymentMethod   = '';
    public string $bankAccountName = '';
    public string $bankName        = '';
    public string $bankBranch      = '';
    public string $bankBranchCode  = '';
    public string $bankAccountNo   = '';
    public string $bankSwift       = '';

    // public string $printedBy = '';
}
