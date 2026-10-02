<?php

namespace Config;

use App\Libraries\ZohoBooks;
use App\Libraries\ZohoClient;
use App\Models\ZbCacheModel;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Zoho Books reads (customers, items, VAT tax) and invoice create/update,
     * backed by the zb_cache table.
     */
    public static function zohoBooks(bool $getShared = true): ZohoBooks
    {
        if ($getShared) {
            return static::getSharedInstance('zohoBooks');
        }

        $config = config(Invoice::class);

        return new ZohoBooks(new ZohoClient($config), model(ZbCacheModel::class), $config);
    }
}
