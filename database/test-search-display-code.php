<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\DeliveryService;
use App\ParcelCodeHelper;

$campaign = CampaignService::all()[0];
$id = (int) $campaign['id'];
$campaignFull = CampaignService::find($id);
$suffix = (string) ($campaignFull['parcel_code_suffix'] ?? '');

$beneficiaries = CampaignService::beneficiaries($id);
$b = $beneficiaries[0];
$full = (string) ($b['disbursement_code'] ?? '');
$display = ParcelCodeHelper::displayForBeneficiary($full, $suffix);

echo "full={$full} display={$display}\n";

$byDisplay = DeliveryService::search($id, $display);
$byFull = DeliveryService::search($id, $full);

echo ($byDisplay ? 'OK' : 'FAIL') . " search_by_display_code\n";
echo ($byFull ? 'OK' : 'FAIL') . " search_by_full_code\n";
